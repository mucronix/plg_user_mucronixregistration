<?php

/**
 * @package     Mucronix Registration
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Plugin logic on a copy of the countries and regions table in SQLite (in memory):
 * which countries and regions are offered, names and sorting, the checks on saving
 * (substituted codes, values saved earlier), writing to the profile. Not part of the package.
 *
 * Usage: php tests/logic-check.php [--joomla=...] [--plugin=...]
 */

require __DIR__ . '/bootstrap.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseFactory;
use Joomla\Registry\Registry;
use Mucronix\Plugin\User\MucronixRegistration\Extension\MucronixRegistration;
use Mucronix\Plugin\User\MucronixRegistration\Service\RegionDirectory;

$lang = Language::getInstance('en-GB');
$lang->load('plg_user_mucronixregistration', $plugin);
Factory::$language = $lang;

$db = (new DatabaseFactory())->getDriver('sqlite', ['database' => ':memory:', 'prefix' => 'jos_']);
$db->setQuery('CREATE TABLE jos_mucronixregistration_regions (code TEXT PRIMARY KEY, country_code TEXT, type TEXT, name_en TEXT, name_uk TEXT, name_ru TEXT, published INTEGER)')->execute();
$db->setQuery('CREATE TABLE jos_user_profiles (user_id INTEGER, profile_key TEXT, profile_value TEXT, ordering INTEGER)')->execute();

foreach (DatabaseDriver::splitSql(file_get_contents("$plugin/sql/install.mysql.utf8.sql")) as $q) {
    if (str_starts_with(trim($q), 'INSERT')) {
        $db->setQuery($q)->execute();
    }
}

echo 'rows: ', $db->setQuery('SELECT COUNT(*) FROM jos_mucronixregistration_regions')->loadResult(), "\n";

$dir = fn(array $p) => new RegionDirectory($db, new Registry($p));

echo "Directory\n";
$d    = $dir(['top_countries' => 'ua, PL ;XX']);
$opts = $d->countryOptions('uk-UA');
check('top countries first, in order, unknown skipped', array_slice(array_keys($opts), 0, 2), ['UA', 'PL']);
check('uk name of UA', $opts['UA'], 'Україна');
check('249 countries offered', count($opts), 249);
check('UA regions uk: 27', count($d->regionOptions('UA', 'uk-UA')), 27);
check('ru name UA-53', $d->name('UA-53', 'ru-RU'), 'Полтавская область');
check('ru empty -> english (DO-33)', $d->name('DO-33', 'ru-RU'), 'Cibao Nordeste');
check('ru from Wikidata (GB-WLS)', $d->name('GB-WLS', 'ru-RU'), 'Уэльс');
check('Wikidata override (SY-RD)', $d->name('SY-RD', 'ru-RU'), 'Риф-Дамаск');
check('Wikidata label capitalised (TW-CHA)', $d->name('TW-CHA', 'ru-RU'), 'Уезд Чжанхуа');
check('Wikidata label capitalised, no clash with the city (MX-MEX)', [$d->name('MX-MEX', 'ru-RU'), $d->name('MX-CMX', 'ru-RU')], ['Штат Мехико', 'Мехико']);
check('corrected: GR-K uk (North Aegean)', $d->name('GR-K', 'uk-UA'), 'Північні Егейські острови');
// In PHP: SQLite lower() and upper() know ASCII only.
check('no ru region name starts in lower case', array_values(array_filter($db->setQuery("SELECT name_ru FROM jos_mucronixregistration_regions WHERE type = 'region'")->loadColumn(), static fn($n) => preg_match('/^\p{Ll}/u', $n) === 1)), []);
check('Russia: every region has a ru name', (int) $db->setQuery("SELECT COUNT(*) FROM jos_mucronixregistration_regions WHERE country_code = 'RU' AND type = 'region' AND name_ru = ''")->loadResult(), 0);
check('de-DE -> english (UA)', $d->name('UA', 'de-DE'), 'Ukraine');
check('corrected: RU-KYA uk', $d->name('RU-KYA', 'uk-UA'), 'Красноярський край');
check('corrected: US-DC ru', $d->name('US-DC', 'ru-RU'), 'Округ Колумбия');
check('same-name pair: TW-CYQ ru', $d->name('TW-CYQ', 'ru-RU'), 'Цзяи (уезд)');
check('bracket note dropped: GB-WLS uk', $d->name('GB-WLS', 'uk-UA'), 'Уельс');

foreach (['uk-UA', 'ru-RU', 'en-GB'] as $tag) {
    $duplicates = $db->setQuery(
        'SELECT COUNT(*) FROM (SELECT country_code, ' . ($tag === 'en-GB' ? 'name_en' : "COALESCE(NULLIF(name_" . substr($tag, 0, 2) . ", ''), name_en)")
        . " AS n FROM jos_mucronixregistration_regions WHERE type = 'region' GROUP BY country_code, n HAVING COUNT(*) > 1)"
    )->loadResult();
    check("$tag: no two regions of a country with one name", (int) $duplicates, 0);
}

foreach (['en-GB' => 'en_GB', 'uk-UA' => 'uk_UA', 'ru-RU' => 'ru_RU'] as $tag => $locale) {
    $rest   = array_values(array_slice($d->countryOptions($tag), 2));
    $sorted = $rest;
    (new Collator($locale))->sort($sorted);
    check("$tag: rest sorted by collation", $rest === $sorted, true);
}

echo '  uk first five after top: ', implode(', ', array_slice($d->countryOptions('uk-UA'), 2, 5)), "\n";
echo '  uk UA regions first five: ', implode(', ', array_slice($d->regionOptions('UA', 'uk-UA'), 0, 5)), "\n";

$selR = ['regions_mode' => 'selected', 'region_countries' => ['PL']];
$selC = ['countries_mode' => 'selected', 'countries' => ['PL']];
check('regions_mode=selected[PL]: UA off', $dir($selR)->regionsEnabled('UA'), false);
check('regions_mode=selected[PL]: PL on', $dir($selR)->regionsEnabled('PL'), true);
check('regions_mode=selected, empty list: none', $dir(['regions_mode' => 'selected', 'region_countries' => []])->regionsEnabled('PL'), false);
check('countries selected [PL]: UA disabled', $dir($selC)->isCountryEnabled('UA'), false);
check('countries selected []: all', $dir(['countries_mode' => 'selected', 'countries' => []])->isCountryEnabled('UA'), true);
check('countries selected [PL]: options keep saved UA', array_keys($dir($selC)->countryOptions('en-GB', 'UA')), ['PL', 'UA']);
check('regions off: options keep saved UA-53 only', array_keys($dir($selR)->regionOptions('UA', 'en-GB', 'UA-53')), ['UA-53']);
check('regions off: keep of another country ignored', $dir($selR)->regionOptions('UA', 'en-GB', 'PL-14'), []);
check('isRegionOf UA-53/UA', $d->isRegionOf('UA-53', 'UA'), true);
check('isRegionOf PL-14/UA', $d->isRegionOf('PL-14', 'UA'), false);
check('removed duplicate CN-TW absent', $d->isRegionOf('CN-TW', 'CN'), false);
check('normalise lower/space', RegionDirectory::normaliseCode(' ua-53 '), 'UA-53');
check('normalise junk', RegionDirectory::normaliseCode("UA'--"), '');
check('normalise array', RegionDirectory::normaliseCode(['UA']), '');
check('split codes', RegionDirectory::splitCodes(' ua,pl;; UA  de '), ['UA', 'PL', 'DE']);

$noRegions = $db->setQuery("SELECT c.code FROM jos_mucronixregistration_regions c WHERE c.type = 'country' AND NOT EXISTS (SELECT 1 FROM jos_mucronixregistration_regions r WHERE r.type = 'region' AND r.country_code = c.code) ORDER BY c.code LIMIT 1")->loadResult();
echo "  country without regions: $noRegions\n";

echo "Resolve\n";
$resolve = function (array $params, array $submitted, array $stored, string $context) use ($db) {
    $p = new MucronixRegistration(['name' => 'mucronixregistration', 'type' => 'user', 'params' => new Registry($params)]);
    $p->setDatabase($db);
    $m = new ReflectionMethod($p, 'resolve');

    try {
        return $m->invoke($p, $submitted, $stored, $context);
    } catch (InvalidArgumentException $e) {
        return 'ERR: ' . $e->getMessage();
    }
};

$R = 'registration';
$P = 'profile';
$A = 'admin';
$regionRequired = 'ERR: Please select a region for the selected country.';
$mismatch       = 'ERR: The selected region does not belong to the selected country.';
$countryBad     = 'ERR: The selected country is not available.';
$countryReq     = 'ERR: Please select a country.';
$notOffered     = 'ERR: Regions are not available for the selected country.';

$cases = [
    ['UA + UA-53', [], ['country' => 'UA', 'region' => 'UA-53'], [], $R, ['country' => 'UA', 'region' => 'UA-53']],
    ['lower case accepted', [], ['country' => 'ua', 'region' => 'ua-53'], [], $R, ['country' => 'UA', 'region' => 'UA-53']],
    ['UA without region', [], ['country' => 'UA', 'region' => ''], [], $R, $regionRequired],
    ['region of another country', [], ['country' => 'UA', 'region' => 'PL-14'], [], $R, $mismatch],
    ['unknown region code', [], ['country' => 'UA', 'region' => 'UA-99'], [], $R, $mismatch],
    ['junk region', [], ['country' => 'UA', 'region' => "1' OR 1=1"], [], $R, $mismatch],
    ['unknown country', [], ['country' => 'XX'], [], $R, $countryBad],
    ['region code as country', [], ['country' => 'UA-53'], [], $R, $countryBad],
    ['array as country', [], ['country' => ['UA']], [], $R, $countryReq],
    ['no country, required', [], [], [], $R, $countryReq],
    ['no country, not required', ['country_required' => 0], ['country' => '', 'region' => 'UA-53'], [], $R, ['country' => '', 'region' => '']],
    ['country without regions', [], ['country' => $noRegions, 'region' => ''], [], $R, ['country' => $noRegions, 'region' => '']],
    ['regions off for UA: no region ok', $selR, ['country' => 'UA', 'region' => ''], [], $R, ['country' => 'UA', 'region' => '']],
    ['regions off for UA: region refused', $selR, ['country' => 'UA', 'region' => 'UA-53'], [], $R, $notOffered],
    ['regions off: saved region unchanged', $selR, ['country' => 'UA', 'region' => 'UA-53'], ['country' => 'UA', 'region' => 'UA-53'], $P, ['country' => 'UA', 'region' => 'UA-53']],
    ['regions off: empty keeps saved region', $selR, ['country' => 'UA', 'region' => ''], ['country' => 'UA', 'region' => 'UA-53'], $P, ['country' => 'UA', 'region' => 'UA-53']],
    ['regions off: other region refused', $selR, ['country' => 'UA', 'region' => 'UA-32'], ['country' => 'UA', 'region' => 'UA-53'], $P, $notOffered],
    ['country disabled: refused at registration', $selC, ['country' => 'UA', 'region' => 'UA-53'], [], $R, $countryBad],
    ['country disabled: saved one unchanged', $selC, ['country' => 'UA', 'region' => 'UA-53'], ['country' => 'UA', 'region' => 'UA-53'], $P, ['country' => 'UA', 'region' => 'UA-53']],
    ['country disabled: saved, empty region kept', $selC, ['country' => 'UA', 'region' => ''], ['country' => 'UA', 'region' => 'UA-53'], $P, ['country' => 'UA', 'region' => 'UA-53']],
    ['country disabled: switching to it refused', $selC, ['country' => 'UA'], ['country' => 'PL', 'region' => 'PL-14'], $P, $countryBad],
    ['profile: change region', [], ['country' => 'UA', 'region' => 'UA-32'], ['country' => 'UA', 'region' => 'UA-53'], $P, ['country' => 'UA', 'region' => 'UA-32']],
    ['profile: change country, old region dropped', [], ['country' => 'PL', 'region' => 'UA-53'], ['country' => 'UA', 'region' => 'UA-53'], $P, $mismatch],
    ['admin: empty allowed', [], [], ['country' => 'UA', 'region' => 'UA-53'], $A, ['country' => '', 'region' => '']],
    ['admin: UA without region allowed', [], ['country' => 'UA'], [], $A, ['country' => 'UA', 'region' => '']],
    ['admin: foreign region refused', [], ['country' => 'UA', 'region' => 'PL-14'], [], $A, $mismatch],
];

foreach ($cases as [$label, $params, $submitted, $stored, $context, $want]) {
    check($label, $resolve($params, $submitted, $stored, $context), $want);
}

echo "Update file\n";
$updateFiles = glob("$plugin/sql/updates/mysql/*.sql");
usort($updateFiles, static fn($a, $b) => version_compare(basename($a, '.sql'), basename($b, '.sql')));
$latest      = end($updateFiles);
$updateSql   = file_get_contents($latest);
$statements  = array_values(array_filter(array_map('trim', DatabaseDriver::splitSql($updateSql))));
$kinds       = array_map(static fn($s) => strtok(preg_replace('/^(--[^\n]*\n)+/', '', $s), ' '), $statements);

check('latest update file is ' . basename($latest), basename($latest), '1.0.1.sql');
check('update file: CREATE IF NOT EXISTS, DELETE, then INSERTs only', array_values(array_unique($kinds)), ['CREATE', 'DELETE', 'INSERT']);
check('update file does not touch the user profiles', str_contains($updateSql, 'user_profiles') && preg_match('/^(?!--).*user_profiles/m', $updateSql) === 1, false);

$db->setQuery("INSERT INTO jos_mucronixregistration_regions VALUES ('XX-1', 'XX', 'region', 'Old', '', '', 1)")->execute();
$db->setQuery("INSERT INTO jos_user_profiles VALUES (9, 'mucronixregistration.country', 'UA', 1)")->execute();

foreach ($statements as $q) {
    if (!str_starts_with(preg_replace('/^(--[^\n]*\n)+/', '', $q), 'CREATE')) {
        $db->setQuery($q)->execute();
    }
}

$installRows = 0;

foreach (DatabaseDriver::splitSql(file_get_contents("$plugin/sql/install.mysql.utf8.sql")) as $q) {
    $installRows += str_starts_with(trim($q), 'INSERT') ? preg_match_all("/^\('/m", $q) : 0;
}

check('after update: old row gone', (int) $db->setQuery("SELECT COUNT(*) FROM jos_mucronixregistration_regions WHERE code = 'XX-1'")->loadResult(), 0);
check('after update: as many rows as the install file', (int) $db->setQuery('SELECT COUNT(*) FROM jos_mucronixregistration_regions')->loadResult(), $installRows);
check('after update: user profile row kept', $db->setQuery("SELECT profile_value FROM jos_user_profiles WHERE user_id = 9")->loadResult(), 'UA');
$installSql = file_get_contents("$plugin/sql/install.mysql.utf8.sql");
check(
    'install and update hold the same rows (sha256 of the INSERT part)',
    hash('sha256', substr($updateSql, strpos($updateSql, "\nINSERT"))),
    hash('sha256', substr($installSql, strpos($installSql, "\nINSERT")))
);
$db->setQuery('DELETE FROM jos_user_profiles')->execute();

echo "Store\n";
$p = new MucronixRegistration(['name' => 'mucronixregistration', 'type' => 'user', 'params' => new Registry([])]);
$p->setDatabase($db);
$store = new ReflectionMethod($p, 'store');
$load  = new ReflectionMethod($p, 'loadStored');
$db->setQuery("INSERT INTO jos_user_profiles VALUES (7, 'profile.city', 'Kyiv', 1)")->execute();
$store->invoke($p, 7, ['country' => 'UA', 'region' => 'UA-53', 'reg_ip' => '203.0.113.5']);
check('stored after registration', $load->invoke($p, 7), ['country' => 'UA', 'region' => 'UA-53', 'reg_ip' => '203.0.113.5']);
$store->invoke($p, 7, ['country' => $noRegions, 'region' => '']);
check('profile edit: region removed, IP kept', $load->invoke($p, 7), ['country' => $noRegions, 'reg_ip' => '203.0.113.5']);
check('foreign profile key untouched', $db->setQuery("SELECT profile_value FROM jos_user_profiles WHERE profile_key = 'profile.city'")->loadResult(), 'Kyiv');

finish();
