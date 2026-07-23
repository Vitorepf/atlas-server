<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Control;

use App\Services\Ai\Aaeos\Control\Adapters\AaeosExecutorModeAdapter;
use App\Services\Ai\Aaeos\Control\Adapters\AutonomosModeAdapter;
use App\Services\Ai\Aaeos\Control\Adapters\DevModeAdapter;
use App\Services\Ai\Aaeos\Control\Adapters\ForgeModeAdapter;
use App\Services\Ai\Aaeos\Spine\AaeosEngineeringSpine;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Throwable;

/**
 * Sole declared mutator of an AAEOS control cycle.
 *
 * @see docs/superpowers/plans/2026-07-23-aaeos-god-sota-complete.md
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
        $admit = $this->admission->admit($objective, $difficulty, $mode, $worldArray);

        $cyclePlan = [
            'objective' => $objective,
            'difficulty' => $difficulty,
            'mode' => $mode,
            'admission' => $admit,
            'world' => $worldArray,
            'live_dispatch' => ! $dryRun && (bool) ($hints['live_dispatch'] ?? false),
        ];

        $dispatch = $this->dispatch($mode['mode'], $admit, $cyclePlan);
        $spineAssert = $this->spine->assertShared((string) ($mode['mode'] ?? 'dev'), []);

        $receipt = [
            'schema' => self::SCHEMA,
            'status' => $admit['allows_execution'] ? 'dispatched' : 'halted',
            'dry_run' => $dryRun,
            'runtime_write_performed' => true,
            'runtime_write_kind' => 'aaeos_cycle_receipt',
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
            'human_in_engineering_loop' => $mode['mode'] === AaeosExecutorMode::DEV,
            'evidence_status' => 'skipped',
        ];

        if (! $dryRun) {
            $receipt['evidence_status'] = $this->recordEvidence($receipt);
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
     * @return array<string,mixed>
     */
    private function dispatch(string $mode, array $admit, array $cyclePlan): array
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

        return $adapter->accept($cyclePlan);
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function recordEvidence(array $receipt): string
    {
        $ledger = $this->ledger;
        if ($ledger === null) {
            try {
                if (function_exists('app')) {
                    $ledger = app(AtlasEvidenceLedger::class);
                }
            } catch (Throwable) {
                return 'skipped_no_container';
            }
        }

        if ($ledger === null) {
            return 'skipped_no_ledger';
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
                    'spine' => $receipt['spine'] ?? null,
                    'objective_hash' => hash('sha256', (string) ($receipt['objective']['objective'] ?? '')),
                ],
                [
                    'emitter_stage' => 'atlas.aaeos.cycle',
                    'scope_type' => 'aaeos_cycle',
                    'envelope_id' => 'aaeos-cycle-'.substr(hash('sha256', (string) microtime(true)), 0, 16),
                ],
            );

            return $event === null ? 'skipped_table_missing' : 'recorded';
        } catch (Throwable) {
            return 'skipped_error';
        }
    }
}
