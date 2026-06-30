<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedReplacementDraftCompleter;
use Tests\TestCase;

final class AtlasTaskBlockedReplacementDraftCompleterTest extends TestCase
{
    private function completer(): AtlasTaskBlockedReplacementDraftCompleter
    {
        return new AtlasTaskBlockedReplacementDraftCompleter;
    }

    private function draft(array $overrides = []): array
    {
        return array_merge([
            'task_packet_id' => 'tp-blocked-1',
            'allowed_files' => [],
            'acceptance_criteria' => [],
            'required_evidence' => [],
        ], $overrides);
    }

    public function test_strong_field_recovery_with_trusted_evidence_yields_can_submit_true(): void
    {
        $items = [[
            'draft' => $this->draft(),
            'field_recovery' => [
                'allowed_files' => ['app/Services/Ai/Foo.php'],
                'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test FooTest exits 0'],
                'required_evidence' => ['tests_or_gates_result'],
                'trust' => 'trusted',
                'confidence' => 0.9,
            ],
        ]];

        $result = $this->completer()->complete($items);

        $completed = $result['completed_drafts'][0];
        $this->assertTrue($completed['can_submit']);
        $this->assertSame([], $completed['missing_fields']);
        $this->assertSame([], $completed['refusal_reasons']);
    }

    public function test_untrusted_recovery_keeps_can_submit_false_with_refusal_reasons(): void
    {
        $items = [[
            'draft' => $this->draft(),
            'field_recovery' => [
                'allowed_files' => ['app/Services/Ai/Foo.php'],
                'acceptance_criteria' => ['some criteria'],
                'required_evidence' => ['tests_or_gates_result'],
                'trust' => 'untrusted',
                'confidence' => 0.9,
            ],
        ]];

        $result = $this->completer()->complete($items);

        $completed = $result['completed_drafts'][0];
        $this->assertFalse($completed['can_submit']);
        $this->assertNotEmpty($completed['refusal_reasons']);
        $this->assertStringContainsString('trust_not_trusted', implode(',', $completed['refusal_reasons']));
        $this->assertNull($completed['replacement_task_packet_id']);
    }

    public function test_ambiguous_recovery_keeps_can_submit_false(): void
    {
        $items = [[
            'draft' => $this->draft(),
            'field_recovery' => [
                'allowed_files' => ['app/Foo.php'],
                'acceptance_criteria' => ['criteria'],
                'required_evidence' => ['tests_or_gates_result'],
                'trust' => 'ambiguous',
                'confidence' => 0.95,
            ],
        ]];

        $result = $this->completer()->complete($items);

        $this->assertFalse($result['completed_drafts'][0]['can_submit']);
    }

    public function test_low_confidence_keeps_can_submit_false_even_with_all_fields_present(): void
    {
        $items = [[
            'draft' => $this->draft(),
            'field_recovery' => [
                'allowed_files' => ['app/Foo.php'],
                'acceptance_criteria' => ['criteria'],
                'required_evidence' => ['tests_or_gates_result'],
                'trust' => 'trusted',
                'confidence' => 0.4,
            ],
        ]];

        $result = $this->completer()->complete($items);

        $completed = $result['completed_drafts'][0];
        $this->assertFalse($completed['can_submit']);
        $this->assertStringContainsString('confidence_below_threshold', implode(',', $completed['refusal_reasons']));
    }

    public function test_still_missing_fields_after_recovery_lists_missing_fields(): void
    {
        $items = [[
            'draft' => $this->draft(),
            'field_recovery' => [
                'allowed_files' => ['app/Foo.php'],
                'trust' => 'trusted',
                'confidence' => 0.9,
            ],
        ]];

        $result = $this->completer()->complete($items);

        $completed = $result['completed_drafts'][0];
        $this->assertFalse($completed['can_submit']);
        $this->assertContains('acceptance_criteria', $completed['missing_fields']);
        $this->assertContains('required_evidence', $completed['missing_fields']);
    }

    public function test_replacement_id_is_derived_from_source_id_and_never_duplicates_it(): void
    {
        $items = [[
            'draft' => $this->draft(['task_packet_id' => 'tp-source-1']),
            'field_recovery' => [
                'allowed_files' => ['app/Foo.php'],
                'acceptance_criteria' => ['criteria'],
                'required_evidence' => ['tests_or_gates_result'],
                'trust' => 'trusted',
                'confidence' => 0.9,
            ],
        ]];

        $result = $this->completer()->complete($items);
        $completed = $result['completed_drafts'][0];

        $this->assertStringStartsWith('tp-source-1-replacement-', $completed['replacement_task_packet_id']);
        $this->assertNotSame('tp-source-1', $completed['replacement_task_packet_id']);
    }

    public function test_replacement_id_is_deterministic_for_identical_input(): void
    {
        $items = [[
            'draft' => $this->draft(['task_packet_id' => 'tp-source-2']),
            'field_recovery' => [
                'allowed_files' => ['app/Foo.php'],
                'acceptance_criteria' => ['criteria'],
                'required_evidence' => ['tests_or_gates_result'],
                'trust' => 'trusted',
                'confidence' => 0.9,
            ],
        ]];

        $completer = $this->completer();
        $a = $completer->complete($items)['completed_drafts'][0]['replacement_task_packet_id'];
        $b = $completer->complete($items)['completed_drafts'][0]['replacement_task_packet_id'];

        $this->assertSame($a, $b);
    }

    public function test_does_not_mutate_queue(): void
    {
        $result = $this->completer()->complete([]);

        $this->assertFalse($result['mutates_queue']);
    }

    public function test_source_performs_no_queue_provider_file_or_git_io(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/TaskQuality/AtlasTaskBlockedReplacementDraftCompleter.php'));
        foreach (['->enqueue(', '->updateStatus(', '->retire(', 'Http::', 'file_put_contents(', 'exec(', 'shell_exec(', 'proc_open('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "completer must not perform {$forbidden}");
        }
    }
}
