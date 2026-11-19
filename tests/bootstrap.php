<?php

/**
 * @package     Mucronix Registration
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Common start of the checks in tests/. Not part of the package.
 *
 * Loads the Joomla 6 libraries of a local site (read only, nothing is written
 * there) and the plugin sources. The database is SQLite in memory, never the
 * site database.
 *
 * Usage: php tests/<check>.php [--joomla=D:/OSPanel1/home/joomla6] [--plugin=<folder>]
 *   --joomla  Joomla 6 site whose libraries are used. Default: joomla6 next to the project folder.
 *   --plugin  Plugin folder to check. Default: the project folder. A built tree
 *             (build/plg_user_mucronixregistration) or a deliberately broken copy can be given.
 */

const _JEXEC = 1;

$options = getopt('', ['joomla:', 'plugin:']);
$project = dirname(__DIR__);
$joomla  = rtrim(str_replace('\\', '/', $options['joomla'] ?? dirname($project) . '/joomla6'), '/');
$plugin  = rtrim(str_replace('\\', '/', $options['plugin'] ?? $project), '/');

if (!is_file($joomla . '/libraries/loader.php')) {
    fwrite(STDERR, "Joomla libraries not found in $joomla, pass --joomla=<site folder>\n");
    exit(2);
}

if (!is_file($plugin . '/mucronixregistration.xml')) {
    fwrite(STDERR, "Plugin not found in $plugin, pass --plugin=<folder>\n");
    exit(2);
}

$cache = sys_get_temp_dir() . '/mucronixregistration-tests';

if (!is_dir($cache)) {
    mkdir($cache, 0777, true);
}

define('JPATH_ROOT', $joomla);
define('JPATH_BASE', $joomla);
define('JPATH_SITE', $joomla);
define('JPATH_ADMINISTRATOR', $joomla . '/administrator');
define('JPATH_LIBRARIES', $joomla . '/libraries');
define('JPATH_PLATFORM', JPATH_LIBRARIES);
define('JPATH_PLUGINS', $joomla . '/plugins');
define('JPATH_CACHE', $cache);

require JPATH_LIBRARIES . '/loader.php';
JLoader::setup();
require JPATH_LIBRARIES . '/vendor/autoload.php';
JLoader::registerNamespace('Joomla\\CMS', JPATH_LIBRARIES . '/src');
JLoader::registerNamespace('Mucronix\\Plugin\\User\\MucronixRegistration', $plugin . '/src');

$pass = 0;
$fail = 0;

/**
 * Compares a result with the expected one and prints the case.
 */
function check(string $label, mixed $got, mixed $want): void
{
    global $pass, $fail;

    if ($got === $want) {
        $pass++;
        echo "  ok   $label\n";

        return;
    }

    $fail++;
    echo "  FAIL $label\n"
        . '       got:  ' . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n"
        . '       want: ' . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
}

/**
 * Prints the totals and sets the exit code.
 */
function finish(): never
{
    global $pass, $fail;

    echo "\npassed $pass, failed $fail\n";
    exit($fail ? 1 : 0);
}
