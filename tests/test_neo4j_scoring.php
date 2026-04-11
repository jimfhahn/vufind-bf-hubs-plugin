#!/usr/bin/env php
<?php
/**
 * Standalone test: exercises Neo4jService + RelationshipInferrer (surprise scoring)
 * against the live Neo4j graph. No VuFind required.
 *
 * Usage: php tests/test_neo4j_scoring.php "Pride and prejudice"
 *        php tests/test_neo4j_scoring.php "Hamlet"
 */

require_once __DIR__ . '/bootstrap.php';

use BibframeHub\Graph\Neo4jService;
use BibframeHub\Relationship\RelationshipInferrer;

// ── Setup ──
$neo4j = new Neo4jService([
    'uri'      => 'bolt://localhost:7687',
    'username' => 'neo4j',
    'password' => 'bibframe123',
    'database' => 'neo4j',
    'enabled'  => true,
]);

$scorer = new RelationshipInferrer();

$title = $argv[1] ?? 'Pride and prejudice';

echo "=== Searching for: $title ===\n\n";

// Step 1: Find Hub
$hubUri = $neo4j->findHubByTitle($title);
if (!$hubUri) {
    echo "No Hub found for '$title'\n";
    exit(1);
}

$hubTitle = $neo4j->getHubTitle($hubUri);
echo "Found Hub: $hubTitle\n";
echo "URI: $hubUri\n\n";

// Step 2: Get related
$related = $neo4j->findRelatedHubs($hubUri);
echo "Found " . count($related) . " related hubs\n\n";

if (empty($related)) {
    echo "No relationships found.\n";
    exit(0);
}

// Step 3: Get source context
$sourceAgents = $neo4j->getHubAgents($hubUri);
$sourceMedia = $neo4j->getHubMediaTypes($hubUri);
echo "Source agents: " . implode(', ', $sourceAgents) . "\n";
echo "Source media: " . implode(', ', array_map('basename', $sourceMedia)) . "\n\n";

// Step 4: Score
$frequencies = $neo4j->getRelationshipTypeFrequencies();
echo "Loaded " . count($frequencies) . " relationship type frequencies\n\n";

$results = $scorer->scoreRelatedHubs(
    $sourceAgents,
    $sourceMedia,
    $related,
    $frequencies,
    fn(string $uri) => $neo4j->getHubTitle($uri),
    fn(string $uri) => $neo4j->getHubAgents($uri),
    fn(string $uri) => $neo4j->getHubMediaTypes($uri),
);

// Step 5: Display
echo str_repeat('─', 90) . "\n";
printf("%5s %4s %-35s %s\n", 'Score', 'Tier', 'RelType', 'Title');
echo str_repeat('─', 90) . "\n";

foreach ($results as $r) {
    $flags = [];
    if ($r['differentAuthor']) $flags[] = 'DIFF_AUTHOR';
    if (!empty($r['media'])) $flags[] = implode('+', $r['media']);
    $flagStr = $flags ? ' [' . implode(', ', $flags) . ']' : '';

    printf("%5.0f %4d %-35s %s%s\n",
        $r['score'],
        $r['breakdown']['tier'],
        substr($r['relType'], 0, 35),
        $r['title'] ?? 'Unknown',
        $flagStr
    );
}

echo "\n" . str_repeat('=', 90) . "\n";
echo "TOP 10 MOST SURPRISING:\n";
echo str_repeat('=', 90) . "\n\n";

foreach (array_slice($results, 0, 10) as $i => $r) {
    $n = $i + 1;
    $flags = [];
    if ($r['differentAuthor']) $flags[] = 'different author';
    if (!empty($r['media'])) $flags[] = implode(' + ', $r['media']);
    $flagStr = $flags ? ' (' . implode(', ', $flags) . ')' : '';
    $b = $r['breakdown'];

    echo "  $n. [{$r['score']}] " . ($r['title'] ?? 'Unknown') . "$flagStr\n";
    echo "     via: {$r['relType']}\n";
    echo "     → {$scorer->humanLabel($r['relType'])}\n";
    echo "     scoring: base={$b['base']} + rarity={$b['rarityBonus']} + author={$b['authorBonus']} + medium={$b['mediumBonus']}\n\n";
}

// Summary
$interesting = array_filter($results, fn($r) => $r['score'] >= 50);
$boring = array_filter($results, fn($r) => $r['score'] < 20);
echo "Summary: " . count($results) . " total, " . count($interesting) . " interesting (≥50), " . count($boring) . " low-surprise (<20)\n";
