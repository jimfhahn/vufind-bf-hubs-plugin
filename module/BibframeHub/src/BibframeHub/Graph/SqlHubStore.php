<?php

namespace BibframeHub\Graph;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerAwareInterface;
use VuFind\Log\LoggerAwareTrait;

/**
 * HubStoreInterface backed by three tables in VuFind's own database, loaded
 * from the Hugging Face Parquet export (see bibframehub/load-hubs and
 * tools/hf-dataset/export_sql_tsv.py).
 *
 *   bibframehub_hub      (hub_id, title, marc_key, lccn, media, degree)  FULLTEXT(title)
 *   bibframehub_agent    (hub_id, agent_uri)
 *   bibframehub_relation (source_id, target_id, rel_type)
 *
 * hub_id is the bare UUID; media is a comma-separated list of bibframe class
 * local names (MovingImage, Audio, …). Both are expanded to full URIs on read.
 */
class SqlHubStore implements HubStoreInterface, LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const HUB_PREFIX = 'http://id.loc.gov/resources/hubs/';
    public const BF_PREFIX  = 'http://id.loc.gov/ontologies/bibframe/';
    public const AGENT_PREFIX = 'http://id.loc.gov/rwo/agents/';

    protected ?array $frequencyCache = null;
    protected int $frequencyCacheTtl = 86400;

    public function __construct(
        protected Connection $db,
        protected bool $enabled = true,
        protected ?string $frequencyCachePath = null
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public static function toId(string $hubUri): string
    {
        return str_starts_with($hubUri, self::HUB_PREFIX)
            ? substr($hubUri, strlen(self::HUB_PREFIX)) : $hubUri;
    }

    public static function toUri(string $hubId): string
    {
        return str_starts_with($hubId, 'http') ? $hubId : self::HUB_PREFIX . $hubId;
    }

    public function findHubByTitle(string $title): ?string
    {
        if (!$this->enabled) {
            return null;
        }
        $clean = preg_replace('/^(the|a|an)\s+/i', '', trim($title));
        // InnoDB boolean mode: every token required; strip operator characters.
        $words = array_filter(preg_split('/\s+/', preg_replace('/[+\-<>()~*"@]/', ' ', $clean)));
        if (!$words) {
            return null;
        }
        $query = implode(' ', array_map(fn($w) => '+' . $w, $words));
        try {
            $id = $this->db->fetchOne(
                'SELECT hub_id FROM bibframehub_hub
                 WHERE MATCH(title) AGAINST (? IN BOOLEAN MODE)
                 ORDER BY degree DESC, LENGTH(title) ASC LIMIT 1',
                [$query]
            );
            return $id ? self::toUri($id) : null;
        } catch (\Throwable $e) {
            $this->logError('SQL title lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    public function findHubByLccn(string $lccn): ?string
    {
        if (!$this->enabled) {
            return null;
        }
        try {
            $id = $this->db->fetchOne(
                'SELECT hub_id FROM bibframehub_hub WHERE lccn = ? ORDER BY degree DESC LIMIT 1',
                [preg_replace('/\s+/', '', $lccn)]
            );
            return $id ? self::toUri($id) : null;
        } catch (\Throwable $e) {
            $this->logError('SQL LCCN lookup failed: ' . $e->getMessage());
            return null;
        }
    }

    public function getHubTitle(string $hubUri): ?string
    {
        return $this->getHubsBulk([$hubUri])[$hubUri]['title'] ?? null;
    }

    public function getHubAgents(string $hubUri): array
    {
        return $this->getHubsBulk([$hubUri])[$hubUri]['agents'] ?? [];
    }

    public function getHubMediaTypes(string $hubUri): array
    {
        return $this->getHubsBulk([$hubUri])[$hubUri]['media'] ?? [];
    }

    public function getHubsBulk(array $hubUris): array
    {
        if (!$this->enabled || !$hubUris) {
            return [];
        }
        $ids = array_values(array_unique(array_map([self::class, 'toId'], array_filter($hubUris))));
        $out = [];
        try {
            $rows = $this->db->fetchAllAssociative(
                'SELECT hub_id, title, media FROM bibframehub_hub WHERE hub_id IN (?)',
                [$ids],
                [ArrayParameterType::STRING]
            );
            foreach ($rows as $r) {
                $media = $r['media'] ? explode(',', $r['media']) : [];
                $out[self::toUri($r['hub_id'])] = [
                    'title'  => $r['title'],
                    'agents' => [],
                    'media'  => array_map(fn($m) => self::BF_PREFIX . $m, $media),
                ];
            }
            $agents = $this->db->fetchAllAssociative(
                'SELECT hub_id, agent_uri FROM bibframehub_agent WHERE hub_id IN (?)',
                [$ids],
                [ArrayParameterType::STRING]
            );
            foreach ($agents as $a) {
                $uri = self::toUri($a['hub_id']);
                if (isset($out[$uri])) {
                    $out[$uri]['agents'][] = $a['agent_uri'];
                }
            }
        } catch (\Throwable $e) {
            $this->logError('SQL bulk Hub fetch failed: ' . $e->getMessage());
        }
        return $out;
    }

    public function findRelatedHubs(string $hubUri): array
    {
        if (!$this->enabled) {
            return [];
        }
        $id = self::toId($hubUri);
        try {
            // Only neighbours that exist in the hub table (the LC dump has ~12K dangling targets).
            $rows = $this->db->fetchAllAssociative(
                'SELECT r.target_id AS other, r.rel_type, 0 AS inbound
                   FROM bibframehub_relation r JOIN bibframehub_hub h ON h.hub_id = r.target_id
                  WHERE r.source_id = ?
                 UNION ALL
                 SELECT r.source_id AS other, r.rel_type, 1 AS inbound
                   FROM bibframehub_relation r JOIN bibframehub_hub h ON h.hub_id = r.source_id
                  WHERE r.target_id = ?',
                [$id, $id]
            );
        } catch (\Throwable $e) {
            $this->logError('SQL related hubs query failed: ' . $e->getMessage());
            return [];
        }

        // Same dedup policy as Neo4jService: prefer typed over generic, outbound over inbound.
        $seen = [];
        foreach ($rows as $r) {
            $key = self::toUri($r['other']);
            $isInbound = (bool)$r['inbound'];
            $isGeneric = str_starts_with($r['rel_type'], 'related');
            $cand = [
                'targetUri' => $key,
                'relType'   => $r['rel_type'] . ($isInbound ? '_INBOUND' : ''),
                'isDirect'  => false,
            ];
            if (!isset($seen[$key])) {
                $seen[$key] = $cand;
                continue;
            }
            $existingInbound = str_ends_with($seen[$key]['relType'], '_INBOUND');
            $existingGeneric = str_starts_with($seen[$key]['relType'], 'related');
            if ($existingGeneric && !$isGeneric) {
                $seen[$key] = $cand;
            } elseif (!$existingGeneric && $isGeneric) {
                continue;
            } elseif ($existingInbound && !$isInbound) {
                $seen[$key] = $cand;
            }
        }
        return array_values($seen);
    }

    public function getRelationshipTypeFrequencies(): array
    {
        if ($this->frequencyCache !== null) {
            return $this->frequencyCache;
        }
        if ($this->frequencyCachePath && file_exists($this->frequencyCachePath)
            && time() - filemtime($this->frequencyCachePath) < $this->frequencyCacheTtl
        ) {
            $data = json_decode((string)file_get_contents($this->frequencyCachePath), true);
            if (is_array($data)) {
                return $this->frequencyCache = $data;
            }
        }
        if (!$this->enabled) {
            return [];
        }
        try {
            $rows = $this->db->fetchAllKeyValue(
                'SELECT rel_type, COUNT(*) FROM bibframehub_relation GROUP BY rel_type'
            );
            $this->frequencyCache = array_map('intval', $rows);
            if ($this->frequencyCachePath) {
                @mkdir(dirname($this->frequencyCachePath), 0755, true);
                @file_put_contents($this->frequencyCachePath, json_encode($this->frequencyCache), LOCK_EX);
            }
            return $this->frequencyCache;
        } catch (\Throwable $e) {
            $this->logError('SQL frequency query failed: ' . $e->getMessage());
            return [];
        }
    }
}
