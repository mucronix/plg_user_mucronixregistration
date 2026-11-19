<?php

/**
 * @package     Mucronix Registration
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Snapshot of what an installation over the plugin must keep: its settings and the
 * plugin rows of #__user_profiles. Read only. Not part of the package.
 *
 * Usage: php tests/snapshot.php --save=<file> | --compare=<file> [--site=D:/OSPanel1/home/joomla6]
 *
 * The settings are read from #__extensions, one row: allowed by the site owner for
 * this check only.
 */

require __DIR__ . '/site-db.php';

$options = getopt('', ['save:', 'compare:', 'site:']);
$site    = $options['site'] ?? dirname(__DIR__, 2) . '/joomla6';
$db      = new SiteDb($site);

$params = $db->pluginParams();
$rows   = $db->select("SELECT user_id, profile_key, profile_value FROM #__user_profiles WHERE profile_key LIKE 'mucronixregistration.%' ORDER BY user_id, profile_key");
$table  = $db->value('SELECT COUNT(*) FROM #__mucronixregistration_regions');
$now    = ['params' => $params, 'profile_rows' => $rows, 'table_rows' => (int) $table];

if (isset($options['save'])) {
    file_put_contents($options['save'], json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo 'saved: ' . count($rows) . " profile rows, $table table rows, params " . $params . "\n";
    exit(0);
}

$before = json_decode((string) file_get_contents($options['compare'] ?? ''), true);

if (!$before) {
    fwrite(STDERR, "Pass --save=<file> or --compare=<file>\n");
    exit(2);
}

$fail = 0;

foreach (['params', 'profile_rows', 'table_rows'] as $key) {
    $same = $before[$key] === $now[$key];
    $fail += $same ? 0 : 1;
    echo ($same ? '  ok   ' : '  FAIL ') . "$key unchanged" . ($key === 'profile_rows' ? ' (' . count($now[$key]) . ' rows)' : '') . "\n";
}

exit($fail ? 1 : 0);
