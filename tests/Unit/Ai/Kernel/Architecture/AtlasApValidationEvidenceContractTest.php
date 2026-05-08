<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\AtlasApValidationEvidenceContract;
use Tests\TestCase;

final class AtlasApValidationEvidenceContractTest extends TestCase
{
    public function test_template_declares_validation_evidence_shape_without_execution(): void
    {
        $payload = app(AtlasApValidationEvidenceContract::class)->template();

        $this->assertSame('atlas.ap_validation_evidence_contract.v1', $payload['schema_version']);
        $this->assertSame('implemented', $payload['status']);
        $this->assertSame('read_only_validation_evidence_contract', $payload['mode']);
        $this->assertSame('ap_validation_evidence_shape_only_no_command_execution', $payload['authority']);
        $this->assertSame('AtlasApCompletionChecklistContract', $payload['delegates_completion_status_to']);
        $this->assertContains('focused_tests_passed', $payload['required_boolean_keys']);
        $this->assertContains('uncovered_changed_paths', $payload['required_array_keys']);
        $this->assertSame([], data_get($payload, 'template.uncovered_changed_paths'));
        $this->assertFalse(data_get($payload, 'guardrails.writes_files'));
        $this->assertFalse(data_get($payload, 'guardrails.executes_commands'));
        $this->assertFalse(data_get($payload, 'guardrails.marks_complete'));
        $this->assertFalse(data_get($payload, 'guardrails.accepts_unknown_keys'));
    }

    public function test_validate_accepts_complete_evidence_shape(): void
    {
        $payload = app(AtlasApValidationEvidenceContract::class)->validate([
            'code_or_doc_changes_scoped' => true,
            'focused_tests_passed' => true,
            'docs_health_ok' => true,
            'architecture_validate_ok' => false,
            'git_diff_check_passed' => true,
            'ap_doc_updated' => true,
            'uncovered_changed_paths' => ['app/New/Reviewed.php'],
            'uncovered_paths_reviewed' => true,
            'commands' => ['php artisan test tests/Unit/Ai/Kernel/Architecture/AtlasApValidationEvidenceContractTest.php'],
            'notes' => ['architecture validate blocked by known scanner issue'],
        ]);

        $this->assertSame('valid_shape', $payload['status']);
        $this->assertSame(0, $payload['error_count']);
        $this->assertSame([], $payload['errors']);
        $this->assertSame(1, data_get($payload, 'normalized_summary.uncovered_changed_path_count'));
        $this->assertSame(1, data_get($payload, 'normalized_summary.command_count'));
        $this->assertSame('pass_evidence_to_ap_completion_checklist_contract', $payload['next_action']);
    }

    public function test_validate_rejects_missing_wrong_type_and_unknown_evidence_keys(): void
    {
        $payload = app(AtlasApValidationEvidenceContract::class)->validate([
            'code_or_doc_changes_scoped' => true,
            'focused_tests_passed' => 'yes',
            'docs_health_ok' => true,
            'architecture_validate_ok' => false,
            'git_diff_check_passed' => true,
            'uncovered_changed_paths' => 'app/Foo.php',
            'commands' => 'php artisan test',
            'unexpected' => true,
        ]);

        $reasons = array_column($payload['errors'], 'reason');

        $this->assertSame('invalid_shape', $payload['status']);
        $this->assertContains('required_key_must_be_boolean', $reasons);
        $this->assertContains('missing_required_boolean_key', $reasons);
        $this->assertContains('key_must_be_array', $reasons);
        $this->assertContains('unknown_validation_evidence_key', $reasons);
        $this->assertSame('repair_validation_evidence_shape_before_completion_report', $payload['next_action']);
    }
}
