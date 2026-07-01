<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentValidationGateRepairRecommendationBuilder;
use PHPUnit\Framework\TestCase;

final class AgentValidationGateRepairRecommendationBuilderTest extends TestCase
{
    private function failureClassification(string $category, bool $requiresHuman = false): array
    {
        return [
            'is_failure_classification' => true,
            'gate_id' => 'gate-1',
            'category' => $category,
            'recovery_class' => 'auto_repairable',
            'severity' => 'medium',
            'requires_human_review' => $requiresHuman,
        ];
    }

    public function test_scope_violation_recommendations_include_forbidden_files_and_forbid_broad_operations(): void
    {
        $builder = new AgentValidationGateRepairRecommendationBuilder;

        $result = $builder->recommend(
            $this->failureClassification('scope_violation'),
            ['gate_id' => 'gate-1'],
            ['allowed_files' => ['app/Foo.php'], 'forbidden_files' => ['.env', 'config/app.php']],
        );

        $this->assertSame(['.env', 'config/app.php'], $result['forbidden_files']);
        $this->assertContains('no_git_add_all', $result['forbidden_ops']);
        $this->assertContains('no_broad_git_operations', $result['forbidden_ops']);
        $this->assertContains('no_broad_filesystem_operations', $result['forbidden_ops']);
        $this->assertContains('edit_inside_forbidden_files', $result['forbidden_ops']);
    }

    public function test_no_repair_required_keeps_status_and_none_evidence(): void
    {
        $builder = new AgentValidationGateRepairRecommendationBuilder;

        $result = $builder->recommend(
            ['is_failure_classification' => false, 'gate_id' => 'gate-1'],
            ['gate_id' => 'gate-1'],
        );

        $this->assertSame('no_repair_required', $result['status']);
        $this->assertSame(['none'], $result['evidence_needed']);
    }

    public function test_empty_allowed_files_escalates_instead_of_retry_inside_same_session(): void
    {
        $builder = new AgentValidationGateRepairRecommendationBuilder;

        $result = $builder->recommend(
            $this->failureClassification('lint_error'),
            ['gate_id' => 'gate-1'],
            ['allowed_files' => []],
        );

        $this->assertSame('human_review_required_empty_allowed_files', $result['escalation']);
        $this->assertNotSame('retry_inside_same_session', $result['escalation']);
    }

    public function test_non_empty_allowed_files_retries_inside_same_session_when_not_requiring_human(): void
    {
        $builder = new AgentValidationGateRepairRecommendationBuilder;

        $result = $builder->recommend(
            $this->failureClassification('lint_error'),
            ['gate_id' => 'gate-1'],
            ['allowed_files' => ['app/Foo.php']],
        );

        $this->assertSame('retry_inside_same_session', $result['escalation']);
    }
}
