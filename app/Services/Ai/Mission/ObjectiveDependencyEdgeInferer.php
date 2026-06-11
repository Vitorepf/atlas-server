<?php

declare(strict_types=1);

namespace App\Services\Ai\Mission;

use App\Services\Ai\Mission\Support\MissionPromptTokenizer;
use App\Services\Ai\Support\AiStringListNormalizer;

/**
 * Infers directed producer-before-consumer dependency edges between mission
 * objectives.
 *
 * An edge fires when one objective's title carries a producer verb (it creates
 * a deliverable) and another objective's title carries a consumer verb (it
 * relies on that deliverable) AND both titles share a deliverable-noun. The
 * resulting edge always points producer -> consumer, never the reverse.
 *
 * Pure and deterministic: the result is computed solely from the supplied
 * objective titles via a fixed rule table. No I/O, no clock, no randomness.
 */
final class ObjectiveDependencyEdgeInferer
{
    private const SCHEMA_VERSION = 'atlas.aaeos.objective_dependency_edges.v1';

    /**
     * Curated producer-verb -> consumer-verb dependency rules. Exactly seven.
     * Each entry is [producerVerb, consumerVerb]; an edge using the rule string
     * "producerVerb->consumerVerb" fires when the producer verb appears in one
     * objective and the consumer verb in another that shares a deliverable-noun.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const RULES = [
        ['criar', 'usar'],
        ['criar', 'integrar'],
        ['criar', 'testar'],
        ['criar', 'consumir'],
        ['gerar', 'usar'],
        ['gerar', 'integrar'],
        ['gerar', 'testar'],
    ];

    /**
     * Portuguese function words that can never be a deliverable-noun.
     *
     * @var list<string>
     */
    private const STOPWORDS = [
        'de', 'do', 'da', 'dos', 'das',
        'o', 'a', 'os', 'as',
        'e', 'ou',
        'no', 'na', 'nos', 'nas',
        'em', 'um', 'uma', 'uns', 'umas',
        'para', 'com', 'ao', 'aos', 'por',
    ];

    /**
     * @param  list<array{id: string, title: string, description?: string}>  $objectives
     * @return array{schema_version: string, edges: list<array{from: string, to: string, artifact: string, rule: string}>}
     */
    public function inferEdges(array $objectives): array
    {
        $normalized = $this->normalizeObjectives($objectives);

        $producerVerbs = $this->producerVerbs();
        $consumerVerbs = $this->consumerVerbs();

        // Pre-compute, per objective, which producer/consumer verbs it carries
        // and its ordered list of deliverable-noun candidates.
        $verbHits = [];
        $nouns = [];
        foreach ($normalized as $index => $objective) {
            $tokens = $objective['tokens'];
            $verbHits[$index] = [
                'producer' => $this->matchedVerbs($tokens, $producerVerbs),
                'consumer' => $this->matchedVerbs($tokens, $consumerVerbs),
            ];
            $nouns[$index] = $this->deliverableNouns($tokens);
        }

        // Key edges on from|to|artifact so synonym consumer verbs collapse to a
        // single edge; keep the lexicographically smallest rule for that key.
        $byKey = [];

        foreach (self::RULES as $rule) {
            [$producerVerb, $consumerVerb] = $rule;
            $ruleLabel = $producerVerb.'->'.$consumerVerb;

            foreach ($normalized as $producerIndex => $producerObjective) {
                if (! in_array($producerVerb, $verbHits[$producerIndex]['producer'], true)) {
                    continue;
                }

                foreach ($normalized as $consumerIndex => $consumerObjective) {
                    if ($producerIndex === $consumerIndex) {
                        // Same objective: never fabricate a self-edge.
                        continue;
                    }

                    if (! in_array($consumerVerb, $verbHits[$consumerIndex]['consumer'], true)) {
                        continue;
                    }

                    $artifact = $this->sharedDeliverable($nouns[$producerIndex], $nouns[$consumerIndex]);
                    if ($artifact === null) {
                        continue;
                    }

                    $from = $producerObjective['id'];
                    $to = $consumerObjective['id'];

                    if ($from === $to) {
                        // Distinct array slots may still carry the same id.
                        continue;
                    }

                    $key = $from."\x00".$to."\x00".$artifact;

                    if (! array_key_exists($key, $byKey) || strcmp($ruleLabel, $byKey[$key]['rule']) < 0) {
                        $byKey[$key] = [
                            'from' => $from,
                            'to' => $to,
                            'artifact' => $artifact,
                            'rule' => $ruleLabel,
                        ];
                    }
                }
            }
        }

        $edges = array_values($byKey);

        usort($edges, static function (array $left, array $right): int {
            return [$left['from'], $left['to'], $left['artifact']]
                <=> [$right['from'], $right['to'], $right['artifact']];
        });

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'edges' => $edges,
        ];
    }

    /**
     * @param  list<array{id: string, title: string, description?: string}>  $objectives
     * @return list<array{id: string, tokens: list<string>}>
     */
    private function normalizeObjectives(array $objectives): array
    {
        $normalized = [];

        foreach ($objectives as $objective) {
            if (! is_array($objective)) {
                continue;
            }

            $id = isset($objective['id']) ? (string) $objective['id'] : '';
            if ($id === '') {
                continue;
            }

            $title = isset($objective['title']) ? (string) $objective['title'] : '';

            $normalized[] = [
                'id' => $id,
                'tokens' => MissionPromptTokenizer::semanticWords($title),
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $tokens
     * @param  list<string>  $verbs
     * @return list<string>
     */
    private function matchedVerbs(array $tokens, array $verbs): array
    {
        $matched = [];
        foreach ($verbs as $verb) {
            if (in_array($verb, $tokens, true) && ! in_array($verb, $matched, true)) {
                $matched[] = $verb;
            }
        }

        return $matched;
    }

    /**
     * Ordered, de-duplicated deliverable-noun candidates: title tokens that are
     * neither verbs nor function words, preserving first-seen order.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    private function deliverableNouns(array $tokens): array
    {
        $verbs = array_merge($this->producerVerbs(), $this->consumerVerbs());

        $nouns = [];
        foreach ($tokens as $token) {
            if (in_array($token, $verbs, true)) {
                continue;
            }

            if (in_array($token, self::STOPWORDS, true)) {
                continue;
            }

            if (! in_array($token, $nouns, true)) {
                $nouns[] = $token;
            }
        }

        return $nouns;
    }

    /**
     * First deliverable-noun shared by both objectives, in producer order.
     *
     * @param  list<string>  $producerNouns
     * @param  list<string>  $consumerNouns
     */
    private function sharedDeliverable(array $producerNouns, array $consumerNouns): ?string
    {
        foreach ($producerNouns as $noun) {
            if (in_array($noun, $consumerNouns, true)) {
                return $noun;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function producerVerbs(): array
    {
        return AiStringListNormalizer::uniqueStrings(array_column(self::RULES, 0));
    }

    /**
     * @return list<string>
     */
    private function consumerVerbs(): array
    {
        return AiStringListNormalizer::uniqueStrings(array_column(self::RULES, 1));
    }
}
