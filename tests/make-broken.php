<?php

/**
 * @package     Mucronix Registration
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Deliberately broken test packages, to see every case of tests/registration-test.php
 * red at least once. Never for a site other than the local test site.
 *
 * Takes the built files of build/plg_user_mucronixregistration/ (run build.ps1 first),
 * applies the breakages of a variant and writes
 * build/test/plg_user_mucronixregistration-<version>-broken-<variant>.zip.
 * The description of the plugin starts with "BROKEN TEST BUILD <variant>".
 * Every breakage must apply exactly as often as stated, or nothing is written.
 *
 *   A  lists, checks on saving, writing, IP address, display, profile variants
 *   B  name = username and the region requirement, which with A would hide other cases,
 *      and a right region refused
 *   C  B without the refused region, which in B hides the name check of the same case
 *
 * Usage: php tests/make-broken.php --variant=A|B|C
 */

$options = getopt('', ['variant:']);
$variant = strtoupper($options['variant'] ?? '');
$root    = dirname(__DIR__);
$tree    = $root . '/build/plg_user_mucronixregistration';
$php     = PHP_BINARY;

if (!in_array($variant, ['A', 'B', 'C'], true)) {
    fwrite(STDERR, "Pass --variant=A, B or C\n");
    exit(2);
}

if (!is_file($tree . '/mucronixregistration.xml')) {
    fwrite(STDERR, "No built files in $tree: run build.ps1 first\n");
    exit(2);
}

$ext = 'src/Extension/MucronixRegistration.php';
$dir = 'src/Service/RegionDirectory.php';

// [file, from, to, times, what it breaks]
$breakages = [
    'A' => [
        [$ext, "            \$event->getForm()->getName() !== 'com_users.registration'\n", "            !\\in_array(\$event->getForm()->getName(), ['com_users.registration', 'com_users.profile'], true)\n", 1, 'the name is replaced by the username in the profile too'],
        [$ext, "\$this->params->get('country_required', 1) ? 'true' : 'false'", "\$this->params->get('country_required', 1) ? 'false' : 'true'", 2, 'required attribute of the country inverted'],
        [$ext, "        \$form->removeField('country_code', self::GROUP);\n        \$form->removeField('reg_ip', self::GROUP);\n    }", "        // broken: the read-only fields stay on the site\n    }", 1, 'code and IP fields on the site'],
        [$ext, "self::GROUP);\n\n        \$this->loadScript(true);\n    }", "self::GROUP);\n    }", 1, 'no region script on the registration form'],
        [$ext, "if (\$required && \$this->params->get('country_required', 1)) {", 'if (false) {', 1, 'country never required on the server'],
        [$ext, "        \$countryAccepted = \\strlen(\$country) === 2\n            && (\$directory->isCountryEnabled(\$country) || (\$country === \$storedCountry && \$directory->countryExists(\$country)));", "        \$countryAccepted = true;", 1, 'any country accepted'],
        [$ext, "if (!\$directory->isRegionOf(\$region, \$country)) {", 'if (false) {', 1, 'any region accepted'],
        [$ext, "if (\$required && \$regionsOffered) {", 'if (false) {', 1, 'region never required'],
        [$ext, "\$ip = \$this->getApplication()->getInput()->server->getString('REMOTE_ADDR', '');", "\$ip = \$this->getApplication()->getInput()->server->getString('HTTP_X_FORWARDED_FOR', '') ?: \$this->getApplication()->getInput()->server->getString('REMOTE_ADDR', '');", 1, 'proxy header trusted'],
        [$ext, "if (\$context === self::SAVE_REGISTRATION && \$this->params->get('store_ip', 1)) {", 'if (true) {', 1, 'IP written on every save, setting ignored'],
        [$ext, "if ((\$values[\$key] ?? '') === '') {", "if (!isset(\$values[\$key])) {", 1, 'empty values stored'],
        [$ext, "\$event->addResult(\$list);", "\$event->addResult(\\array_slice(\$list, 0, -1) ?: [['code' => '', 'name' => 'none']]);", 1, 'AJAX drops the last region, answers for anything'],
        [$ext, "foreach (\$directory->regionOptions(\$country, \$tag) as", "foreach (\$directory->regionOptions(\$country, 'en-GB') as", 1, 'AJAX names in English'],
        [$ext, "        \$name = \$code !== '' ? \$this->directory()->name(\$code, \$this->getApplication()->getLanguage()->getTag()) : '';", "        \$name = \$code;", 1, 'profile page shows codes'],
        [$ext, "if ((string) (\$values['region'] ?? '') === '') {", 'if (false) {', 1, 'empty region line kept on the profile page'],
        [$ext, "return \$input->getCmd('layout', '') === 'edit' || \$input->getCmd('task', '') !== '';", 'return false;', 1, 'edit page taken for the profile page'],
        [$ext, "if (\$this->isTask('profile', 'save') && (int) \$this->params->get('profile_site', self::PROFILE_EDIT) === self::PROFILE_EDIT) {", "if (\$this->isTask('profile', 'save')) {", 1, 'profile saved whatever the profile setting'],
        [$ext, "if (\$mode === self::PROFILE_HIDE || (\$mode === self::PROFILE_VIEW && \$editing)) {", "if ((\$mode === self::PROFILE_VIEW || \$mode === self::PROFILE_HIDE) && \$editing) {", 1, 'Hide works like View only'],
        [$dir, "        return \$top + \$options;", "        unset(\$top['UA'], \$options['UA']);\n\n        return \$top + \$options;", 1, 'Ukraine missing from the country list'],
        [$dir, "(int) \$collator->compare(\$a, \$b)", "(int) \$collator->compare(\$b, \$a)", 1, 'names sorted backwards'],
        [$dir, "if (\$this->params->get('regions_mode', 'all') !== 'selected') {", 'if (true) {', 1, 'Show Regions setting ignored'],
        [$dir, "if (\$this->isCountryEnabled(\$code) || \$code === \$keep) {", "if (\$this->isCountryEnabled(\$code)) {", 1, 'saved country not kept in the list'],
        ['src/Field/MucronixregionField.php', "        if (\$country === '') {\n            return array_merge(\$options, parent::getOptions());\n        }", "        \$options[] = HTMLHelper::_('select.option', 'UA-30', 'Kyiv');\n\n        if (true) {\n            return array_merge(\$options, parent::getOptions());\n        }", 1, 'region list always Kyiv only'],
    ],
    'B' => [
        [$ext, "        if (\$this->params->get('hide_name', 1)) {\n            // Hidden, not removed", "        if (!\$this->params->get('hide_name', 1)) {\n            // Hidden, not removed", 1, 'Name field hidden when it should show, and the other way round'],
        [$ext, "            || !\$this->params->get('hide_name', 1)\n", "            || \$this->params->get('hide_name', 1)\n", 1, 'name replaced by the username only when Hide Name Field is off'],
        [$ext, "if (\$required && \$regionsOffered) {", "if (\$required) {", 1, 'region required even where none is offered'],
        [$ext, "if (!\$directory->isRegionOf(\$region, \$country)) {", "if (\$directory->isRegionOf(\$region, \$country)) {", 1, 'region check inverted: a right region is refused'],
    ],
];

$breakages['C'] = array_slice($breakages['B'], 0, 3);

$languageFiles = [];

foreach (['en-GB', 'uk-UA', 'ru-RU'] as $tag) {
    foreach (['ini', 'sys.ini'] as $type) {
        $languageFiles[] = "language/$tag/plg_user_mucronixregistration.$type";
    }
}

foreach ($languageFiles as $file) {
    $breakages[$variant][] = [$file, 'PLG_USER_MUCRONIXREGISTRATION_XML_DESCRIPTION="', 'PLG_USER_MUCRONIXREGISTRATION_XML_DESCRIPTION="BROKEN TEST BUILD ' . $variant . '. ', 1, 'marked as broken'];
}

// Read everything, apply in memory, write only when all applied.
$files = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS)) as $item) {
    $path         = str_replace('\\', '/', substr($item->getPathname(), strlen($tree) + 1));
    $files[$path] = file_get_contents($item->getPathname());
}

$problems = [];

foreach ($breakages[$variant] as [$file, $from, $to, $times, $what]) {
    $found = substr_count($files[$file] ?? '', $from);

    if ($found !== $times) {
        $problems[] = "$file: '$what' found $found times, expected $times";
        continue;
    }

    $files[$file] = str_replace($from, $to, $files[$file]);
}

if ($problems) {
    fwrite(STDERR, "nothing was written:\n  " . implode("\n  ", $problems) . "\n");
    exit(1);
}

$manifest = simplexml_load_string($files['mucronixregistration.xml']);
$version  = (string) $manifest->version;
$outDir   = $root . '/build/test';
$work     = $outDir . '/broken-' . $variant;
$zipPath  = "$outDir/plg_user_mucronixregistration-$version-broken-" . strtolower($variant) . '.zip';

if (is_dir($work)) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
}

foreach ($files as $path => $content) {
    $target = "$work/$path";

    if (!is_dir(dirname($target))) {
        mkdir(dirname($target), 0777, true);
    }

    file_put_contents($target, $content);

    if (str_ends_with($path, '.php')) {
        exec(escapeshellarg($php) . ' -l ' . escapeshellarg($target) . ' 2>&1', $out, $code);

        if ($code !== 0) {
            fwrite(STDERR, "php -l failed for $path: " . implode(' ', $out) . "\n");
            exit(1);
        }
    }
}

@unlink($zipPath);
$zip = new ZipArchive();
$zip->open($zipPath, ZipArchive::CREATE);

foreach ($files as $path => $content) {
    $zip->addFromString($path, $content);
}

$zip->close();

echo "BROKEN TEST BUILD $variant - for the local test site only\n";
foreach ($breakages[$variant] as [$file, , , , $what]) {
    if (!str_starts_with($file, 'language/')) {
        echo "  broken: $what\n";
    }
}
echo "written: $zipPath\n";
echo 'sha256:  ' . hash_file('sha256', $zipPath) . "\n";
