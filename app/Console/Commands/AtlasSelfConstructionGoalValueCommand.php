<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LoadsFactsJsonOption;
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
    use LoadsFactsJsonOption;

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
            'decide' => $policy->decide([
                'verification_status' => (string) ($facts['verification']['color'] ?? ''),
                'has_implementation_evidence' => (bool) ($facts['leverage_verdict']['real_leverage'] ?? false),
                'compounding_metric' => $facts['leverage_verdict']['compounding_metric'] ?? null,
                'autonomy_unlock' => (bool) ($facts['anti_proxy_verdict']['autonomy_unlock'] ?? false),
                'downstream_consumer_evidence' => (bool) ($facts['anti_proxy_verdict']['downstream_consumer_evidence'] ?? false),
            ]),
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
