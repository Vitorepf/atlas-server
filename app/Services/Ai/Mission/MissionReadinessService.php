<?php

namespace App\Services\Ai\Mission;

use App\Models\AiMission;
use App\Models\AiMissionCertification;
use App\Models\AiMissionEvent;
use App\Models\AiMissionEvidenceRef;
use App\Models\AiObjective;
use App\Models\AiWorkOrder;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

class MissionReadinessService
{
    use \App\Services\Ai\Support\BuildsReadinessChecks;

    private const REQUIRED_KERNEL_RUNTIME_TABLES = [
        'ai_missions',
        'ai_objectives',
        'ai_work_orders',
        'ai_mission_events',
    ];

    private const REQUIRED_CERTIFICATION_TABLES = [
        'ai_mission_evidence_refs',
        'ai_mission_certifications',
    ];

    private const KERNEL_MIGRATION = '2026_05_17_900000_create_ai_mission_foundation_tables';

    private const REQUIRED_MODELS = [
        AiMission::class,
        AiObjective::class,
        AiWorkOrder::class,
        AiMissionEvent::class,
        AiMissionEvidenceRef::class,
        AiMissionCertification::class,
    ];

    private const REQUIRED_SERVICES = [
        MissionFactoryService::class,
        ObjectiveDecomposerService::class,
        WorkOrderFactoryService::class,
        MissionLifecycleService::class,
        MissionEvidenceService::class,
        MissionCertificationService::class,
        MissionControlPlaneService::class,
    ];

    private const REQUIRED_TEST_FILES = [
        'tests/Feature/Ai/Mission/MissionFoundationReadinessTest.php',
        'tests/Feature/Ai/Mission/MissionFoundationSmokeTest.php',
        'tests/Feature/Ai/Mission/MissionFoundationLifecycleGuardTest.php',
        'tests/Feature/Ai/Mission/MissionFoundationObjectiveDecomposerTest.php',
        'tests/Feature/Ai/Mission/MissionFoundationWorkOrderFactoryTest.php',
        'tests/Feature/Ai/Mission/MissionFoundationEvidenceServiceTest.php',
        'tests/Feature/Ai/Mission/MissionFoundationCertificationServiceTest.php',
        'tests/Feature/Ai/Mission/MissionFoundationControlPlaneServiceTest.php',
    ];

    public function __construct(private readonly Container $container) {}

    /**
     * Tables the HTTP gateway bridge needs before it can safely record a
     * mission envelope without forcing the legacy path to depend on the full
     * certification layer.
     *
     * @return list<string>
     */
    public static function requiredKernelRuntimeTables(): array
    {
        return self::REQUIRED_KERNEL_RUNTIME_TABLES;
    }

    /**
     * @return list<string>
     */
    private static function requiredTables(): array
    {
        return [
            ...self::REQUIRED_KERNEL_RUNTIME_TABLES,
            ...self::REQUIRED_CERTIFICATION_TABLES,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    /**
     * Repair migration drift when the mission-foundation migration is recorded
     * but kernel tables were dropped (common after partial DB wipes).
     *
     * @return array{ok:bool,repaired:bool,missing:list<string>,action?:string,hint?:string}
     */
    public function ensureKernelTablesReady(): array
    {
        $missing = array_values(array_filter(
            self::requiredTables(),
            static fn (string $table): bool => ! DatabaseTableAvailability::has($table),
        ));

        if ($missing === []) {
            return ['ok' => true, 'repaired' => false, 'missing' => []];
        }

        $migrationRecorded = DB::table('migrations')
            ->where('migration', self::KERNEL_MIGRATION)
            ->exists();

        if ($migrationRecorded && ! DatabaseTableAvailability::has('ai_missions')) {
            DB::table('migrations')->where('migration', self::KERNEL_MIGRATION)->delete();
            Artisan::call('migrate', [
                '--path' => 'database/migrations/'.self::KERNEL_MIGRATION.'.php',
                '--force' => true,
            ]);

            $stillMissing = array_values(array_filter(
                self::requiredTables(),
                static fn (string $table): bool => ! DatabaseTableAvailability::has($table),
            ));

            return [
                'ok' => $stillMissing === [],
                'repaired' => true,
                'action' => 'migration_rerun',
                'missing' => $stillMissing,
            ];
        }

        return [
            'ok' => false,
            'repaired' => false,
            'missing' => $missing,
            'hint' => 'run php artisan migrate',
        ];
    }

    public function report(): array
    {
        $checks = [];

        foreach (self::requiredTables() as $table) {
            $exists = DatabaseTableAvailability::has($table);
            $checks[] = [
                'name' => "table:{$table}",
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'exists' : 'missing - run php artisan migrate',
            ];
        }

        $checks = array_merge($checks, $this->modelChecks(self::REQUIRED_MODELS));

        $checks = array_merge($checks, $this->serviceChecks($this->container, self::REQUIRED_SERVICES));

        $checks[] = $this->checkLifecycleGuard();
        $checks[] = $this->checkCertificationGuard();

        foreach (self::REQUIRED_TEST_FILES as $relativePath) {
            $exists = file_exists(base_path($relativePath));
            $checks[] = [
                'name' => 'test:'.basename($relativePath),
                'status' => $exists ? 'passed' : 'failed',
                'detail' => $exists ? 'present' : "missing [{$relativePath}]",
            ];
        }

        $passed = collect($checks)->where('status', 'passed')->count();
        $failed = collect($checks)->where('status', 'failed')->count();

        return [
            'ok' => $failed === 0,
            'schema' => 'atlas.ai.mission.readiness.v1',
            'summary' => [
                'total' => count($checks),
                'passed' => $passed,
                'failed' => $failed,
            ],
            'checks' => $checks,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkLifecycleGuard(): array
    {
        $lifecycle = $this->container->make(MissionLifecycleService::class);
        $allowedFromDraft = $lifecycle->allowedNext(MissionLifecycleService::STATUS_DRAFT);
        $allowedFromBlocked = $lifecycle->allowedNext(MissionLifecycleService::STATUS_BLOCKED);

        $draftAllowsPlanned = in_array(MissionLifecycleService::STATUS_PLANNED, $allowedFromDraft, true);
        $blockedRejectsRunning = ! in_array(MissionLifecycleService::STATUS_RUNNING, $allowedFromBlocked, true);
        $passes = $draftAllowsPlanned && $blockedRejectsRunning;

        return [
            'name' => 'guard:lifecycle_transitions',
            'status' => $passes ? 'passed' : 'failed',
            'detail' => $passes
                ? 'draft->planned allowed; blocked->running forbidden'
                : 'lifecycle transition table is inconsistent with canon',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkCertificationGuard(): array
    {
        $reflection = new \ReflectionClass(MissionLifecycleService::class);
        $hasGuard = $reflection->hasMethod('transition')
            && str_contains((string) file_get_contents((string) $reflection->getFileName()), 'guardCompletion');

        return [
            'name' => 'guard:completion_requires_certification',
            'status' => $hasGuard ? 'passed' : 'failed',
            'detail' => $hasGuard ? 'guardCompletion present' : 'guardCompletion missing from MissionLifecycleService',
        ];
    }
}
