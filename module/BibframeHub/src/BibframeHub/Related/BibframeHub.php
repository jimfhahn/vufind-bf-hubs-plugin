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

        // Step 1: Resolve Hub URI
        // Modern MARC fast lane: use Hub URI from MARC 758/130/240 $0
        // when available, skipping the Neo4j/suggest2 resolution cascade.
        if (!empty($marcFields['hubUri'])) {
            $this->hubUri = $marcFields['hubUri'];
        } else {
            // Legacy resolution: LCCN → Neo4j title → LC suggest2
            $this->hubUri = $this->resolveHubUri($marcFields);
        }
        if (!$this->hubUri) {
            return;
        }

        // Pre-set hub title from the catalog record (better than Neo4j's first-found)
        $this->hubTitle = $marcFields['uniformTitle'] ?? $marcFields['title'] ?? null;

        // Save the original URI from resolution for fallback
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
     *
     * Modern MARC records may include Hub URIs directly in $0 subfields
     * of 130/240 (uniform title) or 758 (resource identifier) fields,
     * enabling fast Hub resolution without Neo4j or suggest2 lookups.
     */
    protected function extractMarcFields($driver): array
    {
        $fields = [
            'author' => null,
            'title' => null,
            'uniformTitle' => null,
            'lccn' => null,
            'hubUri' => null,
            'marc758Relations' => [],
        ];

        // High-level methods (work on all drivers)
        if (method_exists($driver, 'getPrimaryAuthor')) {
            $fields['author'] = $driver->getPrimaryAuthor() ?: null;
        }

        if (method_exists($driver, 'getShortTitle')) {
            $fields['title'] = $driver->getShortTitle() ?: null;
        }
        if (!$fields['title'] && method_exists($driver, 'getTitle')) {
            $fields['title'] = $driver->getTitle() ?: null;
        }

        if (method_exists($driver, 'getLCCN')) {
            $fields['lccn'] = $driver->getLCCN() ?: null;
        }

        // MarcReader-based extraction (SolrMarc drivers with MARC data)
        $marcReader = method_exists($driver, 'tryMethod')
            ? $driver->tryMethod('getMarcReader')
            : null;

        if ($marcReader) {
            // Uniform title from 130/240 $a
            if (!$fields['uniformTitle']) {
                $fields['uniformTitle'] =
                    $this->getFirstMarcSubfield($marcReader, '130', 'a')
                    ?? $this->getFirstMarcSubfield($marcReader, '240', 'a');
            }

            // LCCN fallback from MARC 010 $a
            if (!$fields['lccn']) {
                $fields['lccn'] = $this->getFirstMarcSubfield(
                    $marcReader, '010', 'a'
                );
            }

            // --- Modern MARC Fast Lane ---

            // Hub URI from 130/240 $0 or $1 (uniform title with Hub identifier)
            // e.g. 240 10 $aPalinuro de México. $lEnglish $1http://id.loc.gov/resources/hubs/...
            $fields['hubUri'] = $this->getFirstHubUriFromField($marcReader, '130')
                ?? $this->getFirstHubUriFromField($marcReader, '240');

            // 758 Resource Identifier fields
            $fields['marc758Relations'] = $this->extract758Relations($marcReader);

            // Self-hub URI from 758 if not found in 130/240
            if (!$fields['hubUri']) {
                foreach ($fields['marc758Relations'] as $rel) {
                    if ($rel['isSelfHub'] && !empty($rel['hubUri'])) {
                        $fields['hubUri'] = $rel['hubUri'];
                        break;
                    }
                }
            }
        }

        // Clean trailing punctuation on string fields
        foreach ($fields as $key => $value) {
            if (is_string($value)) {
                $fields[$key] = rtrim($value, ' /;:,.');
            }
        }

        return $fields;
    }

    // ── Modern MARC Helper Methods ──────────────────────────────────

    /**
     * Get the first value of a specific subfield from a MARC field.
     */
    protected function getFirstMarcSubfield($marcReader, string $tag, string $code): ?string
    {
        $fields = $marcReader->getFields($tag, [$code]);
        foreach ($fields as $field) {
            foreach ($field['subfields'] ?? [] as $sf) {
                if ($sf['code'] === $code && !empty($sf['data'])) {
                    return trim($sf['data']);
                }
            }
        }
        return null;
    }

    /**
     * Find the first Hub resource URI in $0 or $1 subfields of a MARC field.
     *
     * Modern MARC uses $1 (Real World Object URI) for Hub URIs in 240/130:
     *   240 10 $aPalinuro de México. $lEnglish $1http://id.loc.gov/resources/hubs/...
     */
    protected function getFirstHubUriFromField($marcReader, string $tag): ?string
    {
        $fields = $marcReader->getFields($tag, ['0', '1']);
        foreach ($fields as $field) {
            foreach ($field['subfields'] ?? [] as $sf) {
                if (in_array($sf['code'], ['0', '1'], true)
                    && $this->isHubResourceUri($sf['data'])
                ) {
                    return $sf['data'];
                }
            }
        }
        return null;
    }

    /**
     * Check if a URI points to an id.loc.gov Hub resource.
     */
    protected function isHubResourceUri(string $uri): bool
    {
        return (bool)preg_match('#https?://id\.loc\.gov/resources/hubs/#', $uri);
    }

    /**
     * Extract relationship data from MARC 758 fields (Modern MARC).
     *
     * Returns array of:
     *   'hubUri'    => Hub URI found in $0 or $1 (if it's a Hub resource)
     *   'authUri'   => Authority URI from $0 (may not be a Hub URI)
     *   'label'     => Human-readable label from $a
     *   'relLabel'  => Relationship label from $i (e.g., "Parody of (work)")
     *   'relUri'    => RDA relationship URI from $4
     *   'isSelfHub' => Whether this identifies the work's own Hub
     *
     * @return array[]
     */
    protected function extract758Relations($marcReader): array
    {
        $relations = [];
        $fields = $marcReader->getFields('758');

        foreach ($fields as $field) {
            $subfields = [];
            foreach ($field['subfields'] ?? [] as $sf) {
                $subfields[$sf['code']][] = $sf['data'];
            }

            $label = $subfields['a'][0] ?? null;
            $relLabel = $subfields['i'][0] ?? null;
            $authUri = $subfields['0'][0] ?? null;
            $relUri = $subfields['4'][0] ?? null;

            // Find a Hub URI in either $0 or $1
            $hubUri = null;
            $candidates = array_merge($subfields['0'] ?? [], $subfields['1'] ?? []);
            foreach ($candidates as $uri) {
                if ($this->isHubResourceUri($uri)) {
                    $hubUri = $uri;
                    break;
                }
            }

            // Detect self-hub: "Has work manifested" or "Expression of"
            $isSelfHub = $relLabel && (
                stripos($relLabel, 'has work manifested') !== false
                || stripos($relLabel, 'expression of') !== false
                || stripos($relLabel, 'has expression manifested') !== false
            );

            if ($hubUri || $relLabel) {
                $relations[] = [
                    'hubUri'    => $hubUri,
                    'authUri'   => $authUri,
                    'label'     => $label ? rtrim($label, ' /;:,.') : null,
                    'relLabel'  => $relLabel ? rtrim($relLabel, ' :') : null,
                    'relUri'    => $relUri,
                    'isSelfHub' => $isSelfHub,
                ];
            }
        }

        return $relations;
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
