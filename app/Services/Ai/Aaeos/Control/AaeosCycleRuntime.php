<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

use App\Services\Ai\Aaeos\Control\Adapters\AaeosExecutorModeAdapter;
use App\Services\Ai\Aaeos\Control\Adapters\AutonomosModeAdapter;
use App\Services\Ai\Aaeos\Control\Adapters\DevModeAdapter;
use App\Services\Ai\Aaeos\Control\Adapters\ForgeModeAdapter;
use App\Services\Ai\Aaeos\Control\Dispatch\AaeosLiveDispatchGateway;
use App\Services\Ai\Aaeos\Spine\AaeosEngineeringSpine;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Throwable;

/**
 * Sole declared mutator of an AAEOS control cycle.
 *
 * @see docs/evidence/2026-07-23-aaeos-operate/DAY-IN-THE-LIFE.md
 */
final class AaeosCycleRuntime
{
    public const SCHEMA = 'atlas.aaeos.cycle_receipt.v1';

    /** @var array<string, AaeosExecutorModeAdapter> */
    private array $adapters;

    public function __construct(
        private readonly AaeosIntentCompiler $intents = new AaeosIntentCompiler,
        private readonly AaeosDifficultyClassifier $difficulty = new AaeosDifficultyClassifier,
        private readonly AaeosModeSelector $modes = new AaeosModeSelector,
        private readonly AaeosAdmissionPolicy $admission = new AaeosAdmissionPolicy,
        private readonly AaeosWorldSnapshotBuilder $worldBuilder = new AaeosWorldSnapshotBuilder,
        private readonly AaeosEngineeringSpine $spine = new AaeosEngineeringSpine,
        private readonly AaeosLiveDispatchGateway $liveGateway = new AaeosLiveDispatchGateway,
        private readonly ?AtlasEvidenceLedger $ledger = null,
        ?DevModeAdapter $dev = null,
        ?ForgeModeAdapter $forge = null,
        ?AutonomosModeAdapter $autonomos = null,
    ) {
        $this->adapters = [
            AaeosExecutorMode::DEV => $dev ?? new DevModeAdapter($this->spine),
            AaeosExecutorMode::FORGE => $forge ?? new ForgeModeAdapter($this->spine),
            AaeosExecutorMode::AUTONOMOS => $autonomos ?? new AutonomosModeAdapter($this->spine),
        ];
    }

    /**
     * @param  array<string,mixed>  $hints
     * @param  array<string,mixed>  $worldOverrides
     * @return array<string,mixed>
     */
    public function runCycle(string $rawIntent, array $hints = [], array $worldOverrides = [], bool $dryRun = false): array
    {
        $world = $this->worldBuilder->build($worldOverrides);
        $worldArray = array_merge($world->toArray(), $worldOverrides);

        $objective = $this->intents->compile($rawIntent, $hints);
        $difficulty = $this->difficulty->classify($objective);
        $mode = $this->modes->select($objective, $difficulty, $worldArray);
        $forcedMode = strtolower(trim((string) ($worldArray['force_mode'] ?? '')));
        if ($forcedMode !== '' && ! AaeosExecutorMode::isValid($forcedMode)) {
            $mode = [
                'schema' => AaeosModeSelector::SCHEMA,
                'mode' => $forcedMode,
                'reason' => 'invalid_forced_mode',
                'same_bar' => true,
                'difficulty_level' => (int) ($difficulty['level'] ?? AaeosDifficultyLevel::L1),
            ];
        }
        $admit = $this->admission->admit($objective, $difficulty, $mode, $worldArray);

        $liveDispatch = ! $dryRun && (bool) ($hints['live_dispatch'] ?? false);
        $cyclePlan = [
            'objective' => $objective,
            'difficulty' => $difficulty,
            'mode' => $mode,
            'admission' => $admit,
            'world' => $worldArray,
            'live_dispatch' => $liveDispatch,
        ];

        $dispatch = $this->dispatch((string) ($mode['mode'] ?? ''), $admit, $cyclePlan, $hints, $dryRun);
        $spineAssert = $this->spine->assertShared((string) ($mode['mode'] ?? 'dev'), []);

        $status = (string) ($admit['verdict'] ?? '') === AaeosAdmissionVerdict::REPAIR_REQUIRED
            ? 'repair_required'
            : 'halted';
        if ((bool) ($admit['allows_execution'] ?? false)) {
            $liveStatus = (string) ($dispatch['live']['status'] ?? '');
            $status = match ($liveStatus) {
                'dispatch_failed' => 'dispatch_failed',
                'dispatched_live' => 'dispatched_live',
                'plan_only' => 'dispatched',
                default => 'dispatched',
            };
        }

        $receipt = [
            'schema' => self::SCHEMA,
            'status' => $status,
            'dry_run' => $dryRun,
            'live_dispatch' => $liveDispatch,
            'runtime_write_performed' => false,
            'runtime_write_kind' => null,
            'objective' => $objective,
            'difficulty' => $difficulty,
            'mode' => $mode,
            'admission' => $admit,
            'world' => $worldArray,
            'dispatch' => $dispatch,
            'spine' => [
                'delivery' => 'N9',
                'evidence' => 'N11',
                'assert' => $spineAssert,
            ],
            'elite_same_bar' => true,
            'evidence_status' => 'skipped',
            'next_commands' => (array) ($dispatch['live']['next_commands'] ?? $dispatch['operate_path'] ?? []),
        ];

        if (! $dryRun) {
            $evidence = $this->recordEvidence($receipt);
            $receipt['evidence_status'] = $evidence['status'];
            $receipt['runtime_write_performed'] = $evidence['written'];
            $receipt['runtime_write_kind'] = $evidence['written'] ? 'aaeos_cycle_receipt' : null;
        }

        $receipt['effect_level'] = $this->effectLevel($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $hints
     * @return array<string,mixed>
     */
    public function runAutonomosCycle(string $rawIntent = 'autonomos_queue_cycle', array $hints = [], bool $dryRun = false): array
    {
        $hints = array_merge([
            'source' => 'autonomos',
            'interactive' => false,
            'self_evolve' => true,
        ], $hints);

        return $this->runCycle($rawIntent, $hints, [
            'queue_default' => true,
            'force_mode' => AaeosExecutorMode::AUTONOMOS,
        ], $dryRun);
    }

    /**
     * @param  array<string,mixed>  $admit
     * @param  array<string,mixed>  $cyclePlan
     * @param  array<string,mixed>  $hints
     * @return array<string,mixed>
     */
    private function dispatch(string $mode, array $admit, array $cyclePlan, array $hints = [], bool $dryRun = false): array
    {
        if (! (bool) ($admit['allows_execution'] ?? false)) {
            return [
                'status' => 'blocked',
                'mode' => $mode,
                'operate_path' => [],
                'reason' => 'admission_denied',
                'spine' => ['delivery' => 'N9', 'evidence' => 'N11'],
            ];
        }

        $adapter = $this->adapters[$mode] ?? null;
        if ($adapter === null) {
            return [
                'status' => 'blocked',
                'mode' => $mode,
                'operate_path' => [],
                'reason' => 'unknown_mode',
            ];
        }

        $accept = $adapter->accept($cyclePlan);
        $cyclePlan['adapter_accept'] = $accept;

        if ($dryRun) {
            $dryProjection = [
                'schema' => AaeosLiveDispatchGateway::SCHEMA,
                'status' => 'plan_only',
                'mode' => $mode,
                'live' => false,
                'effects' => [],
                'next_commands' => (array) ($accept['operate_path'] ?? []),
                'dualcore' => [
                    'recorded' => false,
                    'status' => 'not_attempted_dry_run',
                ],
                'adapter' => $accept,
                'provider_calls' => 0,
                'reason' => 'dry_run_no_gateway_dispatch',
            ];

            return array_merge($accept, [
                'live' => $dryProjection,
                'dualcore' => $dryProjection['dualcore'],
                'effects' => [],
            ]);
        }

        $liveOptions = [
            'live' => (bool) ($cyclePlan['live_dispatch'] ?? false),
            'plan_only' => ! (bool) ($cyclePlan['live_dispatch'] ?? false),
            'max_seeds' => (int) ($hints['max_seeds'] ?? 0),
            'execute_provider' => (bool) ($hints['execute_provider'] ?? false),
            'run_worker_once' => (bool) ($hints['run_worker_once'] ?? false),
            'run_brain_next' => (bool) ($hints['run_brain_next'] ?? true),
            'scope' => $hints['scope'] ?? null,
        ];

        $live = $this->liveGateway->dispatch($mode, $cyclePlan, $liveOptions);

        return array_merge($accept, [
            'live' => $live,
            'dualcore' => $live['dualcore'] ?? null,
            'effects' => $live['effects'] ?? [],
        ]);
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array{status:string,written:bool}
     */
    private function recordEvidence(array $receipt): array
    {
        $ledger = $this->ledger;
        if ($ledger === null) {
            try {
                if (function_exists('app')) {
                    $ledger = app(AtlasEvidenceLedger::class);
                }
            } catch (Throwable) {
                return ['status' => 'skipped_no_container', 'written' => false];
            }
        }

        if ($ledger === null) {
            return ['status' => 'skipped_no_ledger', 'written' => false];
        }

        try {
            $event = $ledger->record(
                LedgerEventType::AaeosCycleRecorded,
                [
                    'schema' => self::SCHEMA,
                    'cycle_status' => $receipt['status'] ?? null,
                    'mode' => $receipt['mode']['mode'] ?? null,
                    'admission' => $receipt['admission']['verdict'] ?? null,
                    'difficulty_level' => $receipt['difficulty']['level'] ?? null,
                    'elite_same_bar' => true,
                    'live_dispatch' => $receipt['live_dispatch'] ?? false,
                    'spine' => $receipt['spine'] ?? null,
                    'objective_hash' => hash('sha256', (string) ($receipt['objective']['objective'] ?? '')),
                ],
                [
                    'emitter_stage' => 'atlas.aaeos.cycle',
                    'scope_type' => 'aaeos_cycle',
                    'envelope_id' => 'aaeos-cycle-'.substr(hash('sha256', (string) microtime(true)), 0, 16),
                ],
            );

            return $event === null
                ? ['status' => 'skipped_table_missing', 'written' => false]
                : ['status' => 'recorded', 'written' => true];
        } catch (Throwable) {
            return ['status' => 'skipped_error', 'written' => false];
        }
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function effectLevel(array $receipt): string
    {
        if ((bool) ($receipt['dry_run'] ?? false)) {
            return 'none';
        }

        return match ((string) ($receipt['status'] ?? '')) {
            'halted', 'repair_required', 'dispatch_failed', 'blocked' => 'blocked',
            'dispatched_live' => $this->hasDurableMutationProof($receipt)
                ? 'mutated'
                : ((bool) ($receipt['runtime_write_performed'] ?? false) ? 'claimed' : 'prepared'),
            'dispatched' => 'prepared',
            default => 'blocked',
        };
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function hasDurableMutationProof(array $receipt): bool
    {
        $proof = data_get($receipt, 'dispatch.live.mutation_proof');
        if (! is_array($proof)) {
            return false;
        }

        $reference = $proof['durable_ref'] ?? null;
        $hash = $proof['sha256'] ?? null;

        return is_string($reference)
            && $reference !== ''
            && is_string($hash)
            && preg_match('/^[a-f0-9]{64}$/', $hash) === 1;
    }
}
