<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AutonomousRuntime\AtlasAutonomousRuntimeHeartbeatLedger;
use App\Services\Ai\SelfConstruction\AutonomousRuntime\AtlasAutonomousRuntimeOrganPipelineComposer;
use App\Services\Ai\SelfConstruction\AutonomousRuntime\AtlasAutonomousRuntimeSafetyStopGate;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator-visible CLI for the Atlas-native autonomous runtime plan.
 *
 * Verbs:
 *   inspect   — facts-only: services + non-execution guarantees.
 *   cycle     — compose organ_facts into an ordered cycle plan via the pipeline composer.
 *   safety    — evaluate the safety-stop gate over the supplied facts envelope.
 *   plan      — emit the planned ordered_stages + safety verdict; no I/O.
 *   heartbeat — write a heartbeat row to --ledger ONLY when --ledger is supplied AND payload validates.
 *               No --ledger ⇒ status=dry_run.
 */
final class AtlasSelfConstructionAutonomousRuntimeCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:self-construction:runtime {action : inspect|cycle|safety|heartbeat|heartbeat-ledger|plan} {--facts=} {--ledger=} {--json}';

    /** @var string */
    protected $description = 'Atlas-native autonomous runtime CLI (read-only / operator-visible).';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $payload = match ($action) {
            'inspect' => $this->inspect(),
            'cycle' => $this->cycle(),
            'safety' => $this->safety(),
            'plan' => $this->plan(),
            'heartbeat' => $this->heartbeat(),
            'heartbeat-ledger' => $this->heartbeatLedger(),
            default => ['status' => 'unknown_action', 'action' => $action],
        };
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return ($payload['status'] ?? 'ok') === 'ok' || ! isset($payload['status']) ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,mixed>
     */
    private function inspect(): array
    {
        return [
            'status' => 'ok',
            'verbs' => ['inspect', 'cycle', 'safety', 'heartbeat', 'heartbeat-ledger', 'plan'],
            'services' => [
                AtlasAutonomousRuntimeOrganPipelineComposer::SCHEMA,
                AtlasAutonomousRuntimeSafetyStopGate::SCHEMA,
            ],
            'organ_order' => AtlasAutonomousRuntimeOrganPipelineComposer::ORGAN_ORDER,
            'non_execution_guarantees' => [
                'starts_workers' => false,
                'invokes_shell_or_git' => false,
                'calls_external_providers' => false,
                'writes_storage_when_no_ledger' => false,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function cycle(): array
    {
        $facts = $this->readJson('facts');
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        $organs = is_array($facts['organ_facts'] ?? null) ? $facts['organ_facts'] : [];
        $plan = $this->app()->make(AtlasAutonomousRuntimeOrganPipelineComposer::class)->compose($organs);

        return ['status' => 'ok', 'cycle_plan' => $plan];
    }

    /**
     * @return array<string,mixed>
     */
    private function safety(): array
    {
        $facts = $this->readJson('facts');
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        $safetyFacts = is_array($facts['safety_facts'] ?? null) ? $facts['safety_facts'] : $facts;
        $verdict = $this->app()->make(AtlasAutonomousRuntimeSafetyStopGate::class)->evaluate($safetyFacts);

        return ['status' => 'ok', 'safety_verdict' => $verdict];
    }

    /**
     * @return array<string,mixed>
     */
    private function plan(): array
    {
        $facts = $this->readJson('facts');
        if (! is_array($facts)) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON file required'];
        }
        $organs = is_array($facts['organ_facts'] ?? null) ? $facts['organ_facts'] : [];
        $safetyFacts = is_array($facts['safety_facts'] ?? null) ? $facts['safety_facts'] : [];
        $plan = $this->app()->make(AtlasAutonomousRuntimeOrganPipelineComposer::class)->compose($organs);
        $safety = $this->app()->make(AtlasAutonomousRuntimeSafetyStopGate::class)->evaluate($safetyFacts);

        return ['status' => 'ok', 'cycle_plan' => $plan, 'safety_verdict' => $safety];
    }

    /**
     * @return array<string,mixed>
     */
    private function heartbeat(): array
    {
        $facts = $this->readJson('facts');
        if (! is_array($facts) || ! isset($facts['heartbeat'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with heartbeat key required'];
        }
        $hb = $facts['heartbeat'];
        if (! is_array($hb) || ! isset($hb['ts_iso8601'], $hb['cycle_id'])) {
            return ['status' => 'heartbeat_invalid', 'reason' => 'heartbeat.ts_iso8601 and heartbeat.cycle_id required'];
        }
        $ledgerPath = (string) ($this->option('ledger') ?? '');
        if ($ledgerPath === '') {
            return ['status' => 'dry_run', 'heartbeat' => $hb];
        }
        try {
            $dir = dirname($ledgerPath);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            $fh = fopen($ledgerPath, 'a');
            if ($fh === false) {
                return ['status' => 'error', 'reason' => 'ledger_unopenable'];
            }
            flock($fh, LOCK_EX);
            fwrite($fh, (string) json_encode($hb, JSON_UNESCAPED_SLASHES)."\n");
            fflush($fh);
            flock($fh, LOCK_UN);
            fclose($fh);
        } catch (Throwable $e) {
            return ['status' => 'error', 'reason' => $e->getMessage()];
        }

        return ['status' => 'ok', 'wrote' => $ledgerPath, 'heartbeat' => $hb];
    }

    /**
     * Validated heartbeat → ledger append. Unlike `heartbeat` (which hand-rolls
     * fopen/flock and validates nothing), this verb routes the append through
     * {@see AtlasAutonomousRuntimeHeartbeatLedger::append()}, which validates the
     * required fields (cycle_id, state, decision, safety_verdict, plan_hash,
     * evidence_refs, ts_unix) fail-closed and never touches the file on blockers.
     *
     * @return array<string,mixed>
     */
    private function heartbeatLedger(): array
    {
        $facts = $this->readJson('facts');
        if (! is_array($facts) || ! isset($facts['heartbeat']) || ! is_array($facts['heartbeat'])) {
            return ['status' => 'usage_error', 'reason' => '--facts JSON with heartbeat object required'];
        }

        $ledgerPath = (string) ($this->option('ledger') ?? '');
        if ($ledgerPath === '') {
            return ['status' => 'dry_run', 'heartbeat' => $facts['heartbeat']];
        }

        $verdict = $this->app()->make(AtlasAutonomousRuntimeHeartbeatLedger::class, ['path' => $ledgerPath])
            ->append($facts['heartbeat']);

        if (! ($verdict['appended'] ?? false)) {
            return [
                'status' => 'heartbeat_invalid',
                'blockers' => $verdict['blockers'] ?? [],
                'heartbeat' => $facts['heartbeat'],
            ];
        }

        return [
            'status' => 'ok',
            'wrote' => $ledgerPath,
            'appended' => true,
            'heartbeat' => $facts['heartbeat'],
        ];
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
