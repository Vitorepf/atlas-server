<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\Support\TaskFabricTemplateFarmSignalSupport;

/**
 * Pure gate: detects template-farm repetition across a batch of packets by comparing
 * normalised objective stems and acceptance fragments.
 *
 * Class-name tokens (CamelCase ≥5 chars) are stripped before comparison so that
 * "Implement AtlasFooBar" and "Implement AtlasBazQux" with the same surrounding
 * verb+context collapse to the same stem.
 *
 * similarity_score = max(repeated-stem-packet-ratio, repeated-fragment-packet-ratio)
 * blocking = score >= BLOCKING_THRESHOLD (0.7)
 *
 * A coherent macro-batch where each packet has a distinct proof path but shares a
 * common research thesis will produce low repeated-fragment coverage and pass.
 *
 * mechanism_hashes (new): a per-packet hash of (objective stem + sorted proof-path shapes) —
 * the literal "mechanism and proof shape" hash. Two arm/wrapper/proxy packets with different
 * class names but the same underlying stem and proof-gate boilerplate collapse to the SAME
 * mechanism hash even though their raw text differs; it participates in similarity_score the
 * same way every other repeated-signal ratio does.
 *
 * replacement_hint (new): non-null only when blocking=true. Names which repeated signal(s)
 * triggered the block and asks explicitly for a different leverage MECHANISM — a renamed
 * target or rewritten prose that keeps the same stem/proof shape does not satisfy it.
 *
 * Signal extraction / ratio math lives on
 * {@see TaskFabricTemplateFarmSignalSupport} (pure Support peel).
 */
final class AtlasTaskFabricTemplateFarmSimilarityGate
{
    public const SCHEMA = 'atlas.task_fabric.template_farm_similarity_gate.v1';

    public const BLOCKING_THRESHOLD = 0.7;

    /** @deprecated Use TaskFabricTemplateFarmSignalSupport::STEM_WORD_COUNT */
    public const STEM_WORD_COUNT = TaskFabricTemplateFarmSignalSupport::STEM_WORD_COUNT;

    /**
     * @param  list<array<string,mixed>>  $packets  Each: objective, acceptance_criteria, allowed_files
     * @return array{schema_version:string, similarity_score:float, repeated_stems:list<string>, repeated_acceptance_fragments:list<string>, repeated_allowed_files_shapes:list<string>, repeated_proof_paths:list<string>, repeated_acceptance_verbs:list<string>, blocking:bool, packet_count:int}
     */
    public function assess(array $packets): array
    {
        $total = count($packets);

        if ($total <= 1) {
            return [
                'schema_version' => self::SCHEMA,
                'similarity_score' => 0.0,
                'repeated_stems' => [],
                'repeated_acceptance_fragments' => [],
                'repeated_allowed_files_shapes' => [],
                'repeated_proof_paths' => [],
                'repeated_acceptance_verbs' => [],
                'repeated_mechanism_hashes' => [],
                'replacement_hint' => null,
                'blocking' => false,
                'packet_count' => $total,
            ];
        }

        $stems = array_map(
            static fn (array $p): string => TaskFabricTemplateFarmSignalSupport::extractStem((string) ($p['objective'] ?? '')),
            $packets,
        );

        $stemCounts = array_count_values($stems);
        $repeatedStems = array_values(array_keys(array_filter($stemCounts, static fn (int $c): bool => $c >= 2)));
        sort($repeatedStems);
        $packetsWithRepeatedStem = (int) array_sum(array_map(
            static fn (string $s): int => ($stemCounts[$s] ?? 0) >= 2 ? 1 : 0,
            $stems,
        ));

        $packetFragments = array_map(static function (array $p): array {
            $criteria = is_array($p['acceptance_criteria'] ?? null) ? $p['acceptance_criteria'] : [];
            $frags = array_filter(
                array_unique(array_map(
                    static fn (mixed $c): string => TaskFabricTemplateFarmSignalSupport::extractFragment((string) $c),
                    $criteria,
                )),
                static fn (string $f): bool => strlen($f) >= 5,
            );

            return array_values($frags);
        }, $packets);

        $fragmentOccurrences = [];
        foreach ($packetFragments as $frags) {
            foreach (array_unique($frags) as $frag) {
                $fragmentOccurrences[$frag] = ($fragmentOccurrences[$frag] ?? 0) + 1;
            }
        }

        $repeatedFragments = array_values(array_keys(array_filter($fragmentOccurrences, static fn (int $c): bool => $c >= 2)));
        sort($repeatedFragments);

        $packetsWithRepeatedFrag = 0;
        foreach ($packetFragments as $frags) {
            foreach ($frags as $frag) {
                if (($fragmentOccurrences[$frag] ?? 0) >= 2) {
                    $packetsWithRepeatedFrag++;
                    break;
                }
            }
        }

        $shapes = array_map(
            static fn (array $p): ?string => TaskFabricTemplateFarmSignalSupport::extractAllowedFilesShape((array) ($p['allowed_files'] ?? [])),
            $packets,
        );
        [$repeatedShapes, $packetsWithRepeatedShape] = TaskFabricTemplateFarmSignalSupport::repeatedSignalRatio($shapes);

        $proofPaths = array_map(
            static fn (array $p): array => TaskFabricTemplateFarmSignalSupport::extractProofPaths(
                is_array($p['acceptance_criteria'] ?? null) ? $p['acceptance_criteria'] : [],
            ),
            $packets,
        );
        [$repeatedProofPaths, $packetsWithRepeatedProofPath] = TaskFabricTemplateFarmSignalSupport::repeatedMultiSignalRatio($proofPaths);

        $verbs = array_map(
            static fn (array $p): array => TaskFabricTemplateFarmSignalSupport::extractAcceptanceVerbs(
                is_array($p['acceptance_criteria'] ?? null) ? $p['acceptance_criteria'] : [],
            ),
            $packets,
        );
        [$repeatedVerbs, $packetsWithRepeatedVerb] = TaskFabricTemplateFarmSignalSupport::repeatedMultiSignalRatio($verbs);

        // Noun-substitution templates: stems that differ from each other by exactly one word
        // (e.g. "implement service to validate user accounts" vs "... order accounts") are the
        // same disguised template even though they are not byte-identical stems.
        $templateSignatures = array_map(
            static fn (string $stem): array => TaskFabricTemplateFarmSignalSupport::extractTemplateSignatures($stem),
            $stems,
        );
        [$repeatedTemplates, $packetsWithRepeatedTemplate] = TaskFabricTemplateFarmSignalSupport::repeatedMultiSignalRatio($templateSignatures);

        // Mechanism hash: stem + sorted proof-path shapes, hashed. Two arm/wrapper/proxy packets
        // that differ only by class name collapse to the same hash here even if none of the
        // above signals individually crossed the repetition threshold.
        $mechanismHashes = array_map(
            static fn (string $stem, array $proofPaths): string => TaskFabricTemplateFarmSignalSupport::mechanismHash($stem, $proofPaths),
            $stems,
            $proofPaths,
        );
        [$repeatedMechanismHashes, $packetsWithRepeatedMechanismHash] = TaskFabricTemplateFarmSignalSupport::repeatedSignalRatio($mechanismHashes);

        $score = round(max(
            $packetsWithRepeatedStem / $total,
            $packetsWithRepeatedFrag / $total,
            $packetsWithRepeatedShape / $total,
            $packetsWithRepeatedProofPath / $total,
            $packetsWithRepeatedVerb / $total,
            $packetsWithRepeatedTemplate / $total,
            $packetsWithRepeatedMechanismHash / $total,
        ), 3);

        // CORROBORATION FLOOR: a single repeated signal class must never conote farm on its own.
        // The canonical runnable proof phrase ("php artisan test --filter=... passes green") is
        // REQUIRED by AtlasTaskPacketQualityInspector, so in pairwise admission (total=2) every
        // healthy packet pair shares it and any single-signal ratio saturates to 1.0 — which
        // poisoned live enqueue (every legitimate packet blocked as template_farm_similarity).
        // A real farm repeats structure across independent signal classes (stem + fragments +
        // file shape + template signature); boilerplate proof wording alone is compliance, not
        // farming. Blocking therefore requires the score AND >= 2 repeated signal families.
        // A proof-path fragment normalizes to the same string in BOTH the generic fragment
        // family and the proof-path family — that is one underlying signal, not two.
        $fragmentsBeyondProofPaths = array_values(array_diff($repeatedFragments, $repeatedProofPaths));

        // Signal families split into CONTENT (what the author wrote: stems, fragments beyond
        // the proof phrase, modal verbs, noun-substitution templates, mechanism hashes) and
        // ENVIRONMENT (where the work lives / what compliance mandates: dir:ext shape, the
        // canonical runnable proof phrase the inspector REQUIRES). Two legitimate sibling tasks
        // in one subsystem inevitably share the whole environment set, so environment families
        // alone must never conote farm; a real farm always repeats authored content too.
        $contentFamilies = count(array_filter([
            $repeatedStems !== [],
            $fragmentsBeyondProofPaths !== [],
            $repeatedVerbs !== [],
            $repeatedTemplates !== [],
            $repeatedMechanismHashes !== [],
        ]));
        $repeatedSignalFamilies = $contentFamilies + count(array_filter([
            $repeatedShapes !== [],
            $repeatedProofPaths !== [],
        ]));
        $blocking = $score >= self::BLOCKING_THRESHOLD
            && $repeatedSignalFamilies >= 2
            && $contentFamilies >= 1;

        return [
            'schema_version' => self::SCHEMA,
            'similarity_score' => $score,
            'repeated_stems' => $repeatedStems,
            'repeated_acceptance_fragments' => $repeatedFragments,
            'repeated_allowed_files_shapes' => $repeatedShapes,
            'repeated_proof_paths' => $repeatedProofPaths,
            'repeated_acceptance_verbs' => $repeatedVerbs,
            'repeated_noun_substitution_templates' => $repeatedTemplates,
            'repeated_mechanism_hashes' => $repeatedMechanismHashes,
            'corroborating_signal_families' => $repeatedSignalFamilies,
            'replacement_hint' => $blocking
                ? TaskFabricTemplateFarmSignalSupport::replacementHint($repeatedMechanismHashes)
                : null,
            'blocking' => $blocking,
            'packet_count' => $total,
        ];
    }
}
