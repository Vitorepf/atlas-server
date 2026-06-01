<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevPolicyService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Policy invariant decider CLI.
 *
 *   php artisan atlas:aaeos:atlas-dev-policy
 *     [--kind=run]                         // run | runtime_execute | apply_patch | edit_doc | ...
 *     [--target-path=app/Services/Ai/Programming/AtlasDev/Schemas/Foo.php]
 *     [--out-of-scope-kind=conceptual_research]
 *     [--provider=claude_cli]
 *     [--write]
 *     [--fallback-allowed]
 *     [--operator-confirmed]
 *     [--token-state=valid]                // valid | missing | expired | reused
 *     [--contract-valid]
 *     [--scope-guard-status=passed]        // passed | needs_review | failed
 *     [--json]
 *
 * Read-only, deterministic. Classifies a proposed Atlas Dev action against the
 * 17 invariants and returns the verdict (allow | needs_decision_receipt |
 * policy_wins | delegate_to_other_flow). It NEVER executes the action.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-policy.md
 */
class AtlasDevPolicyCommand extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-policy
        {--kind= : proposed action kind (run, runtime_execute, apply_patch, edit_doc, ...)}
        {--target-path= : path the action would touch (for P17 leakage checks)}
        {--out-of-scope-kind= : if out of Atlas Dev scope, the kind (e.g. conceptual_research)}
        {--provider= : provider lock for the run (default claude_cli)}
        {--write : the run performs a write}
        {--fallback-allowed : assert fallback is allowed within the run (P11 violation)}
        {--operator-confirmed : operator confirmed the run (P5)}
        {--token-state= : confirmation token state: valid|missing|expired|reused}
        {--contract-valid : task_contract_hash references a persisted plan}
        {--scope-guard-status= : passed|needs_review|failed}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Dev · invariant decider that judges a proposed action against the 17 Atlas Dev Policy invariants (P1-P17).';

    public function handle(AtlasDevPolicyService $service): int
    {
        try {
            $run = [
                'provider' => $this->str('provider') ?? 'claude_cli',
                'write' => (bool) $this->option('write'),
                'fallback_allowed' => (bool) $this->option('fallback-allowed'),
                'operator_confirmed' => (bool) $this->option('operator-confirmed'),
                'confirmation_token_state' => $this->str('token-state') ?? 'valid',
                'task_contract_hash_valid' => (bool) $this->option('contract-valid'),
            ];
            $scopeStatus = $this->str('scope-guard-status');
            if ($scopeStatus !== null) {
                $run['scope_guard_status'] = $scopeStatus;
            }

            $action = [
                'kind' => $this->str('kind') ?? 'run',
                'target_path' => $this->str('target-path') ?? '',
                'out_of_scope_kind' => $this->str('out-of-scope-kind'),
                'run' => $run,
            ];

            $result = $service->evaluate($action);

            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'kind' => AtlasDevPolicyService::VERDICT_KIND,
                'error' => true,
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    private function str(string $option): ?string
    {
        $raw = $this->option($option);

        return is_string($raw) && trim($raw) !== '' ? trim($raw) : null;
    }
}
