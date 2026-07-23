<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainReasoningScaffoldCompiler;
use Tests\TestCase;

final class AtlasExternalBrainReasoningScaffoldCompilerTest extends TestCase
{
    private function svc(): AtlasExternalBrainReasoningScaffoldCompiler
    {
        return new AtlasExternalBrainReasoningScaffoldCompiler;
    }

    private function compile(array $input = []): array
    {
        return $this->svc()->compile($input);
    }

    private function sectionOrder(array $result, string $sectionId): ?int
    {
        foreach ($result['scaffold_sections'] as $section) {
            if ($section['section_id'] === $sectionId) {
                return $section['order'];
            }
        }
        return null;
    }

    // ── AC1: runnable gate (implicit — all tests exit 0) ─────────────────────

    public function test_ac1_valid_compilation_produces_expected_keys(): void
    {
        $r = $this->compile();
        $this->assertArrayHasKey('schema',                     $r);
        $this->assertArrayHasKey('is_valid',                   $r);
        $this->assertArrayHasKey('rejection_reason',           $r);
        $this->assertArrayHasKey('scaffold_sections',          $r);
        $this->assertArrayHasKey('required_artifacts',         $r);
        $this->assertArrayHasKey('stop_conditions',            $r);
        $this->assertArrayHasKey('frontier_deepening_prompts', $r);
    }

    // ── AC2: queue_state_read and evidence_intake before candidate_tasks ──────

    public function test_ac2_queue_state_read_is_first_section(): void
    {
        $r = $this->compile();
        $this->assertTrue($r['is_valid']);

        $ids = array_column($r['scaffold_sections'], 'section_id');
        $this->assertSame('queue_state_read', $ids[0],
            'queue_state_read must be the first section');
    }

    public function test_ac2_evidence_intake_precedes_candidate_tasks(): void
    {
        $r = $this->compile();
        $evidenceOrder   = $this->sectionOrder($r, 'evidence_intake');
        $candidatesOrder = $this->sectionOrder($r, 'candidate_tasks');

        $this->assertNotNull($evidenceOrder);
        $this->assertNotNull($candidatesOrder);
        $this->assertLessThan($candidatesOrder, $evidenceOrder,
            'evidence_intake must appear before candidate_tasks');
    }

    public function test_ac2_queue_state_read_precedes_candidate_tasks(): void
    {
        $r = $this->compile();
        $queueOrder      = $this->sectionOrder($r, 'queue_state_read');
        $candidatesOrder = $this->sectionOrder($r, 'candidate_tasks');

        $this->assertLessThan($candidatesOrder, $queueOrder,
            'queue_state_read must appear before candidate_tasks');
    }

    public function test_ac2_section_order_is_monotonically_increasing(): void
    {
        $r      = $this->compile();
        $orders = array_column($r['scaffold_sections'], 'order');
        $sorted = $orders;
        sort($sorted);
        $this->assertSame($sorted, $orders,
            'scaffold sections must be in strictly ascending order');
    }

    // ── AC3: direct final answer and missing evidence intake are rejected ─────

    public function test_ac3_direct_final_answer_is_rejected(): void
    {
        $r = $this->compile(['allow_direct_final_answer' => true]);

        $this->assertFalse($r['is_valid']);
        $this->assertSame('direct_final_answer_not_allowed', $r['rejection_reason']);
        $this->assertEmpty($r['scaffold_sections']);
    }

    public function test_ac3_skipping_evidence_intake_is_rejected(): void
    {
        $r = $this->compile(['skip_sections' => ['evidence_intake']]);

        $this->assertFalse($r['is_valid']);
        $this->assertSame('must_include_evidence_intake', $r['rejection_reason']);
    }

    public function test_ac3_skipping_semantic_dedup_is_rejected(): void
    {
        $r = $this->compile(['skip_sections' => ['semantic_dedup']]);

        $this->assertFalse($r['is_valid']);
        $this->assertStringContainsString('semantic_dedup', $r['rejection_reason'],
            'skipping semantic_dedup must be rejected as a required section');
    }

    public function test_ac3_rejected_scaffold_has_empty_sections_and_artifacts(): void
    {
        $r = $this->compile(['allow_direct_final_answer' => true]);

        $this->assertEmpty($r['scaffold_sections']);
        $this->assertEmpty($r['required_artifacts']);
        $this->assertEmpty($r['frontier_deepening_prompts']);
    }

    public function test_ac3_skipping_queue_state_read_is_rejected(): void
    {
        $r = $this->compile(['skip_sections' => ['queue_state_read']]);

        $this->assertFalse($r['is_valid']);
        $this->assertStringContainsString('queue_state_read', $r['rejection_reason']);
    }

    // ── AC4: frontier_deepening_prompts only after anti-dup + critique ────────

    public function test_ac4_frontier_deepening_prompts_present_in_valid_scaffold(): void
    {
        $r = $this->compile();

        $this->assertNotEmpty($r['frontier_deepening_prompts'],
            'valid scaffold must include frontier_deepening_prompts');
    }

    public function test_ac4_frontier_deepening_prompts_absent_in_rejected_scaffold(): void
    {
        $r = $this->compile(['allow_direct_final_answer' => true]);

        $this->assertEmpty($r['frontier_deepening_prompts'],
            'rejected scaffold must not expose frontier_deepening_prompts');
    }

    public function test_ac4_anti_duplication_proof_precedes_final_batch_selection(): void
    {
        $r = $this->compile();

        $dedupOrder = $this->sectionOrder($r, 'anti_duplication_proof');
        $finalOrder = $this->sectionOrder($r, 'final_batch_selection');

        $this->assertNotNull($dedupOrder);
        $this->assertNotNull($finalOrder);
        $this->assertLessThan($finalOrder, $dedupOrder,
            'anti_duplication_proof must precede final_batch_selection');
    }

    public function test_ac4_adversarial_critique_precedes_final_batch_selection(): void
    {
        $r = $this->compile();

        $critiqueOrder = $this->sectionOrder($r, 'adversarial_critique');
        $finalOrder    = $this->sectionOrder($r, 'final_batch_selection');

        $this->assertLessThan($finalOrder, $critiqueOrder,
            'adversarial_critique must precede final_batch_selection');
    }

    public function test_ac4_semantic_dedup_artifact_is_required(): void
    {
        $r = $this->compile();

        $this->assertArrayHasKey('semantic_dedup', $r['required_artifacts'],
            'semantic_dedup must have a required_artifact entry');
        $this->assertContains('semantic_dedup_report', $r['required_artifacts']['semantic_dedup']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_deterministic_output_on_identical_input(): void
    {
        $this->assertSame(
            json_encode($this->compile(), JSON_UNESCAPED_SLASHES),
            json_encode($this->compile(), JSON_UNESCAPED_SLASHES),
        );
    }
}
