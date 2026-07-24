<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

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
    public const SCHEMA = 'atlas.aaeos.cycle_receipt.v2';

    public function __construct(
        private readonly AaeosIntentCompiler $intents = new AaeosIntentCompiler,
        private readonly AaeosDifficultyClassifier $difficulty = new AaeosDifficultyClassifier,
        private readonly AaeosModeSelector $modes = new AaeosModeSelector,
        private readonly AaeosAdmissionPolicy $admission = new AaeosAdmissionPolicy,
        private readonly AaeosWorldSnapshotBuilder $worldBuilder = new AaeosWorldSnapshotBuilder,
        private readonly AaeosEngineeringSpine $spine = new AaeosEngineeringSpine,
        private readonly AaeosLiveDispatchGateway $liveGateway = new AaeosLiveDispatchGateway,
        private readonly ?AtlasEvidenceLedger $ledger = null,
    ) {}

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
                'dispatch_failed', 'dispatch_refused', 'dispatch_skipped' => 'dispatch_failed',
                'dispatched_live' => 'dispatched_live',
                'commissioned' => 'commissioned',
                'claimed' => 'claimed',
                'plan_only' => 'dispatched',
                default => 'dispatch_failed',
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
        ];

        $receipt['effect_level'] = $this->effectLevel($receipt);

        if (! $dryRun) {
            $evidence = $this->recordEvidence($receipt);
            $receipt['evidence_status'] = $evidence['status'];
            $receipt['runtime_write_performed'] = $evidence['written'];
            $receipt['runtime_write_kind'] = $evidence['written'] ? 'aaeos_cycle_receipt' : null;
            $receipt['receipt_core'] = $evidence['receipt_core'];
            $receipt['receipt_core_hash'] = $evidence['receipt_core_hash'];
            $receipt['evidence_event_id'] = $evidence['evidence_event_id'];
            $receipt['evidence_event_hash'] = $evidence['evidence_event_hash'];
        }

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

        if (! AaeosExecutorMode::isValid($mode)) {
            return [
                'status' => 'blocked',
                'mode' => $mode,
                'operate_path' => [],
                'reason' => 'unknown_mode',
            ];
        }

        $modeContract = [
            'status' => 'ready',
            'mode' => $mode,
            'spine' => ['delivery' => 'N9', 'evidence' => 'N11'],
            'spine_contract' => $this->spine->contractForMode($mode),
            'elite_same_bar' => true,
            'difficulty_level' => (int) ($cyclePlan['difficulty']['level'] ?? AaeosDifficultyLevel::L1),
        ];
        $cyclePlan['mode_contract'] = $modeContract;

        if ($dryRun) {
            $dryProjection = [
                'schema' => AaeosLiveDispatchGateway::SCHEMA,
                'status' => 'plan_only',
                'mode' => $mode,
                'live' => false,
                'effects' => [],
                'dualcore' => [
                    'recorded' => false,
                    'status' => 'not_attempted_dry_run',
                ],
                'provider_calls' => 0,
                'reason' => 'dry_run_no_gateway_dispatch',
            ];

            return array_merge($modeContract, [
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
            'scope' => $hints['scope'] ?? null,
            'workspace' => $hints['workspace'] ?? data_get($cyclePlan, 'world.workspace'),
            // AAEOS transports native authority; it does not manufacture it.
            'confirmed_dev_run' => $hints['confirmed_dev_run'] ?? null,
            'forge_commissioning' => $hints['forge_commissioning'] ?? null,
            'dry_run' => (bool) ($hints['dry_run'] ?? false),
        ];

        $live = $this->liveGateway->dispatch($mode, $cyclePlan, $liveOptions);

        return array_merge($modeContract, [
            'live' => $live,
            'dualcore' => $live['dualcore'] ?? null,
            'effects' => $live['effects'] ?? [],
        ]);
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array{
     *     status:string,
     *     written:bool,
     *     receipt_core:array<string,mixed>,
     *     receipt_core_hash:string,
     *     evidence_event_id:string|null,
     *     evidence_event_hash:string|null
     * }
     */
    private function recordEvidence(array $receipt): array
    {
        $receiptCore = self::receiptCore($receipt);
        $receiptCoreHash = self::receiptCoreHash($receiptCore);
        $empty = [
            'receipt_core' => $receiptCore,
            'receipt_core_hash' => $receiptCoreHash,
            'evidence_event_id' => null,
            'evidence_event_hash' => null,
        ];
        $ledger = $this->ledger;
        if ($ledger === null) {
            try {
                if (function_exists('app')) {
                    $ledger = app(AtlasEvidenceLedger::class);
                }
            } catch (Throwable) {
                return ['status' => 'skipped_no_container', 'written' => false, ...$empty];
            }
        }

        if ($ledger === null) {
            return ['status' => 'skipped_no_ledger', 'written' => false, ...$empty];
        }

        try {
            $event = $ledger->record(
                LedgerEventType::AaeosCycleRecorded,
                [
                    'schema' => self::SCHEMA,
                    'receipt_core' => $receiptCore,
                    'receipt_core_hash' => $receiptCoreHash,
                ],
                [
                    'tenant_id' => data_get($receipt, 'world.tenant_id', data_get($receipt, 'objective.tenant_id', 'default')),
                    'operator_id' => data_get($receipt, 'world.operator_id', data_get($receipt, 'objective.operator_id', 'aaeos')),
                    'emitter_stage' => 'atlas.aaeos.cycle',
                    'emitter_version' => self::SCHEMA,
                    'scope_type' => 'aaeos_cycle',
                    'scope_id' => substr($receiptCoreHash, 0, 64),
                    'correlation_id' => data_get($receipt, 'world.journey_id', $receiptCoreHash),
                    'envelope_id' => 'aaeos-cycle-'.substr($receiptCoreHash, 0, 16),
                ],
            );

            return $event === null
                ? ['status' => 'skipped_table_missing', 'written' => false, ...$empty]
                : [
                    'status' => 'recorded',
                    'written' => true,
                    ...$empty,
                    'evidence_event_id' => (string) $event->event_id,
                    'evidence_event_hash' => (string) $event->event_hash,
                ];
        } catch (Throwable) {
            return ['status' => 'skipped_error', 'written' => false, ...$empty];
        }
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public static function receiptCore(array $receipt): array
    {
        unset(
            $receipt['receipt_core'],
            $receipt['receipt_core_hash'],
            $receipt['evidence_event_id'],
            $receipt['evidence_event_hash'],
        );

        /** @var array<string,mixed> $canonical */
        $canonical = self::canonicalize($receipt);

        return $canonical;
    }

    /**
     * @param  array<string,mixed>  $receiptCore
     */
    public static function receiptCoreHash(array $receiptCore): string
    {
        return hash('sha256', json_encode(
            self::canonicalize($receiptCore),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }

        return array_is_list($value) ? array_values($value) : $value;
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
            'halted', 'repair_required', 'dispatch_failed', 'dispatch_refused', 'blocked' => 'blocked',
            'dispatched_live' => $this->hasDurableMutationProof($receipt) ? 'mutated' : 'prepared',
            'dispatched', 'commissioned' => 'prepared',
            'claimed' => 'claimed',
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
