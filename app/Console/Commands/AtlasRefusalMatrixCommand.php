<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasRefusalMatrixService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas AI Cyber Refusal Matrix CLI.
 *
 *   php artisan atlas:aaeos:refusal-matrix
 *     [--technique=dos]
 *     [--target=staging.example.com]
 *     [--target-in-scope]
 *     [--clauses=operator_clause,non_prod_dedicated_env,window_under_1h]
 *     [--actor=provider_tool]              // operator | provider_tool
 *     [--bypass-attempt=0]
 *     [--skill=cyber-bb-runner]
 *     [--json]
 *
 * Read-only, deterministic. A cyber-* skill consults this matrix BEFORE
 * proposing an action to Atlas Decide; the command returns the decision
 * (refuse | allow_with_clause | allow) plus the Refusal-with-Receipt. It NEVER
 * executes the requested action.
 *
 * @see docs/engineering-knowledge-base/cyber-security/refusal-matrix.md
 */
class AtlasRefusalMatrixCommand extends Command
{
    protected $signature = 'atlas:aaeos:refusal-matrix
        {--technique= : requested technique/action label (e.g. dos, typosquatting, c2 channel)}
        {--target= : requested target (optional)}
        {--target-in-scope : assert the target is listed in the BB scope.in}
        {--clauses= : comma-separated exception-clause tokens supplied by the operator}
        {--actor= : actor requesting the action: operator | provider_tool}
        {--bypass-attempt= : count of prior refusals retried this operation}
        {--skill= : the cyber-* skill consulting the matrix}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas cyber · refusal matrix that decides refuse|allow_with_clause|allow for a requested action and emits a Refusal-with-Receipt.';

    public function handle(AtlasRefusalMatrixService $service): int
    {
        try {
            $bypassRaw = $this->option('bypass-attempt');
            $actorRaw = $this->option('actor');

            $request = [
                'technique' => $this->str('technique') ?? 'dos',
                'target' => $this->str('target') ?? '',
                'target_in_scope' => (bool) $this->option('target-in-scope'),
                'clauses' => $this->list('clauses'),
                'actor_requested' => is_string($actorRaw) && trim($actorRaw) !== ''
                    ? trim($actorRaw)
                    : 'provider_tool',
                'bypass_attempt' => is_string($bypassRaw) && ctype_digit(trim($bypassRaw))
                    ? (int) trim($bypassRaw)
                    : 0,
                'skill_id' => $this->str('skill') ?? 'cyber-bb-runner',
            ];

            $result = $service->evaluate($request);

            $this->line((string) json_encode(
                ['ok' => true, 'result' => $result],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            // A refused (or run-aborting) decision is a non-zero exit so callers
            // and CI can gate on it.
            return $result['decision'] === AtlasRefusalMatrixService::DECISION_REFUSE
                ? self::FAILURE
                : self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'refusal_matrix_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }

    private function str(string $option): ?string
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        return trim($raw);
    }

    /**
     * @return list<string>
     */
    private function list(string $option): array
    {
        $raw = $this->option($option);
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        return array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            static fn ($v) => $v !== '',
        ));
    }
}
