<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroGiveBackPatternMiner;
use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroPacketReshaper;
use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroWorkerAffinityRouter;
use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroWorkerBehaviorLedger;
use Illuminate\Console\Command;
use Throwable;

/**
 * PART 2 · A7 — the operator/agent-facing front door of THE CONTRACT. Two verbs over one fixed JSON schema:
 *
 *   atlas:task next   --client=<opaque-id> [--tag=..]* [--json]
 *   atlas:task report --client=<opaque-id> --task=<id> --lease=<id> [--outcome=success|failed|give_back]
 *                     [--evidence=<json|->] [--json]
 *
 * `--client` is the ONLY client parameter and is OPAQUE — any AI passes its own id; the server never branches
 * on platform. Delegates 1:1 to {@see AtlasTaskServingService}. Gated by the loop master switch.
 */
class AtlasTaskCommand extends Command
{
    protected $signature = 'atlas:task {action : next|report} {verb?}
        {--client= : Opaque client id (any AI/harness)}
        {--task= : task_packet_id (report)}
        {--lease= : lease_id (report)}
        {--outcome=success : success|failed|give_back (report)}
        {--commit : SHARED-MAIN resolve — commit the task allowed_files as your own commit (report success)}
        {--evidence= : JSON evidence, or - to read STDIN (report)}
        {--tag=* : optional queue tag filter (next)}
        {--packet= : task_packet_id (maestro:reshape / maestro:tiering)}
        {--task-class= : task class id (maestro:route)}
        {--worker=* : eligible worker client id (maestro:route)}
        {--tier= : declared tier (maestro:tiering register-worker)}
        {--limit=10 : tail size (maestro:tiering history)}
        {--json : Print machine-readable JSON}';

    protected $description = 'The Atlas task-serving contract: PULL the next task (next) or hand back a result (report). Platform-free, client_id opaque.';

    public function handle(AtlasTaskServingService $serving): int
    {
        $action = (string) $this->argument('action');
        $client = (string) ($this->option('client') ?? '');

        try {
            $result = match ($action) {
                'next' => $serving->next($client, ['tags' => array_values((array) $this->option('tag'))]),
                'report' => $serving->report(
                    $client,
                    (string) ($this->option('task') ?? ''),
                    (string) ($this->option('lease') ?? ''),
                    ['outcome' => (string) $this->option('outcome'), 'commit' => (bool) $this->option('commit'), 'evidence' => $this->evidence()],
                ),
                'maestro:behaviors' => $this->maestroBehaviors(),
                'maestro:reshape' => $this->maestroReshape(),
                'maestro:route' => $this->maestroRoute(),
                'maestro:tiering' => $this->maestroTiering(),
                default => ['schema' => 'atlas.task_serving.error.v1', 'status' => 'unknown_action', 'action' => $action],
            };
        } catch (Throwable $e) {
            $result = ['schema' => 'atlas.task_serving.error.v1', 'status' => 'error', 'error' => $e->getMessage()];
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        $ok = in_array((string) ($result['status'] ?? ''), ['served', 'no_claimable_task', 'no_self_sufficient_task', 'reported', 'resolved', 'disabled', 'ok', 'adaptive_disabled'], true);

        return $ok ? self::SUCCESS : self::FAILURE;
    }

    /**
     * §maestro-adaptive — print FACTS by (client, class) from the behavior ledger. Read-only; never mutates
     * the queue. When the adaptive flag is OFF (default) returns a fixed `adaptive_disabled` envelope.
     *
     * @return array<string,mixed>
     */
    private function maestroBehaviors(): array
    {
        if (! (bool) config('atlas.maestro.adaptive.behavior_ledger_enabled', false)) {
            return ['schema' => 'atlas.task_serving.maestro_behaviors.v1', 'status' => 'adaptive_disabled', 'rows' => []];
        }
        $rows = (new AtlasMaestroWorkerBehaviorLedger)->topGiveBackClasses(50);

        return ['schema' => 'atlas.task_serving.maestro_behaviors.v1', 'status' => 'ok', 'rows' => $rows];
    }

    /**
     * §maestro-adaptive — DRY-RUN the packet reshaper for --packet=<id>. Reads the queue repo, looks up the
     * packet, runs reshape() (which is itself pure), and prints the reshape_receipt. NEVER calls claim/lease
     * mutations on the orchestrator; we only use the queue repo's read-only get(), so the queue is byte-stable.
     *
     * @return array<string,mixed>
     */
    private function maestroReshape(): array
    {
        $packetId = trim((string) $this->option('packet'));
        if ($packetId === '') {
            return ['schema' => 'atlas.task_serving.maestro_reshape.v1', 'status' => 'usage_error', 'reason' => 'packet_required'];
        }
        if (! (bool) config('atlas.maestro.adaptive.reshape_enabled', false)) {
            return ['schema' => 'atlas.task_serving.maestro_reshape.v1', 'status' => 'adaptive_disabled', 'packet_id' => $packetId];
        }

        try {
            $record = AtlasTaskServingStack::queueRepo()->get($packetId);
        } catch (Throwable) {
            $record = null;
        }
        $packet = is_array($record) ? (array) ($record['task_packet'] ?? []) : [];
        if ($packet === []) {
            return ['schema' => 'atlas.task_serving.maestro_reshape.v1', 'status' => 'ok', 'packet_id' => $packetId, 'reshape_receipt' => null, 'reason' => 'packet_not_in_queue'];
        }

        $reshaper = new AtlasMaestroPacketReshaper(new AtlasMaestroGiveBackPatternMiner);
        $reshaped = $reshaper->reshape($packet);

        return [
            'schema' => 'atlas.task_serving.maestro_reshape.v1',
            'status' => 'ok',
            'packet_id' => $packetId,
            'reshape_receipt' => $reshaped['reshape_receipt'] ?? null,
        ];
    }

    /**
     * §maestro-adaptive — show the affinity router's selection over a --task-class given --worker=* eligible
     * client ids. Read-only. OFF (default) ⇒ adaptive_disabled.
     *
     * @return array<string,mixed>
     */
    private function maestroRoute(): array
    {
        $taskClass = trim((string) $this->option('task-class'));
        if ($taskClass === '') {
            return ['schema' => 'atlas.task_serving.maestro_route.v1', 'status' => 'usage_error', 'reason' => 'task_class_required'];
        }
        if (! (bool) config('atlas.maestro.adaptive.router_enabled', false)) {
            return ['schema' => 'atlas.task_serving.maestro_route.v1', 'status' => 'adaptive_disabled', 'task_class' => $taskClass];
        }

        $workers = array_values(array_filter(array_map('strval', (array) $this->option('worker')), static fn (string $w): bool => $w !== ''));
        $verdict = (new AtlasMaestroWorkerAffinityRouter)->route($taskClass, $workers);

        return ['schema' => 'atlas.task_serving.maestro_route.v1', 'status' => 'ok', 'task_class' => $taskClass, 'verdict' => $verdict];
    }

    /**
     * Maestro tiering — advisory routing surface (classify / register-worker / policy / history).
     * Delegates ALL logic to {@see \App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTieringCli}.
     *
     * @return array<string,mixed>
     */
    private function maestroTiering(): array
    {
        $cli = app(\App\Services\Ai\SelfConstruction\Maestro\Tiering\AtlasMaestroTieringCli::class);
        $packetLookup = static function (string $packetId): ?array {
            // Test-friendly override: container-bound callable wins.
            if (app()->bound('atlas.maestro.tiering.packet_lookup')) {
                $override = app('atlas.maestro.tiering.packet_lookup');
                if (is_callable($override)) {
                    $hit = $override($packetId);

                    return is_array($hit) ? $hit : null;
                }
            }
            try {
                $service = app(AtlasTaskServingService::class);
                if (method_exists($service, 'lookupPacket')) {
                    $hit = $service->lookupPacket($packetId);

                    return is_array($hit) ? $hit : null;
                }
            } catch (Throwable) {
            }

            return null;
        };

        return $cli->dispatch([
            'verb' => (string) ($this->argument('verb') ?? ''),
            'client_id' => (string) ($this->option('client') ?? ''),
            'packet' => (string) ($this->option('packet') ?? ''),
            'tier' => (string) ($this->option('tier') ?? ''),
            'limit' => (int) $this->option('limit'),
            'packet_lookup' => $packetLookup,
        ]);
    }

    /** @return array<string, mixed> the decoded --evidence JSON (or stdin when '-'); [] on absent/invalid. */
    private function evidence(): array
    {
        $raw = (string) ($this->option('evidence') ?? '');
        if ($raw === '-') {
            $raw = (string) @file_get_contents('php://stdin');
        }
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
