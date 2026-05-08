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

    /**
     * Cache of Hub URIs whose live RDF was empty / slow / missing, so we skip
     * the (sometimes 10+s) id.loc.gov fetch on subsequent requests and go
     * straight to the Neo4j fallback.
     *
     * @var array<string, array{time:int}>
     */
    protected array $emptyRdfCache = [];
    protected int $emptyRdfCacheTtl = 86400;
    protected ?string $emptyRdfCachePath = null;

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
        $this->emptyRdfCacheTtl = (int)($display['emptyRdfCacheTtl'] ?? 86400);
        $this->emptyRdfCachePath = $display['emptyRdfCachePath'] ?? null;
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
        // fall back to Neo4j graph
        $scored = $this->fetchAndScoreViaRdf($this->hubUri);

        // If RDF failed, try suggest2 for a current URI
        if (empty($scored)) {
            $suggest2Hit = $this->resolveCurrentUriWithLabel($marcFields);
            $currentUri = $suggest2Hit['uri'] ?? null;
            if ($currentUri && $currentUri !== $this->hubUri) {
                $this->hubUri = $currentUri;
                $scored = $this->fetchAndScoreViaRdf($this->hubUri);
            }

            // If suggest2's hub had no relationships, it may be a qualified
            // edition (e.g. collected works). Derive the base work AAP and
            // try the label endpoint for the canonical work hub.
            if (empty($scored) && !empty($suggest2Hit['aLabel'])) {
                $baseWorkUri = $this->hubClient
                    ->resolveBaseWorkUri($suggest2Hit['aLabel']);
                if ($baseWorkUri
                    && $baseWorkUri !== $this->hubUri
                    && $baseWorkUri !== $originalUri
                ) {
                    $this->hubUri = $baseWorkUri;
                    $scored = $this->fetchAndScoreViaRdf($this->hubUri);
                }
            }
        }

        // Final fallback: Neo4j graph. Try every candidate URI we have
        // collected so far — the original (Neo4j title match) might be a
        // weak fulltext hit (e.g. "Hamlet" → "Hamlet versus Hamlet") while
        // the suggest2 URI is the canonical Hub. Conversely, a suggest2
        // hub may not exist in the local graph yet. Try both, prefer the
        // one that actually returns related Hubs.
        if (empty($scored)) {
            $candidates = array_values(array_unique(array_filter([
                $this->hubUri,
                $originalUri,
            ])));
            foreach ($candidates as $candidate) {
                $scored = $this->fetchAndScoreViaNeo4j($candidate);
                if (!empty($scored)) {
                    $this->resultsFromNeo4j = true;
                    $this->hubUri = $candidate;
                    break;
                }
            }
        }

        if (empty($scored)) {
            return;
        }

        // Step 3: Validate URIs against id.loc.gov.
        $this->results = $this->validateDisplayedUris($scored);

        // Always validate the primary Hub URI before linking to it. The
        // RDF fast-lane URIs can also drift, and reconciliation may not
        // have caught up — hard-validation is the final gate.
        if ($this->hubUri && $this->validateUris) {
            $this->loadValidationCache();
            if (!$this->isHubUriValid($this->hubUri)) {
                $this->hubUri = null;
            }
            $this->saveValidationCache();
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
        // Skip hubs we've recently confirmed have no scorable RDF relations —
        // id.loc.gov can take 10–30s to return a giant empty-relations hub,
        // and that outcome is stable for a given URI.
        $this->loadEmptyRdfCache();
        if (isset($this->emptyRdfCache[$hubUri])) {
            $entry = $this->emptyRdfCache[$hubUri];
            if (time() - ($entry['time'] ?? 0) < $this->emptyRdfCacheTtl) {
                return [];
            }
        }

        $parsed = $this->rdfParser->fetchAndParse($hubUri);
        if (!$parsed || empty($parsed['relations'])) {
            $this->emptyRdfCache[$hubUri] = ['time' => time()];
            $this->saveEmptyRdfCache();
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

        // Batch-fetch agent/media context for all targets in one Cypher query
        // rather than one round-trip per Hub during scoring.
        $targetUris = array_column($relatedHubs, 'targetUri');
        $bulk = $this->neo4j->getHubsBulk($targetUris);

        return $this->scorer->scoreRelatedHubs(
            $sourceAgents,
            $sourceMedia,
            $relatedHubs,
            $frequencies,
            fn(string $uri) => $titleCache[$uri]
                ?? ($bulk[$uri]['title'] ?? null),
            fn(string $uri) => $bulk[$uri]['agents'] ?? [],
            fn(string $uri) => $bulk[$uri]['media']  ?? [],
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

        // Batch-fetch title/agents/media for every related Hub in one Cypher
        // round-trip — avoids per-Hub round-trips inside the scoring loop.
        $bulk = $this->neo4j->getHubsBulk(array_column($relatedHubs, 'targetUri'));

        return $this->scorer->scoreRelatedHubs(
            $sourceAgents,
            $sourceMedia,
            $relatedHubs,
            $frequencies,
            fn(string $uri) => $bulk[$uri]['title']  ?? null,
            fn(string $uri) => $bulk[$uri]['agents'] ?? [],
            fn(string $uri) => $bulk[$uri]['media']  ?? [],
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
        return $this->resolveCurrentUriWithLabel($marcFields)['uri'] ?? null;
    }

    /**
     * Resolve a current Hub URI via suggest2, returning both the URI
     * and the AAP label (needed for base-work derivation when the
     * first hit turns out to be a qualified edition with no relationships).
     *
     * @return array{uri?: string, aLabel?: string}
     */
    protected function resolveCurrentUriWithLabel(array $marcFields): array
    {
        $searchTitle = $marcFields['uniformTitle'] ?? $marcFields['title'] ?? null;
        if (!$searchTitle) {
            return [];
        }

        try {
            $lookup = $this->hubClient->findHubsForRecord($marcFields);
            if (!empty($lookup['hits'][0]['uri'])) {
                return [
                    'uri' => $lookup['hits'][0]['uri'],
                    'aLabel' => $lookup['hits'][0]['aLabel'] ?? null,
                ];
            }
        } catch (\Exception $e) {
            // LC API may be down — that's fine
        }

        return [];
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

        // Cap how many we'll validate: enough headroom for misses but bounded.
        $cap = max(10, $this->maxDisplayResults * 3);
        $candidates = array_slice($results, 0, $cap);

        // Collect URIs that still need a live HEAD check.
        $toCheck = [];
        foreach ($candidates as $i => $result) {
            $uri = $result['uri'] ?? '';
            if ($uri === '') {
                continue;
            }
            if (isset($this->validationCache[$uri])) {
                $entry = $this->validationCache[$uri];
                if (time() - ($entry['time'] ?? 0) < $this->validationCacheTtl) {
                    continue;
                }
            }
            $toCheck[$uri] = true;
        }

        if (!empty($toCheck)) {
            $this->headCheckUrisParallel(array_keys($toCheck));
        }

        // Filter in order using the (now fully-populated) cache.
        $validated = [];
        foreach ($candidates as $result) {
            $uri = $result['uri'] ?? '';
            if ($uri === '') {
                continue;
            }
            $entry = $this->validationCache[$uri] ?? null;
            if ($entry && ($entry['valid'] ?? false)) {
                $validated[] = $result;
                if (count($validated) >= $this->maxDisplayResults) {
                    break;
                }
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
     * HEAD-check a batch of URIs in parallel via curl_multi and populate the
     * runtime validation cache. Follows up to 3 redirects per URI.
     *
     * Parallelism is bounded so we don't open dozens of connections to
     * id.loc.gov at once. 10 in flight is a reasonable sweet spot.
     *
     * @param string[] $uris
     */
    protected function headCheckUrisParallel(array $uris): void
    {
        $maxParallel = 10;
        $now = time();
        $chunks = array_chunk(array_values(array_unique($uris)), $maxParallel);

        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($chunk as $uri) {
                $ch = curl_init($uri);
                curl_setopt_array($ch, [
                    CURLOPT_NOBODY         => true,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                    CURLOPT_TIMEOUT        => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => [
                        'User-Agent: VuFind-BibframeHub/1.0',
                    ],
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$uri] = $ch;
            }

            $running = null;
            do {
                curl_multi_exec($mh, $running);
                if ($running) {
                    curl_multi_select($mh, 1.0);
                }
            } while ($running > 0);

            foreach ($handles as $uri => $ch) {
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $this->validationCache[$uri] = [
                    'valid' => $httpCode >= 200 && $httpCode < 400,
                    'time'  => $now,
                ];
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }
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

    /**
     * Path for the "empty RDF" negative cache.
     */
    protected function getEmptyRdfCachePath(): ?string
    {
        if ($this->emptyRdfCachePath) {
            return $this->emptyRdfCachePath;
        }
        $localDir = defined('LOCAL_OVERRIDE_DIR') ? LOCAL_OVERRIDE_DIR : null;
        return $localDir ? $localDir . '/cache/bibframehub_empty_rdf.json' : null;
    }

    protected bool $emptyRdfCacheLoaded = false;

    protected function loadEmptyRdfCache(): void
    {
        if ($this->emptyRdfCacheLoaded) {
            return;
        }
        $this->emptyRdfCacheLoaded = true;
        $path = $this->getEmptyRdfCachePath();
        if ($path && file_exists($path)) {
            $data = json_decode(file_get_contents($path), true);
            if (is_array($data)) {
                $now = time();
                $this->emptyRdfCache = array_filter(
                    $data,
                    fn($entry) => ($now - ($entry['time'] ?? 0)) < $this->emptyRdfCacheTtl
                );
            }
        }
    }

    protected function saveEmptyRdfCache(): void
    {
        $path = $this->getEmptyRdfCachePath();
        if (!$path) {
            return;
        }
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        @file_put_contents(
            $path,
            json_encode($this->emptyRdfCache),
            LOCK_EX
        );
    }
}
