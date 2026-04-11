<?php

namespace BibframeHub\Graph;

use Psr\Log\LoggerAwareInterface;
use VuFind\Log\LoggerAwareTrait;

/**
 * Queries the n10s-imported BIBFRAME Hubs graph in Neo4j.
 *
 * The graph was bulk-loaded via n10s from the LC hubs.bibframe.ttl download.
 * All data lives under n10s namespace-shortened labels: ns0__Hub, ns1__Relationship, etc.
 * This service is read-only — we never write to the graph.
 */
class Neo4jService implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected string $uri;
    protected string $username;
    protected string $password;
    protected string $database;
    protected bool $enabled;

    /** Cached relationship type frequencies (lazy-loaded). */
    protected ?array $frequencyCache = null;

    public function __construct(array $config = [])
    {
        $this->uri = $config['uri'] ?? 'bolt://localhost:7687';
        $this->username = $config['username'] ?? 'neo4j';
        $this->password = $config['password'] ?? '';
        $this->database = $config['database'] ?? 'neo4j';
        $this->enabled = !empty($config['enabled']);
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Find a Hub URI by matching title text using a full-text index.
     * Returns the Hub with the most outbound relationships (most connected = canonical).
     *
     * Requires: CREATE FULLTEXT INDEX hub_title_ft FOR (t:ns0__Title) ON EACH [t.ns0__mainTitle]
     */
    public function findHubByTitle(string $title): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            // Strip leading articles (standard library practice for title matching)
            $searchTitle = preg_replace('/^(the|a|an)\s+/i', '', trim($title));

            // Escape Lucene special characters and join with AND for phrase-like matching
            $words = preg_split('/\s+/', $searchTitle);
            $escaped = implode(' AND ', array_map([$this, 'escapeLucene'], $words));

            $rows = $this->runQuery(
                'CALL db.index.fulltext.queryNodes("hub_title_ft", $query)
                 YIELD node AS t, score
                 WHERE score > 5.0
                 MATCH (h:ns0__Hub)-[:ns0__title]->(t)
                 WITH DISTINCT h
                 LIMIT 20
                 OPTIONAL MATCH (h)-[r]-(other:ns0__Hub)
                 RETURN h.uri AS uri, count(r) AS rels
                 ORDER BY rels DESC
                 LIMIT 1',
                ['query' => $escaped]
            );
            return $rows[0]['uri'] ?? null;
        } catch (\Exception $e) {
            $this->logError('Title lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Escape Lucene special characters for safe full-text queries.
     */
    protected function escapeLucene(string $input): string
    {
        $special = ['\\', '+', '-', '!', '(', ')', '{', '}', '[', ']',
                     '^', '"', '~', '*', '?', ':', '/'];
        foreach ($special as $char) {
            $input = str_replace($char, '\\' . $char, $input);
        }
        return $input;
    }

    /**
     * Find a Hub URI by LCCN. Traverses ns0__identifiedBy to find matching rdf__value.
     */
    public function findHubByLccn(string $lccn): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        $normalized = preg_replace('/\s+/', '', $lccn);

        try {
            $rows = $this->runQuery(
                'MATCH (h:ns0__Hub)-[:ns0__identifiedBy]->(id)
                 WHERE replace(id.rdf__value, " ", "") = $lccn
                 RETURN h.uri LIMIT 1',
                ['lccn' => $normalized]
            );
            return $rows[0]['h.uri'] ?? null;
        } catch (\Exception $e) {
            $this->logError('LCCN lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get the main title for a Hub.
     */
    public function getHubTitle(string $hubUri): ?string
    {
        if (!$this->enabled) {
            return null;
        }

        try {
            $rows = $this->runQuery(
                'MATCH (h:ns0__Hub)-[:ns0__title]->(t)
                 WHERE h.uri = $uri
                 RETURN t.ns0__mainTitle[0] AS title
                 LIMIT 1',
                ['uri' => $hubUri]
            );
            return $rows[0]['title'] ?? null;
        } catch (\Exception $e) {
            $this->logError('Title fetch failed: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Get agent URIs for a Hub (via contribution → agent path).
     *
     * @return string[] Agent URIs
     */
    public function getHubAgents(string $hubUri): array
    {
        if (!$this->enabled) {
            return [];
        }

        try {
            $rows = $this->runQuery(
                'MATCH (h:ns0__Hub)-[:ns0__contribution]->(c)-[:ns0__agent]->(a)
                 WHERE h.uri = $uri
                 RETURN DISTINCT a.uri AS agentUri',
                ['uri' => $hubUri]
            );
            return array_column($rows, 'agentUri');
        } catch (\Exception $e) {
            $this->logError('Agent lookup failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get rdf:type URIs for a Hub (medium/genre: MovingImage, Audio, NotatedMusic, etc.).
     *
     * @return string[] Type URIs (excluding generic Hub/Work)
     */
    public function getHubMediaTypes(string $hubUri): array
    {
        if (!$this->enabled) {
            return [];
        }

        try {
            $rows = $this->runQuery(
                'MATCH (h:ns0__Hub)-[:rdf__type]->(t)
                 WHERE h.uri = $uri
                 RETURN t.uri AS typeUri',
                ['uri' => $hubUri]
            );
            return array_column($rows, 'typeUri');
        } catch (\Exception $e) {
            $this->logError('Media type lookup failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Get all related Hubs with relationship types.
     * Queries four patterns: direct outbound, direct inbound, typed outbound, typed inbound.
     *
     * @return array[] Each element: ['targetUri' => string, 'relType' => string, 'isDirect' => bool]
     */
    public function findRelatedHubs(string $hubUri): array
    {
        if (!$this->enabled) {
            return [];
        }

        $results = [];

        try {
            // 1. Direct outbound Hub→Hub edges (translationOf, relatedTo, arrangementOf)
            $outbound = $this->runQuery(
                'MATCH (h:ns0__Hub)-[r]->(target:ns0__Hub)
                 WHERE h.uri = $uri
                 RETURN type(r) AS edgeType, target.uri AS targetUri',
                ['uri' => $hubUri]
            );
            foreach ($outbound as $row) {
                $results[] = [
                    'targetUri' => $row['targetUri'],
                    'relType' => $row['edgeType'],
                    'isDirect' => true,
                ];
            }

            // 2. Direct inbound (skip relatedTo — already got those outbound)
            $inbound = $this->runQuery(
                'MATCH (source:ns0__Hub)-[r]->(h:ns0__Hub)
                 WHERE h.uri = $uri AND type(r) <> "ns0__relatedTo"
                 RETURN type(r) AS edgeType, source.uri AS sourceUri',
                ['uri' => $hubUri]
            );
            foreach ($inbound as $row) {
                $results[] = [
                    'targetUri' => $row['sourceUri'],
                    'relType' => $row['edgeType'] . '_INBOUND',
                    'isDirect' => true,
                ];
            }

            // 3. Typed outbound via bflc:relationship
            $typedOut = $this->runQuery(
                'MATCH (h:ns0__Hub)-[:ns1__relationship]->(rel:ns1__Relationship)-[:ns1__relation]->(rt)
                 WHERE h.uri = $uri AND NOT rt.uri STARTS WITH "bnode"
                 MATCH (rel)-[:ns0__relatedTo]->(target:ns0__Hub)
                 RETURN replace(rt.uri, "http://id.loc.gov/entities/relationships/", "") AS relType,
                        target.uri AS targetUri',
                ['uri' => $hubUri]
            );
            foreach ($typedOut as $row) {
                $results[] = [
                    'targetUri' => $row['targetUri'],
                    'relType' => $row['relType'],
                    'isDirect' => false,
                ];
            }

            // 4. Typed inbound
            $typedIn = $this->runQuery(
                'MATCH (source:ns0__Hub)-[:ns1__relationship]->(rel:ns1__Relationship)-[:ns1__relation]->(rt)
                 WHERE NOT rt.uri STARTS WITH "bnode"
                 MATCH (rel)-[:ns0__relatedTo]->(h:ns0__Hub)
                 WHERE h.uri = $uri
                 RETURN replace(rt.uri, "http://id.loc.gov/entities/relationships/", "") AS relType,
                        source.uri AS sourceUri',
                ['uri' => $hubUri]
            );
            foreach ($typedIn as $row) {
                $results[] = [
                    'targetUri' => $row['sourceUri'],
                    'relType' => $row['relType'] . '_INBOUND',
                    'isDirect' => false,
                ];
            }
        } catch (\Exception $e) {
            $this->logError('Related hubs query failed: ' . $e->getMessage());
            return [];
        }

        // Deduplicate by target URI — prefer typed (more informative) over direct
        $seen = [];
        foreach ($results as $r) {
            $key = $r['targetUri'];
            if (!isset($seen[$key]) || (!$r['isDirect'] && $seen[$key]['isDirect'])) {
                $seen[$key] = $r;
            }
        }

        return array_values($seen);
    }

    /**
     * Get frequency counts for all bflc:relationship types.
     * Cached for the lifetime of this service instance.
     *
     * @return array<string, int> Map of relationship type → frequency
     */
    public function getRelationshipTypeFrequencies(): array
    {
        if ($this->frequencyCache !== null) {
            return $this->frequencyCache;
        }

        if (!$this->enabled) {
            return [];
        }

        try {
            $rows = $this->runQuery(
                'MATCH (:ns0__Hub)-[:ns1__relationship]->(rel:ns1__Relationship)-[:ns1__relation]->(rt)
                 WHERE NOT rt.uri STARTS WITH "bnode"
                 RETURN replace(rt.uri, "http://id.loc.gov/entities/relationships/", "") AS relType,
                        count(*) AS freq',
                []
            );
            $this->frequencyCache = [];
            foreach ($rows as $row) {
                $this->frequencyCache[$row['relType']] = (int)$row['freq'];
            }
            return $this->frequencyCache;
        } catch (\Exception $e) {
            $this->logError('Frequency query failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Execute a Cypher query via the Neo4j HTTP API.
     */
    protected function runQuery(string $cypher, array $parameters): array
    {
        $httpUri = $this->getHttpUri();
        $url = $httpUri . '/db/' . $this->database . '/tx/commit';

        $payload = json_encode([
            'statements' => [
                [
                    'statement' => $cypher,
                    'parameters' => $parameters ?: new \stdClass(),
                ],
            ],
        ]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_USERPWD => $this->username . ':' . $this->password,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$body) {
            throw new \RuntimeException(
                "Neo4j query failed (HTTP $httpCode): " . ($body ?: 'no response')
            );
        }

        $result = json_decode($body, true);

        if (!empty($result['errors'])) {
            throw new \RuntimeException(
                'Neo4j error: ' . $result['errors'][0]['message']
            );
        }

        // Extract rows from the result
        $rows = [];
        foreach ($result['results'] ?? [] as $res) {
            $columns = $res['columns'] ?? [];
            foreach ($res['data'] ?? [] as $datum) {
                $row = [];
                foreach ($columns as $i => $col) {
                    $row[$col] = $datum['row'][$i] ?? null;
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Convert bolt:// URI to http:// for the HTTP API.
     */
    protected function getHttpUri(): string
    {
        $parsed = parse_url($this->uri);
        $host = $parsed['host'] ?? 'localhost';
        $port = $parsed['port'] ?? 7474;

        // Default Neo4j HTTP port is 7474, Bolt is 7687
        if ($port === 7687) {
            $port = 7474;
        }

        $scheme = ($parsed['scheme'] ?? '') === 'bolt+s' ? 'https' : 'http';
        return "{$scheme}://{$host}:{$port}";
    }
}
