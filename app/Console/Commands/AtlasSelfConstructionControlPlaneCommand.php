<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionNextActionSelector;
use App\Services\Ai\SelfConstruction\ControlPlane\AtlasSelfConstructionOrganReadinessComposer;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only operator CLI for the Self-Construction Control Plane.
 *
 *   inspect       describe required services + non-execution guarantees.
 *   mode          read facts.autonomy_mode and emit a policy verdict.
 *   scope         read facts.scope_gate and emit the scope risk-budget verdict.
 *   next          compose organ-readiness + mode + scope + work_queue facts and print the next
 *                  action WITHOUT executing it.
 *
 * NEVER enqueues, dispatches, executes, merges, or writes ledgers.
 */
final class AtlasSelfConstructionControlPlaneCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:self-construction:control-plane {action : inspect|mode|scope|next} {--facts= : path to a JSON facts payload} {--json}';

    protected $description = 'Read-only Control-Plane CLI: inspect | mode | scope | next.';

    public function handle(
        AtlasSelfConstructionOrganReadinessComposer $organReadiness,
        AtlasSelfConstructionNextActionSelector $selector,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'inspect' => $this->inspectAction(),
            'mode' => $this->modeAction(),
            'scope' => $this->scopeAction(),
            'next' => $this->nextAction($organReadiness, $selector),
            default => $this->usage('unknown action: '.$action),
        };
    }

    private function inspectAction(): int
    {
        $this->emit([
            'required_services' => [
                AtlasSelfConstructionOrganReadinessComposer::class,
                AtlasSelfConstructionNextActionSelector::class,
            ],
            'non_execution_guarantees' => [
                'never_enqueues',
                'never_dispatches_workers',
                'never_calls_providers',
                'never_runs_git_or_shell',
                'never_writes_ledgers',
                'never_merges_to_main',
            ],
        ]);

        return self::EXIT_OK;
    }

    private function modeAction(): int
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return self::EXIT_USAGE;
        }
        $mode = is_array($facts['autonomy_mode'] ?? null) ? $facts['autonomy_mode'] : ['mode' => 'off'];
        $modeName = (string) ($mode['mode'] ?? 'off');
        $this->emit([
            'autonomy_mode' => $modeName,
            'allowed_to_execute' => $modeName === 'execute',
            'reasons' => array_values((array) ($mode['reasons'] ?? [])),
        ]);

        return self::EXIT_OK;
    }

    private function scopeAction(): int
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return self::EXIT_USAGE;
        }
        $scope = is_array($facts['scope_gate'] ?? null) ? $facts['scope_gate'] : ['allowed' => false];
        $this->emit([
            'scope_gate_allowed' => (bool) ($scope['allowed'] ?? false),
            'reasons' => array_values((array) ($scope['reasons'] ?? [])),
        ]);

        return self::EXIT_OK;
    }

    private function nextAction(AtlasSelfConstructionOrganReadinessComposer $organReadiness, AtlasSelfConstructionNextActionSelector $selector): int
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return self::EXIT_USAGE;
        }
        $readiness = $organReadiness->compose((array) ($facts['organ_facts'] ?? []));
        $verdict = $selector->select(
            $readiness,
            (array) ($facts['scope_gate'] ?? []),
            (array) ($facts['autonomy_mode'] ?? []),
            (array) ($facts['work_queue'] ?? []),
        );
        $this->emit($verdict);

        return self::EXIT_OK;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadFacts(): ?array
    {
        $path = (string) $this->option('facts');
        if ($path === '' || ! is_file($path)) {
            $this->error('--facts=<path> is required and must point to an existing JSON file');

            return null;
        }
        try {
            $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            $this->error('facts payload not valid JSON: '.mb_substr($e->getMessage(), 0, 200));

            return null;
        }
        if (! is_array($decoded)) {
            $this->error('facts payload root must be a JSON object');

            return null;
        }

        return $decoded;
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

    private function usage(string $message): int
    {
        $this->error($message);

        return self::EXIT_USAGE;
    }
}
