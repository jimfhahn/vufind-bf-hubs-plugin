#!/usr/bin/env php
<?php
/**
 * Standalone POC test — exercises HubClient + RelationshipInferrer
 * against the live LC suggest2 API. No VuFind install needed.
 *
 * Usage: php tests/test_poc.php "Shakespeare, William, 1564-1616" "Hamlet"
 *        php tests/test_poc.php "" "Hamlet"
 *        php tests/test_poc.php "Tolkien, J. R. R." "Lord of the rings"
 */

require_once __DIR__ . '/bootstrap.php';

use BibframeHub\Connection\HubClient;
use BibframeHub\Relationship\RelationshipInferrer;

// --- Parse CLI args ---
$author = $argv[1] ?? 'Shakespeare, William, 1564-1616';
$title  = $argv[2] ?? 'Hamlet';
$uniformTitle = $argv[3] ?? null;
$lccn   = $argv[4] ?? null;

echo "=== BIBFRAME Hub POC Test ===\n";
echo "Author:        " . ($author ?: '(none)') . "\n";
echo "Title:         $title\n";
echo "Uniform Title: " . ($uniformTitle ?: '(none)') . "\n";
echo "LCCN:          " . ($lccn ?: '(none)') . "\n\n";

// --- Build a minimal HTTP client using curl ---
$client = new SimpleHttpClient();
$hubClient = new HubClient($client, [
    'baseUrl' => 'https://id.loc.gov',
    'userAgent' => 'VuFind-BibframeHub-Test/1.0',
    'timeout' => 15,
    'maxResults' => 20,
]);

$inferrer = new RelationshipInferrer();

// --- Step 1: Find matching Hub(s) ---
echo "--- Step 1: Finding Hub ---\n";
$marcFields = [
    'author' => $author ?: null,
    'title' => $title,
    'uniformTitle' => $uniformTitle ?: null,
    'lccn' => $lccn,
];

$lookup = $hubClient->findHubsForRecord($marcFields);
echo "Strategy: {$lookup['strategy']}\n";
echo "Verified: " . ($lookup['verified'] ? 'yes' : 'no') . "\n";
echo "Hits: " . count($lookup['hits']) . "\n";

if (empty($lookup['hits'])) {
    echo "\nNo Hub found. Try different search terms.\n";
    exit(1);
}

$primary = $lookup['hits'][0];
echo "Primary Hub: {$primary['aLabel']}\n";
echo "URI: {$primary['uri']}\n\n";

// --- Step 2: Find related Hubs ---
echo "--- Step 2: Finding related Hubs ---\n";
$relatedHits = $hubClient->findRelatedHubs($primary['aLabel']);
echo "Related hits: " . count($relatedHits) . "\n\n";

// --- Step 3: Classify relationships ---
echo "--- Step 3: Classifying relationships ---\n";
$classified = $inferrer->classifyRelatedHubs($primary, $relatedHits);

$totalRelated = 0;
foreach ($classified as $group) {
    $count = count($group['hubs']);
    $totalRelated += $count;
    echo "\n  [{$group['label']}] ($count)\n";
    foreach ($group['hubs'] as $hub) {
        $detail = $hub['detail'] ? " ({$hub['detail']})" : '';
        $conf = number_format($hub['confidence'], 2);
        echo "    - {$hub['label']}{$detail} [confidence: $conf]\n";
        if (!empty($hub['variantTitles'])) {
            $variants = implode('; ', array_slice($hub['variantTitles'], 0, 2));
            echo "      aka: $variants\n";
        }
    }
}

echo "\n--- Summary ---\n";
echo "Primary: {$primary['aLabel']}\n";
echo "Strategy: {$lookup['strategy']}\n";
echo "Total related works found: $totalRelated\n";
echo "Relationship types: " . count($classified) . "\n";
