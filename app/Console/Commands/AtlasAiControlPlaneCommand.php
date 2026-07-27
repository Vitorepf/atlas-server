<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\ControlPlane\AtlasAiControlPlaneService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneBlockerService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneMissionService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneNextActionService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneReadinessService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneSnapshotService;
use App\Services\Ai\ControlPlane\AtlasControlPlaneStatus;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiControlPlaneCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:control-plane
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, snapshot, mission, blockers, next-actions, smoke, runtime}
        {--mission= : Mission uuid for mission action}
        {--hours=24 : Window in hours for the runtime action}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas AI Control Plane: aggregate read-model snapshots, readiness, blockers, next actions, plus trace-level runtime observability.';

    public function handle(
        AtlasControlPlaneReadinessService $readiness,
        AtlasControlPlaneSnapshotService $snapshot,
        AtlasControlPlaneMissionService $mission,
        AtlasControlPlaneBlockerService $blockers,
        AtlasControlPlaneNextActionService $nextActions,
        AtlasAiControlPlaneService $aiRuntime,
    ): int {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');

        try {
            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'snapshot' => $this->renderSnapshot($snapshot),
                'mission' => $this->renderMission($mission),
                'blockers' => $this->renderBlockers($blockers),
                'next-actions' => $this->renderNextActions($nextActions),
                'smoke' => $this->renderSmoke($readiness, $snapshot, $blockers, $nextActions),
                'runtime' => $this->renderRuntime($aiRuntime),
                default => $this->invalidAction($action),
            };
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
                'type' => $e::class,
            ];
            $this->line($this->encodeOrEmptyObject($payload));

            return self::FAILURE;
        }
    }

    private function renderReadiness(AtlasControlPlaneReadinessService $service): int
    {
        $payload = $service->report();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('ready', (string) $payload['summary']['ready']);
            $this->components->twoColumnDetail('degraded', (string) $payload['summary']['degraded']);
            $this->components->twoColumnDetail('missing', (string) $payload['summary']['missing']);
            foreach ($payload['components'] as $component) {
                $this->components->twoColumnDetail(
                    'component:'.(string) $component['component'],
                    (string) $component['status'],
                );
            }
        });

        // exit success when at least mission OR evidence is ready.
        return $payload['status'] === AtlasControlPlaneStatus::READY
            || $payload['status'] === AtlasControlPlaneStatus::DEGRADED
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function renderSnapshot(AtlasControlPlaneSnapshotService $service): int
    {
        $payload = $service->snapshot();
        $payload['ok'] = $payload['status'] !== AtlasControlPlaneStatus::BLOCKED;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail(
                'blockers.total',
                (string) ($payload['blockers_summary']['total'] ?? 0),
            );
            $this->components->twoColumnDetail(
                'next_actions',
                (string) count($payload['next_actions'] ?? []),
            );
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderMission(AtlasControlPlaneMissionService $service): int
    {
        $uuid = $this->stringOption('mission');
        if ($uuid === null) {
            return $this->failWith('mission requires --mission=<uuid>');
        }
        $payload = $service->snapshot($uuid);
        if ($payload === null) {
            return $this->failWith("mission not found for uuid [{$uuid}]");
        }
        $payload['ok'] = (($payload['status'] ?? null) === AtlasControlPlaneStatus::READY);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('component', (string) ($payload['component'] ?? ''));
            $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? ''));
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderBlockers(AtlasControlPlaneBlockerService $service): int
    {
        $payload = $service->snapshot(20);
        $payload['ok'] = true;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('total', (string) ($payload['total'] ?? 0));
            $this->components->twoColumnDetail('critical', (string) ($payload['critical'] ?? 0));
            foreach (($payload['by_source'] ?? []) as $source => $count) {
                $this->components->twoColumnDetail('source:'.$source, (string) $count);
            }
        });

        return self::SUCCESS;
    }

    private function renderNextActions(AtlasControlPlaneNextActionService $service): int
    {
        $payload = $service->actions();
        $payload['ok'] = true;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('count', (string) $payload['count']);
            foreach (array_slice($payload['items'] ?? [], 0, 5) as $i => $item) {
                $this->components->twoColumnDetail(
                    'next['.$i.']:'.(string) $item['kind'],
                    (string) ($item['priority'] ?? '').' / '.(string) ($item['component'] ?? ''),
                );
            }
        });

        return self::SUCCESS;
    }

    private function renderSmoke(
        AtlasControlPlaneReadinessService $readinessService,
        AtlasControlPlaneSnapshotService $snapshotService,
        AtlasControlPlaneBlockerService $blockersService,
        AtlasControlPlaneNextActionService $nextActionsService,
    ): int {
        $readiness = $readinessService->report();
        $snapshot = $snapshotService->snapshot();
        $blockers = $blockersService->snapshot(10);
        $nextActions = $nextActionsService->actions();

        $payload = [
            'ok' => true,
            'action' => 'smoke',
            'readiness_status' => $readiness['status'],
            'snapshot_status' => $snapshot['status'],
            'blockers_total' => $blockers['total'] ?? 0,
            'next_actions_count' => $nextActions['count'],
            'components_present' => array_map(static fn (array $c): string => (string) $c['component'], $readiness['components']),
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('readiness', (string) $payload['readiness_status']);
            $this->components->twoColumnDetail('snapshot', (string) $payload['snapshot_status']);
            $this->components->twoColumnDetail('blockers', (string) $payload['blockers_total']);
            $this->components->twoColumnDetail('next_actions', (string) $payload['next_actions_count']);
        });

        return self::SUCCESS;
    }

    private function renderRuntime(AtlasAiControlPlaneService $service): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $payload = $service->report($hours);
        $payload['ok'] = ($payload['status'] ?? null) !== AtlasAiControlPlaneService::STATUS_BLOCKED;
        $summary = (array) ($payload['summary'] ?? []);

        $this->emit($payload, function () use ($payload, $summary, $hours): void {
            $this->components->twoColumnDetail('schema', (string) ($payload['schema_version'] ?? ''));
            $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? ''));
            $this->components->twoColumnDetail('window_hours', (string) $hours);
            $this->components->twoColumnDetail('traces.total', (string) ($summary['total_traces'] ?? 0));
            $this->components->twoColumnDetail('traces.succeeded', (string) ($summary['succeeded'] ?? 0));
            $this->components->twoColumnDetail('traces.failed', (string) ($summary['failed'] ?? 0));
            $this->components->twoColumnDetail('flows.unique', (string) ($summary['unique_flows'] ?? 0));
            $this->components->twoColumnDetail('blockers.count', (string) ($summary['blockers_count'] ?? 0));
            $this->components->twoColumnDetail('handoffs.count', (string) ($summary['handoffs_count'] ?? 0));
            $this->components->twoColumnDetail('hash', (string) ($payload['hash'] ?? ''));
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function failWith(string $message): int
    {
        $payload = ['ok' => false, 'error' => 'invalid_arguments', 'message' => $message];
        $this->line($this->encodeOrEmptyObject($payload));

        return self::FAILURE;
    }

    private function invalidAction(string $action): int
    {
        return $this->failWith("invalid action [{$action}] for atlas:ai:control-plane");
    }

}
