<?php

/**
 * @package     Mucronix Registration
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * The plugin on a running site, over HTTP, without JavaScript: registration,
 * substituted values, name = username, registration IP address, the profile
 * on the site in the variant set in the plugin settings. Not part of the package.
 *
 * The site's database is only read (SELECT), see site-db.php. Users are created
 * through the registration form only, with logins starting with the prefix;
 * the suite never deletes them.
 *
 * The --config value states what is set in the plugin settings; the page does not
 * say it, so a wrong value gives red cases that are not about the plugin:
 *
 *   default   every setting at its default
 *   selected  Countries to Show: Only selected - Ukraine, Poland, Germany;
 *             Show Regions: Only for selected - Poland;
 *             Countries at the Top: PL, UA;
 *             Country Required: No; Hide Name Field: No; Store IP Address: No;
 *             the rest at the default
 *   view      Country and Region in the Site Profile: View only, the rest at the default
 *   hide      Country and Region in the Site Profile: Hide, the rest at the default
 *
 * Run "default" first: it creates the users the other variants log in with,
 * and keeps their logins in the temporary folder.
 *
 * Usage: php tests/registration-test.php --config=default|selected|view|hide
 *            [--url=https://joomla6/] [--site=D:/OSPanel1/home/joomla6]
 *            [--prefix=mrtest_] [--email=mrtest+{login}@example.com]
 *
 * Requests come from two local addresses, 127.0.0.7 for registration and 127.0.0.8
 * for the profile, so a registration IP address overwritten later is visible.
 * Certificates are not checked: this is for a local test site only.
 */

require __DIR__ . '/site-db.php';

$options = getopt('', ['config:', 'url:', 'site:', 'prefix:', 'email:']);
$config  = $options['config'] ?? '';
$base    = rtrim($options['url'] ?? 'https://joomla6/', '/') . '/';
$site    = rtrim(str_replace('\\', '/', $options['site'] ?? dirname(__DIR__, 2) . '/joomla6'), '/');
$prefix  = $options['prefix'] ?? 'mrtest_';
$email   = $options['email'] ?? 'mrtest+{login}@example.com';

if (!in_array($config, ['default', 'selected', 'view', 'hide'], true)) {
    fwrite(STDERR, "Pass --config=default|selected|view|hide: what is set in the plugin settings now\n");
    exit(2);
}

const IP_REGISTER = '127.0.0.7';
const IP_PROFILE  = '127.0.0.8';

$stateFile = sys_get_temp_dir() . '/mucronixregistration-tests/state.json';
$db        = new SiteDb($site);
$pass      = 0;
$fail      = 0;
$created   = [];

// ---------------------------------------------------------------------------
// HTTP

final class Browser
{
    private string $jar;

    public string $url = '';

    public function __construct(private string $base, private string $ip)
    {
        $this->jar = tempnam(sys_get_temp_dir(), 'mr-cookies-');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /**
     * Sends a request, follows redirects, returns the body of the last page.
     */
    public function request(string $path, ?array $post = null, array $headers = []): string
    {
        $curl = curl_init(str_starts_with($path, 'http') ? $path : $this->base . ltrim($path, '/'));

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_COOKIEJAR      => $this->jar,
            CURLOPT_COOKIEFILE     => $this->jar,
            CURLOPT_INTERFACE      => $this->ip,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        if ($post !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $body = curl_exec($curl);

        if ($body === false) {
            throw new RuntimeException('HTTP error: ' . curl_error($curl));
        }

        $this->url = (string) curl_getinfo($curl, CURLINFO_EFFECTIVE_URL);

        return $body;
    }
}

final class Page
{
    public DOMXPath $xpath;

    public function __construct(public string $html)
    {
        $document = new DOMDocument();
        @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
        $this->xpath = new DOMXPath($document);
    }

    public function form(string $query): ?DOMElement
    {
        $node = $this->xpath->query($query)->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    /**
     * The values a browser would send with a form: name => value.
     */
    public function values(DOMElement $form): array
    {
        $values = [];

        foreach ($this->xpath->query('.//input[@name] | .//select[@name] | .//textarea[@name]', $form) as $field) {
            $name = $field->getAttribute('name');

            if ($field->hasAttribute('disabled')) {
                continue;
            }

            if ($field->nodeName === 'select') {
                $selected = $this->xpath->query('.//option[@selected]', $field)->item(0) ?? $this->xpath->query('.//option', $field)->item(0);
                $values[$name] = $selected ? $selected->getAttribute('value') : '';
            } elseif ($field->nodeName === 'textarea') {
                $values[$name] = $field->textContent;
            } elseif (in_array($field->getAttribute('type'), ['checkbox', 'radio'], true)) {
                if ($field->hasAttribute('checked')) {
                    $values[$name] = $field->getAttribute('value') ?: 'on';
                }
            } elseif (!in_array($field->getAttribute('type'), ['submit', 'button', 'reset', 'file'], true)) {
                $values[$name] = $field->getAttribute('value');
            }
        }

        return $values;
    }

    public function action(DOMElement $form, string $base): string
    {
        $action = html_entity_decode($form->getAttribute('action'));

        return preg_match('#^https?://#', $action) ? $action : rtrim($base, '/') . '/' . ltrim($action, '/');
    }

    /**
     * Option values of a select, in order, without the empty one.
     */
    public function options(string $id): array
    {
        $list = [];

        foreach ($this->xpath->query("//select[@id='$id']/option") as $option) {
            if ($option->getAttribute('value') !== '') {
                $list[$option->getAttribute('value')] = trim($option->textContent);
            }
        }

        return $list;
    }

    public function exists(string $query): bool
    {
        return $this->xpath->query($query)->length > 0;
    }

    /**
     * The system messages of the page, as text.
     */
    public function messages(): string
    {
        $text = '';

        foreach ($this->xpath->query("//*[@id='system-message-container']") as $node) {
            $text .= ' ' . $node->textContent;
        }

        foreach ($this->xpath->query("//script[contains(@class, 'joomla-script-options')]") as $node) {
            $json = json_decode($node->textContent, true);

            foreach ((array) ($json['joomla.messages'] ?? []) as $group) {
                foreach ((array) $group as $messages) {
                    $text .= ' ' . implode(' ', (array) $messages);
                }
            }
        }

        return trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($text))));
    }

    /**
     * The fieldset of the plugin on the profile page: label => value.
     */
    public function profileValues(): array
    {
        $result = [];

        foreach ($this->xpath->query("//fieldset[@id='users-profile-custom-mucronixregistration']//dt") as $dt) {
            $dd = $dt->nextSibling;

            while ($dd && $dd->nodeName !== 'dd') {
                $dd = $dd->nextSibling;
            }

            $result[trim($dt->textContent)] = $dd ? trim($dd->textContent) : null;
        }

        return $result;
    }
}

// ---------------------------------------------------------------------------
// Helpers

function check(string $label, mixed $got, mixed $want): bool
{
    global $pass, $fail;

    if ($got === $want) {
        $pass++;
        echo "  ok   $label\n";

        return true;
    }

    $fail++;
    echo "  FAIL $label\n"
        . '       got:  ' . json_encode($got, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
        . '       want: ' . json_encode($want, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

    return false;
}

function section(string $title): void
{
    echo "\n$title\n";
}

/**
 * Language strings of an ini file: key => value.
 */
function readIni(string $file): array
{
    $strings = [];

    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^([A-Z0-9_]+)="(.*)"$/', $line, $m)) {
            $strings[$m[1]] = $m[2];
        }
    }

    return $strings;
}

function newLogin(string $tag): string
{
    global $prefix;

    return $prefix . $tag . '_' . bin2hex(random_bytes(3));
}

function newPassword(): string
{
    return 'Mr-' . bin2hex(random_bytes(8)) . '-Aa9';
}

/**
 * Opens the registration form, fills it in and sends it.
 *
 * @param   array  $fields  Fields to set: short names (country, region, name) or full form names.
 * @param   array  $drop    Full form names to leave out of the request.
 *
 * @return  array{0: Page, 1: string, 2: string, 3: Page}  the page after sending, login, password, the form page
 */
function register(array $fields, array $drop = [], array $headers = []): array
{
    global $base, $email, $created;

    $browser = new Browser($base, IP_REGISTER);
    $page    = new Page($browser->request('index.php?option=com_users&view=registration'));
    $form    = $page->form("//form[@id='member-registration']");

    if (!$form) {
        throw new RuntimeException('No registration form: is user registration allowed?');
    }

    $login    = $fields['login'] ?? newLogin('r');
    $password = newPassword();
    $values   = $page->values($form);

    $values['jform[username]']  = $login;
    $values['jform[password1]'] = $password;
    $values['jform[password2]'] = $password;
    $values['jform[email1]']    = str_replace('{login}', $login, $email);

    $short = ['country' => 'jform[mucronixregistration][country]', 'region' => 'jform[mucronixregistration][region]', 'name' => 'jform[name]'];

    foreach ($fields as $key => $value) {
        if ($key !== 'login') {
            $values[$short[$key] ?? $key] = $value;
        }
    }

    foreach ($drop as $key) {
        unset($values[$short[$key] ?? $key]);
    }

    $after = new Page($browser->request($page->action($form, $base), $values, $headers));
    $user  = findUser($login);

    if ($user) {
        $created[] = $login;
    }

    return [$after, $login, $password, $page];
}

function findUser(string $login): ?array
{
    global $db;

    $rows = $db->select('SELECT id, name, username FROM #__users WHERE username = ?', [$login]);

    return $rows[0] ?? null;
}

/**
 * Logs a user in on the site from the profile address.
 */
function logIn(string $login, string $password): Browser
{
    global $base;

    $browser = new Browser($base, IP_PROFILE);
    $page    = new Page($browser->request('index.php?option=com_users&view=login'));
    $form    = $page->form("//form[.//input[@name='task' and @value='user.login'] and .//input[@name='username']]");

    if (!$form) {
        throw new RuntimeException('No login form');
    }

    $values             = $page->values($form);
    $values['username'] = $login;
    $values['password'] = $password;

    $browser->request($page->action($form, $base), $values);

    $profile = new Page($browser->request('index.php?option=com_users&view=profile'));

    if ($profile->exists("//form[.//input[@name='task' and @value='user.login'] and .//input[@name='username'] and contains(@class, 'com-users-login')]")) {
        throw new RuntimeException("Login as $login failed");
    }

    return $browser;
}

/**
 * Opens the profile edit form, changes fields and saves it.
 */
function saveProfile(Browser $browser, array $fields, bool $force = false): array
{
    global $base;

    $page = new Page($browser->request('index.php?option=com_users&view=profile&layout=edit'));
    $form = $page->form("//form[@id='member-profile']");

    if (!$form) {
        throw new RuntimeException('No profile edit form');
    }

    $values = $page->values($form);
    $short  = ['country' => 'jform[mucronixregistration][country]', 'region' => 'jform[mucronixregistration][region]', 'name' => 'jform[name]'];

    // The task is the submit button; a browser sends the one that was pressed.
    if (!$page->exists("//form[@id='member-profile']//button[@name='task' and @value='profile.save']")) {
        throw new RuntimeException('No save button in the profile edit form');
    }

    $values['task'] = 'profile.save';

    foreach ($fields as $key => $value) {
        $values[$short[$key] ?? $key] = $value;
    }

    $after = new Page($browser->request($page->action($form, $base), $values));

    return [$after, $page];
}

function nameOf(string $code): string
{
    global $db, $column;

    return (string) $db->value("SELECT COALESCE(NULLIF($column, ''), name_en) FROM #__mucronixregistration_regions WHERE code = ?", [$code]);
}

function runCase(string $title, callable $case): void
{
    global $fail;

    section($title);

    try {
        $case();
    } catch (Throwable $e) {
        $fail++;
        echo '  FAIL ' . $e->getMessage() . "\n";
    }
}

// ---------------------------------------------------------------------------
// Site language and strings

$probe = new Page((new Browser($base, IP_REGISTER))->request('index.php?option=com_users&view=registration'));
$html  = $probe->xpath->query('//html')->item(0);
$lang  = $html instanceof DOMElement ? $html->getAttribute('lang') : '';
$tag   = preg_match('/^([a-z]{2})-([a-z]{2})$/i', $lang, $m) ? strtolower($m[1]) . '-' . strtoupper($m[2]) : 'en-GB';

$column = ['uk-UA' => 'name_uk', 'ru-RU' => 'name_ru'][$tag] ?? 'name_en';
$pluginIni = "$site/plugins/user/mucronixregistration/language/$tag/plg_user_mucronixregistration.ini";

if (!is_file($pluginIni)) {
    $pluginIni = "$site/plugins/user/mucronixregistration/language/en-GB/plg_user_mucronixregistration.ini";
}

$t = readIni($pluginIni);

$errors = [
    'countryRequired' => $t['PLG_USER_MUCRONIXREGISTRATION_ERROR_COUNTRY_REQUIRED'],
    'countryInvalid'  => $t['PLG_USER_MUCRONIXREGISTRATION_ERROR_COUNTRY_INVALID'],
    'regionRequired'  => $t['PLG_USER_MUCRONIXREGISTRATION_ERROR_REGION_REQUIRED'],
    'regionMismatch'  => $t['PLG_USER_MUCRONIXREGISTRATION_ERROR_REGION_MISMATCH'],
    'regionNotOffered' => $t['PLG_USER_MUCRONIXREGISTRATION_ERROR_REGION_NOT_OFFERED'],
];

echo "site:    $base\nlanguage: $tag\nconfig:  $config\n";

$ajax = function (string $country) use ($base, $tag): mixed {
    $json = json_decode((new Browser($base, IP_REGISTER))->request(
        'index.php?option=com_ajax&group=user&plugin=mucronixregistration&format=json&language=' . $tag . '&country=' . rawurlencode($country)
    ), true);

    return $json['data'][0] ?? null;
};

/**
 * A refused registration: no user, the expected message on the page.
 */
$refused = function (string $title, array $fields, ?string $message, array $drop = []): void {
    [$after, $login] = register($fields, $drop);
    check("$title: no user created", findUser($login), null);

    if ($message !== null && !check("$title: message \"$message\"", str_contains($after->messages(), $message), true)) {
        echo '       page says: ' . $after->messages() . "\n";
    }
};

$state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];

// ===========================================================================

if ($config === 'default') {
    runCase('Registration form', function () use ($probe) {
        check('Name field is hidden', $probe->exists("//form[@id='member-registration']//input[@id='jform_name' and @type='hidden']"), true);
        check('Name field not required', $probe->exists("//input[@id='jform_name' and @required]"), false);
        check('country list required', $probe->exists("//select[@id='jform_mucronixregistration_country' and @required]"), true);
        check('249 countries offered', count($probe->options('jform_mucronixregistration_country')), 249);
        check('region list empty before a country is chosen', $probe->options('jform_mucronixregistration_region'), []);
        check('code and IP fields not on the site', $probe->exists("//*[@id='jform_mucronixregistration_reg_ip' or @id='jform_mucronixregistration_country_code']"), false);
        check('region script loaded', str_contains($probe->html, 'plg_user_mucronixregistration/js/regions.js'), true);

        $countries = $probe->options('jform_mucronixregistration_country');
        $names     = array_values($countries);
        $sorted    = $names;
        (new Collator(str_replace('-', '_', $GLOBALS['tag'])))->sort($sorted);
        check('countries in alphabetical order of the site language', $names === $sorted, true);
        check('UA named in the site language', $countries['UA'] ?? null, nameOf('UA'));
    });

    runCase('Regions over AJAX', function () use ($ajax) {
        $ua = $ajax('UA');
        check('UA: 27 regions', is_array($ua) ? count($ua) : $ua, 27);
        check('UA: UA-53 named in the site language', is_array($ua) ? (array_column($ua, 'name', 'code')['UA-53'] ?? null) : null, nameOf('UA-53'));
        check('GI (no regions): empty', $ajax('GI'), []);
        check('XX (unknown): empty', $ajax('XX'), []);
        check('region code as country: empty', $ajax('UA-53'), []);
        check('junk: empty', $ajax("UA' OR '1'='1"), []);
    });

    runCase('Registration: Ukraine with a region, another name sent, proxy header sent', function () use (&$state) {
        global $db;

        [$after, $login, $password] = register(['country' => 'UA', 'region' => 'UA-53', 'name' => 'Somebody Else'], [], ['X-Forwarded-For: 203.0.113.99', 'Client-IP: 203.0.113.98']);
        $user = findUser($login);

        if (!check('user created', $user !== null, true)) {
            echo '       page says: ' . $after->messages() . "\n";

            return;
        }

        check('name = username, not the one sent', $user['name'], $login);
        check('stored', $db->profile((int) $user['id']), ['country' => 'UA', 'region' => 'UA-53', 'reg_ip' => IP_REGISTER]);
        $state['ua'] = ['login' => $login, 'password' => $password, 'id' => (int) $user['id']];
    });

    runCase('Registration: no Name field in the request at all', function () {
        [, $login] = register(['country' => 'UA', 'region' => 'UA-32'], ['name']);
        $user = findUser($login);
        check('user created', $user !== null, true);
        check('name = username', $user['name'] ?? null, $login);
    });

    runCase('Registration: country without regions (GI)', function () use (&$state) {
        global $db;

        [, $login, $password] = register(['country' => 'GI', 'region' => '']);
        $user = findUser($login);

        if (check('user created', $user !== null, true)) {
            check('stored: country, IP, no region', $db->profile((int) $user['id']), ['country' => 'GI', 'reg_ip' => IP_REGISTER]);
            $state['ai'] = ['login' => $login, 'password' => $password, 'id' => (int) $user['id']];
        }
    });

    runCase('Registration: Ukraine without a region, the form comes back built for Ukraine', function () use ($errors) {
        [$after, $login] = register(['country' => 'UA', 'region' => '']);
        check('no user created', findUser($login), null);
        check('message "' . $errors['regionRequired'] . '"', str_contains($after->messages(), $errors['regionRequired']), true);

        // Without JavaScript the region list comes from the server.
        check('form shown again', $after->exists("//form[@id='member-registration']"), true);
        check('Ukraine kept', $after->exists("//select[@id='jform_mucronixregistration_country']/option[@value='UA' and @selected]"), true);
        check('region list of Ukraine built on the server: 27', count($after->options('jform_mucronixregistration_region')), 27);
    });

    runCase('Registration: substituted values are refused', function () use ($refused, $errors) {
        $refused('region of another country (UA + PL-14)', ['country' => 'UA', 'region' => 'PL-14'], $errors['regionMismatch']);
        $refused('unknown region (UA-99)', ['country' => 'UA', 'region' => 'UA-99'], $errors['regionMismatch']);
        $refused('unknown country (XX)', ['country' => 'XX', 'region' => ''], $errors['countryInvalid']);
        $refused('region code as country (UA-53)', ['country' => 'UA-53', 'region' => ''], $errors['countryInvalid']);
        // Tags would be stripped by the field filter and leave a valid code, so no tags here.
        $refused('junk as country', ['country' => "UA' OR '1'='1", 'region' => ''], $errors['countryInvalid']);
        $refused('no country', ['country' => '', 'region' => ''], null);
        $refused('country and region left out of the request', [], null, ['country', 'region']);
    });

    runCase('Profile page on the site: names, not codes', function () use (&$state) {
        global $t;

        $browser = logIn($state['ua']['login'], $state['ua']['password']);
        $page    = new Page($browser->request('index.php?option=com_users&view=profile'));
        $values  = $page->profileValues();

        check('country shown by name', $values[$t['PLG_USER_MUCRONIXREGISTRATION_COUNTRY_LABEL']] ?? null, nameOf('UA'));
        check('region shown by name', $values[$t['PLG_USER_MUCRONIXREGISTRATION_REGION_LABEL']] ?? null, nameOf('UA-53'));
        check('no code or IP on the site', array_keys($values), [$t['PLG_USER_MUCRONIXREGISTRATION_COUNTRY_LABEL'], $t['PLG_USER_MUCRONIXREGISTRATION_REGION_LABEL']]);

        $ai = (new Page(logIn($state['ai']['login'], $state['ai']['password'])->request('index.php?option=com_users&view=profile')))->profileValues();
        check('without a region: only the country line', $ai, [$t['PLG_USER_MUCRONIXREGISTRATION_COUNTRY_LABEL'] => nameOf('GI')]);
    });

    runCase('Profile edit on the site', function () use (&$state, $errors) {
        global $db;

        $browser = logIn($state['ua']['login'], $state['ua']['password']);
        $id      = $state['ua']['id'];

        [, $form] = saveProfile($browser, []);
        check('edit form: Ukraine selected', $form->exists("//select[@id='jform_mucronixregistration_country']/option[@value='UA' and @selected]"), true);
        check('edit form: UA-53 selected, list built on the server', [$form->exists("//select[@id='jform_mucronixregistration_region']/option[@value='UA-53' and @selected]"), count($form->options('jform_mucronixregistration_region'))], [true, 27]);

        saveProfile($browser, ['name' => 'Changed Name', 'region' => 'UA-32']);
        check('name changed in the profile is kept, not the username', findUser($state['ua']['login'])['name'] ?? null, 'Changed Name');
        check('region changed, registration IP unchanged', $db->profile($id), ['country' => 'UA', 'region' => 'UA-32', 'reg_ip' => IP_REGISTER]);

        [$after] = saveProfile($browser, ['region' => 'PL-14']);
        check('region of another country refused: message', str_contains($after->messages(), $errors['regionMismatch']), true);
        check('region of another country refused: stored unchanged', $db->profile($id), ['country' => 'UA', 'region' => 'UA-32', 'reg_ip' => IP_REGISTER]);

        [$after] = saveProfile($browser, ['country' => 'XX', 'region' => '']);
        check('unknown country refused', str_contains($after->messages(), $errors['countryInvalid']), true);

        // After a refused save Joomla shows the refused values again, as a browser would see
        // them; the country is chosen again here like a person would.
        saveProfile($browser, ['country' => 'UA', 'region' => 'UA-53']);
        check('back to UA-53', $db->profile($id), ['country' => 'UA', 'region' => 'UA-53', 'reg_ip' => IP_REGISTER]);
    });
}

// ===========================================================================

if ($config === 'selected') {
    runCase('Registration form with selected countries', function () use ($probe) {
        check('Name field visible', $probe->exists("//input[@id='jform_name' and @type='text']"), true);
        check('country not required', $probe->exists("//select[@id='jform_mucronixregistration_country' and @required]"), false);
        check('countries: PL, UA at the top in this order, then DE', array_keys($probe->options('jform_mucronixregistration_country')), ['PL', 'UA', 'DE']);
    });

    runCase('Regions over AJAX with regions for Poland only', function () use ($ajax) {
        check('UA: regions off, empty', $ajax('UA'), []);
        check('DE: regions off, empty', $ajax('DE'), []);
        check('FR: country not shown, empty', $ajax('FR'), []);
        $pl = $ajax('PL');
        check('PL: 16 voivodeships', is_array($pl) ? count($pl) : $pl, 16);
    });

    runCase('Registration with these settings', function () use ($refused, $errors) {
        global $db;

        [$after, $login] = register(['country' => 'UA', 'region' => '', 'name' => 'Given Name']);
        $user = findUser($login);

        if (check('UA without a region accepted', $user !== null, true)) {
            check('stored: country only, no IP (Store IP Address: No)', $db->profile((int) $user['id']), ['country' => 'UA']);
        } else {
            echo '       page says: ' . $after->messages() . "\n";
        }

        // The name is checked on a registration without a country: no region rule can refuse it.
        [, $login] = register(['country' => '', 'region' => '', 'name' => 'No Country']);
        $user = findUser($login);
        check('no country accepted (Country Required: No)', $user !== null, true);
        check('name as sent (Hide Name Field: No)', $user['name'] ?? null, 'No Country');
        check('nothing stored without a country', $user ? $db->profile((int) $user['id']) : null, []);

        [, $login] = register(['country' => 'PL', 'region' => 'PL-14', 'name' => 'Pole']);
        $user = findUser($login);
        check('PL with a region accepted', $user ? $db->profile((int) $user['id']) : null, ['country' => 'PL', 'region' => 'PL-14']);

        $refused('UA with a region: regions off for Ukraine', ['country' => 'UA', 'region' => 'UA-53', 'name' => 'X'], $errors['regionNotOffered']);
        $refused('FR: country not shown', ['country' => 'FR', 'region' => '', 'name' => 'X'], $errors['countryInvalid']);
        $refused('GI: country not shown', ['country' => 'GI', 'region' => '', 'name' => 'X'], $errors['countryInvalid']);
    });

    runCase('Saved values that are no longer offered', function () use (&$state, $errors) {
        global $db;

        if (empty($state['ua']) || empty($state['ai'])) {
            throw new RuntimeException('Run --config=default first: it creates the users for this case');
        }

        $ua = logIn($state['ua']['login'], $state['ua']['password']);
        [, $form] = saveProfile($ua, []);
        check('UA user: saved region still in the list', array_keys($form->options('jform_mucronixregistration_region')), ['UA-53']);
        check('UA user: saving unchanged keeps the region', $db->profile($state['ua']['id']), ['country' => 'UA', 'region' => 'UA-53', 'reg_ip' => IP_REGISTER]);

        saveProfile($ua, ['region' => '']);
        check('UA user: an empty region does not erase the saved one', $db->profile($state['ua']['id'])['region'] ?? null, 'UA-53');

        [$after] = saveProfile($ua, ['region' => 'UA-32']);
        check('UA user: another region of Ukraine refused', str_contains($after->messages(), $errors['regionNotOffered']), true);

        $ai = logIn($state['ai']['login'], $state['ai']['password']);
        [, $form] = saveProfile($ai, []);
        check('GI user: GI still in the country list', array_key_exists('GI', $form->options('jform_mucronixregistration_country')), true);
        check('GI user: saving unchanged keeps GI', $db->profile($state['ai']['id'])['country'] ?? null, 'GI');

        [$after] = saveProfile($ai, ['country' => 'FR']);
        check('GI user: switching to FR (not shown) refused', str_contains($after->messages(), $errors['countryInvalid']), true);
        check('GI user: still GI', $db->profile($state['ai']['id'])['country'] ?? null, 'GI');

        check('UA user: refused region not stored', $db->profile($state['ua']['id'])['region'] ?? null, 'UA-53');

        // A new session: after a refused save Joomla shows the refused values from the
        // session on the profile page too (ProfileModel::getData), for every field.
        $page = new Page(logIn($state['ua']['login'], $state['ua']['password'])->request('index.php?option=com_users&view=profile'));
        check('UA user: profile page still names the saved region',$page->profileValues()[$GLOBALS['t']['PLG_USER_MUCRONIXREGISTRATION_REGION_LABEL']] ?? null, nameOf('UA-53'));
    });
}

// ===========================================================================

if ($config === 'view' || $config === 'hide') {
    runCase("Profile on the site: " . ($config === 'view' ? 'View only' : 'Hide'), function () use (&$state, $config) {
        global $db, $t;

        if (empty($state['ua'])) {
            throw new RuntimeException('Run --config=default first: it creates the users for this case');
        }

        $browser = logIn($state['ua']['login'], $state['ua']['password']);
        $id      = $state['ua']['id'];
        $before  = $db->profile($id);

        $page   = new Page($browser->request('index.php?option=com_users&view=profile'));
        $values = $page->profileValues();

        if ($config === 'view') {
            check('profile page shows the names', $values, [
                $t['PLG_USER_MUCRONIXREGISTRATION_COUNTRY_LABEL'] => nameOf($before['country'] ?? ''),
                $t['PLG_USER_MUCRONIXREGISTRATION_REGION_LABEL']  => nameOf($before['region'] ?? ''),
            ]);
        } else {
            check('profile page has no country and region', $values, []);
        }

        [, $form] = saveProfile($browser, []);
        check('edit page has no country and region fields', $form->exists("//*[starts-with(@id, 'jform_mucronixregistration_')]"), false);

        // A value sent by hand, as if the fields were there; never the stored one,
        // or an ignored value and a saved one would look the same.
        $forged = ($before['country'] ?? '') === 'PL' ? ['DE', 'DE-BY'] : ['PL', 'PL-14'];
        $name   = 'View ' . bin2hex(random_bytes(2));
        [$after] = saveProfile($browser, ['name' => $name, 'jform[mucronixregistration][country]' => $forged[0], 'jform[mucronixregistration][region]' => $forged[1]]);
        check('the rest of the profile is saved', findUser($state['ua']['login'])['name'] ?? null, $name);
        check('country and region sent by hand are ignored', $db->profile($id), $before);
    });
}

// ---------------------------------------------------------------------------

if (!is_dir(dirname($stateFile))) {
    mkdir(dirname($stateFile), 0777, true);
}

$state['created'] = array_values(array_unique(array_merge($state['created'] ?? [], $created)));
file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT));

echo "\nusers created in this run (" . count($created) . "): " . implode(', ', $created) . "\n";
echo "\npassed $pass, failed $fail\n";
exit($fail ? 1 : 0);
