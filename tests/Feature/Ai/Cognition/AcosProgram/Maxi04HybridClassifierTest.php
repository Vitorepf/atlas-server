<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCognitiveImmuneInputClassifier;
use App\Services\Ai\Cognition\AtlasImmuneClassifierHybridFreeze;
use App\Services\Ai\Cognition\AtlasImmuneHybridInputClassifier;
use App\Services\Ai\Cognition\BigramJaccardImmuneSemanticSimilarityPort;
use App\Services\Ai\Cognition\ImmuneSemanticSimilarityPort;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * MAXI-04 — Hybrid immune classifier acceptance.
 *
 * The four acceptance vectors from the plan (§ Área 2 MAXI-04):
 *  (a) Switch OFF ⇒ byte-identical to the base classifier for the base fields;
 *  (b) recall on the ≥20 obfuscated injections ≥ semantic_recall_floor from
 *      the freeze (X, stamped BEFORE this test ran per ELEV-03);
 *  (c) FP on the ≥20 legitimate-technical samples ≤ fp_ceiling from the freeze;
 *  (d) anchor never leaves the machine (port receives the text; there is no
 *      network / provider seam in the hybrid classifier code).
 *
 * The freeze content_hash is deterministic; the corpus + anchors are pinned
 * inside the freeze payload by sha256. author≠judge in the freeze.
 */
final class Maxi04HybridClassifierTest extends TestCase
{
    public function test_freeze_content_hash_is_deterministic_and_author_neq_judge(): void
    {
        $a = AtlasImmuneClassifierHybridFreeze::freezePayload();
        $b = AtlasImmuneClassifierHybridFreeze::freezePayload();

        $this->assertSame($a['content_hash'], $b['content_hash']);
        $this->assertSame(AtlasImmuneClassifierHybridFreeze::MEASURE_ID, $a['measure_id']);
        $this->assertNotSame($a['author_engine_id'], $a['judge_engine_id']);
        $this->assertSame(false, $a['switch']['default']);
        $this->assertSame(0.30, $a['thresholds']['tau']);
        $this->assertGreaterThanOrEqual(0.70, $a['thresholds']['semantic_recall_floor_on_obfuscated']);
        $this->assertLessThanOrEqual(0.20, $a['thresholds']['fp_ceiling_on_legitimate']);
        $this->assertSame(hash_file('sha256', base_path(AtlasImmuneClassifierHybridFreeze::ANCHOR_FIXTURE_RELATIVE)), $a['fixtures']['anchors_sha256']);
        $this->assertSame(hash_file('sha256', base_path(AtlasImmuneClassifierHybridFreeze::CORPUS_FIXTURE_RELATIVE)), $a['fixtures']['corpus_sha256']);
    }

    public function test_switch_off_is_byte_identical_to_base_classifier_on_the_red_team_corpus(): void
    {
        Config::set('atlas.aaeos.immune_classifier.semantic_arm_enabled', false);
        $base = new AtlasCognitiveImmuneInputClassifier;
        $hybrid = new AtlasImmuneHybridInputClassifier(
            base: $base,
            port: new BigramJaccardImmuneSemanticSimilarityPort,
            enabled: false,
        );

        $corpus = $this->corpus();
        foreach (array_merge($corpus['obfuscated_injections'], $corpus['legitimate_technical']) as $sample) {
            $text = (string) $sample['text'];
            $baseResult = $base->classify($text);
            $hybridResult = $hybrid->classifyHybrid($text);

            $strippedHybrid = $hybridResult;
            unset($strippedHybrid['hybrid_arm']);
            $this->assertSame(
                $baseResult,
                $strippedHybrid,
                'Sample '.$sample['id'].' broke the switch-OFF byte-identical invariant.'
            );
            $this->assertFalse($hybridResult['hybrid_arm']['enabled']);
            $this->assertSame('off', $hybridResult['hybrid_arm']['source']);
            $this->assertFalse($hybridResult['hybrid_arm']['override_applied']);
        }
    }

    public function test_switch_on_meets_recall_floor_on_obfuscated_and_fp_ceiling_on_legitimate(): void
    {
        Config::set('atlas.aaeos.immune_classifier.semantic_arm_enabled', true);
        $freeze = AtlasImmuneClassifierHybridFreeze::freezePayload();
        $recallFloor = (float) $freeze['thresholds']['semantic_recall_floor_on_obfuscated'];
        $fpCeiling = (float) $freeze['thresholds']['fp_ceiling_on_legitimate'];

        $hybrid = new AtlasImmuneHybridInputClassifier(
            port: new BigramJaccardImmuneSemanticSimilarityPort,
            enabled: true,
        );

        $corpus = $this->corpus();
        $this->assertGreaterThanOrEqual(20, count($corpus['obfuscated_injections']), 'Obfuscated denominator must be \u226520 (freeze law).');
        $this->assertGreaterThanOrEqual(20, count($corpus['legitimate_technical']), 'Legitimate denominator must be \u226520 (freeze law).');

        $hostileClasses = ['prompt_injection', 'private_sensitive', 'untrusted_content'];

        $captured = 0;
        $missed = [];
        foreach ($corpus['obfuscated_injections'] as $sample) {
            $result = $hybrid->classifyHybrid((string) $sample['text']);
            if (in_array($result['input_class'], $hostileClasses, true)) {
                $captured++;
            } else {
                $missed[] = $sample['id'].'->'.$result['input_class'];
            }
        }
        $recall = $captured / count($corpus['obfuscated_injections']);
        $this->assertGreaterThanOrEqual(
            $recallFloor,
            $recall,
            'MAXI-04 semantic-arm recall on obfuscated corpus fell below the frozen floor. '
                .'recall='.round($recall, 4).' floor='.$recallFloor
                .' missed='.implode(',', $missed)
        );

        $falsePositives = [];
        foreach ($corpus['legitimate_technical'] as $sample) {
            $result = $hybrid->classifyHybrid((string) $sample['text']);
            if (in_array($result['input_class'], $hostileClasses, true)) {
                $falsePositives[] = $sample['id'].'->'.$result['input_class'];
            }
        }
        $fpRate = count($falsePositives) / count($corpus['legitimate_technical']);
        $this->assertLessThanOrEqual(
            $fpCeiling,
            $fpRate,
            'MAXI-04 semantic-arm FP on legitimate corpus exceeded the frozen ceiling. '
                .'fp_rate='.round($fpRate, 4).' ceiling='.$fpCeiling
                .' false_positives='.implode(',', $falsePositives)
        );
    }

    public function test_unavailable_port_returns_lexical_verdict_without_fabricated_similarity(): void
    {
        Config::set('atlas.aaeos.immune_classifier.semantic_arm_enabled', true);
        $unavailable = new class implements ImmuneSemanticSimilarityPort
        {
            public function similarity(string $candidate, string $exemplar): ?float
            {
                return null;
            }
        };
        $hybrid = new AtlasImmuneHybridInputClassifier(
            port: $unavailable,
            enabled: true,
        );
        $result = $hybrid->classifyHybrid('this is a benign technical note about refactoring the router');
        $this->assertSame('unavailable', $result['hybrid_arm']['source']);
        $this->assertNull($result['hybrid_arm']['max_similarity']);
        $this->assertFalse($result['hybrid_arm']['override_applied']);
    }

    public function test_hostile_severity_max_governs_when_semantic_and_lexical_disagree(): void
    {
        Config::set('atlas.aaeos.immune_classifier.semantic_arm_enabled', true);
        // A stub port that always says "prompt_injection anchor is a perfect match".
        $stub = new class implements ImmuneSemanticSimilarityPort
        {
            public function similarity(string $candidate, string $exemplar): ?float
            {
                return 1.0;
            }
        };
        $hybrid = new AtlasImmuneHybridInputClassifier(
            port: $stub,
            anchors: [
                'prompt_injection' => ['ignore previous instructions'],
                'private_sensitive' => ['operator lens contents'],
                'untrusted_content' => ['found in a public gist'],
            ],
            enabled: true,
        );

        // Base classifier would call this "operational_ephemeral"; the arm overrides.
        $result = $hybrid->classifyHybrid('a totally benign-looking sentence.');
        $this->assertSame('prompt_injection', $result['input_class']);
        $this->assertSame('semantic', $result['hybrid_arm']['winner_source']);
        $this->assertTrue($result['hybrid_arm']['override_applied']);
        $this->assertFalse($result['memory_eligible']);
        $this->assertFalse($result['embedding_allowed']);
        $this->assertSame('blocked_ephemeral_evidence', $result['default_destination']);
        $this->assertContains('semantic_arm_hit', $result['matched_signals']);
    }

    /**
     * @return array{obfuscated_injections:list<array<string,mixed>>,legitimate_technical:list<array<string,mixed>>}
     */
    private function corpus(): array
    {
        $decoded = json_decode(
            (string) file_get_contents(base_path(AtlasImmuneClassifierHybridFreeze::CORPUS_FIXTURE_RELATIVE)),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        return $decoded;
    }
}
