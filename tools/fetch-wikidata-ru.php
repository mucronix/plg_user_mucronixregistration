<?php

/**
 * @package     Mucronix Registration
 * @copyright   (C) 2026 Mucronix
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 *
 * Downloads, once, the Russian labels of the Wikidata items that carry an
 * ISO 3166-2 code (property P300) and saves them to tools/source/wikidata-p300-ru.json.
 * tools/build-regions.php reads that file only; the build never goes to the network.
 *
 * Wikidata content is CC0.
 *
 * Usage: php tools/fetch-wikidata-ru.php
 *
 * Every P300 statement is kept with what is needed to choose between items that
 * share a code: the rank of the statement, an end time qualifier (P582) and the
 * dissolution date of the item (P576). Choosing is done by the build script.
 */

const ENDPOINT = 'https://query.wikidata.org/sparql';
const TARGET   = __DIR__ . '/source/wikidata-p300-ru.json';

const QUERY = <<<'SPARQL'
SELECT ?item ?code ?rank ?end ?dissolved ?ru WHERE {
  ?item p:P300 ?statement .
  ?statement ps:P300 ?code ;
             wikibase:rank ?rank .
  OPTIONAL { ?statement pq:P582 ?end . }
  OPTIONAL { ?item wdt:P576 ?dissolved . }
  OPTIONAL { ?item rdfs:label ?ru . FILTER(LANG(?ru) = "ru") }
}
SPARQL;

// POST, not GET: a GET answer may come from the query service cache. The service
// sometimes sends a truncated answer with status 200 (seen: exactly 256 KiB, not valid
// JSON), so only a complete JSON answer is taken, after up to three attempts.
$json = null;

for ($attempt = 1; $attempt <= 3 && $json === null; $attempt++) {
    $curl = curl_init(ENDPOINT);

    curl_setopt_array($curl, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query(['query' => QUERY, 'format' => 'json']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => ['Accept: application/sparql-results+json', 'User-Agent: regions-build-script/1.0 (php-curl)'],
    ]);

    $body   = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $json   = ($body !== false && $status === 200) ? json_decode($body, true) : null;

    if (!isset($json['results']['bindings'])) {
        $json = null;
        fwrite(STDERR, "attempt $attempt: status $status, " . strlen((string) $body) . " bytes, not a complete answer\n");
    }
}

if ($json === null) {
    fwrite(STDERR, "ERROR: no complete answer from Wikidata\n");
    exit(1);
}

$rows = [];

foreach ($json['results']['bindings'] as $b) {
    $rows[] = [
        'code'      => $b['code']['value'],
        'item'      => basename($b['item']['value']),
        'rank'      => substr(strrchr($b['rank']['value'], '#'), 1),
        'end'       => $b['end']['value'] ?? null,
        'dissolved' => $b['dissolved']['value'] ?? null,
        'ru'        => $b['ru']['value'] ?? null,
    ];
}

// One row per distinct statement content, in a stable order: the file only changes with the data.
$rows = array_values(array_unique(array_map('json_encode', $rows)));
$rows = array_map(static fn($r) => json_decode($r, true), $rows);

usort($rows, static fn($a, $b) => [$a['code'], (int) substr($a['item'], 1), (string) $a['end'], (string) $a['dissolved']]
    <=> [$b['code'], (int) substr($b['item'], 1), (string) $b['end'], (string) $b['dissolved']]);

$data = [
    'source'  => 'Wikidata, https://www.wikidata.org, CC0',
    'fetched' => gmdate('Y-m-d'),
    'query'   => QUERY,
    'rows'    => $rows,
];

file_put_contents(TARGET, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

echo 'Wikidata P300 statements: ' . count($rows) . ', codes: ' . count(array_unique(array_column($rows, 'code')))
    . ', with a ru label: ' . count(array_filter($rows, static fn($r) => $r['ru'] !== null)) . "\n";
echo 'Written: ' . TARGET . ', ' . filesize(TARGET) . " bytes, sha256 " . hash_file('sha256', TARGET) . "\n";
