<?php

namespace BibframeHub\Connection;

use Laminas\Http\Client as HttpClient;
use Psr\Log\LoggerAwareInterface;
use VuFind\Log\LoggerAwareTrait;

class HubClient implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    protected HttpClient $httpClient;
    protected string $baseUrl = 'https://id.loc.gov';
    protected string $userAgent = 'VuFind-BibframeHub/1.0';
    protected int $timeout = 10;
    protected int $maxResults = 20;

    public function __construct(HttpClient $httpClient, array $config = [])
    {
        $this->httpClient = $httpClient;
        if (isset($config['baseUrl'])) {
            $this->baseUrl = rtrim($config['baseUrl'], '/');
        }
        if (isset($config['userAgent'])) {
            $this->userAgent = $config['userAgent'];
        }
        if (isset($config['timeout'])) {
            $this->timeout = (int)$config['timeout'];
        }
        if (isset($config['maxResults'])) {
            $this->maxResults = (int)$config['maxResults'];
        }
    }

    /**
     * Find Hub(s) for a record using MARC field fallback cascade.
     *
     * Priority:
     *   1. Author + Uniform Title (100+240) via label endpoint
     *   2. Uniform Title alone (130) via label endpoint
     *   3. Author + Title Proper (100+245) via suggest2 keyword
     *   4. Title Proper alone (245) via suggest2 keyword
     *   5. LCCN (010) used as post-filter verification
     */
    public function findHubsForRecord(array $marcFields): array
    {
        $lccn = $marcFields['lccn'] ?? null;

        // Strategy 1: Author + Uniform Title (most precise)
        if (!empty($marcFields['author']) && !empty($marcFields['uniformTitle'])) {
            $aap = $marcFields['author'] . '. ' . $marcFields['uniformTitle'];
            $result = $this->lookupByLabel($aap);
            if ($result) {
                return $this->buildResponse($result, 'label:author+uniformTitle', $lccn);
            }
        }

        // Strategy 2: Uniform Title alone
        if (!empty($marcFields['uniformTitle'])) {
            $result = $this->lookupByLabel($marcFields['uniformTitle']);
            if ($result) {
                return $this->buildResponse($result, 'label:uniformTitle', $lccn);
            }
        }

        // Strategy 3: Author + Title Proper via suggest2 keyword
        if (!empty($marcFields['author']) && !empty($marcFields['title'])) {
            $query = $marcFields['author'] . '. ' . $marcFields['title'];
            $results = $this->searchSuggest2($query, 'keyword');
            if (!empty($results)) {
                return $this->buildResponse($results, 'suggest2:author+title', $lccn);
            }
        }

        // Strategy 4: Title Proper alone
        if (!empty($marcFields['title'])) {
            $results = $this->searchSuggest2($marcFields['title'], 'keyword');
            if (!empty($results)) {
                return $this->buildResponse($results, 'suggest2:title', $lccn);
            }
        }

        return ['hits' => [], 'strategy' => 'none', 'verified' => false];
    }

    /**
     * Label endpoint — returns exact match via 302 redirect.
     */
    public function lookupByLabel(string $label): ?array
    {
        $url = $this->baseUrl . '/resources/hubs/label/'
            . rawurlencode($label);

        try {
            $this->httpClient->reset();
            $this->httpClient->setUri($url);
            $this->httpClient->setOptions([
                'timeout' => $this->timeout,
                'maxredirects' => 0,
            ]);
            $this->httpClient->setHeaders([
                'User-Agent' => $this->userAgent,
            ]);

            $response = $this->httpClient->send();
            $status = $response->getStatusCode();

            if ($status === 302) {
                $hubUri = $response->getHeaders()->get('Location')->getFieldValue();
                $token = basename($hubUri);
                // Search suggest2 using the original label to get full details,
                // but always preserve the URI from the redirect (suggest2's
                // first hit may be a different hub with the same keywords).
                $details = $this->searchSuggest2($label, 'keyword', 1);
                if (!empty($details)) {
                    $details[0]['uri'] = $hubUri;
                    $details[0]['token'] = $token;
                    return $details[0];
                }
                // Fallback: return minimal info from redirect
                return [
                    'uri' => $hubUri,
                    'aLabel' => $label,
                    'token' => $token,
                    'more' => [],
                ];
            }
        } catch (\Exception $e) {
            $this->logError('Label lookup failed for "' . $label . '": ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Suggest2 search — keyword or left-anchored.
     */
    public function searchSuggest2(
        string $query,
        string $searchType = 'keyword',
        ?int $count = null
    ): array {
        $count = $count ?? $this->maxResults;
        $url = $this->baseUrl . '/resources/hubs/suggest2?'
            . http_build_query([
                'q' => $query,
                'searchtype' => $searchType,
                'count' => $count,
            ]);

        try {
            $this->httpClient->reset();
            $this->httpClient->setUri($url);
            $this->httpClient->setOptions([
                'timeout' => $this->timeout,
            ]);
            $this->httpClient->setHeaders([
                'User-Agent' => $this->userAgent,
                'Accept' => 'application/json',
            ]);

            $response = $this->httpClient->send();

            if ($response->isSuccess()) {
                $data = json_decode($response->getBody(), true);
                return $data['hits'] ?? [];
            }
        } catch (\Exception $e) {
            $this->logError('Suggest2 search failed for "' . $query . '": ' . $e->getMessage());
        }

        return [];
    }

    /**
     * Fetch related Hubs for a known Hub URI/label.
     * Searches for sibling Hubs sharing the same base work.
     */
    public function findRelatedHubs(string $hubLabel): array
    {
        // Strip language/form qualifiers to find the base work
        $baseLabel = $this->extractBaseLabel($hubLabel);
        if ($baseLabel === $hubLabel) {
            // Already a base work — search for derived hubs
            return $this->searchSuggest2($baseLabel, 'keyword', $this->maxResults);
        }

        // Search for the base work and all its variants
        return $this->searchSuggest2($baseLabel, 'keyword', $this->maxResults);
    }

    /**
     * Extract base label from a qualified AAP.
     * "Shakespeare, William, 1564-1616. Hamlet. Russian" => "Shakespeare, William, 1564-1616. Hamlet"
     */
    protected function extractBaseLabel(string $label): string
    {
        // Known trailing qualifiers that indicate a derived Hub
        $qualifierPatterns = [
            '/\.\s+(Selections|Libretto|Vocal score|Piano score)(\.|$)/i',
            '/\.\s+(Act\s+\d+.*?)$/i',
            '/\.\s+[A-Z][a-z]+(\s+&\s+[A-Z][a-z]+)?$/u', // Language qualifiers
            '/\s+\([^)]+\)$/',  // Parenthetical qualifiers like (Motion picture)
        ];

        foreach ($qualifierPatterns as $pattern) {
            $stripped = preg_replace($pattern, '', $label);
            if ($stripped !== $label && !empty($stripped)) {
                return $stripped;
            }
        }

        return $label;
    }

    /**
     * Build a normalized response, optionally verifying against LCCN.
     */
    protected function buildResponse(
        $hits,
        string $strategy,
        ?string $lccn = null
    ): array {
        if (!is_array($hits) || (isset($hits['uri']))) {
            $hits = [$hits];
        }

        $verified = false;
        if ($lccn) {
            foreach ($hits as $hit) {
                $identifiers = $hit['more']['identifiers'] ?? [];
                foreach ($identifiers as $id) {
                    $normalizedId = preg_replace('/\s+/', '', $id);
                    $normalizedLccn = preg_replace('/\s+/', '', $lccn);
                    if ($normalizedId === $normalizedLccn) {
                        $verified = true;
                        break 2;
                    }
                }
            }
        }

        return [
            'hits' => $hits,
            'strategy' => $strategy,
            'verified' => $verified,
        ];
    }

    /**
     * Given an AAP label from suggest2, derive the canonical base work hub URI.
     *
     * Strips known qualifiers (language, form, parenthetical) from the AAP
     * and looks up the base label via the label endpoint.
     * E.g. "Fitzgerald, ... Great Gatsby (collected works edition)" → "Fitzgerald, ... Great Gatsby"
     *
     * @return string|null Hub URI if the base work exists, null otherwise
     */
    public function resolveBaseWorkUri(string $aapLabel): ?string
    {
        $baseLabel = $this->extractBaseLabel($aapLabel);
        if ($baseLabel === $aapLabel) {
            return null; // Already a base label — no stripping happened
        }

        $result = $this->lookupByLabel($baseLabel);
        return $result['uri'] ?? null;
    }

    protected function logError(string $message): void
    {
        if ($this->logger) {
            $this->logger->err('BibframeHub: ' . $message);
        }
    }
}
