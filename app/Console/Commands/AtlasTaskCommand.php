<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroGiveBackPatternMiner;
use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroPacketReshaper;
use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroWorkerAffinityRouter;
use App\Services\Ai\SelfConstruction\Maestro\Adaptive\AtlasMaestroWorkerBehaviorLedger;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasTaskSimplicityContractAuditor;
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
    protected $signature = 'atlas:task {action : next|report|task-graph:replenish} {verb?}
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
        {--limit=10 : tail size (maestro:tiering history / contract actions)}
        {--apply : Mutate queue records (contract:backfill — defaults to dry-run when absent)}
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
                'contract:audit' => $this->contractAudit(),
                'contract:backfill' => $this->contractBackfill(),
                'task-graph:replenish' => $this->taskGraphReplenish(),
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

    /**
     * Read-only simplicity-contract audit over the queue (single packet via --packet, or batch via --limit).
     *
     * @return array<string,mixed>
     */
    private function contractAudit(): array
    {
        $records = $this->loadContractRecords();
        if (isset($records['__usage__'])) {
            return $records['__usage__'];
        }

        $verdict = (new AtlasTaskSimplicityContractAuditor)->audit($records);

        return [
            'schema' => AtlasTaskSimplicityContractAuditor::SCHEMA,
            'status' => 'ok',
            'mode' => 'audit',
            'inspected_count' => (int) $verdict['inspected_count'],
            'conforming_count' => (int) $verdict['conforming_count'],
            'missing_count' => (int) $verdict['missing_count'],
            'drift_count' => (int) $verdict['drift_count'],
            'skipped_count' => (int) $verdict['skipped_count'],
            'findings' => array_slice((array) $verdict['findings'], 0, 200),
            'proof_summary' => (array) $verdict['proof_summary'],
        ];
    }

    /**
     * Backfill drifted/missing simplicity_contract fields. DRY-RUN by default; --apply mutates the queue.
     *
     * @return array<string,mixed>
     */
    private function contractBackfill(): array
    {
        $records = $this->loadContractRecords();
        if (isset($records['__usage__'])) {
            return $records['__usage__'];
        }

        $verdict = (new AtlasTaskSimplicityContractAuditor)->audit($records);
        $apply = (bool) $this->option('apply');

        $candidates = array_values(array_filter(
            (array) $verdict['findings'],
            static fn (array $f): bool => in_array((string) ($f['status'] ?? ''), [AtlasTaskSimplicityContractAuditor::STATUS_DRIFTED, AtlasTaskSimplicityContractAuditor::STATUS_MISSING], true),
        ));

        $mutations = [];
        if ($apply) {
            foreach ($candidates as $candidate) {
                $taskPacketId = (string) ($candidate['task_packet_id'] ?? '');
                if ($taskPacketId === '') {
                    continue;
                }
                try {
                    $result = AtlasTaskServingStack::queueRepo()->backfillSimplicityContract($taskPacketId);
                } catch (Throwable $e) {
                    $result = ['status' => 'error', 'error' => $e->getMessage()];
                }
                $mutations[] = [
                    'task_packet_id' => $taskPacketId,
                    'pre_status' => (string) $candidate['status'],
                    'drift_fields' => array_values((array) ($candidate['drift_fields'] ?? [])),
                    'backfill_status' => (string) ($result['event'] ?? $result['status'] ?? 'unknown'),
                ];
            }
        }

        return [
            'schema' => AtlasTaskSimplicityContractAuditor::SCHEMA,
            'status' => 'ok',
            'mode' => $apply ? 'apply' : 'dry_run',
            'dry_run' => ! $apply,
            'candidate_count' => count($candidates),
            'inspected_count' => (int) $verdict['inspected_count'],
            'mutated_count' => count($mutations),
            'candidates' => array_slice($candidates, 0, 200),
            'mutations' => $mutations,
        ];
    }

    /**
     * Resolve the queue records to audit: --packet (single) or --limit (batch). Returns a list, or a
     * `{__usage__ => envelope}` array on usage error so the caller short-circuits.
     *
     * @return list<array<string,mixed>>|array{__usage__: array<string,mixed>}
     */
    private function loadContractRecords(): array
    {
        $packetId = trim((string) ($this->option('packet') ?? ''));
        try {
            $repo = AtlasTaskServingStack::queueRepo();
        } catch (Throwable $e) {
            return ['__usage__' => ['schema' => AtlasTaskSimplicityContractAuditor::SCHEMA, 'status' => 'usage_error', 'reason' => 'queue_repo_unavailable', 'error' => $e->getMessage()]];
        }

        if ($packetId !== '') {
            try {
                $record = $repo->get($packetId);
            } catch (Throwable $e) {
                return ['__usage__' => ['schema' => AtlasTaskSimplicityContractAuditor::SCHEMA, 'status' => 'usage_error', 'reason' => 'packet_lookup_failed', 'packet_id' => $packetId, 'error' => $e->getMessage()]];
            }
            if (! is_array($record)) {
                return ['__usage__' => ['schema' => AtlasTaskSimplicityContractAuditor::SCHEMA, 'status' => 'usage_error', 'reason' => 'unknown_packet', 'packet_id' => $packetId]];
            }

            return [$record];
        }

        $limit = (int) $this->option('limit');
        if ($limit <= 0) {
            $limit = 10;
        }
        try {
            $records = $repo->list(['limit' => $limit]);
        } catch (Throwable $e) {
            return ['__usage__' => ['schema' => AtlasTaskSimplicityContractAuditor::SCHEMA, 'status' => 'usage_error', 'reason' => 'queue_list_failed', 'error' => $e->getMessage()]];
        }

        return array_values($records);
    }

    /**
     * task-graph:replenish — DRY-RUN by default; --apply turns enqueue writes on.
     *
     * Inputs are resolved from the container so feature tests can bind a fake
     * `atlas.task_graph.replenisher.inputs` provider (a callable returning
     * `[coverage_facts, planner_drafts, queue_facts, max_applied]`). When not
     * bound, the action emits an empty plan envelope — safe default that never
     * touches the queue. Apply path uses the container-bound enqueue callback
     * (or falls back to AtlasTaskServingStack::queueRepo()->enqueue).
     *
     * @return array<string,mixed>
     */
    private function taskGraphReplenish(): array
    {
        $apply = (bool) $this->option('apply');

        $inputs = ['coverage_facts' => [], 'planner_drafts' => [], 'queue_facts' => [], 'max_applied' => \App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphAutonomousReplenisher::DEFAULT_MAX_APPLIED];
        if (app()->bound('atlas.task_graph.replenisher.inputs')) {
            $provider = app('atlas.task_graph.replenisher.inputs');
            if (is_callable($provider)) {
                $resolved = $provider();
                if (is_array($resolved)) {
                    $inputs = array_replace($inputs, $resolved);
                }
            }
        }

        $callback = null;
        if ($apply) {
            if (app()->bound('atlas.task_graph.replenisher.enqueue_callback')) {
                $override = app('atlas.task_graph.replenisher.enqueue_callback');
                if (is_callable($override)) {
                    $callback = $override;
                }
            }
            if ($callback === null) {
                $callback = static function (array $input): array {
                    $packet = is_array($input['task_packet'] ?? null) ? $input['task_packet'] : [];

                    return AtlasTaskServingStack::queueRepo()->enqueue($packet);
                };
            }
        }

        $replenisher = new \App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphAutonomousReplenisher;
        $verdict = $replenisher->run(
            (array) $inputs['coverage_facts'],
            array_values((array) $inputs['planner_drafts']),
            (array) $inputs['queue_facts'],
            ['apply' => $apply, 'max_applied' => (int) $inputs['max_applied'], 'enqueue_callback' => $callback],
        );

        $plan = (array) ($verdict['plan'] ?? []);

        return [
            'schema' => \App\Services\Ai\SelfConstruction\TaskGraph\AtlasSelfConstructionTaskGraphAutonomousReplenisher::SCHEMA,
            'status' => 'ok',
            'mode' => $apply ? 'apply' : 'dry_run',
            'dry_run' => (bool) ($verdict['dry_run'] ?? true),
            'planned_count' => (int) ($plan['enqueue_input_count'] ?? 0),
            'applied_count' => (int) ($verdict['applied_count'] ?? 0),
            'withheld_count' => (int) ($verdict['withheld_count'] ?? 0),
            'duplicate_count' => (int) ($verdict['duplicate_count'] ?? 0),
            'max_applied' => (int) ($verdict['max_applied'] ?? 0),
            'replenisher_hash' => (string) ($verdict['replenisher_hash'] ?? ''),
            'enqueue_results' => array_values((array) ($verdict['enqueue_results'] ?? [])),
        ];
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
