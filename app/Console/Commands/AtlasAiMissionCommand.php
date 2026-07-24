<?php

namespace App\Console\Commands;

use App\Models\AiMission;
use App\Services\Ai\Mission\MissionCertificationService;
use App\Services\Ai\Mission\MissionDetectionService;
use App\Services\Ai\Mission\MissionFollowThroughService;
use App\Services\Ai\Mission\MissionModeService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Atlas AI Mission Mode CLI.
 *
 * Single command with positional action:
 *   atlas:ai:mission create --goal="..." [--json]
 *   atlas:ai:mission show --mission=<uuid> [--json]
 *   atlas:ai:mission certify --mission=<uuid> [--json]
 *   atlas:ai:mission list [--status=<status>] [--limit=20] [--json]
 *   atlas:ai:mission detect --goal="..." [--json]  (dry-run signal only)
 *   atlas:ai:mission run --mission=<uuid> [--until-blocked] [--max-cycles=3] [--json]
 *
 * Always exits 0 on operational success; non-zero on usage/runtime error.
 * --json keeps stdout machine-readable; default is humanized text.
 */
class AtlasAiMissionCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:mission
        {action : create|show|certify|list|detect|run}
        {--goal= : Raw operator prompt for create/detect}
        {--mission= : Mission uuid for show/certify/run}
        {--status= : Filter status for list}
        {--limit=20 : Max rows for list}
        {--mission-type= : Optional mission_type override for create}
        {--autonomy-level= : Optional autonomy_level override for create}
        {--primary-domain= : Optional primary_domain hint for create}
        {--until-blocked : run cycles até mission alcançar terminal/blocked}
        {--max-cycles=3 : Hard cap de cycles para run --until-blocked (max 20)}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas AI Mission Mode · create/show/certify/list/detect missions persistent above Hyperflow.';

    public function handle(MissionModeService $missionMode, MissionFollowThroughService $followThrough): int
    {
        $action = (string) $this->argument('action');

        try {
            return match ($action) {
                'create' => $this->renderCreate($missionMode),
                'show' => $this->renderShow($missionMode),
                'certify' => $this->renderCertify($missionMode),
                'list' => $this->renderList($missionMode),
                'detect' => $this->renderDetect($missionMode),
                'run' => $this->renderRun($missionMode, $followThrough),
                default => $this->renderUsageError($action),
            };
        } catch (Throwable $exception) {
            return $this->renderError($exception);
        }
    }

    private function renderCreate(MissionModeService $missionMode): int
    {
        $goal = (string) ($this->option('goal') ?? '');
        if (trim($goal) === '') {
            return $this->renderUsageError('create requires --goal="..."');
        }

        $context = array_filter([
            'mission_type' => $this->option('mission-type'),
            'autonomy_level' => $this->option('autonomy-level'),
            'primary_domain' => $this->option('primary-domain'),
            'actor_type' => 'cli',
        ], static fn ($v) => $v !== null && $v !== '');

        $result = $missionMode->processIntent($goal, $context);

        return $this->emit([
            'ok' => true,
            'action' => 'create',
            'result' => $result->toArray(),
            'snapshot' => $result->activated() && $result->mission !== null
                ? $missionMode->snapshot($result->mission)
                : null,
        ]);
    }

    private function renderShow(MissionModeService $missionMode): int
    {
        $uuid = (string) ($this->option('mission') ?? '');
        if (trim($uuid) === '') {
            return $this->renderUsageError('show requires --mission=<uuid>');
        }

        $mission = AiMission::query()->where('uuid', $uuid)->first();
        if ($mission === null) {
            return $this->emit([
                'ok' => false,
                'action' => 'show',
                'error' => 'mission_not_found',
                'mission_uuid' => $uuid,
            ], exit: 1);
        }

        return $this->emit([
            'ok' => true,
            'action' => 'show',
            'snapshot' => $missionMode->snapshot($mission),
        ]);
    }

    private function renderCertify(MissionModeService $missionMode): int
    {
        $uuid = (string) ($this->option('mission') ?? '');
        if (trim($uuid) === '') {
            return $this->renderUsageError('certify requires --mission=<uuid>');
        }

        $mission = AiMission::query()->where('uuid', $uuid)->first();
        if ($mission === null) {
            return $this->emit([
                'ok' => false,
                'action' => 'certify',
                'error' => 'mission_not_found',
                'mission_uuid' => $uuid,
            ], exit: 1);
        }

        $certification = $missionMode->certify($mission);
        $mission->refresh();

        return $this->emit([
            'ok' => $certification->status === MissionCertificationService::STATUS_PASSED,
            'action' => 'certify',
            'certification' => [
                'id' => $certification->id,
                'uuid' => $certification->uuid,
                'status' => $certification->status,
                'certification_hash' => $certification->certification_hash,
                'missing_count' => count((array) $certification->missing_requirements),
                'evidence_refs' => (array) $certification->evidence_refs,
            ],
            'mission_status' => $mission->status,
            'snapshot' => $missionMode->snapshot($mission),
        ]);
    }

    private function renderList(MissionModeService $missionMode): int
    {
        $status = $this->option('status');
        $limit = max(1, min(100, (int) $this->option('limit')));

        $query = AiMission::query()->orderByDesc('created_at')->limit($limit);
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $missions = $query->get(['id', 'uuid', 'title', 'mission_type', 'status', 'risk_level', 'autonomy_level', 'primary_domain', 'certification_hash', 'completed_at', 'created_at']);

        return $this->emit([
            'ok' => true,
            'action' => 'list',
            'filter' => ['status' => $status, 'limit' => $limit],
            'count' => $missions->count(),
            'missions' => $missions->map(static fn (AiMission $m) => [
                'uuid' => $m->uuid,
                'title' => $m->title,
                'mission_type' => $m->mission_type,
                'status' => $m->status,
                'risk_level' => $m->risk_level,
                'autonomy_level' => $m->autonomy_level,
                'primary_domain' => $m->primary_domain,
                'certification_hash' => $m->certification_hash,
                'completed_at' => $m->completed_at?->toIso8601String(),
                'created_at' => $m->created_at?->toIso8601String(),
            ])->values()->all(),
        ]);
    }

    private function renderRun(MissionModeService $missionMode, MissionFollowThroughService $followThrough): int
    {
        $uuid = (string) ($this->option('mission') ?? '');
        if (trim($uuid) === '') {
            return $this->renderUsageError('run requires --mission=<uuid>');
        }

        $mission = AiMission::query()->where('uuid', $uuid)->first();
        if ($mission === null) {
            return $this->emit([
                'ok' => false,
                'action' => 'run',
                'error' => 'mission_not_found',
                'mission_uuid' => $uuid,
            ], exit: 1);
        }

        $untilBlocked = (bool) $this->option('until-blocked');
        $maxCycles = (int) $this->option('max-cycles');

        $lastCycle = $untilBlocked
            ? $followThrough->runUntilBlockedOrComplete($mission, $maxCycles)
            : $followThrough->runNext($mission);

        $mission->refresh();

        return $this->emit([
            'ok' => true,
            'action' => 'run',
            'mode' => $untilBlocked ? 'run_until_blocked_or_complete' : 'run_next',
            'max_cycles' => $untilBlocked ? max(1, min($maxCycles, MissionFollowThroughService::MAX_CYCLES_HARD_CAP)) : 1,
            'cycle' => $lastCycle->toArray(),
            'mission_status' => $mission->status,
            'follow_through_snapshot' => $followThrough->snapshot($mission),
            'snapshot' => $missionMode->snapshot($mission),
        ]);
    }

    private function renderDetect(MissionModeService $missionMode): int
    {
        $goal = (string) ($this->option('goal') ?? '');
        if (trim($goal) === '') {
            return $this->renderUsageError('detect requires --goal="..."');
        }

        // Dry-run: roda só detection sem persistir mission. Útil para CI/teste
        // de classificação.
        $detection = app(MissionDetectionService::class);
        $signal = $detection->detect($goal);

        return $this->emit([
            'ok' => true,
            'action' => 'detect',
            'persisted' => false,
            'signal' => $signal->toArray(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = 0): int
    {
        if ($this->option('json')) {
            $this->line($this->encode($payload));

            return $exit;
        }

        $this->line('[atlas:ai:mission] '.($payload['action'] ?? 'unknown'));
        $this->line($this->encode($payload));

        return $exit;
    }

    private function renderUsageError(string $action): int
    {
        return $this->emit([
            'ok' => false,
            'action' => $action,
            'error' => 'usage_error',
            'usage' => 'atlas:ai:mission {create|show|certify|list|detect|run} [--goal=...] [--mission=<uuid>] [--status=...] [--until-blocked] [--max-cycles=N] [--json]',
        ], exit: 2);
    }

    private function renderError(Throwable $exception): int
    {
        return $this->emit([
            'ok' => false,
            'action' => (string) $this->argument('action'),
            'error' => 'exception',
            'exception_class' => $exception::class,
            'message' => $exception->getMessage(),
        ], exit: 1);
    }
}
