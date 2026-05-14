<?php

namespace App\Services\Ai\Programming\Sdd;

use App\Models\AtlasDecisionReceipt;
use App\Models\AtlasOperation;
use App\Models\AtlasPlan;
use App\Models\AtlasSpec;
use App\Services\Ai\Programming\Sdd\Enums\AutonomyLevel;
use App\Services\Ai\Programming\Sdd\Pipeline\ContextPack;
use App\Services\Ai\Programming\Sdd\Pipeline\Intent;
use App\Services\Ai\Programming\Sdd\Pipeline\OperationEnvelope;
use Illuminate\Support\Str;

/**
 * Creates the signed Decision Receipt that authorizes runtime execution.
 *
 * Hard law (data-model-and-services.md:295): no controller may bypass this
 * receipt. The runtime executor MUST validate it before each file write.
 */
class DecisionEngine
{
    /**
     * @param  array<string,mixed>  $plan
     * @param  list<array<string,mixed>>  $tasks
     */
    public function createReceipt(
        OperationEnvelope $envelope,
        AtlasOperation $operation,
        AtlasSpec $spec,
        AtlasPlan $plan,
        array $tasks,
        ContextPack $context,
        Intent $intent,
        ?AutonomyLevel $requestedAutonomy = null,
    ): AtlasDecisionReceipt {
        $autonomy = $this->resolveAutonomy($intent, $requestedAutonomy);

        $allowedFiles = array_values(array_unique(array_merge(
            (array) ($plan->target_files_json ?? []),
            $this->collectFromTasks($tasks, 'allowed_files'),
        )));
        $forbiddenFiles = array_values(array_unique(array_merge(
            (array) ($plan->forbidden_files_json ?? []),
            $this->collectFromTasks($tasks, 'forbidden_files'),
        )));

        $allowedActions = $this->resolveAllowedActions($autonomy);
        $forbiddenActions = $this->resolveForbiddenActions($autonomy);
        $requiredGates = $this->resolveRequiredGates($intent, $autonomy);

        $taskIds = array_values(array_filter(array_map(static fn ($t) => is_array($t) ? ($t['id'] ?? $t['code'] ?? null) : null, $tasks)));

        $payload = [
            'operation_id' => $operation->id,
            'spec_id' => $spec->id,
            'plan_id' => $plan->id,
            'autonomy_level' => $autonomy->value,
            'allowed_actions' => $allowedActions,
            'forbidden_actions' => $forbiddenActions,
            'allowed_files' => $allowedFiles,
            'forbidden_files' => $forbiddenFiles,
            'required_gates' => $requiredGates,
            'task_ids' => $taskIds,
            'context_digest' => $context->digest,
            'context_packages' => $context->packages,
            'envelope_user' => $envelope->userId,
        ];
        $inputJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        $inputHash = hash('sha256', $inputJson);
        $outputHash = hash('sha256', $inputHash.'|'.now()->toJSON());
        $receiptId = 'rcpt_'.substr(hash('sha256', $inputHash.'|'.Str::ulid()), 0, 48);
        $signature = hash_hmac('sha256', $receiptId.'|'.$inputHash, config('app.key', 'atlas-fallback-key'));

        return AtlasDecisionReceipt::query()->create([
            'receipt_id' => $receiptId,
            'operation_id' => $operation->id,
            'spec_id' => $spec->id,
            'plan_id' => $plan->id,
            'autonomy_level' => $autonomy->value,
            'allowed_actions_json' => $allowedActions,
            'forbidden_actions_json' => $forbiddenActions,
            'allowed_files_json' => $allowedFiles,
            'forbidden_files_json' => $forbiddenFiles,
            'required_gates_json' => $requiredGates,
            'task_ids_json' => $taskIds,
            'context_pack_refs_json' => [
                'digest' => $context->digest,
                'stack' => $context->stack,
                'packages' => $context->packages,
            ],
            'input_hash' => $inputHash,
            'output_hash' => $outputHash,
            'signature' => $signature,
            'signed_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
    }

    public function authorizesFileWrite(AtlasDecisionReceipt $receipt, string $relativePath): bool
    {
        if (! $receipt->isActive()) {
            return false;
        }
        if (! AutonomyLevel::from($receipt->autonomy_level)->allowsImplementation()) {
            return false;
        }
        foreach ((array) $receipt->forbidden_files_json as $pattern) {
            if ($this->matches($pattern, $relativePath)) {
                return false;
            }
        }
        foreach ((array) $receipt->allowed_files_json as $pattern) {
            if ($this->matches($pattern, $relativePath)) {
                return true;
            }
        }

        return false;
    }

    public function revoke(AtlasDecisionReceipt $receipt, string $reason): void
    {
        $receipt->forceFill([
            'revoked_at' => now(),
            'revoked_reason' => substr($reason, 0, 255),
        ])->save();
    }

    private function resolveAutonomy(Intent $intent, ?AutonomyLevel $requested): AutonomyLevel
    {
        if ($intent->confidenceClass->isBlocking()) {
            return AutonomyLevel::L0Manual;
        }
        if ($requested !== null) {
            return $requested;
        }

        return match ($intent->riskLevel) {
            'critical' => AutonomyLevel::L0Manual,
            'high' => AutonomyLevel::L1Assisted,
            'medium' => AutonomyLevel::L2AutoPatch,
            default => AutonomyLevel::L2AutoPatch,
        };
    }

    /**
     * @return list<string>
     */
    private function resolveAllowedActions(AutonomyLevel $autonomy): array
    {
        $actions = ['read', 'inspect', 'attach_evidence'];
        if ($autonomy->allowsImplementation()) {
            $actions[] = 'write_allowed_files';
            $actions[] = 'run_validation_commands';
        }
        if ($autonomy->allowsPullRequest()) {
            $actions[] = 'open_pull_request';
        }
        if ($autonomy->allowsAutoMerge()) {
            $actions[] = 'merge_pull_request';
        }

        return $actions;
    }

    /**
     * @return list<string>
     */
    private function resolveForbiddenActions(AutonomyLevel $autonomy): array
    {
        $forbidden = ['mutate_kernel_policy', 'rotate_secrets', 'delete_database'];
        if (! $autonomy->allowsImplementation()) {
            $forbidden[] = 'write_files';
        }
        if (! $autonomy->allowsAutoMerge()) {
            $forbidden[] = 'merge_to_protected_branch';
        }

        return $forbidden;
    }

    /**
     * @return list<string>
     */
    private function resolveRequiredGates(Intent $intent, AutonomyLevel $autonomy): array
    {
        $gates = ['evidence-required', 'scope-guard', 'completion'];
        if ($intent->harnessRequired || $intent->riskLevel === 'high' || $intent->riskLevel === 'critical') {
            $gates[] = 'docs-health';
            $gates[] = 'code-intelligence-context';
            $gates[] = 'spec-before-code';
        }
        if ($autonomy->allowsAutoMerge()) {
            $gates[] = 'integration-queue-clean';
        }

        return array_values(array_unique($gates));
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     * @return list<string>
     */
    private function collectFromTasks(array $tasks, string $key): array
    {
        $values = [];
        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            foreach ((array) ($task[$key] ?? []) as $v) {
                if (is_string($v) && trim($v) !== '') {
                    $values[] = $v;
                }
            }
        }

        return $values;
    }

    private function matches(string $pattern, string $path): bool
    {
        if ($pattern === $path) {
            return true;
        }
        if (str_contains($pattern, '*') && fnmatch($pattern, $path, FNM_NOESCAPE)) {
            return true;
        }
        if (str_ends_with($pattern, '/') && str_starts_with($path, $pattern)) {
            return true;
        }

        return false;
    }
}
