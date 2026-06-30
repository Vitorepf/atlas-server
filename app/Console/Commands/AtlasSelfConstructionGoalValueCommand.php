<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\GoalValue\AtlasGoalValueAntiProxyGate;
use App\Services\Ai\SelfConstruction\GoalValue\AtlasGoalValueDecisionPolicy;
use App\Services\Ai\SelfConstruction\GoalValue\AtlasGoalValueOutcomeEvidenceEvaluator;
use App\Services\Ai\SelfConstruction\GoalValue\AtlasGoalValueRealLeverageContract;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only operator surface for the goal-value brain:
 *
 *   contract    — runs AtlasGoalValueRealLeverageContract over `dimension_evidence` facts.
 *   anti-proxy  — runs AtlasGoalValueAntiProxyGate over `signals` + `real_levers` facts.
 *   evidence    — echoes a normalized facts envelope (no business decision, for inspection).
 *   decide      — runs AtlasGoalValueDecisionPolicy over composed leverage + gate + verification facts.
 *
 * EVERY action is read-only. Fail-closed on missing/invalid facts payload (exit 2).
 */
final class AtlasSelfConstructionGoalValueCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:self-construction:goal-value {action : contract|anti-proxy|evidence|decide|outcome-evidence} {--facts= : path to a JSON facts payload} {--json}';

    protected $description = 'Read-only goal-value CLI: contract | anti-proxy | evidence | decide | outcome-evidence.';

    public function handle(
        AtlasGoalValueRealLeverageContract $contract,
        AtlasGoalValueAntiProxyGate $gate,
        AtlasGoalValueDecisionPolicy $policy,
        AtlasGoalValueOutcomeEvidenceEvaluator $evaluator,
    ): int {
        $action = (string) $this->argument('action');
        $facts = $this->loadFacts();
        if ($facts === null) {
            return self::EXIT_USAGE;
        }

        $payload = match ($action) {
            'contract' => $contract->evaluate((array) ($facts['dimension_evidence'] ?? [])),
            'anti-proxy' => $gate->evaluate((array) ($facts['signals'] ?? []), (array) ($facts['real_levers'] ?? [])),
            'evidence' => ['echo' => $facts],
            'decide' => $policy->decide(
                (array) ($facts['leverage_verdict'] ?? []),
                (array) ($facts['anti_proxy_verdict'] ?? []),
                (array) ($facts['verification'] ?? []),
            ),
            'outcome-evidence' => $evaluator->evaluate($facts),
            default => null,
        };
        if ($payload === null) {
            $this->refuseUsage('unknown action: '.$action);

            return self::EXIT_USAGE;
        }

        $this->emit($payload);

        return self::EXIT_OK;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadFacts(): ?array
    {
        $path = (string) $this->option('facts');
        if ($path === '' || ! is_file($path)) {
            $this->refuseUsage('--facts=<path> is required and must point to an existing JSON file');

            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->refuseUsage('facts payload not valid JSON: '.mb_substr($e->getMessage(), 0, 200));

            return null;
        }
        if (! is_array($decoded)) {
            $this->refuseUsage('facts payload root must be a JSON object');

            return null;
        }

        return $decoded;
    }

    private function refuseUsage(string $reason): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => $reason], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $this->error($reason);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($payload as $k => $v) {
            $this->line($k.': '.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }
}
