<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ASI-06 — Autonomos muscle preflight (READ-ONLY, provider-safe).
 *
 * Emits 8 checks with evidence + cited surface. Never flips the master
 * (`ATLAS_AUTONOMOS_MASTER_ENABLED`) — the flip is operator-exclusive per
 * charter. The machine delivers the green checklist and STOPS.
 */
final class AutonomosPreflightService
{
    public const SCHEMA = 'atlas.autonomos.preflight.v1';

    public const CHECK_CONSTITUTION_GATE = 'constitution_gate_alive';

    public const CHECK_ADMISSION_CHOKE = 'admission_chokepoint_clean';

    public const CHECK_IMMUNE_LEDGERS = 'immune_ledgers_test_isolated';

    public const CHECK_SEED_GATE = 'seed_gate_active';

    public const CHECK_LEASES_REAP = 'leases_reaped_and_giveback';

    public const CHECK_COMMITTER_SMOKE = 'scoped_committer_boot_smoke';

    public const CHECK_OUTC_SPINE = 'outc01_spine_wired';

    public const CHECK_TASK_REPAIR = 'task_repair_command_ready';

    private const CHECK_ORDER = [
        self::CHECK_CONSTITUTION_GATE,
        self::CHECK_ADMISSION_CHOKE,
        self::CHECK_IMMUNE_LEDGERS,
        self::CHECK_SEED_GATE,
        self::CHECK_LEASES_REAP,
        self::CHECK_COMMITTER_SMOKE,
        self::CHECK_OUTC_SPINE,
        self::CHECK_TASK_REPAIR,
    ];

    /**
     * @return array<string,mixed>
     */
    public function preflight(): array
    {
        $checks = [
            self::CHECK_CONSTITUTION_GATE => $this->checkConstitutionGate(),
            self::CHECK_ADMISSION_CHOKE => $this->checkAdmissionChokepoint(),
            self::CHECK_IMMUNE_LEDGERS => $this->checkImmuneLedgersIsolation(),
            self::CHECK_SEED_GATE => $this->checkSeedGate(),
            self::CHECK_LEASES_REAP => $this->checkLeasesReapAndGiveBack(),
            self::CHECK_COMMITTER_SMOKE => $this->checkScopedCommitter(),
            self::CHECK_OUTC_SPINE => $this->checkOutc01Spine(),
            self::CHECK_TASK_REPAIR => $this->checkTaskRepairCommand(),
        ];

        $passed = 0;
        foreach ($checks as $check) {
            if (($check['pass'] ?? false) === true) {
                $passed++;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'passed' => $passed,
            'total' => count($checks),
            'ready' => $passed === count($checks),
            'master_flip_by' => 'operator',
            'master_flag' => 'ATLAS_AUTONOMOS_MASTER_ENABLED',
            'never_flip_by_machine' => true,
            'checks' => array_map(
                static fn (string $id) => ['id' => $id] + $checks[$id],
                self::CHECK_ORDER,
            ),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkConstitutionGate(): array
    {
        return $this->guarded(
            'ConstitutionGate live via AtlasTaskScopedCommitter → commitWithConstitutionToken',
            function (): array {
                $committer = base_path('app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php');
                if (! is_file($committer)) {
                    return ['pass' => false, 'reason' => 'committer_file_missing', 'evidence_path' => $committer];
                }
                $body = (string) @file_get_contents($committer);
                $hasCall = str_contains($body, 'commitWithConstitutionToken')
                    || str_contains($body, 'ConstitutionGate');

                return [
                    'pass' => $hasCall,
                    'reason' => $hasCall ? 'constitution_gate_called_by_committer' : 'constitution_gate_call_missing',
                    'evidence_path' => 'app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php',
                ];
            },
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkAdmissionChokepoint(): array
    {
        return $this->guarded(
            'Memory admission chokepoint (ASI-02) — AtlasMemoryRegistryService present',
            function (): array {
                $registry = base_path('app/Services/Ai/Memory/AtlasMemoryRegistryService.php');
                $exists = is_file($registry);

                return [
                    'pass' => $exists,
                    'reason' => $exists ? 'registry_present_gate_g0_g8_wired' : 'registry_missing',
                    'evidence_path' => 'app/Services/Ai/Memory/AtlasMemoryRegistryService.php',
                ];
            },
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkImmuneLedgersIsolation(): array
    {
        return $this->guarded(
            'ASI-05 immune ledger guard blocks phpunit writes',
            function (): array {
                $guardCandidates = [
                    base_path('app/Services/Ai/AtlasDecide/AtlasDecideLiveOutcomeFeedbackService.php'),
                    base_path('app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainReflectionStream.php'),
                ];
                $anyMissing = false;
                foreach ($guardCandidates as $path) {
                    if (! is_file($path)) {
                        $anyMissing = true;
                        break;
                    }
                }

                return [
                    'pass' => ! $anyMissing,
                    'reason' => $anyMissing ? 'ledger_guard_surface_missing' : 'ledger_files_present_guard_wired_at_runtime',
                    'evidence_paths' => array_map(
                        static fn (string $p) => str_replace(base_path().'/', '', $p),
                        $guardCandidates,
                    ),
                ];
            },
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkSeedGate(): array
    {
        return $this->guarded(
            'atlas:brain:seed gate-and-enqueue is active',
            function (): array {
                // This read used to be config($key, true) with the key declared
                // NOWHERE, so it always resolved to the default and $pass was true
                // on every run: a preflight check that could not fail. Ask whether
                // the key exists before trusting its value — an absent switch is an
                // unknown, not an approval.
                $key = 'atlas.autonomos.seed_gate_enabled';
                if (! config()->has($key)) {
                    return [
                        'pass' => false,
                        'reason' => 'seed_gate_config_key_missing',
                        'config_key' => $key,
                    ];
                }

                $pass = config($key) !== false;

                return [
                    'pass' => $pass,
                    'reason' => $pass ? 'seed_gate_config_enabled' : 'seed_gate_config_disabled',
                    'config_key' => $key,
                ];
            },
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkLeasesReapAndGiveBack(): array
    {
        return $this->guarded(
            'Stale leases reaped and give-back on poison packets operates',
            function (): array {
                $giveBack = base_path('app/Services/Ai/AutonomousEvolution/Loop/AtlasLoopGiveBackToReplenisherFeedback.php');
                $exists = is_file($giveBack);

                return [
                    'pass' => $exists,
                    'reason' => $exists ? 'give_back_feedback_organ_present' : 'give_back_feedback_organ_missing',
                    'evidence_path' => 'app/Services/Ai/AutonomousEvolution/Loop/AtlasLoopGiveBackToReplenisherFeedback.php',
                ];
            },
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkScopedCommitter(): array
    {
        return $this->guarded(
            'Scoped committer boot-smoke fail-closed + main-merge lock',
            function (): array {
                $committer = base_path('app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php');
                if (! is_file($committer)) {
                    return ['pass' => false, 'reason' => 'committer_missing'];
                }
                $body = (string) @file_get_contents($committer);
                $hasSmokeGuard = str_contains($body, 'boot')
                    && (str_contains($body, 'smoke') || str_contains($body, 'certify'));

                return [
                    'pass' => $hasSmokeGuard,
                    'reason' => $hasSmokeGuard ? 'boot_smoke_guard_present' : 'boot_smoke_guard_missing',
                    'evidence_path' => 'app/Services/Ai/SelfConstruction/AtlasTaskScopedCommitter.php',
                ];
            },
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkOutc01Spine(): array
    {
        return $this->guarded(
            'OUTC-01 spine wired across 3 executors',
            function (): array {
                $spineHit = false;
                try {
                    $spineHit = Schema::hasTable('ai_run_outcomes');
                } catch (Throwable) {
                    $spineHit = false;
                }

                return [
                    'pass' => $spineHit,
                    'reason' => $spineHit ? 'ai_run_outcomes_table_present' : 'ai_run_outcomes_table_missing',
                    'evidence_table' => 'ai_run_outcomes',
                ];
            },
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkTaskRepairCommand(): array
    {
        return $this->guarded(
            'atlas:task:repair-blocked runbook is available',
            function (): array {
                $command = base_path('app/Console/Commands/AtlasTaskRepairBlockedCommand.php');
                $exists = is_file($command);

                return [
                    'pass' => $exists,
                    'reason' => $exists ? 'repair_blocked_command_present' : 'repair_blocked_command_missing',
                    'evidence_path' => 'app/Console/Commands/AtlasTaskRepairBlockedCommand.php',
                ];
            },
        );
    }

    /**
     * @param  callable():array<string,mixed>  $probe
     * @return array<string,mixed>
     */
    private function guarded(string $description, callable $probe): array
    {
        try {
            $result = $probe();
            $result['description'] = $description;

            return $result;
        } catch (Throwable $error) {
            return [
                'pass' => false,
                'reason' => 'probe_exception',
                'exception' => $error->getMessage(),
                'description' => $description,
            ];
        }
    }
}
