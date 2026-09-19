<?php

namespace BibframeHub\Graph;

/**
 * Read-only access to the BIBFRAME Hubs graph, independent of the backing
 * store. Implemented by Neo4jService (n10s graph) and SqlHubStore (tables in
 * VuFind's own database loaded from the Hugging Face Parquet export).
 *
 * All Hub identifiers are full URIs (http://id.loc.gov/resources/hubs/{uuid});
 * agent and media identifiers are full URIs as well, so that results are
 * interchangeable with what HubRdfParser extracts from live RDF.
 */
interface HubStoreInterface
{
    public function isEnabled(): bool;

    /** Best-connected Hub whose main title matches the given text, or null. */
    public function findHubByTitle(string $title): ?string;

    /** Hub carrying the given LCCN (whitespace-insensitive), or null. */
    public function findHubByLccn(string $lccn): ?string;

    public function getHubTitle(string $hubUri): ?string;

    /** @return string[] Agent (RWO) URIs */
    public function getHubAgents(string $hubUri): array;

    /** @return string[] rdf:type URIs such as http://id.loc.gov/ontologies/bibframe/MovingImage */
    public function getHubMediaTypes(string $hubUri): array;

    /**
     * @param string[] $hubUris
     * @return array<string, array{title: ?string, agents: string[], media: string[]}> keyed by Hub URI
     */
    public function getHubsBulk(array $hubUris): array;

    /**
     * One-hop neighbours in either direction, deduplicated by target.
     * Inbound relations carry the '_INBOUND' suffix on relType.
     *
     * @return array<int, array{targetUri: string, relType: string, isDirect: bool}>
     */
    public function findRelatedHubs(string $hubUri): array;

    /** @return array<string, int> relationship type slug → occurrence count */
    public function getRelationshipTypeFrequencies(): array;
}
