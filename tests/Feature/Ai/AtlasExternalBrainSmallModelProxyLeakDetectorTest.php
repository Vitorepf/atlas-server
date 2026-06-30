<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSmallModelProxyLeakDetector;
use Tests\TestCase;

final class AtlasExternalBrainSmallModelProxyLeakDetectorTest extends TestCase
{
    private function svc(): AtlasExternalBrainSmallModelProxyLeakDetector
    {
        return new AtlasExternalBrainSmallModelProxyLeakDetector;
    }

    private function detect(array $spec): array
    {
        return $this->svc()->detect(['candidate_spec' => $spec]);
    }

    private function cleanSpec(array $overrides = []): array
    {
        return array_merge([
            'objective'           => 'Implement AtlasExternalBrainSmallModelProxyLeakDetector so it detects seven distinct proxy leak signals including template repetition and missing runnable gates for the self-construction pipeline',
            'acceptance_criteria' => ['Runnable gate: php artisan test --filter=AtlasExternalBrainSmallModelProxyLeakDetectorTest exits 0'],
            'evidence_fields'     => ['test_result', 'implementation_notes'],
            'template_signature'  => 'unique_sig_' . uniqid(),
            'prior_signatures'    => [],
            'recent_accepted_specs' => [],
        ], $overrides);
    }

    // ── AC1: runnable gate (implicit — all tests exit 0) ─────────────────────

    public function test_ac1_output_always_has_required_keys(): void
    {
        $r = $this->detect($this->cleanSpec());
        $this->assertArrayHasKey('rejected',           $r);
        $this->assertArrayHasKey('leak_reasons',       $r);
        $this->assertArrayHasKey('severity',           $r);
        $this->assertArrayHasKey('proxy_leak_score',   $r);
        $this->assertArrayHasKey('killed_reason',      $r);
        $this->assertArrayHasKey('repair_prompt_hint', $r);
        $this->assertArrayHasKey('quality_signal',     $r);
    }

    // ── AC2: template_repetition / no runnable acceptance / no evidence ───────

    public function test_ac2_repeated_template_signature_rejects_with_severity(): void
    {
        $r = $this->detect($this->cleanSpec([
            'template_signature' => 'sig_already_used',
            'prior_signatures'   => ['sig_already_used', 'sig_other'],
        ]));

        $this->assertTrue($r['rejected']);
        $this->assertContains('template_repetition', $r['leak_reasons']);
        $this->assertNotSame('clean', $r['severity']);
        $this->assertNotNull($r['repair_prompt_hint']);
        $this->assertStringContainsString('template', strtolower($r['repair_prompt_hint']));
    }

    public function test_ac2_no_runnable_acceptance_rejects_with_missing_evidence(): void
    {
        $r = $this->detect($this->cleanSpec([
            'acceptance_criteria' => ['The implementation should have a success rate above 90%'],
            'evidence_fields'     => ['implementation_notes'],
        ]));

        $this->assertTrue($r['rejected']);
        $this->assertContains('missing_evidence', $r['leak_reasons']);
        $this->assertNotNull($r['repair_prompt_hint']);
    }

    public function test_ac2_empty_evidence_fields_rejects_with_missing_evidence(): void
    {
        $r = $this->detect($this->cleanSpec([
            'evidence_fields' => [],
        ]));

        $this->assertTrue($r['rejected']);
        $this->assertContains('missing_evidence', $r['leak_reasons']);
        $this->assertNotSame('clean', $r['severity']);
        $this->assertNotNull($r['repair_prompt_hint']);
    }

    public function test_ac2_repair_prompt_hint_is_null_when_clean(): void
    {
        $r = $this->detect($this->cleanSpec());

        $this->assertFalse($r['rejected']);
        $this->assertNull($r['repair_prompt_hint']);
        $this->assertNull($r['killed_reason']);
    }

    public function test_ac2_severity_increases_with_more_signals(): void
    {
        // One signal → lower severity than two signals.
        $oneSignal = $this->detect($this->cleanSpec(['evidence_fields' => []]));
        $twoSignals = $this->detect([
            'objective'           => 'wrap',  // short + cosmetic
            'acceptance_criteria' => ['score above 90%'],  // metric-only, no runnable
            'evidence_fields'     => [],
            'template_signature'  => 'repeated',
            'prior_signatures'    => ['repeated'],
        ]);

        $scores = ['clean' => 0, 'low' => 1, 'medium' => 2, 'high' => 3];
        $this->assertGreaterThanOrEqual(
            $scores[$oneSignal['severity']],
            $scores[$twoSignals['severity']],
            'more signals must produce equal or higher severity',
        );
    }

    // ── AC3: excessive similarity rejects even structurally valid specs ───────

    public function test_ac3_similar_objective_is_rejected(): void
    {
        $priorSpec = 'Implement AtlasExternalBrainSmallModelProxyLeakDetector so it detects seven distinct proxy leak signals including template repetition and missing runnable gates for the self-construction pipeline';

        $r = $this->detect($this->cleanSpec([
            'recent_accepted_specs' => [$priorSpec],
            // Objective is identical → Jaccard = 1.0 >> threshold
        ]));

        $this->assertTrue($r['rejected']);
        $this->assertContains('excessive_similarity', $r['leak_reasons']);
        $this->assertNotNull($r['repair_prompt_hint']);
        $this->assertStringContainsString('similar', strtolower($r['repair_prompt_hint']));
    }

    public function test_ac3_below_similarity_threshold_passes(): void
    {
        $priorSpec = 'Build an entirely different unrelated feature for a completely different subsystem with no overlap';

        $r = $this->detect($this->cleanSpec([
            'recent_accepted_specs' => [$priorSpec],
        ]));

        $this->assertNotContains('excessive_similarity', $r['leak_reasons']);
    }

    public function test_ac3_similarity_check_fires_even_when_structure_looks_valid(): void
    {
        // A spec with valid structure (runnable gate, FQCN, evidence) but too similar.
        $prior = 'Implement AtlasSomeService to process SomeInput and emit SomeOutput for the self-construction pipeline with runnable acceptance gate';

        $r = $this->detect([
            'objective'           => 'Implement AtlasSomeService to process SomeInput and emit SomeOutput for the self-construction pipeline with runnable gate',
            'acceptance_criteria' => ['Runnable gate: php artisan test --filter=AtlasSomeServiceTest exits 0'],
            'evidence_fields'     => ['test_result', 'notes'],
            'template_signature'  => 'new_unique_sig',
            'prior_signatures'    => [],
            'recent_accepted_specs' => [$prior],
        ]);

        $this->assertTrue($r['rejected']);
        $this->assertContains('excessive_similarity', $r['leak_reasons']);
    }

    // ── AC4: clean high-value candidate passes with proxy_leak_score=0 ────────

    public function test_ac4_concrete_candidate_passes_cleanly(): void
    {
        $r = $this->detect([
            'objective'           => 'Implement AtlasExternalBrainFinalReadinessMap so capability-area evidence maps to readiness bands with risks, gaps, next_leverage, and priority_score ordering for the self-construction OS external brain pipeline',
            'acceptance_criteria' => [
                'Runnable gate: /opt/homebrew/bin/php artisan test --filter=AtlasExternalBrainFinalReadinessMapTest exits 0',
                'app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainFinalReadinessMap.php implements all required fields',
            ],
            'evidence_fields'     => ['tests_or_gates_result', 'implementation_notes'],
            'template_signature'  => 'unique_abc_xyz_' . md5('distinct'),
            'prior_signatures'    => ['old_sig_1', 'old_sig_2'],
            'recent_accepted_specs' => [
                'Build a trading engine that processes market orders and emits execution reports',
            ],
        ]);

        $this->assertFalse($r['rejected'],
            'clean candidate must not be rejected; leak_reasons: '.implode(', ', $r['leak_reasons']));
        $this->assertSame(0.0, $r['proxy_leak_score']);
        $this->assertSame('clean', $r['severity']);
        $this->assertNull($r['killed_reason']);
        $this->assertNull($r['repair_prompt_hint']);
        $this->assertTrue($r['quality_signal']['has_runnable_acceptance']);
        $this->assertGreaterThan(0, $r['quality_signal']['evidence_field_count']);
    }

    public function test_ac4_deterministic_same_input_same_output(): void
    {
        $spec = $this->cleanSpec([
            'recent_accepted_specs' => ['some prior spec about trading'],
        ]);

        $this->assertSame(
            json_encode($this->detect($spec), JSON_UNESCAPED_SLASHES),
            json_encode($this->detect($spec), JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_ac4_path_reference_satisfies_concrete_target(): void
    {
        $r = $this->detect([
            'objective'           => 'Harden app/Services/Ai/SelfConstruction/ExternalBrain/AtlasExternalBrainSmallModelProxyLeakDetector.php to reject template repetition and missing runnable evidence in small-model origination tasks for the self-construction pipeline',
            'acceptance_criteria' => ['Runnable gate: php artisan test exits 0'],
            'evidence_fields'     => ['test_result'],
            'template_signature'  => 'path_ref_sig',
            'prior_signatures'    => [],
            'recent_accepted_specs' => [],
        ]);

        $this->assertNotContains('generic_objective', $r['leak_reasons'],
            'a file path reference must satisfy the concrete target check');
    }
}
