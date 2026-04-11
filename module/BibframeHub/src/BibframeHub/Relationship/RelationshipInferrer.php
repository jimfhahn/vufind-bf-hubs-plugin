<?php

namespace BibframeHub\Relationship;

/**
 * Scores related BIBFRAME Hubs by "surprise" — how non-obvious and interesting
 * the connection is. Translates relationship type, rarity, author distance,
 * and medium crossing into a 0–100 score.
 */
class RelationshipInferrer
{
    // ── Tier 1: Creative transformations (base 90) ──
    private const TIER_1 = [
        'inspirationfor', 'inspiredby', 'parodyof', 'imitationof',
        'derivative', 'graphicnovelizationof', 'novelizationof',
        'critiqueof', 'commentaryon', 'analysisof',
    ];

    // ── Tier 2: Cross-medium adaptations (base 75) ──
    private const TIER_2 = [
        'adaptedasmotionpicture', 'motionpictureadaptationof',
        'adaptedastelevisionprogram', 'televisionadaptationof',
        'operaadaptationof', 'musicaltheatreadaptationof', 'musicaltheatreadaptionof',
        'adaptedasmusicaltheatre', 'variationsbasedon', 'musicalvariationsbasedon',
        'dramatizationof', 'radioadaptationof', 'adaptedaslibretto',
        'verseadaptationof', 'musicalsettingof', 'settomusicas',
        'oratorioadaptationof', 'incidentalmusicfor',
        'cadenzacomposedfor', 'musicalvariations',
        'motionpicturescreenplaybasedon', 'screenplayformotionpicture',
        'librettobasedon',
    ];

    // ── Tier 3: Narrative continuations (base 55) ──
    private const TIER_3 = [
        'sequel', 'sequelto', 'prequel', 'prequelto',
        'basedon', 'adaptedas', 'adaptationof',
        'librettofor', 'libretto', 'remakeof',
    ];

    // ── Tier 4: Serial/structural (base 30) ──
    private const TIER_4 = [
        'continuedby', 'continuationof', 'continues', 'precededby', 'preceededby',
        'succeededby', 'expandedas', 'expandedversionof',
        'abridgedas', 'abridgementof', 'revisionof', 'revisedas',
        'augmentationof', 'augmentedby', 'complementedby',
        'supplementto', 'supplement', 'mergerof', 'mergedtoform',
        'replacementof', 'replacedby', 'paraphraseof',
        'freetranslationof', 'absorbedby', 'absorptionof',
        'separatedfrom', 'continuedinpartby',
    ];

    // ── Tier 5: Predictable (base 10) ──
    private const TIER_5 = [
        'translator', 'translationof', 'translatedas',
        'editor', 'compiler', 'editorofcompilation',
        'inseries', 'containedin', 'containerof', 'contains',
        'subseriesof', 'seriescontainerof', 'issuedas',
        'author', 'creator', 'contributor', 'publisher',
        'issuingbody', 'sponsoringbody', 'founder',
        'filmdirector', 'performer', 'actor', 'singer',
        'producer', 'lyricist', 'composer', 'artist',
        'illustrator', 'photographer', 'host',
        'dedicatee', 'honouree', 'addressee', 'formerowner',
        'writerofaddedcommentary', 'writerofintroduction',
        'writerofsupplementarytextualcontent', 'writerofaddedtext',
        'writerofforeword', 'attributedname',
        'related', 'descriptionof', 'setting',
        'musicformotionpicture', 'motionpicturemusic',
        'musicfortelevisionprogram', 'incidentalmusic', 'musicfor',
    ];

    // Direct edge tiers (not via bflc:relationship)
    private const DIRECT_EDGE_TIERS = [
        'ns0__translationOf' => 5,
        'ns0__relatedTo'     => 3,
        'ns0__arrangementOf' => 4,
    ];

    private const TIER_SCORES = [1 => 90, 2 => 75, 3 => 55, 4 => 30, 5 => 10];

    // rdf:type URIs that indicate a non-text medium
    private const GENERIC_TYPES = [
        'http://id.loc.gov/ontologies/bibframe/Hub',
        'http://id.loc.gov/ontologies/bibframe/Work',
    ];

    /**
     * Score a list of related Hubs by surprise.
     *
     * @param string[] $sourceAgents   Agent URIs for the source Hub
     * @param string[] $sourceMedia    rdf:type URIs for the source Hub
     * @param array[]  $relatedHubs    From Neo4jService::findRelatedHubs()
     * @param array<string,int> $frequencies  Relationship type frequency map
     * @param callable $getTitle       fn(string $uri): ?string
     * @param callable $getAgents      fn(string $uri): string[]
     * @param callable $getMedia       fn(string $uri): string[]
     * @return array[] Scored results sorted by score desc
     */
    public function scoreRelatedHubs(
        array $sourceAgents,
        array $sourceMedia,
        array $relatedHubs,
        array $frequencies,
        callable $getTitle,
        callable $getAgents,
        callable $getMedia,
    ): array {
        $maxFreq = !empty($frequencies) ? max($frequencies) : 1;
        $scored = [];

        foreach ($relatedHubs as $rel) {
            $targetUri = $rel['targetUri'];
            $relType = $rel['relType'];
            $isDirect = $rel['isDirect'];

            $targetAgents = $getAgents($targetUri);
            $targetMedia = $getMedia($targetUri);

            $cleanType = strtolower(str_replace('_INBOUND', '', $relType));
            $freq = $frequencies[$cleanType] ?? 0;
            $directEdgeType = $isDirect ? str_replace('_INBOUND', '', $relType) : null;

            [$score, $breakdown] = $this->computeSurprise(
                $cleanType, $freq, $maxFreq,
                $sourceAgents, $targetAgents,
                $sourceMedia, $targetMedia,
                $isDirect, $directEdgeType,
            );

            $interestingMedia = array_diff($targetMedia, self::GENERIC_TYPES);

            $scored[] = [
                'uri'       => $targetUri,
                'title'     => $getTitle($targetUri),
                'relType'   => $relType,
                'label'     => $this->humanLabel($relType),
                'score'     => $score,
                'breakdown' => $breakdown,
                'media'     => array_map(fn($m) => basename($m), $interestingMedia),
                'differentAuthor' => !empty($sourceAgents) && !empty($targetAgents)
                    && empty(array_intersect($sourceAgents, $targetAgents)),
            ];
        }

        usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);

        return $scored;
    }

    /**
     * Compute surprise score (0–100) for a single relationship.
     *
     * @return array{0: float, 1: array} [score, breakdown]
     */
    public function computeSurprise(
        string $relType,
        int $relFreq,
        int $maxFreq,
        array $sourceAgents,
        array $targetAgents,
        array $sourceMedia,
        array $targetMedia,
        bool $isDirect = false,
        ?string $directEdgeType = null,
    ): array {
        // 1. Base tier score
        if ($isDirect && isset(self::DIRECT_EDGE_TIERS[$directEdgeType])) {
            $tier = self::DIRECT_EDGE_TIERS[$directEdgeType];
        } else {
            $tier = $this->getTier($relType);
        }
        $base = self::TIER_SCORES[$tier] ?? 40;

        // 2. Rarity bonus (0–10)
        if ($relFreq > 0 && $maxFreq > 0) {
            $rarity = 1.0 - (log10($relFreq + 1) / log10($maxFreq + 1));
            $rarityBonus = $rarity * 10;
        } else {
            $rarityBonus = 5.0;
        }

        // 3. Author distance (0–15)
        if (!empty($sourceAgents) && !empty($targetAgents)) {
            $overlap = array_intersect($sourceAgents, $targetAgents);
            if (empty($overlap)) {
                $authorBonus = 15;
            } elseif (count($overlap) < max(count($sourceAgents), count($targetAgents))) {
                $authorBonus = 8;
            } else {
                $authorBonus = 0;
            }
        } else {
            $authorBonus = 5;
        }

        // 4. Medium crossing (0–10)
        $srcInteresting = array_diff($sourceMedia, self::GENERIC_TYPES);
        $tgtInteresting = array_diff($targetMedia, self::GENERIC_TYPES);
        if (!empty($srcInteresting) && !empty($tgtInteresting)
            && empty(array_intersect($srcInteresting, $tgtInteresting))) {
            $mediumBonus = 10;
        } elseif (empty($srcInteresting) && !empty($tgtInteresting)) {
            $mediumBonus = 8;
        } elseif (!empty($srcInteresting) && empty($tgtInteresting)) {
            $mediumBonus = 3;
        } else {
            $mediumBonus = 0;
        }

        $total = min(100, max(0, $base + $rarityBonus + $authorBonus + $mediumBonus));

        return [$total, [
            'tier'         => $tier,
            'base'         => $base,
            'rarityBonus'  => round($rarityBonus, 1),
            'authorBonus'  => $authorBonus,
            'mediumBonus'  => $mediumBonus,
        ]];
    }

    /**
     * Look up the tier (1–5) for a relationship type string.
     */
    public function getTier(string $relType): int
    {
        $rt = strtolower($relType);
        if (in_array($rt, self::TIER_1, true)) return 1;
        if (in_array($rt, self::TIER_2, true)) return 2;
        if (in_array($rt, self::TIER_3, true)) return 3;
        if (in_array($rt, self::TIER_4, true)) return 4;
        if (in_array($rt, self::TIER_5, true)) return 5;
        return 3; // unknown defaults to mid-tier
    }

    /**
     * Human-readable label for a relationship type.
     */
    public function humanLabel(string $relType): string
    {
        $labels = [
            'adaptedasmotionpicture'     => 'Film Adaptation',
            'motionpictureadaptationof'  => 'Based on Film',
            'adaptedastelevisionprogram' => 'TV Adaptation',
            'televisionadaptationof'     => 'Based on TV Program',
            'operaadaptationof'          => 'Opera Adaptation',
            'musicaltheatreadaptationof' => 'Musical Adaptation',
            'musicaltheatreadaptionof'  => 'Musical Adaptation',
            'adaptedasmusicaltheatre'    => 'Musical Adaptation',
            'variationsbasedon'          => 'Musical Variations',
            'dramatizationof'            => 'Dramatization',
            'inspirationfor'             => 'Inspired',
            'inspiredby'                 => 'Inspired By',
            'parodyof'                   => 'Parody',
            'derivative'                 => 'Derivative Work',
            'graphicnovelizationof'      => 'Graphic Novel',
            'novelizationof'             => 'Novelization',
            'critiqueof'                 => 'Critique',
            'commentaryon'               => 'Commentary',
            'sequel'                     => 'Sequel',
            'sequelto'                   => 'Sequel',
            'prequel'                    => 'Prequel',
            'prequelto'                  => 'Prequel',
            'basedon'                    => 'Based On',
            'adaptedas'                  => 'Adapted As',
            'adaptationof'               => 'Adaptation Of',
            'librettofor'                => 'Libretto',
            'remakeof'                   => 'Remake',
            'continuedby'                => 'Continued By',
            'continuationof'             => 'Continuation',
            'translationof'              => 'Translation',
            'translatedas'               => 'Translation',
            'translator'                 => 'Translation',
            'containerof'                => 'Contains',
            'containedin'                => 'Contained In',
            'inseries'                   => 'Series',
            'radioadaptationof'          => 'Radio Adaptation',
            'adaptedaslibretto'          => 'Libretto Adaptation',
            'verseadaptationof'          => 'Verse Adaptation',
            'musicalsettingof'           => 'Musical Setting',
            'settomusicas'               => 'Set to Music',
            'screenplayformotionpicture' => 'Screenplay',
            'motionpicturescreenplaybasedon' => 'Based on Screenplay',
            'librettobasedon'            => 'Libretto Based On',
            'imitationof'                => 'Imitation',
            'analysisof'                 => 'Analysis',
            'continues'                  => 'Continues',
            'precededby'                 => 'Preceded By',
            'succeededby'                => 'Succeeded By',
            'expandedas'                 => 'Expanded Edition',
            'expandedversionof'          => 'Expanded Version',
            'abridgedas'                 => 'Abridged',
            'abridgementof'              => 'Abridgement',
            'revisionof'                 => 'Revision',
            'revisedas'                  => 'Revised As',
            'supplementto'               => 'Supplement',
            'replacedby'                 => 'Replaced By',
            'replacementof'              => 'Replacement',
            'musicalvariationsbasedon'   => 'Musical Variations',
            'musicalvariations'          => 'Musical Variations',
            'cadenzacomposedfor'         => 'Cadenza',
            'incidentalmusicfor'         => 'Incidental Music',
            'oratorioadaptationof'       => 'Oratorio Adaptation',
            'ns0__translationOf'         => 'Translation',
            'ns0__relatedTo'             => 'Related Work',
            'ns0__arrangementOf'         => 'Arrangement',
        ];

        $clean = strtolower(str_replace('_INBOUND', '', $relType));
        if (isset($labels[$clean])) {
            return $labels[$clean];
        }
        // Strip n10s namespace prefix (e.g. "ns0__translationof" → "translationof")
        $stripped = preg_replace('/^ns\d+__/', '', $clean);
        if (isset($labels[$stripped])) {
            return $labels[$stripped];
        }
        return ucwords(
            preg_replace('/([a-z])([A-Z])/', '$1 $2',
                preg_replace('/of$|for$/', '', $stripped))
        );
    }
}
