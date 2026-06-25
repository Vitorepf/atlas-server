<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Autopoiesis\AtlasSelfConstructionAutopoiesisExperimentDesigner;
use App\Services\Ai\SelfConstruction\Autopoiesis\AtlasSelfConstructionAutopoiesisGuardrailGate;
use App\Services\Ai\SelfConstruction\Autopoiesis\AtlasSelfConstructionAutopoiesisOutcomeInterpreter;
use App\Services\Ai\SelfConstruction\OperatorInterface\AtlasSelfConstructionOperatorDependencyRegressionGate;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only CLI for the Self-Construction Autopoiesis surface.
 *
 * Verbs:
 *   hypothesize — echo a parsed hypothesis payload (shape-validate; no proposal generation).
 *   design      — invoke the experiment designer over a hypothesis JSON.
 *   gate        — invoke the operator-dependency-regression gate over the supplied plan.
 *   interpret   — invoke the outcome interpreter over experiment FACTS.
 *
 * Every response carries a `governed_organ` envelope field declaring that Autopoiesis is one organ of
 * the larger Self-Construction OS — NOT the whole system. CLI is FACTS-ONLY.
 */
final class AtlasSelfConstructionAutopoiesisCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:self-construction:autopoiesis {action : hypothesize|design|gate|interpret|guardrail} {--facts=} {--json}';

    /** @var string */
    protected $description = 'Read-only Autopoiesis surface: hypothesize / design / gate / interpret.';

    public const GOVERNED_ORGAN_STATEMENT = [
        'role' => 'governed_organ',
        'not' => 'whole_self_construction_os',
        'authority' => 'propose_only',
        'verification_owned_by' => 'verification_court',
        'merge_owned_by' => 'merge_governor',
        'learning_promote_owned_by' => 'learning_transfer',
    ];

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $facts = $this->readJson('facts');

        $payload = match ($action) {
            'hypothesize' => $this->hypothesize($facts),
            'design' => $this->design($facts),
            'gate' => $this->gate($facts),
            'interpret' => $this->interpret($facts),
            'guardrail' => $this->guardrail($facts),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $payload['governed_organ'] = self::GOVERNED_ORGAN_STATEMENT;
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return ($payload['status'] ?? 'ok') === 'ok' || ! isset($payload['status']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function hypothesize(?array $facts): array
    {
        if (! is_array($facts) || ! isset($facts['hypothesis_id'], $facts['claim'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with hypothesis_id + claim required'];
        }

        return ['status' => 'ok', 'hypothesis' => [
            'hypothesis_id' => (string) $facts['hypothesis_id'],
            'claim' => (string) $facts['claim'],
            'scope_paths' => is_array($facts['scope_paths'] ?? null) ? array_values(array_map('strval', $facts['scope_paths'])) : [],
        ]];
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function design(?array $facts): array
    {
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        try {
            $r = $this->app()->make(AtlasSelfConstructionAutopoiesisExperimentDesigner::class)->design($facts);
        } catch (Throwable $e) {
            return ['status' => 'design_invalid', 'reason' => $e->getMessage()];
        }

        return ['status' => 'ok', 'experiment_plan' => $r];
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function gate(?array $facts): array
    {
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        $r = $this->app()->make(AtlasSelfConstructionOperatorDependencyRegressionGate::class)->evaluate($facts);

        return ['status' => 'ok', 'dependency_regression_gate' => $r];
    }

    /**
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function interpret(?array $facts): array
    {
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        $r = $this->app()->make(AtlasSelfConstructionAutopoiesisOutcomeInterpreter::class)->interpret($facts);

        return ['status' => 'ok', 'outcome' => $r];
    }

    /**
     * `guardrail` runs the Autopoiesis guardrail gate over a candidate experiment payload and
     * emits the pure verdict envelope {schema_version, accepted, action, blockers, allowed_class}.
     * Wired here so AtlasSelfConstructionAutopoiesisGuardrailGate reaches a real production call path.
     *
     * @param  array<string,mixed>|null  $facts
     * @return array<string,mixed>
     */
    private function guardrail(?array $facts): array
    {
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        $verdict = $this->app()->make(AtlasSelfConstructionAutopoiesisGuardrailGate::class)->evaluate($facts);

        return ['status' => 'ok', 'guardrail' => $verdict];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readJson(string $optionName): ?array
    {
        $path = (string) ($this->option($optionName) ?? '');
        if ($path === '' || ! is_file($path)) {
            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @return \Illuminate\Contracts\Container\Container */
    private function app()
    {
        return $this->getLaravel();
    }
}
