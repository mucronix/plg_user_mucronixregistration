<?php

/**
 * @package     Mucronix Registration
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Builds the countries and regions table for plg_user_mucronixregistration
 * from the iso-codes project data.
 *
 * Source: iso-codes (Debian), https://salsa.debian.org/iso-codes-team/iso-codes
 * Data licence: LGPL-2.1-or-later.
 *
 * Input (tools/source/, exactly as published at the tag below):
 *   iso_3166-1.json      <- data/iso_3166-1.json
 *   iso_3166-2.json      <- data/iso_3166-2.json
 *   iso_3166-1_uk.po     <- iso_3166-1/uk.po
 *   iso_3166-1_ru.po     <- iso_3166-1/ru.po
 *   iso_3166-2_uk.po     <- iso_3166-2/uk.po
 *   iso_3166-2_ru.po     <- iso_3166-2/ru.po
 *
 * Output: sql/install.mysql.utf8.sql
 *
 * Usage: php tools/build-regions.php [--output=<file>]
 *   --output  write the SQL there instead of sql/install.mysql.utf8.sql;
 *             build.ps1 uses it to check that the SQL in sql/ is the generated one.
 *
 * To move to a new iso-codes version: download the six files from the new
 * tag, update ISO_CODES_VERSION and SOURCE_SHA256, run the script and review
 * its warnings. Data changes for existing installations go into a new file
 * in sql/updates/mysql/, because Joomla does not run the install SQL on update.
 */

const ISO_CODES_VERSION = '4.20.1';

const SOURCE_SHA256 = [
    'iso_3166-1.json'  => 'f01b812b57fba9f31ff621bf33e7c7570a01964dbeb5be2167e94decf538c89f',
    'iso_3166-2.json'  => '78c90ef7fc25b5c2631aac5f089bc9ff6ec22c025c05b6ddbc087a1f1be2e46a',
    'iso_3166-1_uk.po' => '2340b1ac4dfc186413f173254e1827e0c2164cba272f248ecf3d0566bd46b16e',
    'iso_3166-1_ru.po' => 'c26d54de16c0a871fee1653d3e88fd6f472cfa4394eccfdcf8318faf0633b3cf',
    'iso_3166-2_uk.po' => 'ac0d60246eb7b49695a137fc4886da34da1e622ffcb7981a993307059b6292bc',
    'iso_3166-2_ru.po' => 'f623cd0d3020d443ad8b171ca4b83f878f270ba7aa0e1e7e5db8a3564133016c',
];

/**
 * First-level subdivisions that are left out because the same territory is
 * already a country in ISO 3166-1. Key: subdivision code, value: country code.
 */
const DUPLICATES = [
    // The subdivision is the whole country.
    'CN-HK'  => 'HK',
    'CN-MO'  => 'MO',
    'CN-TW'  => 'TW',
    'FI-01'  => 'AX',
    'FR-971' => 'GP',
    'FR-972' => 'MQ',
    'FR-973' => 'GF',
    'FR-974' => 'RE',
    'FR-976' => 'YT',
    'FR-BL'  => 'BL',
    'FR-MF'  => 'MF',
    'FR-NC'  => 'NC',
    'FR-PF'  => 'PF',
    'FR-PM'  => 'PM',
    'FR-TF'  => 'TF',
    'FR-WF'  => 'WF',
    'NL-AW'  => 'AW',
    'NL-CW'  => 'CW',
    'NL-SX'  => 'SX',
    'US-AS'  => 'AS',
    'US-GU'  => 'GU',
    'US-MP'  => 'MP',
    'US-PR'  => 'PR',
    'US-UM'  => 'UM',
    'US-VI'  => 'VI',
    // The subdivision is a part of a country listed in ISO 3166-1.
    'NL-BQ1' => 'BQ',
    'NL-BQ2' => 'BQ',
    'NL-BQ3' => 'BQ',
    'NO-21'  => 'SJ',
    'NO-22'  => 'SJ',
];

/**
 * Subdivisions whose name resembles a country with the same code suffix
 * but which are not that country. Silences the rebuild warning only.
 */
const NOT_DUPLICATES = [
    'ET-SO', // Somali Region of Ethiopia, not Somalia
    'US-IN', // Indiana, not India
];

/**
 * Subdivision types, used only where two regions of one country have the same
 * name: the type is added in brackets, e.g. "Chiayi (city)" / "Chiayi (county)".
 * Key: type as in iso_3166-2.json, or "CC:type" for a country-specific wording.
 */
const TYPE_NAMES = [
    'Municipality'           => ['en' => 'municipality', 'uk' => 'муніципалітет', 'ru' => 'муниципалитет'],
    'Rayon'                  => ['en' => 'rayon', 'uk' => 'район', 'ru' => 'район'],
    'HU:County'              => ['en' => 'county', 'uk' => 'медьє', 'ru' => 'медье'],
    'TW:County'              => ['en' => 'county', 'uk' => 'повіт', 'ru' => 'уезд'],
    'City with county rights' => ['en' => 'city with county rights', 'uk' => 'місто з правами медьє', 'ru' => 'город с правами медье'],
    'Province'               => ['en' => 'province', 'uk' => 'провінція', 'ru' => 'провинция'],
    'Prefecture'             => ['en' => 'prefecture', 'uk' => 'префектура', 'ru' => 'префектура'],
    'City'                   => ['en' => 'city', 'uk' => 'місто', 'ru' => 'город'],
    'Region'                 => ['en' => 'region', 'uk' => 'область', 'ru' => 'область'],
];

/**
 * Base names for same-name pairs whose iso-codes translation names only one
 * member of the pair (the rayon), so it cannot carry a type in brackets.
 * Key: English name, value: [uk, ru].
 */
const PAIR_BASE_NAMES = [
    'Lənkəran'  => ['uk' => 'Ленкорань', 'ru' => 'Ленкорань'],
    'Şəki'      => ['uk' => 'Шекі', 'ru' => 'Шеки'],
    'Yevlax'    => ['uk' => 'Євлах', 'ru' => 'Евлах'],
    // The ru translations of these are marked fuzzy in iso-codes and are skipped.
    'Veszprém'  => ['ru' => 'Веспрем'],
    'Viangchan' => ['ru' => 'Вьентьян'],
    'Maputo'    => ['ru' => 'Мапуту'],
    'Chiayi'    => ['ru' => 'Цзяи'],
    'Hsinchu'   => ['ru' => 'Синьчжу'],
];

/**
 * Wrong translations in iso-codes: a region got the name of its neighbour.
 * Key: region code, value: language => [translation in iso-codes, correct name].
 * The build stops when the translation in iso-codes is no longer the wrong one,
 * so a fixed or changed entry is noticed and this list is cleaned up.
 */
const CORRECTIONS = [
    'BH-14'  => ['uk' => ['Північне губернаторство', 'Південне губернаторство']],
    'LR-RG'  => ['uk' => ['Рівер-Сесс', 'Рівер-Гі']],
    'MV-20'  => ['uk' => ['Північний Маалхосмадулу', 'Південний Маалхосмадулу']],
    'RU-KYA' => ['uk' => ['Краснодарський край', 'Красноярський край']],
    'RU-SA'  => ['uk' => ['Республіка Алтай', 'Республіка Саха (Якутія)']],
    'SS-EW'  => ['uk' => ['Центральна Екваторія', 'Західна Екваторія']],
    'VN-03'  => ['uk' => ['Анзянг', 'Хазянг']],
    'VN-32'  => ['uk' => ['Хингйен', 'Фуйен']],
    'YE-SA'  => ['uk' => ['Сана [місто]', 'Сана (місто)']], // the note in brackets would be dropped
    'US-DC'  => ['ru' => ['Вашингтон', 'Округ Колумбия']],
];

const TABLE       = '#__mucronixregistration_regions';
const ROWS_PER_INSERT = 500;

$root      = dirname(__DIR__);
$sourceDir = __DIR__ . '/source';
$options   = getopt('', ['output:']);
$target    = $options['output'] ?? $root . '/sql/install.mysql.utf8.sql';

/**
 * Stops with a message.
 */
function fail(string $message): never
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit(1);
}

/**
 * Reads a .po file into [msgid => msgstr]. Fuzzy and empty entries are skipped.
 */
function readPo(string $file): array
{
    $result = [];
    $blocks = preg_split('/\R\R+/', str_replace("\r\n", "\n", file_get_contents($file)));

    foreach ($blocks as $block) {
        if (preg_match('/^#,.*\bfuzzy\b/m', $block)) {
            continue;
        }

        $msgid  = poString($block, 'msgid');
        $msgstr = poString($block, 'msgstr');

        if ($msgid === null || $msgid === '' || $msgstr === null || trim($msgstr) === '') {
            continue;
        }

        $result[$msgid] = trim($msgstr);
    }

    return $result;
}

/**
 * Extracts one (possibly multi-line) string of a .po entry.
 */
function poString(string $block, string $keyword): ?string
{
    if (!preg_match('/^' . $keyword . ' ("(?:[^"\\\\]|\\\\.)*"(?:\n"(?:[^"\\\\]|\\\\.)*")*)/m', $block, $m)) {
        return null;
    }

    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $m[1], $parts);

    return stripcslashes(implode('', $parts[1]));
}

/**
 * Quotes a value for MySQL.
 */
function sqlQuote(string $value): string
{
    if (str_contains($value, '\\')) {
        fail('Backslash in value: ' . $value);
    }

    return "'" . str_replace("'", "''", $value) . "'";
}

/**
 * Lower-case ASCII form of a name, for the duplicate warning.
 */
function looseName(string $value): string
{
    $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

    return strtolower(preg_replace('/[^a-z ]+/i', '', (string) $ascii));
}

// ---------------------------------------------------------------------------
// Source files.

foreach (SOURCE_SHA256 as $file => $hash) {
    $path = $sourceDir . '/' . $file;

    if (!is_file($path)) {
        fail("Missing source file tools/source/$file");
    }

    if (hash_file('sha256', $path) !== $hash) {
        fail("tools/source/$file does not match iso-codes " . ISO_CODES_VERSION
            . '. Update ISO_CODES_VERSION and SOURCE_SHA256 together with the files.');
    }
}

$countries    = json_decode(file_get_contents($sourceDir . '/iso_3166-1.json'), true, 512, JSON_THROW_ON_ERROR)['3166-1'];
$subdivisions = json_decode(file_get_contents($sourceDir . '/iso_3166-2.json'), true, 512, JSON_THROW_ON_ERROR)['3166-2'];

$po = [
    'uk' => [
        'country' => readPo($sourceDir . '/iso_3166-1_uk.po'),
        'region'  => readPo($sourceDir . '/iso_3166-2_uk.po'),
    ],
    'ru' => [
        'country' => readPo($sourceDir . '/iso_3166-1_ru.po'),
        'region'  => readPo($sourceDir . '/iso_3166-2_ru.po'),
    ],
];

// ---------------------------------------------------------------------------
// Countries.

$rows          = [];
$countryNames  = [];
$warnings      = [];

foreach ($countries as $country) {
    $code = $country['alpha_2'];

    if (!preg_match('/^[A-Z]{2}$/', $code)) {
        fail("Unexpected country code $code");
    }

    // The common name is shorter and is what people look for in a list.
    $english = $country['common_name'] ?? $country['name'];
    $row     = ['code' => $code, 'country' => $code, 'type' => 'country', 'en' => $english];

    foreach (['uk', 'ru'] as $lang) {
        // If the common name has no translation, the translated full name
        // is still better than the English one.
        $row[$lang] = $po[$lang]['country'][$english] ?? $po[$lang]['country'][$country['name']] ?? '';
    }

    $rows[$code]         = $row;
    $countryNames[$code] = array_filter([$country['name'], $country['common_name'] ?? null, $country['official_name'] ?? null]);
}

// ---------------------------------------------------------------------------
// First-level regions.

$removed = [];

foreach (array_keys(DUPLICATES) as $code) {
    $removed[$code] = null;
}

foreach ($subdivisions as $subdivision) {
    if (isset($subdivision['parent'])) {
        continue;
    }

    $code = $subdivision['code'];

    if (!preg_match('/^([A-Z]{2})-([A-Z0-9]{1,3})$/', $code, $m)) {
        fail("Unexpected subdivision code $code");
    }

    [, $countryCode, $suffix] = $m;

    if (!isset($rows[$countryCode])) {
        fail("Subdivision $code belongs to an unknown country");
    }

    if (array_key_exists($code, DUPLICATES)) {
        $removed[$code] = $subdivision['name'];

        continue;
    }

    // Warn about a possible new duplicate after an iso-codes update.
    if ($suffix !== $countryCode && isset($countryNames[$suffix]) && !in_array($code, NOT_DUPLICATES, true)) {
        $first = explode(' ', looseName($subdivision['name']))[0];

        foreach ($countryNames[$suffix] as $name) {
            $loose = looseName($name);

            if (strlen($first) > 3 && str_contains($loose, $first)) {
                $warnings[] = "$code \"{$subdivision['name']}\" looks like country $suffix \"$name\"";
                break;
            }
        }
    }

    $row = [
        'code'    => $code,
        'country' => $countryCode,
        'type'    => 'region',
        'en'      => $subdivision['name'],
        'isoType' => $subdivision['type'],
    ];

    foreach (['uk', 'ru'] as $lang) {
        $row[$lang] = $po[$lang]['region'][$subdivision['name']] ?? '';
    }

    $rows[$code] = $row;
}

// ---------------------------------------------------------------------------
// Wrong translations.

$corrected = [];

foreach (CORRECTIONS as $code => $languages) {
    if (!isset($rows[$code])) {
        fail("CORRECTIONS entry $code is not in this iso-codes version");
    }

    foreach ($languages as $lang => [$wrong, $right]) {
        if ($rows[$code][$lang] !== $wrong) {
            fail("CORRECTIONS entry $code ($lang) expects \"$wrong\" but iso-codes has \"{$rows[$code][$lang]}\": remove or update the entry");
        }

        $rows[$code][$lang] = $right;
        $corrected[]        = "$code $lang: $wrong -> $right";
    }
}

// ---------------------------------------------------------------------------
// Names: drop notes in square brackets, e.g. "Wales [Cymru GB-CYM]" -> "Wales".

$bracketed = 0;

foreach ($rows as $code => $row) {
    foreach (['en', 'uk', 'ru'] as $lang) {
        $clean = trim(preg_replace('/\s*\[[^\]]*\]/u', '', $row[$lang]));

        if ($clean !== $row[$lang]) {
            $bracketed++;

            if ($clean === '') {
                fail("Name of $code ($lang) is empty without the bracketed part");
            }

            $rows[$code][$lang] = $clean;
        }
    }
}

// ---------------------------------------------------------------------------
// Same name for two regions of one country: add the type in brackets.

$groups = [];

foreach ($rows as $code => $row) {
    if ($row['type'] === 'region') {
        $groups[$row['country'] . '|' . $row['en']][] = $code;
    }
}

$pairs     = [];
$usedBases = [];

foreach ($groups as $codes) {
    if (count($codes) < 2) {
        continue;
    }

    $english = $rows[$codes[0]]['en'];
    $labels  = [];

    foreach ($codes as $code) {
        $row  = $rows[$code];
        $type = TYPE_NAMES[$row['country'] . ':' . $row['isoType']] ?? TYPE_NAMES[$row['isoType']] ?? null;

        if ($type === null) {
            fail("No entry in TYPE_NAMES for type \"{$row['isoType']}\" of $code");
        }

        $base = ['en' => $english];

        foreach (['uk', 'ru'] as $lang) {
            // An empty translation falls back to the English name, so that
            // the type is still shown in the language of the site.
            $base[$lang] = PAIR_BASE_NAMES[$english][$lang] ?? ($row[$lang] !== '' ? $row[$lang] : $english);
        }

        foreach (['en', 'uk', 'ru'] as $lang) {
            $rows[$code][$lang] = $base[$lang] . ' (' . $type[$lang] . ')';
            $labels[$lang][]    = $rows[$code][$lang];
        }

        $pairs[] = $code;
    }

    if (isset(PAIR_BASE_NAMES[$english])) {
        $usedBases[$english] = true;
    }

    foreach ($labels as $lang => $list) {
        if (count(array_unique($list)) !== count($list)) {
            fail("Names stay the same after adding the type: " . implode(', ', $list));
        }
    }
}

foreach (array_keys(PAIR_BASE_NAMES) as $english) {
    if (!isset($usedBases[$english])) {
        fail("PAIR_BASE_NAMES entry \"$english\" is not a same-name pair any more");
    }
}

// Regions that differ in English but share a translated name.
foreach (['uk', 'ru'] as $lang) {
    $seenTranslated = [];

    foreach ($rows as $code => $row) {
        if ($row['type'] !== 'region' || $row[$lang] === '') {
            continue;
        }

        $key = $row['country'] . '|' . $row[$lang];

        if (isset($seenTranslated[$key])) {
            fail("Same $lang name in one country: {$seenTranslated[$key]} / $code \"{$row[$lang]}\". Add the right name to CORRECTIONS.");
        }

        $seenTranslated[$key] = $code;
    }
}

foreach ($removed as $code => $name) {
    if ($name === null) {
        fail("Listed duplicate $code is not a first-level subdivision in this iso-codes version");
    }

    if (!isset($rows[DUPLICATES[$code]]) || $rows[DUPLICATES[$code]]['type'] !== 'country') {
        fail("Listed duplicate $code points to unknown country " . DUPLICATES[$code]);
    }
}

ksort($rows, SORT_STRING);

// ---------------------------------------------------------------------------
// SQL.

$sql   = [];
$sql[] = '--';
$sql[] = '-- Mucronix Registration: countries (ISO 3166-1) and first-level regions (ISO 3166-2).';
$sql[] = '-- Source data: iso-codes ' . ISO_CODES_VERSION . ' (Debian), https://salsa.debian.org/iso-codes-team/iso-codes';
$sql[] = '-- Data licence: LGPL-2.1-or-later, see LICENSE-data.txt.';
$sql[] = '-- Generated by tools/build-regions.php. Do not edit by hand.';
$sql[] = '--';
$sql[] = '-- type = country: code is ISO 3166-1 alpha-2, country_code equals code.';
$sql[] = '-- type = region:  code is ISO 3166-2, country_code is the country it belongs to.';
$sql[] = '-- An empty name_uk / name_ru means: use name_en.';
$sql[] = '--';
$sql[] = '';
$sql[] = 'DROP TABLE IF EXISTS `' . TABLE . '`;';
$sql[] = '';
$sql[] = 'CREATE TABLE `' . TABLE . '` (';
$sql[] = '  `code` varchar(6) NOT NULL,';
$sql[] = '  `country_code` char(2) NOT NULL,';
$sql[] = '  `type` varchar(7) NOT NULL,';
$sql[] = '  `name_en` varchar(255) NOT NULL,';
$sql[] = "  `name_uk` varchar(255) NOT NULL DEFAULT '',";
$sql[] = "  `name_ru` varchar(255) NOT NULL DEFAULT '',";
$sql[] = '  `published` tinyint NOT NULL DEFAULT 1,';
$sql[] = '  PRIMARY KEY (`code`),';
$sql[] = '  KEY `idx_country_type` (`country_code`, `type`, `published`),';
$sql[] = '  KEY `idx_type` (`type`, `published`)';
$sql[] = ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;';

foreach (array_chunk(array_values($rows), ROWS_PER_INSERT) as $chunk) {
    $sql[] = '';
    $sql[] = 'INSERT INTO `' . TABLE . '` (`code`, `country_code`, `type`, `name_en`, `name_uk`, `name_ru`, `published`) VALUES';

    $values = [];

    foreach ($chunk as $row) {
        foreach (['en', 'uk', 'ru'] as $lang) {
            if (mb_strlen($row[$lang]) > 255) {
                fail("Name longer than 255 characters: {$row['code']} $lang");
            }

            if (str_contains($row[$lang], ';')) {
                $warnings[] = "Semicolon in name: {$row['code']} $lang \"{$row[$lang]}\"";
            }
        }

        $values[] = '(' . implode(', ', [
            sqlQuote($row['code']),
            sqlQuote($row['country']),
            sqlQuote($row['type']),
            sqlQuote($row['en']),
            sqlQuote($row['uk']),
            sqlQuote($row['ru']),
            '1',
        ]) . ')';
    }

    $sql[] = implode(",\n", $values) . ';';
}

$content = implode("\n", $sql) . "\n";

if (!is_dir(dirname($target))) {
    fail('Missing folder ' . dirname($target));
}

file_put_contents($target, $content);

// ---------------------------------------------------------------------------
// Report.

$stats = ['country' => ['all' => 0, 'uk' => 0, 'ru' => 0], 'region' => ['all' => 0, 'uk' => 0, 'ru' => 0]];
$withRegions = [];
$sameName    = [];
$seen        = [];

foreach ($rows as $row) {
    $stats[$row['type']]['all']++;

    foreach (['uk', 'ru'] as $lang) {
        if ($row[$lang] !== '') {
            $stats[$row['type']][$lang]++;
        }
    }

    if ($row['type'] === 'region') {
        $withRegions[$row['country']] = ($withRegions[$row['country']] ?? 0) + 1;

        $key = $row['country'] . '|' . $row['en'];

        if (isset($seen[$key])) {
            $sameName[] = $seen[$key] . ' / ' . $row['code'] . ' "' . $row['en'] . '"';
        }

        $seen[$key] = $row['code'];
    }
}

$percent = static fn(int $part, int $all): string => sprintf('%d of %d (%.1f%%)', $part, $all, $all ? 100 * $part / $all : 0);

echo 'iso-codes ' . ISO_CODES_VERSION . PHP_EOL;
echo 'Countries:              ' . $stats['country']['all'] . PHP_EOL;
echo 'First-level regions:    ' . $stats['region']['all'] . PHP_EOL;
echo 'Countries with regions: ' . count($withRegions) . PHP_EOL;
echo 'Countries uk:           ' . $percent($stats['country']['uk'], $stats['country']['all']) . PHP_EOL;
echo 'Countries ru:           ' . $percent($stats['country']['ru'], $stats['country']['all']) . PHP_EOL;
echo 'Regions uk:             ' . $percent($stats['region']['uk'], $stats['region']['all']) . PHP_EOL;
echo 'Regions ru:             ' . $percent($stats['region']['ru'], $stats['region']['all']) . PHP_EOL;
echo 'Removed duplicates:     ' . count($removed) . PHP_EOL;

foreach ($removed as $code => $name) {
    echo "  $code \"$name\" = " . DUPLICATES[$code] . ' "' . $rows[DUPLICATES[$code]]['en'] . '"' . PHP_EOL;
}

echo 'Corrected translations: ' . count($corrected) . PHP_EOL;

foreach ($corrected as $line) {
    echo '  ' . $line . PHP_EOL;
}

echo 'Bracketed notes removed: ' . $bracketed . PHP_EOL;
echo 'Same-name regions with type added: ' . count($pairs) . PHP_EOL;

foreach ($pairs as $code) {
    printf("  %-7s %s | %s | %s\n", $code, $rows[$code]['en'], $rows[$code]['uk'], $rows[$code]['ru']);
}

if ($sameName) {
    fail('Same name within a country is left: ' . implode('; ', $sameName));
}

echo 'Written: ' . $target . ', ' . strlen($content) . ' bytes, sha256 ' . hash('sha256', $content) . PHP_EOL;

foreach ($warnings as $warning) {
    echo 'WARNING: ' . $warning . PHP_EOL;
}
