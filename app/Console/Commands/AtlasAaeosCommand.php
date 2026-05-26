<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AgenticEngineeringOs\AaeosPhaseHandoffService;
use App\Services\Ai\AgenticEngineeringOs\AtlasAaeosHttpPathFacadeService;
use App\Services\Ai\AgenticEngineeringOs\AtlasMissionControlCockpitService;
use App\Services\Ai\AgenticEngineeringOs\AtlasUniversalGatesEvaluator;
use App\Services\Ai\AgenticEngineeringOs\DepartmentContractRuntime;
use App\Services\Ai\AgenticEngineeringOs\RunbookOrchestrator;
use Illuminate\Console\Command;

/**
 * Atlas Agentic Engineering OS — operator entry point.
 *
 * Implements the canonical AAEOS CLI suite mandated by the runbook doc:
 *
 *   atlas:aaeos:runbook            — show the 17-phase canonical runbook
 *   atlas:aaeos:phase-handoff      — emit and validate an atlas.aaeos.phase.v1 envelope
 *   atlas:aaeos:phase-skip         — record a justified phase skip with receipt
 *   atlas:aaeos:department-status  — show department catalogue + canon-field coverage
 *   atlas:aaeos:cockpit            — render mission-control cockpit snapshot
 *   atlas:aaeos:universal-gates    — evaluate the 15 universal gates from a signals JSON file
 *
 * All sub-commands accept `--json` for machine output. Operator input is
 * limited to identifiers and hashes; raw payloads are rejected by the
 * underlying services (provider-safe by default).
 */
final class AtlasAaeosCommand extends Command
{
    protected $signature = 'atlas:aaeos
        {action : runbook|phase-handoff|phase-skip|department-status|cockpit|universal-gates|http-path-status}
        {--intent= : intent_id used by phase-handoff/cockpit/universal-gates}
        {--phase-in= : phase_in for phase-handoff/phase-skip}
        {--phase-out= : phase_out for phase-handoff}
        {--actor-kind=system : agent|operator|system}
        {--actor-id=aaeos.cli : actor identifier}
        {--evidence=* : sha256:* hashes (repeatable)}
        {--operator-signature= : operator signature for phase-handoff}
        {--autonomy=L1 : L0|L1|L2|L3|L4|L5|L6|L7}
        {--receipt= : receipt_id used by phase-skip}
        {--reason= : human-readable reason for phase-skip}
        {--signals= : JSON file with universal-gate signals}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Agentic Engineering OS — operator CLI for the 17-phase runbook.';

    public function handle(
        DepartmentContractRuntime $departments,
        AaeosPhaseHandoffService $phases,
        AtlasUniversalGatesEvaluator $gates,
        RunbookOrchestrator $runbook,
        AtlasMissionControlCockpitService $cockpit,
        AtlasAaeosHttpPathFacadeService $httpPath,
    ): int {
        $action = (string) $this->argument('action');
        $json = (bool) $this->option('json');

        return match ($action) {
            'runbook' => $this->runbook($runbook, $phases, $json),
            'phase-handoff' => $this->phaseHandoff($phases, $json),
            'phase-skip' => $this->phaseSkip($phases, $json),
            'department-status' => $this->departmentStatus($departments, $json),
            'cockpit' => $this->cockpit($cockpit, $json),
            'universal-gates' => $this->universalGates($gates, $json),
            'http-path-status' => $this->httpPathStatus($httpPath, $json),
            default => $this->failWith("unknown action '{$action}'"),
        };
    }

    private function httpPathStatus(AtlasAaeosHttpPathFacadeService $httpPath, bool $json): int
    {
        $configured = (string) config('atlas.aaeos.http_path_phase', 'legacy');
        $payload = $httpPath->telemetrySnapshot($configured);

        if ($json) {
            $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('AAEOS HTTP Path · '.$configured);
        $this->line('  facade_active: '.($payload['facade_active'] ? 'yes' : 'no'));
        foreach ($payload['counters'] as $name => $value) {
            $this->line(sprintf('  counter.%s: %d', $name, $value));
        }
        $samples = (int) ($payload['latency_ms']['samples'] ?? 0);
        if ($samples > 0) {
            $avg = (int) round(((int) $payload['latency_ms']['sum']) / max(1, $samples));
            $this->line(sprintf('  latency_ms.avg: %d (samples=%d, max=%d)', $avg, $samples, (int) $payload['latency_ms']['max']));
        } else {
            $this->line('  latency_ms: no samples yet');
        }

        return self::SUCCESS;
    }

    private function runbook(RunbookOrchestrator $runbook, AaeosPhaseHandoffService $phases, bool $json): int
    {
        $payload = [
            'schema' => 'atlas.aaeos.runbook.v1',
            'phase_count' => count(AaeosPhaseHandoffService::PHASES),
            'phases' => array_map(
                fn (string $p, int $i) => [
                    'index' => $i,
                    'phase' => $p,
                    'gates' => $phases->gatesForPhase($p),
                ],
                AaeosPhaseHandoffService::PHASES,
                array_keys(AaeosPhaseHandoffService::PHASES),
            ),
            'department_flow' => RunbookOrchestrator::DEFAULT_FLOW,
        ];

        $this->emit($payload, $json);

        return self::SUCCESS;
    }

    private function phaseHandoff(AaeosPhaseHandoffService $phases, bool $json): int
    {
        $intent = (string) ($this->option('intent') ?? '');
        $phaseIn = (string) ($this->option('phase-in') ?? '');
        $phaseOut = (string) ($this->option('phase-out') ?? '');
        if ($intent === '' || $phaseIn === '' || $phaseOut === '') {
            return $this->failWith('phase-handoff requires --intent, --phase-in and --phase-out');
        }

        try {
            $envelope = $phases->emit(
                intentId: $intent,
                phaseIn: $phaseIn,
                phaseOut: $phaseOut,
                actor: [
                    'kind' => (string) $this->option('actor-kind'),
                    'id' => (string) $this->option('actor-id'),
                    'provider' => null,
                ],
                inputs: [],
                outputs: [],
                evidenceHashes: array_values(array_filter((array) $this->option('evidence'))),
                operatorSignature: (string) ($this->option('operator-signature') ?? '') ?: null,
                autonomyLevel: (string) $this->option('autonomy'),
            );
        } catch (\Throwable $e) {
            return $this->failWith($e->getMessage());
        }
        $this->emit($envelope, $json);

        return self::SUCCESS;
    }

    private function phaseSkip(AaeosPhaseHandoffService $phases, bool $json): int
    {
        $intent = (string) ($this->option('intent') ?? '');
        $phase = (string) ($this->option('phase-in') ?? '');
        $receipt = (string) ($this->option('receipt') ?? '');
        $reason = (string) ($this->option('reason') ?? '');
        if ($intent === '' || $phase === '' || $receipt === '' || $reason === '') {
            return $this->failWith('phase-skip requires --intent --phase-in --receipt --reason');
        }
        try {
            $envelope = $phases->skip($intent, $phase, $receipt, $reason, (string) $this->option('autonomy'));
        } catch (\Throwable $e) {
            return $this->failWith($e->getMessage());
        }
        $this->emit($envelope, $json);

        return self::SUCCESS;
    }

    private function departmentStatus(DepartmentContractRuntime $departments, bool $json): int
    {
        $catalogue = $departments->catalogue();
        $missing = $departments->missingFieldsByDepartment();
        $this->emit([
            'schema' => $catalogue['schema_version'],
            'department_count' => $catalogue['department_count'],
            'canon_department_count' => $catalogue['canon_department_count'],
            'schema_fields_12_present' => $catalogue['schema_fields_12_present'],
            'departments' => array_keys($catalogue['departments']),
            'missing_fields' => $missing,
        ], $json);

        return $missing === [] ? self::SUCCESS : self::FAILURE;
    }

    private function cockpit(AtlasMissionControlCockpitService $cockpit, bool $json): int
    {
        $intent = (string) ($this->option('intent') ?? '');
        if ($intent === '') {
            return $this->failWith('cockpit requires --intent');
        }
        $signals = $this->loadSignals();
        $snapshot = $cockpit->snapshot(
            intentId: $intent,
            phaseEnvelopes: [],
            gateSignals: $signals,
            autonomyLevel: (string) $this->option('autonomy'),
        );
        $this->emit($snapshot, $json);

        return self::SUCCESS;
    }

    private function universalGates(AtlasUniversalGatesEvaluator $gates, bool $json): int
    {
        $intent = (string) ($this->option('intent') ?? '');
        if ($intent === '') {
            return $this->failWith('universal-gates requires --intent');
        }
        $signals = $this->loadSignals();
        $report = $gates->evaluate($intent, $signals);
        $this->emit($report, $json);

        return $report['outcome'] === 'green' || $report['outcome'] === 'exception' ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<string,bool|string|null> */
    private function loadSignals(): array
    {
        $path = (string) ($this->option('signals') ?? '');
        if ($path === '' || ! is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    private function emit(mixed $payload, bool $json): void
    {
        if ($json) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            return;
        }
        $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function failWith(string $reason): int
    {
        $this->error($reason);

        return self::FAILURE;
    }
}
