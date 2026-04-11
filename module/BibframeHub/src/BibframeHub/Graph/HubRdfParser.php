<?php

namespace BibframeHub\Graph;

use Psr\Log\LoggerAwareInterface;
use VuFind\Log\LoggerAwareTrait;

/**
 * Fetches and parses RDF/XML from id.loc.gov for a single BIBFRAME Hub.
 *
 * The live RDF from id.loc.gov contains richer relationship data than the
 * bulk TTL dataset: typed relationships with both URI-based types and inline
 * labels like "Dramatized as (work)" and "Parodied as (work)".
 *
 * This service is used to augment or replace Neo4j relationship data when
 * the Hub URI resolves on id.loc.gov.
 */
class HubRdfParser implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected string $baseUrl;
    protected string $userAgent;
    protected int $timeout;

    /** Map inline RDF labels to normalized relationship type slugs. */
    private const INLINE_LABEL_MAP = [
        'Dramatized as (work)'                => 'dramatizationof',
        'Dramatized as (expression)'           => 'dramatizationof',
        'Adapted as (work)'                    => 'adaptedas',
        'Adapted as (expression)'              => 'adaptedas',
        'Adapted as motion picture (work)'     => 'adaptedasmotionpicture',
        'Adapted as television program (work)' => 'adaptedastelevisionprogram',
        'Parodied as (work)'                   => 'parodyof',
        'Parodied as (expression)'             => 'parodyof',
        'Musical setting of (work)'            => 'musicalsettingof',
        'Musical setting of (expression)'      => 'musicalsettingof',
        'Continued by (work)'                  => 'continuedby',
        'Continued by (expression)'            => 'continuedby',
        'Sequel (work)'                        => 'sequel',
        'Sequel (expression)'                  => 'sequel',
        'Supplement to (work)'                 => 'supplementto',
        'Supplement to (expression)'           => 'supplementto',
        'Based on (work)'                      => 'basedon',
        'Based on (expression)'                => 'basedon',
        'Abridged as (work)'                   => 'abridgedas',
        'Abridged as (expression)'             => 'abridgedas',
        'Expanded as (work)'                   => 'expandedas',
        'Expanded as (expression)'             => 'expandedas',
        'Revised as (work)'                    => 'revisedas',
        'Revised as (expression)'              => 'revisedas',
        'Absorbed by (work)'                   => 'absorbedby',
        'Merged to form (work)'                => 'mergedtoform',
        'Replaced by (work)'                   => 'replacedby',
        'Preceded by (work)'                   => 'precededby',
        'Succeeded by (work)'                  => 'succeededby',
        'Libretto based on (work)'             => 'librettobasedon',
        'Graphic novelization of (work)'       => 'graphicnovelizationof',
        'Novelization of (work)'               => 'novelizationof',
        'Opera adaptation of (work)'           => 'operaadaptationof',
        'Variations based on (work)'           => 'variationsbasedon',
        'Remake of (work)'                     => 'remakeof',
        'Inspiration for (work)'               => 'inspirationfor',
        'Screenplay based on (work)'           => 'motionpicturescreenplaybasedon',
    ];

    /** Map URI-based relationship types to normalized slugs. */
    private const URI_TYPE_MAP = [
        'http://id.loc.gov/entities/relationships/' => '', // strip prefix
        'http://id.loc.gov/vocabulary/relationship/relatedwork' => 'relatedwork',
        'http://id.loc.gov/vocabulary/relationship/translatedas' => 'translatedas',
        'http://id.loc.gov/vocabulary/relationship/partof' => 'partof',
        'http://id.loc.gov/vocabulary/relationship/expressionof' => 'expressionof',
    ];

    public function __construct(array $config = [])
    {
        $this->baseUrl = rtrim($config['baseUrl'] ?? 'https://id.loc.gov', '/');
        $this->userAgent = $config['userAgent'] ?? 'VuFind-BibframeHub/1.0';
        $this->timeout = (int)($config['timeout'] ?? 10);
    }

    /**
     * Fetch and parse a Hub's RDF/XML from id.loc.gov.
     *
     * Returns an array with:
     *   'hub'       => ['uri' => string, 'title' => string, 'agents' => [...], 'media' => [...]]
     *   'relations' => [['targetUri' => string, 'relType' => string, 'title' => string, ...], ...]
     *
     * @return array|null Parsed hub data, or null if fetch/parse failed
     */
    public function fetchAndParse(string $hubUri): ?array
    {
        $rdfUrl = $this->resolveRdfUrl($hubUri);
        $xml = $this->fetchRdf($rdfUrl);
        if (!$xml) {
            return null;
        }

        return $this->parseRdf($xml, $hubUri);
    }

    /**
     * Parse RDF/XML string into structured hub + relationship data.
     */
    public function parseRdf(string $xml, string $sourceUri): ?array
    {
        $rdf = 'http://www.w3.org/1999/02/22-rdf-syntax-ns#';
        $bf = 'http://id.loc.gov/ontologies/bibframe/';
        $bflc = 'http://id.loc.gov/ontologies/bflc/';
        $rdfs = 'http://www.w3.org/2000/01/rdf-schema#';

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        if (!$dom->loadXML($xml)) {
            $this->logError('Failed to parse RDF/XML for ' . $sourceUri);
            return null;
        }
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $xpath->registerNamespace('rdf', $rdf);
        $xpath->registerNamespace('bf', $bf);
        $xpath->registerNamespace('bflc', $bflc);
        $xpath->registerNamespace('rdfs', $rdfs);

        // --- Parse source hub metadata ---
        $workNode = $xpath->query("//bf:Work[@rdf:about='$sourceUri']")->item(0);
        if (!$workNode) {
            // Try without full URI (some RDF uses relative)
            $workNode = $xpath->query('//bf:Work')->item(0);
        }

        $hub = [
            'uri' => $sourceUri,
            'title' => $this->extractTitle($xpath, $workNode),
            'agents' => $this->extractAgents($xpath, $workNode),
            'media' => $this->extractMediaTypes($xpath, $workNode),
        ];

        // --- Parse relationships ---
        $relations = [];
        $relationNodes = $xpath->query('.//bf:relation', $workNode);

        foreach ($relationNodes as $relNode) {
            $parsed = $this->parseRelation($xpath, $relNode);
            if ($parsed) {
                $relations[] = $parsed;
            }
        }

        // Also parse direct bf:translationOf, bf:relatedTo, bf:arrangementOf
        foreach (['translationOf', 'relatedTo', 'arrangementOf'] as $directPred) {
            $directNodes = $xpath->query(".//bf:{$directPred}", $workNode);
            foreach ($directNodes as $dNode) {
                $targetUri = $this->getAboutUri($dNode, $xpath);
                if ($targetUri) {
                    $relations[] = [
                        'targetUri' => $targetUri,
                        'relType' => strtolower($directPred),
                        'title' => $this->extractTitle($xpath, $dNode),
                        'isDirect' => true,
                    ];
                }
            }
        }

        return [
            'hub' => $hub,
            'relations' => $this->deduplicateRelations($relations),
        ];
    }

    /**
     * Parse a single bf:relation element into a relationship record.
     */
    protected function parseRelation(\DOMXPath $xpath, \DOMElement $relNode): ?array
    {
        // Find target hub URI
        $hubNodes = $xpath->query('.//bf:Hub[@rdf:about]', $relNode);
        $targetUri = null;
        $title = null;

        foreach ($hubNodes as $hubNode) {
            $uri = $hubNode->getAttributeNS(
                'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
                'about'
            );
            if ($uri && strpos($uri, '/resources/hubs/') !== false) {
                $targetUri = $uri;
                $title = $this->extractTitle($xpath, $hubNode);
                break;
            }
        }

        if (!$targetUri) {
            return null;
        }

        // Determine relationship type — check both URI-based and inline labels
        $relType = $this->extractRelationType($xpath, $relNode);

        return [
            'targetUri' => $targetUri,
            'relType' => $relType,
            'title' => $title,
            'isDirect' => false,
        ];
    }

    /**
     * Extract the most specific relationship type from a bf:relation element.
     * Prefers inline labels and specific entity URIs over generic "relatedwork".
     */
    protected function extractRelationType(\DOMXPath $xpath, \DOMElement $relNode): string
    {
        $types = [];

        // Find all bf:Relationship elements
        $rshipNodes = $xpath->query('.//bf:Relationship', $relNode);
        foreach ($rshipNodes as $rshipNode) {
            $about = $rshipNode->getAttributeNS(
                'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
                'about'
            );
            $label = trim($xpath->evaluate('string(rdfs:label)', $rshipNode));

            // URI-based type from entities/relationships/
            if ($about && strpos($about, '/entities/relationships/') !== false) {
                $slug = basename(parse_url($about, PHP_URL_PATH));
                return strtolower($slug);
            }

            // Inline label (e.g., "Dramatized as (work)")
            if ($label && $label !== 'related work') {
                $mapped = self::INLINE_LABEL_MAP[$label] ?? null;
                if ($mapped) {
                    return $mapped;
                }
                // Fallback: normalize the label itself
                $normalized = strtolower(preg_replace(
                    '/[\s\(\)]+/',
                    '',
                    preg_replace('/\s*\((?:work|expression)\)\s*$/', '', $label)
                ));
                if ($normalized) {
                    $types[] = $normalized;
                }
            }

            // Generic vocabulary/relationship/ URI
            if ($about && isset(self::URI_TYPE_MAP[$about])) {
                $types[] = self::URI_TYPE_MAP[$about];
            }
        }

        // Return most specific type found
        foreach ($types as $t) {
            if ($t && $t !== 'relatedwork') {
                return $t;
            }
        }

        return $types[0] ?? 'relatedwork';
    }

    /**
     * Extract the main title from a node's bf:title/bf:Title/bf:mainTitle.
     */
    protected function extractTitle(\DOMXPath $xpath, ?\DOMElement $node): ?string
    {
        if (!$node) {
            return null;
        }

        $titleNode = $xpath->query('.//bf:title/bf:Title/bf:mainTitle', $node)->item(0);
        if ($titleNode) {
            return trim($titleNode->textContent);
        }

        // Fallback: variant title
        $varNode = $xpath->query('.//bf:title/bf:VariantTitle/bf:mainTitle', $node)->item(0);
        return $varNode ? trim($varNode->textContent) : null;
    }

    /**
     * Extract agent URIs from a node's direct bf:contribution children only.
     * Avoids picking up agents from nested related hubs.
     *
     * @return string[]
     */
    protected function extractAgents(\DOMXPath $xpath, ?\DOMElement $node): array
    {
        if (!$node) {
            return [];
        }

        $agents = [];
        // Only look at direct child bf:contribution elements, not nested ones
        $contribNodes = $xpath->query('./bf:contribution', $node);
        foreach ($contribNodes as $contribNode) {
            $agentNodes = $xpath->query('.//bf:agent/bf:Agent[@rdf:about]', $contribNode);
            foreach ($agentNodes as $agentNode) {
                $uri = $agentNode->getAttributeNS(
                    'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
                    'about'
                );
                if ($uri) {
                    $agents[] = $uri;
                }
            }
        }

        return array_unique($agents);
    }

    /**
     * Extract media type indicators from direct rdf:type resources only.
     *
     * @return string[]
     */
    protected function extractMediaTypes(\DOMXPath $xpath, ?\DOMElement $node): array
    {
        if (!$node) {
            return [];
        }

        $types = [];
        // Only direct child rdf:type, not from nested related hubs
        $typeNodes = $xpath->query('./rdf:type[@rdf:resource]', $node);
        foreach ($typeNodes as $typeNode) {
            $uri = $typeNode->getAttribute('rdf:resource');
            if ($uri) {
                $types[] = $uri;
            }
        }

        return array_unique($types);
    }

    /**
     * Get rdf:about URI from a node or its first child with one.
     */
    protected function getAboutUri(\DOMElement $node, \DOMXPath $xpath): ?string
    {
        $uri = $node->getAttributeNS(
            'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
            'about'
        );
        if ($uri) {
            return $uri;
        }

        // Check rdf:resource attribute
        $resource = $node->getAttribute('rdf:resource');
        if ($resource) {
            return $resource;
        }

        // Check child bf:Hub
        $hubNode = $xpath->query('.//bf:Hub[@rdf:about]', $node)->item(0);
        if ($hubNode) {
            return $hubNode->getAttributeNS(
                'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
                'about'
            );
        }

        return null;
    }

    /**
     * Deduplicate relations by target URI, keeping the most specific type.
     */
    protected function deduplicateRelations(array $relations): array
    {
        $seen = [];
        foreach ($relations as $r) {
            $key = $r['targetUri'];
            if (!isset($seen[$key])) {
                $seen[$key] = $r;
            } elseif ($r['relType'] !== 'relatedwork' && $seen[$key]['relType'] === 'relatedwork') {
                // Keep the more specific type
                $seen[$key] = $r;
            }
        }
        return array_values($seen);
    }

    /**
     * Convert a Hub URI to an RDF fetch URL.
     */
    protected function resolveRdfUrl(string $hubUri): string
    {
        // Ensure it ends with .rdf and uses https
        $url = preg_replace('#^http://#', 'https://', $hubUri);
        $url = rtrim($url, '/');
        if (!str_ends_with($url, '.rdf')) {
            $url .= '.rdf';
        }
        return $url;
    }

    /**
     * Fetch RDF/XML from a URL.
     */
    protected function fetchRdf(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . $this->userAgent,
                'Accept: application/rdf+xml',
            ],
        ]);

        $body = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($httpCode !== 200 || !$body) {
            $this->logError("Hub RDF fetch failed (HTTP $httpCode) for $url");
            return null;
        }

        // Verify we got XML, not an HTML error page
        if (stripos($contentType, 'html') !== false || str_starts_with(trim($body), '<!DOCTYPE')) {
            $this->logError("Hub RDF fetch returned HTML instead of RDF for $url");
            return null;
        }

        return $body;
    }
}
