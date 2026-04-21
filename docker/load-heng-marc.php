<?php
/**
 * Load a MARCXML collection file directly into Solr as VuFind biblio docs.
 *
 * Bypasses SolrMarc — extracts just the fields needed for record display
 * and stores the full MARCXML under `fullrecord` so the BibframeHub plugin
 * can parse it on demand.
 *
 * Usage:  php load-heng-marc.php <marcxml-file> <id-prefix>
 * Example: php load-heng-marc.php /data/Concerto-MARC.xml concerto
 */
declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php load-heng-marc.php <marcxml-file> <id-prefix>\n");
    exit(1);
}

[$_, $marcFile, $idPrefix] = $argv;
$solrUrl = getenv('SOLR_URL') ?: 'http://localhost:8983/solr/biblio/update';
$batchSize = 50;

if (!is_readable($marcFile)) {
    fwrite(STDERR, "Cannot read $marcFile\n");
    exit(1);
}

echo "Loading $marcFile (prefix=$idPrefix)...\n";

$reader = new XMLReader();
$reader->open($marcFile);

$ns = 'http://www.loc.gov/MARC21/slim';
$batch = [];
$total = 0;
$skipped = 0;

while ($reader->read()) {
    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'record') {
        continue;
    }
    $node = $reader->expand();
    if (!$node) continue;
    $doc = new DOMDocument();
    $doc->appendChild($doc->importNode($node, true));
    $marcxml = $doc->saveXML($doc->documentElement);

    $sx = simplexml_import_dom($doc->documentElement);
    $sx->registerXPathNamespace('m', $ns);

    $rawId = (string) ($sx->xpath('m:controlfield[@tag="001"]')[0] ?? '');
    if ($rawId === '') { $skipped++; continue; }

    $title    = trim((string) ($sx->xpath('m:datafield[@tag="245"]/m:subfield[@code="a"]')[0] ?? ''));
    $titleSub = trim((string) ($sx->xpath('m:datafield[@tag="245"]/m:subfield[@code="b"]')[0] ?? ''));
    $author   = trim((string) ($sx->xpath('m:datafield[@tag="100"]/m:subfield[@code="a"]')[0] ?? ''));
    if ($author === '') {
        $author = trim((string) ($sx->xpath('m:datafield[@tag="110"]/m:subfield[@code="a"]')[0] ?? ''));
    }
    $lang = (string) ($sx->xpath('m:controlfield[@tag="008"]')[0] ?? '');
    $lang = strlen($lang) >= 38 ? trim(substr($lang, 35, 3)) : '';
    $pubDate = '';
    $f264c = $sx->xpath('m:datafield[@tag="264"]/m:subfield[@code="c"]')[0] ?? null;
    $f260c = $sx->xpath('m:datafield[@tag="260"]/m:subfield[@code="c"]')[0] ?? null;
    if ($f264c !== null) { $pubDate = trim((string)$f264c); }
    elseif ($f260c !== null) { $pubDate = trim((string)$f260c); }
    if (preg_match('/(\d{4})/', $pubDate, $m)) { $pubDate = $m[1]; } else { $pubDate = ''; }

    $title = rtrim($title, ' /:,');
    if ($titleSub !== '') { $title .= ': ' . rtrim($titleSub, ' /:,'); }
    if ($title === '') { $title = '[Untitled]'; }

    $author = rtrim($author, ' ,');

    $doc = [
        'id'             => "$idPrefix-$rawId",
        'title'          => $title,
        'title_short'    => $title,
        'title_full'     => $title,
        'title_sort'     => mb_strtolower($title),
        'title_auth'     => $title,
        'format'         => ['Book'],
        'record_format'  => 'marc',
        'fullrecord'     => $marcxml,
    ];
    if ($author !== '') {
        $doc['author']      = $author;
        $doc['author_sort'] = $author;
    }
    if ($lang !== '') {
        $doc['language'] = [$lang];
    }
    if ($pubDate !== '') {
        $doc['publishDate'] = [$pubDate];
    }

    $batch[] = $doc;
    if (count($batch) >= $batchSize) {
        post($solrUrl, $batch);
        $total += count($batch);
        $batch = [];
        echo "  $total...\n";
    }
}
if ($batch) {
    post($solrUrl, $batch);
    $total += count($batch);
}

// Commit
$ch = curl_init("$solrUrl?commit=true");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => '[]',
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
]);
curl_exec($ch);
curl_close($ch);

echo "Loaded $total records (skipped $skipped without 001).\n";

function post(string $url, array $batch): void
{
    $payload = json_encode($batch, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $resp = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($http >= 300) {
        fwrite(STDERR, "Solr POST failed (HTTP $http): " . substr((string)$resp, 0, 500) . "\n");
        exit(1);
    }
}
