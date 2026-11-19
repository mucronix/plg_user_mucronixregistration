<?php

/**
 * @package     Mucronix Registration
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Language files: read by the Joomla parser in debug mode, every key used in the
 * code is there, nothing unused, the same keys in the same order in en-GB,
 * uk-UA and ru-RU, no exclamation marks. Not part of the package.
 *
 * Usage: php tests/language-check.php [--joomla=...] [--plugin=...]
 */

require __DIR__ . '/bootstrap.php';

use Joomla\CMS\Language\LanguageHelper;

const KEY_PATTERN = '/PLG_USER_MUCRONIXREGISTRATION[A-Z_]*/';
const SYS_KEYS    = ['PLG_USER_MUCRONIXREGISTRATION', 'PLG_USER_MUCRONIXREGISTRATION_XML_DESCRIPTION', 'PLG_USER_MUCRONIXREGISTRATION_INSTALL_MESSAGE'];

$sources = [$plugin . '/mucronixregistration.xml', $plugin . '/forms/mucronixregistration.xml', $plugin . '/script.php'];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($plugin . '/src', FilesystemIterator::SKIP_DOTS)) as $file) {
    $sources[] = $file->getPathname();
}

$used = [];

foreach ($sources as $file) {
    preg_match_all(KEY_PATTERN, file_get_contents($file), $m);
    $used = array_merge($used, $m[0]);
}

$used = array_values(array_unique($used));
sort($used);

echo 'Keys used in the code: ' . count($used) . "\n";

$order = [];

foreach (['en-GB', 'uk-UA', 'ru-RU'] as $tag) {
    foreach (['ini', 'sys.ini'] as $ext) {
        $file    = "$plugin/language/$tag/plg_user_mucronixregistration.$ext";
        $lines   = preg_match_all('/^[A-Z_]+=/m', (string) @file_get_contents($file));
        $strings = LanguageHelper::parseIniFile($file, true);
        $keys    = array_keys($strings);
        $need    = $ext === 'ini' ? array_values(array_diff($used, ['PLG_USER_MUCRONIXREGISTRATION_INSTALL_MESSAGE'])) : SYS_KEYS;

        check("$tag $ext: every line parsed ($lines)", count($strings), $lines);
        check("$tag $ext: no missing keys", array_values(array_diff($need, $keys)), []);
        check("$tag $ext: no unused keys", array_values(array_diff($keys, $used)), []);
        check("$tag $ext: no exclamation marks", array_keys(array_filter($strings, static fn($v) => str_contains($v, '!'))), []);

        if (isset($strings['PLG_USER_MUCRONIXREGISTRATION_INSTALL_MESSAGE'])) {
            check("$tag $ext: one %s in the install message", substr_count($strings['PLG_USER_MUCRONIXREGISTRATION_INSTALL_MESSAGE'], '%s'), 1);
        }

        $order[$ext][$tag] = $keys;
    }
}

foreach ($order as $ext => $byTag) {
    foreach (['uk-UA', 'ru-RU'] as $tag) {
        check("$ext: $tag has the keys of en-GB in the same order", $byTag[$tag], $byTag['en-GB']);
    }
}

finish();
