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
            // P1b.3: projection of native authority/observation refs only — never self-minted.
            'native_authority_projection' => $this->projectNativeAuthorityRefs($hints, $dispatch, $admit),
            'spine' => [
                'delivery' => 'N9',
                'evidence' => 'N11',
                'assert' => $spineAssert,
            ],
            'elite_same_bar' => true,
            'evidence_status' => 'skipped',
        ];

        $receipt['effect_level'] = $this->effectLevel($receipt);

        if ($dryRun) {
            return $this->finalizeEvidenceProjection($receipt, 'skipped', false);
        }

        return $this->recordEvidence($receipt)['receipt'];
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
     * @return array{receipt:array<string,mixed>}
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
                return ['receipt' => $this->finalizeEvidenceProjection($receipt, 'skipped_no_container', false)];
            }
        }

        if ($ledger === null) {
            return ['receipt' => $this->finalizeEvidenceProjection($receipt, 'skipped_no_ledger', false)];
        }

        try {
            // The event binds this *final* successful projection. Event references are
            // deliberately excluded from the core, so they can be attached after the
            // append without making the receipt self-referential.
            $finalReceipt = $this->finalizeEvidenceProjection($receipt, 'recorded', true);
            $receiptCore = (array) $finalReceipt['receipt_core'];
            $receiptCoreHash = (string) $finalReceipt['receipt_core_hash'];
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

            if ($event === null) {
                return ['receipt' => $this->finalizeEvidenceProjection($receipt, 'skipped_table_missing', false)];
            }

            $finalReceipt['evidence_event_id'] = (string) $event->event_id;
            $finalReceipt['evidence_event_hash'] = (string) $event->event_hash;

            return ['receipt' => $finalReceipt];
        } catch (Throwable) {
            return ['receipt' => $this->finalizeEvidenceProjection($receipt, 'skipped_error', false)];
        }
    }

    /**
     * Finalize the exact receipt projection that the advertised core hash
     * represents. This is intentionally done before evidence append on the
     * successful path, so the ledger payload and returned receipt attest to
     * identical runtime/evidence fields.
     *
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function finalizeEvidenceProjection(array $receipt, string $evidenceStatus, bool $written): array
    {
        $receipt['evidence_status'] = $evidenceStatus;
        $receipt['runtime_write_performed'] = $written;
        $receipt['runtime_write_kind'] = $written ? 'aaeos_cycle_receipt' : null;
        $receipt['evidence_event_id'] = null;
        $receipt['evidence_event_hash'] = null;
        $receipt['receipt_core'] = self::receiptCore($receipt);
        $receipt['receipt_core_hash'] = self::receiptCoreHash($receipt['receipt_core']);

        return $receipt;
    }

    /**
     * P1b.3: project only native authorization/observation refs already present
     * on owner seams. Never invent decision/event ids or seal authority here.
     *
     * @param  array<string,mixed>  $hints
     * @param  array<string,mixed>  $dispatch
     * @param  array<string,mixed>  $admit
     * @return array<string,mixed>
     */
    private function projectNativeAuthorityRefs(array $hints, array $dispatch, array $admit): array
    {
        $refs = [
            'schema' => 'atlas.aaeos.native_authority_projection.v1',
            'self_minted' => false,
            'authority_source' => 'native_only',
            'projected' => [],
            'refused_self_mint' => [],
        ];

        // Caller-supplied "observed" authority without a native owner ref is laundering.
        foreach (['observed_authority', 'minted_decision_event_id', 'self_sealed_authority'] as $banned) {
            if (array_key_exists($banned, $hints) && ($hints[$banned] ?? null) !== null) {
                $refs['refused_self_mint'][] = $banned;
            }
        }

        $candidates = [
            'confirmed_dev_run' => $hints['confirmed_dev_run'] ?? null,
            'forge_commissioning' => $hints['forge_commissioning'] ?? null,
            'decision_event_id' => $hints['decision_event_id'] ?? data_get($dispatch, 'live.decision_event_id'),
            'decision_receipt_hash' => $hints['decision_receipt_hash'] ?? data_get($dispatch, 'live.decision_receipt_hash'),
            'land_nonce' => data_get($dispatch, 'live.land_nonce'),
            'engineering_outcome_hash' => data_get($dispatch, 'live.engineering_outcome_hash')
                ?? data_get($dispatch, 'live.outcome.outcome_hash'),
            'admission_verdict' => $admit['verdict'] ?? null,
        ];

        foreach ($candidates as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            // Only project scalars/arrays that already exist — never fabricate hashes/ids.
            if (is_string($value) || is_int($value) || is_bool($value) || is_array($value)) {
                $refs['projected'][$key] = $value;
            }
        }

        $refs['has_native_authority'] = $refs['projected'] !== []
            && ! array_key_exists('minted_decision_event_id', $hints);

        return $refs;
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
        $canonical = json_decode(AtlasEvidenceLedger::canonicalJson($receipt), true, 512, JSON_THROW_ON_ERROR);

        return $canonical;
    }

    /**
     * @param  array<string,mixed>  $receiptCore
     */
    public static function receiptCoreHash(array $receiptCore): string
    {
        return hash('sha256', AtlasEvidenceLedger::canonicalJson($receiptCore));
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
