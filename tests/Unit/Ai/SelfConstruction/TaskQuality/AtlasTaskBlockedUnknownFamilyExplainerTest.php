<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedUnknownFamilyExplainer;
use PHPUnit\Framework\TestCase;

final class AtlasTaskBlockedUnknownFamilyExplainerTest extends TestCase
{
    private function explainer(): AtlasTaskBlockedUnknownFamilyExplainer
    {
        return new AtlasTaskBlockedUnknownFamilyExplainer;
    }

    private function packet(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'pkt-1',
            'objective' => 'Implement a well-defined service for the queue health pipeline',
            'allowed_files' => ['app/Services/Foo.php', 'tests/FooTest.php'],
            'acceptance_criteria' => ['phpunit passes'],
            'required_evidence' => ['tests_or_gates_result'],
        ], $overrides);
    }

    // ── AC: families ────────────────────────────────────────────────────────

    public function test_missing_allowed_files_is_classified(): void
    {
        $r = $this->explainer()->explain($this->packet(['allowed_files' => []]));

        $this->assertSame(AtlasTaskBlockedUnknownFamilyExplainer::FAMILY_MISSING_ALLOWED_FILES, $r['likely_family']);
        $this->assertContains('allowed_files_empty', $r['missing_signals']);
    }

    public function test_missing_acceptance_is_classified(): void
    {
        $r = $this->explainer()->explain($this->packet(['acceptance_criteria' => []]));
        $this->assertSame(AtlasTaskBlockedUnknownFamilyExplainer::FAMILY_MISSING_ACCEPTANCE, $r['likely_family']);
    }

    public function test_missing_required_evidence_is_classified(): void
    {
        $r = $this->explainer()->explain($this->packet(['required_evidence' => []]));
        $this->assertSame(AtlasTaskBlockedUnknownFamilyExplainer::FAMILY_MISSING_REQUIRED_EVIDENCE, $r['likely_family']);
    }

    public function test_forbidden_target_is_classified(): void
    {
        $r = $this->explainer()->explain($this->packet([
            'allowed_files' => ['config/atlas.php'],
            'packet_quality' => ['facts' => ['forbidden_self_targets' => ['config/atlas.php']]],
        ]));

        $this->assertSame(AtlasTaskBlockedUnknownFamilyExplainer::FAMILY_FORBIDDEN_TARGET, $r['likely_family']);
        $this->assertSame(AtlasTaskBlockedUnknownFamilyExplainer::ACTION_RETIRE, $r['safe_next_action']);
    }

    public function test_property_gated_target_without_receipt_is_classified_forbidden(): void
    {
        $r = $this->explainer()->explain($this->packet([
            'allowed_files' => ['app/Services/Ai/AutonomousEvolution/Brain/Foo.php'],
            'packet_quality' => ['facts' => ['property_gated_targets' => ['app/Services/Ai/AutonomousEvolution/Brain/Foo.php']]],
        ]));

        $this->assertSame(AtlasTaskBlockedUnknownFamilyExplainer::FAMILY_FORBIDDEN_TARGET, $r['likely_family']);
    }

    public function test_duplicate_or_already_done_suspect_via_status_completed(): void
    {
        $r = $this->explainer()->explain($this->packet(['status' => 'completed']));
        $this->assertSame(AtlasTaskBlockedUnknownFamilyExplainer::FAMILY_DUPLICATE_OR_ALREADY_DONE_SUSPECT, $r['likely_family']);
    }

    public function test_duplicate_or_already_done_suspect_via_give_back_count_and_prior_success(): void
    {
        $r = $this->explainer()->explain($this->packet(['give_back_count' => 8, 'has_prior_success' => true]));
        $this->assertSame(AtlasTaskBlockedUnknownFamilyExplainer::FAMILY_DUPLICATE_OR_ALREADY_DONE_SUSPECT, $r['likely_family']);
    }

    public function test_high_give_back_without_prior_success_is_not_duplicate_suspect(): void
    {
        $r = $this->explainer()->explain($this->packet(['give_back_count' => 8, 'has_prior_success' => false]));
        $this->assertNotSame(AtlasTaskBlockedUnknownFamilyExplainer::FAMILY_DUPLICATE_OR_ALREADY_DONE_SUSPECT, $r['likely_family']);
    }

    public function test_contradictory_acceptance_suspect_is_classified(): void
    {
        $r = $this->explainer()->explain($this->packet([
            'packet_quality' => ['deficiencies' => ['hidden_poison:contradictory_acceptance']],
        ]));

        $this->assertSame(AtlasTaskBlockedUnknownFamilyExplainer::FAMILY_CONTRADICTORY_ACCEPTANCE_SUSPECT, $r['likely_family']);
    }

    public function test_insufficient_metadata_fallback_for_short_objective(): void
    {
        $r = $this->explainer()->explain($this->packet(['objective' => 'fix it']));
        $this->assertSame(AtlasTaskBlockedUnknownFamilyExplainer::FAMILY_INSUFFICIENT_METADATA, $r['likely_family']);
    }

    // ── AC: output shape ───────────────────────────────────────────────────────

    public function test_output_has_all_required_keys(): void
    {
        $r = $this->explainer()->explain($this->packet());
        foreach (['packet_id', 'likely_family', 'confidence', 'missing_signals', 'respec_hint', 'safe_next_action'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
    }

    // ── AC: priority — first match wins ───────────────────────────────────────

    public function test_missing_allowed_files_takes_priority_over_missing_acceptance(): void
    {
        $r = $this->explainer()->explain($this->packet(['allowed_files' => [], 'acceptance_criteria' => []]));
        $this->assertSame(AtlasTaskBlockedUnknownFamilyExplainer::FAMILY_MISSING_ALLOWED_FILES, $r['likely_family']);
    }

    public function test_forbidden_target_takes_priority_over_missing_acceptance(): void
    {
        $r = $this->explainer()->explain($this->packet([
            'allowed_files' => ['config/atlas.php'],
            'acceptance_criteria' => [],
            'packet_quality' => ['facts' => ['forbidden_self_targets' => ['config/atlas.php']]],
        ]));

        $this->assertSame(AtlasTaskBlockedUnknownFamilyExplainer::FAMILY_FORBIDDEN_TARGET, $r['likely_family']);
    }

    // ── AC: regression fixture — fully empty blocked packet no longer opaque unknown ──

    public function test_fully_empty_packet_no_longer_remains_opaque_unknown(): void
    {
        $r = $this->explainer()->explain([
            'task_packet_id' => 'fully-empty',
            'objective' => '',
            'allowed_files' => [],
            'acceptance_criteria' => [],
            'required_evidence' => [],
        ]);

        $this->assertNotSame('unknown', $r['likely_family']);
        $this->assertSame(AtlasTaskBlockedUnknownFamilyExplainer::FAMILY_MISSING_ALLOWED_FILES, $r['likely_family']);
        $this->assertNotEmpty($r['missing_signals']);
        $this->assertNotEmpty($r['respec_hint']);
    }

    // ── batch ───────────────────────────────────────────────────────────────

    public function test_explain_batch_processes_every_packet(): void
    {
        $r = $this->explainer()->explainBatch([
            $this->packet(['task_packet_id' => 'a', 'allowed_files' => []]),
            $this->packet(['task_packet_id' => 'b']),
        ]);

        $this->assertCount(2, $r);
        $this->assertSame('a', $r[0]['packet_id']);
        $this->assertSame('b', $r[1]['packet_id']);
    }

    // ── purity: only reads the packet array ───────────────────────────────────

    public function test_explainer_source_has_no_io_calls(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../../../../app/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskBlockedUnknownFamilyExplainer.php');
        foreach (['DB::', 'Http::', 'file_put_contents', 'exec(', 'shell_exec', 'Process::', 'fopen('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "explainer must not call {$forbidden}");
        }
    }

    public function test_explain_is_deterministic(): void
    {
        $packet = $this->packet(['allowed_files' => []]);
        $a = $this->explainer()->explain($packet);
        $b = $this->explainer()->explain($packet);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
