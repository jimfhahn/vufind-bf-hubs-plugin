<?php

namespace BibframeHub\Related;

use BibframeHub\Connection\HubClient;
use BibframeHub\Graph\HubRdfParser;
use BibframeHub\Graph\Neo4jService;
use BibframeHub\Relationship\RelationshipInferrer;
use VuFind\Related\RelatedInterface;

class BibframeHub implements RelatedInterface
{
    protected HubClient $hubClient;
    protected Neo4jService $neo4j;
    protected HubRdfParser $rdfParser;
    protected RelationshipInferrer $scorer;

    /** @var array Scored results sorted by surprise score desc */
    protected array $results = [];
    protected ?string $hubUri = null;
    protected ?string $hubTitle = null;
    protected bool $resultsFromNeo4j = false;

    /** URI validation settings */
    protected bool $validateUris = true;
    protected int $validationCacheTtl = 86400; // 24 hours
    protected ?string $cachePath = null;
    protected int $maxDisplayResults = 15;

    /** Runtime cache of URI validation results */
    protected array $validationCache = [];

    public function __construct(
        HubClient $hubClient,
        Neo4jService $neo4j,
        HubRdfParser $rdfParser,
        RelationshipInferrer $scorer,
        array $config = []
    ) {
        $this->hubClient = $hubClient;
        $this->neo4j = $neo4j;
        $this->rdfParser = $rdfParser;
        $this->scorer = $scorer;

        $display = $config['Display'] ?? [];
        $this->validateUris = ($display['validateUris'] ?? true)
            && ($display['validateUris'] ?? 'true') !== 'false';
        $this->validationCacheTtl = (int)($display['validationCacheTtl'] ?? 86400);
        $this->maxDisplayResults = (int)($display['maxDisplayResults'] ?? 15);
        $this->cachePath = $display['validationCachePath'] ?? null;
    }

    /**
     * Establishes base settings and fetches surprise-scored BIBFRAME Hub relationships.
     *
     * @param string $settings Settings from config.ini (unused for now)
     * @param \VuFind\RecordDriver\AbstractBase $driver Record driver
     */
    public function init($settings, $driver): void
    {
        $marcFields = $this->extractMarcFields($driver);

        if (empty($marcFields['title']) && empty($marcFields['uniformTitle'])) {
            return;
        }

        // Step 1: Resolve Hub URI — try Neo4j first (bulk dataset),
        // then LC suggest2 API (current URIs)
        $this->hubUri = $this->resolveHubUri($marcFields);
        if (!$this->hubUri) {
            return;
        }

        // Pre-set hub title from the catalog record (better than Neo4j's first-found)
        $this->hubTitle = $marcFields['uniformTitle'] ?? $marcFields['title'] ?? null;

        // Save the original URI from resolution (likely Neo4j) for fallback
        $originalUri = $this->hubUri;

        // Step 2: Try live RDF from id.loc.gov first (current data, valid URIs),
        // fall back to Neo4j graph (stale but always available)
        $scored = $this->fetchAndScoreViaRdf($this->hubUri);

        // If RDF failed, try suggest2 for a current URI
        if (empty($scored)) {
            $currentUri = $this->resolveCurrentUri($marcFields);
            if ($currentUri && $currentUri !== $this->hubUri) {
                $this->hubUri = $currentUri;
                $scored = $this->fetchAndScoreViaRdf($this->hubUri);
            }
        }

        // Final fallback: Neo4j graph using the ORIGINAL URI
        // (suggest2 may have returned a different hub with no relationships)
        if (empty($scored)) {
            $scored = $this->fetchAndScoreViaNeo4j($originalUri);
            $this->resultsFromNeo4j = !empty($scored);
            if ($this->resultsFromNeo4j) {
                $this->hubUri = $originalUri;
            }
        }

        if (empty($scored)) {
            return;
        }

        // Step 3: Validate URIs (skip for Neo4j-sourced — bulk URIs are stale)
        if ($this->resultsFromNeo4j) {
            $this->results = array_slice($scored, 0, $this->maxDisplayResults);
        } else {
            $this->results = $this->validateDisplayedUris($scored);
        }
    }

    /**
     * Get scored results for the template (sorted by surprise score desc).
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * Get results grouped by relationship label, ordered by best score in each group.
     * Within each group, results are sorted by score desc.
     *
     * @return array<string, array{results: array, topScore: float, tier: int}>
     */
    public function getGroupedResults(): array
    {
        $groups = [];
        foreach ($this->results as $result) {
            $label = $result['label'] ?? 'Related Work';
            if (!isset($groups[$label])) {
                $groups[$label] = [
                    'results' => [],
                    'topScore' => 0,
                    'tier' => $result['breakdown']['tier'] ?? 3,
                ];
            }
            $groups[$label]['results'][] = $result;
            $groups[$label]['topScore'] = max(
                $groups[$label]['topScore'],
                $result['score']
            );
        }

        // Sort groups by their top score (most surprising categories first)
        uasort($groups, fn($a, $b) => $b['topScore'] <=> $a['topScore']);

        return $groups;
    }

    /**
     * Get the matched Hub URI.
     */
    public function getHubUri(): ?string
    {
        return $this->hubUri;
    }

    /**
     * Get the matched Hub title.
     */
    public function getHubTitle(): ?string
    {
        return $this->hubTitle;
    }

    /**
     * Whether results were sourced from Neo4j (URIs may be stale).
     */
    public function isNeo4jSourced(): bool
    {
        return $this->resultsFromNeo4j;
    }

    /**
     * Fetch relationships from id.loc.gov RDF and score them.
     * This gives us the most current data with valid URIs.
     *
     * @return array[] Scored results, empty if fetch/parse failed
     */
    protected function fetchAndScoreViaRdf(string $hubUri): array
    {
        $parsed = $this->rdfParser->fetchAndParse($hubUri);
        if (!$parsed || empty($parsed['relations'])) {
            return [];
        }

        $this->hubTitle = $parsed['hub']['title'] ?? $this->hubTitle;
        $sourceAgents = $parsed['hub']['agents'] ?? [];
        $sourceMedia = $parsed['hub']['media'] ?? [];

        // Convert RDF relations to the format scoreRelatedHubs expects
        $relatedHubs = [];
        foreach ($parsed['relations'] as $rel) {
            $relatedHubs[] = [
                'targetUri' => $rel['targetUri'],
                'relType' => $rel['relType'],
                'isDirect' => $rel['isDirect'] ?? false,
            ];
        }

        // Build title cache from RDF (avoids Neo4j lookups for known titles)
        $titleCache = [];
        foreach ($parsed['relations'] as $rel) {
            if (!empty($rel['title'])) {
                $titleCache[$rel['targetUri']] = $rel['title'];
            }
        }

        $frequencies = $this->neo4j->getRelationshipTypeFrequencies();

        return $this->scorer->scoreRelatedHubs(
            $sourceAgents,
            $sourceMedia,
            $relatedHubs,
            $frequencies,
            fn(string $uri) => $titleCache[$uri]
                ?? $this->neo4j->getHubTitle($uri),
            fn(string $uri) => $this->neo4j->getHubAgents($uri),
            fn(string $uri) => $this->neo4j->getHubMediaTypes($uri),
        );
    }

    /**
     * Fetch relationships from Neo4j graph and score them.
     * Fallback when id.loc.gov RDF is unavailable.
     *
     * @return array[] Scored results, empty if no relationships found
     */
    protected function fetchAndScoreViaNeo4j(string $hubUri): array
    {
        $this->hubTitle = $this->hubTitle ?? $this->neo4j->getHubTitle($hubUri);

        $relatedHubs = $this->neo4j->findRelatedHubs($hubUri);
        if (empty($relatedHubs)) {
            return [];
        }

        $sourceAgents = $this->neo4j->getHubAgents($hubUri);
        $sourceMedia = $this->neo4j->getHubMediaTypes($hubUri);
        $frequencies = $this->neo4j->getRelationshipTypeFrequencies();

        return $this->scorer->scoreRelatedHubs(
            $sourceAgents,
            $sourceMedia,
            $relatedHubs,
            $frequencies,
            fn(string $uri) => $this->neo4j->getHubTitle($uri),
            fn(string $uri) => $this->neo4j->getHubAgents($uri),
            fn(string $uri) => $this->neo4j->getHubMediaTypes($uri),
        );
    }

    /**
     * Resolve a MARC record to a BIBFRAME Hub URI.
     * Strategy: LCCN → title match in Neo4j → LC API fallback.
     */
    protected function resolveHubUri(array $marcFields): ?string
    {
        // Try LCCN first (most precise)
        if (!empty($marcFields['lccn'])) {
            $uri = $this->neo4j->findHubByLccn($marcFields['lccn']);
            if ($uri) {
                return $uri;
            }
        }

        // Try uniform title (more specific than title)
        $searchTitle = $marcFields['uniformTitle'] ?? $marcFields['title'] ?? null;
        if ($searchTitle) {
            $uri = $this->neo4j->findHubByTitle($searchTitle);
            if ($uri) {
                return $uri;
            }
        }

        // Fallback: LC suggest2 API (returns current URIs)
        return $this->resolveCurrentUri($marcFields);
    }

    /**
     * Try to resolve a current Hub URI via the LC suggest2 API.
     * Returns a URI that is known to exist on id.loc.gov right now.
     */
    protected function resolveCurrentUri(array $marcFields): ?string
    {
        $searchTitle = $marcFields['uniformTitle'] ?? $marcFields['title'] ?? null;
        if (!$searchTitle) {
            return null;
        }

        try {
            $lookup = $this->hubClient->findHubsForRecord($marcFields);
            if (!empty($lookup['hits'][0]['uri'])) {
                return $lookup['hits'][0]['uri'];
            }
        } catch (\Exception $e) {
            // LC API may be down — that's fine
        }

        return null;
    }

    /**
     * Extract MARC fields from the record driver using available methods.
     */
    protected function extractMarcFields($driver): array
    {
        $fields = [
            'author' => null,
            'title' => null,
            'uniformTitle' => null,
            'lccn' => null,
        ];

        if (method_exists($driver, 'getPrimaryAuthor')) {
            $fields['author'] = $driver->getPrimaryAuthor() ?: null;
        }

        if (method_exists($driver, 'getShortTitle')) {
            $fields['title'] = $driver->getShortTitle() ?: null;
        }
        if (!$fields['title'] && method_exists($driver, 'getTitle')) {
            $fields['title'] = $driver->getTitle() ?: null;
        }

        if (method_exists($driver, 'tryMethod')) {
            $marc130 = $driver->tryMethod('getMarcFieldData', ['130', ['a']]);
            $marc240 = $driver->tryMethod('getMarcFieldData', ['240', ['a']]);
            if (!empty($marc130)) {
                $fields['uniformTitle'] = is_array($marc130) ? $marc130[0] : $marc130;
            } elseif (!empty($marc240)) {
                $fields['uniformTitle'] = is_array($marc240) ? $marc240[0] : $marc240;
            }
        }

        if (method_exists($driver, 'getLCCN')) {
            $fields['lccn'] = $driver->getLCCN() ?: null;
        } elseif (method_exists($driver, 'tryMethod')) {
            $lccn = $driver->tryMethod('getMarcFieldData', ['010', ['a']]);
            if (!empty($lccn)) {
                $fields['lccn'] = is_array($lccn) ? $lccn[0] : $lccn;
            }
        }

        foreach ($fields as $key => $value) {
            if (is_string($value)) {
                $fields[$key] = rtrim($value, ' /;:,.');
            }
        }

        return $fields;
    }

    /**
     * Validate displayed results by checking that Hub URIs resolve on id.loc.gov.
     * Uses HEAD requests with a file-based cache to avoid repeated checks.
     *
     * @param array[] $results Scored results (already sorted)
     * @return array[] Results with dead URIs removed
     */
    protected function validateDisplayedUris(array $results): array
    {
        if (!$this->validateUris) {
            return $results;
        }

        $this->loadValidationCache();
        $validated = [];

        foreach ($results as $result) {
            $uri = $result['uri'] ?? '';
            if (empty($uri)) {
                continue;
            }

            if ($this->isHubUriValid($uri)) {
                $validated[] = $result;
            }

            // Stop once we have enough validated results
            if (count($validated) >= $this->maxDisplayResults) {
                break;
            }
        }

        $this->saveValidationCache();

        return $validated;
    }

    /**
     * Check whether a Hub URI resolves on id.loc.gov (HEAD request, cached).
     */
    protected function isHubUriValid(string $uri): bool
    {
        // Check runtime cache first
        if (isset($this->validationCache[$uri])) {
            $entry = $this->validationCache[$uri];
            if (time() - $entry['time'] < $this->validationCacheTtl) {
                return $entry['valid'];
            }
        }

        // HEAD request to id.loc.gov
        $valid = $this->headCheckUri($uri);

        $this->validationCache[$uri] = [
            'valid' => $valid,
            'time' => time(),
        ];

        return $valid;
    }

    /**
     * Perform a lightweight HEAD request to check if a URI resolves (2xx or 3xx).
     */
    protected function headCheckUri(string $uri): bool
    {
        $ch = curl_init($uri);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'User-Agent: VuFind-BibframeHub/1.0',
            ],
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 400;
    }

    /**
     * Load the validation cache from disk.
     */
    protected function loadValidationCache(): void
    {
        $path = $this->getValidationCachePath();
        if ($path && file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if (is_array($data)) {
                // Prune expired entries on load
                $now = time();
                $this->validationCache = array_filter(
                    $data,
                    fn($entry) => ($now - ($entry['time'] ?? 0)) < $this->validationCacheTtl
                );
            }
        }
    }

    /**
     * Save the validation cache to disk.
     */
    protected function saveValidationCache(): void
    {
        $path = $this->getValidationCachePath();
        if ($path) {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents(
                $path,
                json_encode($this->validationCache, JSON_PRETTY_PRINT),
                LOCK_EX
            );
        }
    }

    /**
     * Resolve the file path for the validation cache.
     */
    protected function getValidationCachePath(): ?string
    {
        if ($this->cachePath) {
            return $this->cachePath;
        }

        // Default: VuFind local cache directory
        $localDir = defined('LOCAL_OVERRIDE_DIR') ? LOCAL_OVERRIDE_DIR : null;
        if ($localDir) {
            return $localDir . '/cache/bibframehub_uri_validation.json';
        }

        return null;
    }
}
