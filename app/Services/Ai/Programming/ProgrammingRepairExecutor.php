<?php

namespace App\Services\Ai\Programming;

class ProgrammingRepairExecutor
{
    /**
     * @param  array<string,mixed>  $failurePacket
     * @param  array<string,mixed>  $retrievalPlan
     * @return array<string,mixed>
     */
    public function attemptPlan(array $failurePacket, array $retrievalPlan, int $attempt, int $maxAttempts): array
    {
        $noProgress = $attempt > 1
            && (string) data_get($failurePacket, 'failure_hash') === (string) data_get($failurePacket, 'previous_failure_hash');
        $status = $noProgress ? 'blocked_no_progress' : ($attempt <= $maxAttempts ? 'planned' : 'blocked_max_attempts');
        $nextAction = $noProgress || $attempt > $maxAttempts ? 'human_review' : 'patch_repair_then_retest';

        return [
            'schema_version' => 'atlas.programming.repair_attempt.plan.v1',
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'status' => $status,
            'failure_packet_hash' => hash('sha256', json_encode($failurePacket, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'retrieval_plan_id' => data_get($retrievalPlan, 'retrieval_receipt.receipt_id'),
            'repair_capsule' => $this->repairCapsule($failurePacket, $retrievalPlan, $attempt, $maxAttempts, $status, $nextAction),
            'required_receipts' => [
                'failure_packet',
                'patch_manifest',
                'test_manifest',
                'patch_verifier_report',
            ],
            'next_action' => $nextAction,
        ];
    }

    /**
     * @param  array<string,mixed>  $failurePacket
     * @param  array<string,mixed>  $retrievalPlan
     * @return array<string,mixed>
     */
    private function repairCapsule(array $failurePacket, array $retrievalPlan, int $attempt, int $maxAttempts, string $status, string $nextAction): array
    {
        $failureTaxonomy = $this->failureTaxonomy($failurePacket);
        $changedFiles = collect((array) data_get($failurePacket, 'changed_files', []))
            ->merge((array) data_get($retrievalPlan, 'professional_context_pack.changed_files', []))
            ->filter(fn (mixed $file): bool => is_string($file) && $file !== '')
            ->unique()
            ->values()
            ->all();
        $testCommands = collect((array) data_get($failurePacket, 'failed_commands', []))
            ->merge((array) data_get($failurePacket, 'commands', []))
            ->merge((array) data_get($retrievalPlan, 'test_impact.recommended_commands', []))
            ->filter(fn (mixed $command): bool => is_string($command) && $command !== '')
            ->unique()
            ->values()
            ->all();

        return [
            'schema_version' => 'atlas.programming.repair_capsule.v1',
            'status' => $status,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'failure_taxonomy' => $failureTaxonomy,
            'failure_summary' => [
                'failure_hash' => (string) data_get($failurePacket, 'failure_hash', ''),
                'previous_failure_hash' => (string) data_get($failurePacket, 'previous_failure_hash', ''),
                'primary_error' => (string) data_get($failurePacket, 'primary_error', data_get($failurePacket, 'error', 'unknown')),
                'command' => (string) data_get($failurePacket, 'command', ''),
                'exit_code' => data_get($failurePacket, 'exit_code'),
            ],
            'scope' => [
                'changed_files' => $changedFiles,
                'max_changed_files_without_human_review' => 12,
                'allow_scope_expansion' => false,
                'scope_expansion_requires_human_review' => true,
            ],
            'context' => [
                'retrieval_plan_id' => data_get($retrievalPlan, 'retrieval_receipt.receipt_id'),
                'context_pack_hash' => data_get($retrievalPlan, 'professional_context_pack.context_pack_hash'),
                'ranked_ref_count' => count((array) data_get($retrievalPlan, 'professional_context_pack.ranked_refs', [])),
                'provider_safe' => (bool) data_get($retrievalPlan, 'professional_context_pack.provider_safe', true),
            ],
            'provider_policy' => [
                'preserve_original_provider' => true,
                'preserve_original_model' => true,
                'fallback_allowed' => false,
                'provider_bypass_allowed' => false,
            ],
            'verification_plan' => [
                'commands' => $testCommands,
                'patch_verifier_required' => true,
                'test_manifest_required' => true,
                'action_manifest_required' => true,
                'rollback_required' => true,
            ],
            'stop_rule' => [
                'next_action' => $nextAction,
                'human_review_required' => $nextAction === 'human_review',
                'block_if_same_failure_repeats' => true,
                'block_after_attempt' => $maxAttempts,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $failurePacket
     * @return array<string,mixed>
     */
    private function failureTaxonomy(array $failurePacket): array
    {
        $signals = collect([
            (string) data_get($failurePacket, 'failure_type', ''),
            (string) data_get($failurePacket, 'primary_error', ''),
            (string) data_get($failurePacket, 'error', ''),
            (string) data_get($failurePacket, 'command', ''),
        ])->implode(' ');
        $signals = strtolower($signals);

        $category = match (true) {
            str_contains($signals, 'syntax') || str_contains($signals, 'parse error') => 'syntax_error',
            str_contains($signals, 'lint') || str_contains($signals, 'pint') || str_contains($signals, 'format') => 'style_or_static_check',
            str_contains($signals, 'test') || str_contains($signals, 'assert') || str_contains($signals, 'phpunit') => 'test_failure',
            str_contains($signals, 'migration') || str_contains($signals, 'database') || str_contains($signals, 'sql') => 'data_contract_failure',
            str_contains($signals, 'timeout') || str_contains($signals, 'timed out') => 'timeout',
            default => 'unknown_failure',
        };

        return [
            'schema_version' => 'atlas.programming.failure_taxonomy.v1',
            'category' => $category,
            'repair_strategy' => match ($category) {
                'syntax_error' => 'minimal_parse_fix_then_targeted_test',
                'test_failure' => 'fix_smallest_behavioral_cause_then_rerun_failed_tests',
                'style_or_static_check' => 'format_or_static_contract_fix_then_rerun_check',
                'data_contract_failure' => 'verify_schema_contract_before_patch',
                'timeout' => 'reduce_runtime_or_isolate_hanging_step_before_retry',
                default => 'inspect_failure_packet_before_patch',
            },
            'confidence' => $category === 'unknown_failure' ? 'low' : 'medium',
        ];
    }
}
