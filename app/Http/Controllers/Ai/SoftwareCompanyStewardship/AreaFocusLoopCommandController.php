<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ai\SoftwareCompanyStewardship;

use App\Http\Controllers\Controller;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Mobile\AtlasInboxService;
use App\Services\Ai\NightShift\AtlasNightShiftAreaFocusContractRegistry;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCycleRevertService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusOperatorDecisionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoopRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\ProductMode\ProductModeCockpitSurfaceService;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Atlas Loop Command Surface · mobile READ live state + WRITE human commands.
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, not a new OS.
 *
 * This controller is a thin composition seam over EXISTING owner services. It
 * adds no selection, execution or merge logic and never invokes a provider:
 *   - live    composes the AP-739 Product Mode cockpit aggregate + the AP-790
 *             reliable 24h loop runner's read/path-only accessors (lock/kill/
 *             pause/recovery/backlog observability).
 *   - cycles  tails the AP-790 append-only cycle ledger (read-only).
 *   - operatorDecision wraps AreaFocusOperatorDecisionService::decide (AP-724;
 *             an accept unlocks the next owner stage under operator review, it
 *             NEVER executes).
 *   - runControl writes ONLY the runner's own pause/kill signal files (the loop
 *             already checks them with is_file() on each iteration boundary).
 *   - directive persists a natural-language operator directive into the REAL
 *             operational inbox (AtlasInboxService) as an operator-review item,
 *             and is HONEST that the 24h loop does not autonomously consume
 *             inbox items — its only durable free-text finding source is
 *             canonical-doc frontmatter under env flags, which a doc commit
 *             (out of scope for a command surface) must satisfy.
 *
 * GET ETags are the deterministic surface hash over the body minus volatile
 * fields; unknown area mirrors the AP-721 stable 404. Invariants held:
 * real-or-blocked (no fabricated merge/provider-proof), proposal-only (accept
 * never executes; directive never auto-consumed), honest-stop (run-control only
 * places the files the loop already obeys). RSI/EarnedAutonomy default-off untouched.
 */
final class AreaFocusLoopCommandController extends Controller
{
    public function __construct(
        private readonly ProductModeCockpitSurfaceService $cockpit,
        private readonly Reliable24hLoopRunnerService $loopRunner,
        private readonly AreaFocusOperatorDecisionService $operatorDecision,
        private readonly AtlasInboxService $inbox,
        private readonly AtlasNightShiftAreaFocusContractRegistry $areaRegistry,
        private readonly ?AreaFocusCycleRevertService $cycleReverts = null,
    ) {}

    public const LIVE_SCHEMA = 'atlas.software_company_stewardship.loop_command_live.v1';

    public const CYCLES_SCHEMA = 'atlas.software_company_stewardship.loop_command_cycles.v1';

    public const RUN_CONTROL_SCHEMA = 'atlas.software_company_stewardship.loop_command_run_control.v1';

    public const DIRECTIVE_SCHEMA = 'atlas.software_company_stewardship.loop_command_directive.v1';

    public const AREAS_SCHEMA = 'atlas.software_company_stewardship.loop_command_areas.v1';

    public const BACKLOG_SCHEMA = 'atlas.software_company_stewardship.loop_command_backlog.v1';

    public const DONE_SCHEMA = 'atlas.software_company_stewardship.loop_command_done.v1';

    public const TRANSFER_SCHEMA = 'atlas.software_company_stewardship.loop_command_transfer.v1';

    /** Operator-facing done cap (delivered = merged cycles with provider-proof). */
    private const DONE_LIMIT_DEFAULT = 20;

    private const DONE_LIMIT_MAX = 200;

    /** Mirrors the read-model defaults so the surface is keyed identically to the loop. */
    private const DEFAULT_FOCUS = 'dev_forge';

    private const DEFAULT_PORTFOLIO = 'atlas_software_company';

    /** Operator-facing cycles cap (matches array_slice tail semantics). */
    private const CYCLES_TAIL_DEFAULT = 20;

    private const CYCLES_TAIL_MAX = 200;

    /** Run-control signal verbs the loop already obeys (pause/kill files only). */
    private const RUN_CONTROL_ACTIONS = ['pause', 'resume', 'kill', 'clear-kill'];

    /**
     * (a) GET live loop state — compose AP-739 cockpit + AP-790 reliable runner status.
     */
    public function live(Request $request, string $area): JsonResponse
    {
        $focus = $this->focus($request);
        $portfolio = $this->portfolio($request);
        $repoRoot = (string) ($request->query('repo_root') ?? '');

        $cockpit = $this->cockpit->project($portfolio, [
            'area_id' => $area,
            'repo_root' => $repoRoot,
        ]);

        // Unknown area: mirror AreaFocusController's stable 404 exactly.
        if ($unknown = $this->unknownAreaResponse($cockpit, $area)) {
            return $unknown;
        }

        $runState = [
            'lock' => $this->loopRunner->lockStatus($area, $focus),
            'kill_switch' => $this->loopRunner->killSwitchStatus($area, $focus),
            'pause' => $this->loopRunner->pauseStatus($area, $focus),
            'stewardship_recovery' => $this->loopRunner->stewardshipRecoveryUntilConsecutiveMergedCyclesNormal([
                'area_id' => $area,
                'focus' => $focus,
            ]),
            'scheduler_backlog' => $this->loopRunner->continuous24hSchedulerBacklogObservability($area, $focus),
        ];
        $runState = $this->publicRuntimeSurface($runState);

        $body = $this->finalize([
            'schema_version' => self::LIVE_SCHEMA,
            'area_id' => $area,
            'focus' => $focus,
            'portfolio_id' => $portfolio,
            'read_only' => true,
            'cockpit' => $this->publicCockpitSummary($cockpit),
            'run_state' => $runState,
        ]);

        return $this->respond($request, $body);
    }

    /**
     * (b) GET cycles tail — compose AP-790 append-only cycle ledger with ?tail=N&hours=H.
     */
    public function cycles(Request $request, string $area): JsonResponse
    {
        $focus = $this->focus($request);
        $tail = $this->tail($request);
        $hours = $this->hours($request);

        $records = $this->loopRunner->readLedgerRecords($area, $focus);
        $total = count($records);

        // `hours` is applied BEFORE `tail` (per contract): drop records whose
        // recorded_at falls outside the window, then keep the last N.
        if ($hours !== null) {
            $cutoff = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify("-{$hours} hours");
            $records = array_values(array_filter(
                $records,
                fn (array $record): bool => $this->recordedWithin($record, $cutoff),
            ));
        }

        $records = array_slice($records, -$tail);

        $body = $this->finalize([
            'schema_version' => self::CYCLES_SCHEMA,
            'area_id' => $area,
            'focus' => $focus,
            'ledger_record_count_total' => $total,
            'returned_count' => count($records),
            'tail' => $tail,
            'hours' => $hours,
            // O ledger é um artefato interno append-only. A rota móvel expõe
            // somente sua projeção histórica segura, nunca referências de
            // diagnóstico, backlog de plano, IDs de run ou payloads do worker.
            'cycles' => array_values(array_map(
                fn (array $record): array => $this->publicCycleRecord($record),
                $records,
            )),
        ]);

        return $this->respond($request, $body);
    }

    /**
     * GET areas — the selectable run areas, composed from the AP-712 Area Contract Registry.
     *
     * The registry is the canonical, deterministic source of what the loop may steward. v1
     * registers the Atlas-itself areas (`agentic_engineering_os` and `atlas_loop_factory`);
     * this surface never invents more. Each area carries its objective, focus, autonomy tier
     * and dev mode straight from the contract, plus a thin run_state.lock snapshot so the
     * picker can show which area (if any) already has a live run. No execution, no provider,
     * no mutation.
     */
    public function areas(Request $request): JsonResponse
    {
        $defaultFocus = self::DEFAULT_FOCUS;
        $areas = [];
        foreach ($this->areaRegistry->registeredAreas() as $areaId) {
            $contract = $this->areaRegistry->resolve($areaId);
            if (! is_array($contract)) {
                continue;
            }
            $focus = $defaultFocus;
            $areas[] = [
                'area_id' => $areaId,
                'area_name' => (string) ($contract['area_name'] ?? $areaId),
                'focus' => $focus,
                'autonomy_tier' => (int) ($contract['autonomy_tier'] ?? 0),
                'max_tier_for_area' => (int) ($contract['max_tier_for_area'] ?? 0),
                'dev_mode' => (string) ($contract['dev_mode'] ?? 'max_governed'),
                'registered' => true,
                'objective' => (string) ($contract['objective'] ?? ''),
                'owned_systems' => array_values(array_filter((array) ($contract['owned_systems'] ?? []), 'is_string')),
                // O contrato canônico inclui paths permitidos/proibidos para
                // o runner. A superfície móvel só precisa dos repositórios
                // nomeados, nunca da topologia ou policy de filesystem.
                'repo_scope' => [
                    'repos' => $this->publicRepositoryNames(data_get($contract, 'repo_scope.repos', [])),
                ],
                'stop_conditions' => array_values(array_filter((array) ($contract['stop_conditions'] ?? []), 'is_string')),
                // Thin live snapshot so the picker reflects which area already runs (no full /live).
                'run_state' => [
                    'lock' => $this->publicRuntimeSurface([
                        'lock' => $this->loopRunner->lockStatus($areaId, $focus),
                    ])['lock'],
                ],
            ];
        }

        $body = $this->finalize([
            'schema_version' => self::AREAS_SCHEMA,
            'read_only' => true,
            'areas' => $areas,
            'area_count' => count($areas),
            'default_area' => AtlasNightShiftAreaFocusContractRegistry::AREA_AGENTIC_ENGINEERING_OS,
            'default_focus' => $defaultFocus,
        ]);

        return $this->respond($request, $body);
    }

    /**
     * GET backlog — open findings / to-implement for an area (thin projection over the SAME
     * AP-739 cockpit `project()` the live endpoint composes; no second source, no duplication).
     *
     * Returns only the area_focus backlog slice (findings/work_orders/inbox_items/budgets) plus a
     * paginate-friendly `total`/`returned` over the findings list. Read-only; never executes,
     * never invokes a provider, never fabricates a finding (an empty backlog stays honestly empty).
     */
    public function backlog(Request $request, string $area): JsonResponse
    {
        $focus = $this->focus($request);
        $portfolio = $this->portfolio($request);
        $repoRoot = (string) ($request->query('repo_root') ?? '');
        $limit = $this->boundedLimit($request, self::DONE_LIMIT_DEFAULT, self::DONE_LIMIT_MAX);
        $offset = max(0, (int) ($request->query('offset') ?? 0));

        $cockpit = $this->cockpit->project($portfolio, [
            'area_id' => $area,
            'repo_root' => $repoRoot,
        ]);

        if ($unknown = $this->unknownAreaResponse($cockpit, $area)) {
            return $unknown;
        }

        $areaFocus = is_array($cockpit['area_focus'] ?? null) ? $cockpit['area_focus'] : [];
        $findings = is_array($areaFocus['findings'] ?? null) ? $areaFocus['findings'] : [];
        $items = array_values(array_filter((array) ($findings['items'] ?? []), 'is_array'));
        $findingsTotal = (int) ($findings['total'] ?? count($items));
        // Collapse identical findings (the self-improvement closed-loop can emit the SAME
        // backlog item many times) into one row carrying a `count`, ranked by priority (desc)
        // then count (desc). `total` stays the raw finding count; `distinct_total` is the
        // deduped row count the list actually shows. Pure; never fabricates a finding.
        $items = $this->dedupeAndRankBacklog($items);
        $distinctTotal = count($items);
        $page = array_slice($items, $offset, $limit);

        $body = $this->finalize([
            'schema_version' => self::BACKLOG_SCHEMA,
            'area_id' => $area,
            'focus' => $focus,
            'portfolio_id' => $portfolio,
            'read_only' => true,
            'findings' => [
                'total' => $findingsTotal,
                'distinct_total' => $distinctTotal,
                'returned' => count($page),
                'offset' => $offset,
                'limit' => $limit,
                'by_risk' => is_array($findings['by_risk'] ?? null) ? $findings['by_risk'] : [],
                'by_route' => is_array($findings['by_route'] ?? null) ? $findings['by_route'] : [],
                'items' => $page,
            ],
            'work_orders' => array_values(array_filter((array) ($areaFocus['work_orders'] ?? []), 'is_array')),
            'inbox_items' => array_values(array_filter((array) ($areaFocus['inbox_items'] ?? []), 'is_array')),
            'budgets' => is_array($areaFocus['budgets'] ?? null) ? $areaFocus['budgets'] : [],
        ]);

        return $this->respond($request, $body);
    }

    /**
     * Collapse identical backlog findings into one ranked row with a count. Key =
     * title|source|gap_kind. Keeps the highest-priority representative, sums the count,
     * sorts by priority_score desc then count desc. Pure; never fabricates a finding.
     *
     * @param  list<array<string,mixed>>  $items
     * @return list<array<string,mixed>>
     */
    private function dedupeAndRankBacklog(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $key = strtolower(trim((string) ($item['title'] ?? '')))
                .'|'.strtolower(trim((string) ($item['source'] ?? '')))
                .'|'.strtolower(trim((string) ($item['gap_kind'] ?? '')));
            if (! isset($groups[$key])) {
                $item['count'] = 1;
                $groups[$key] = $item;

                continue;
            }
            $nextCount = (int) ($groups[$key]['count'] ?? 1) + 1;
            if ((int) ($item['priority_score'] ?? 0) > (int) ($groups[$key]['priority_score'] ?? 0)) {
                $item['count'] = $nextCount;
                $groups[$key] = $item;
            } else {
                $groups[$key]['count'] = $nextCount;
            }
        }
        $ranked = array_values($groups);
        usort($ranked, static function (array $a, array $b): int {
            $pa = (int) ($a['priority_score'] ?? 0);
            $pb = (int) ($b['priority_score'] ?? 0);

            return $pa !== $pb ? $pb <=> $pa : (int) ($b['count'] ?? 1) <=> (int) ($a['count'] ?? 1);
        });

        return $ranked;
    }

    /**
     * GET done — delivered cycles (outcome=merged with a real merge_hash + honest provider-proof),
     * newest-first and paginate-friendly. A thin filter over the SAME AP-790 append-only ledger the
     * cycles endpoint tails; it never fabricates a merge. The hard truth a cycle "delivered" is
     * `outcome===merged && merge_performed && merge_hash!==''` — anything missing those is excluded.
     */
    public function done(Request $request, string $area): JsonResponse
    {
        $focus = $this->focus($request);
        $limit = $this->boundedLimit($request, self::DONE_LIMIT_DEFAULT, self::DONE_LIMIT_MAX);
        $offset = max(0, (int) ($request->query('offset') ?? 0));

        $records = $this->loopRunner->readLedgerRecords($area, $focus);
        $ledgerTotal = count($records);

        // Delivered = real merge with a hash. No merge_hash => never counted (real-or-blocked).
        $delivered = array_values(array_filter(
            $records,
            static fn (array $r): bool => (string) ($r['outcome'] ?? '') === 'merged'
                && ($r['merge_performed'] ?? false) === true
                && trim((string) ($r['merge_hash'] ?? '')) !== '',
        ));
        // Newest-first for an operator delivery log.
        $delivered = array_reverse($delivered);
        $deliveredTotal = count($delivered);
        $page = array_slice($delivered, $offset, $limit);

        $body = $this->finalize([
            'schema_version' => self::DONE_SCHEMA,
            'area_id' => $area,
            'focus' => $focus,
            'read_only' => true,
            'ledger_record_count_total' => $ledgerTotal,
            'delivered_total' => $deliveredTotal,
            'returned' => count($page),
            'offset' => $offset,
            'limit' => $limit,
            // `done` é outro recorte do mesmo ledger interno de `cycles`.
            // A tela móvel recebe a mesma projeção pública, não IDs, refs de
            // finding ou qualquer payload de execução do worker.
            'delivered' => array_values(array_map(
                fn (array $record): array => $this->publicCycleRecord($record),
                $page,
            )),
        ]);

        return $this->respond($request, $body);
    }

    /**
     * POST M08 cycle revert — enqueue a governed git-revert request for a delivered cycle.
     *
     * The endpoint is intentionally honest: it appends a `revert_status=enqueued`
     * ledger receipt pointing at the original cycle/merge_hash, dispatches a queued
     * marker job, and never claims the git revert has already happened.
     */
    public function revertCycle(Request $request, string $area, string $cycle): JsonResponse
    {
        [$status, $body] = ($this->cycleReverts ?? app(AreaFocusCycleRevertService::class))
            ->enqueue($area, $cycle, $this->body($request));

        return response()->json($body, $status);
    }

    /**
     * POST transfer — request a durable, safe-boundary handoff of the CURRENT lock holder.
     *
     * This never selects a host or reports a new worker as started. The source runner must
     * release the lock at an iteration boundary; only a later successor lock can claim it.
     */
    public function transfer(Request $request, string $area): JsonResponse
    {
        $input = $this->body($request);
        $focus = $this->focusFrom($input['focus'] ?? null);
        $actor = trim((string) ($input['operator_actor'] ?? ''));
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($actor === '') {
            return $this->blocked('operator_actor_required', 'operator_actor is required (the handoff must be operator-owned).');
        }
        if ($reason === '') {
            return $this->blocked('transfer_reason_required', 'reason is required so the handoff remains auditable.');
        }
        if (mb_strlen($reason) > 1000) {
            return $this->blocked('transfer_reason_too_long', 'reason may not exceed 1000 characters.');
        }
        if (! $this->areaRegistry->isRegistered($area)) {
            return response()->json(['error' => ['code' => 'unknown_area', 'message' => "Area '{$area}' is not registered for the loop."]], 404);
        }

        $lock = $this->loopRunner->lockStatus($area, $focus);
        $holder = is_array($lock['holder'] ?? null) ? $lock['holder'] : null;
        if (($lock['held'] ?? false) !== true || $holder === null || trim((string) ($holder['run_id'] ?? '')) === '') {
            return response()->json([
                'schema_version' => self::TRANSFER_SCHEMA,
                'status' => 'blocked',
                'reason' => 'no_live_source_run',
                'area_id' => $area,
                'focus' => $focus,
                'detail' => 'No live lock holder can prove a source mission to transfer.',
            ], 409);
        }

        try {
            $record = $this->loopRunner->handoff()->request($area, $focus, $holder, $actor, $reason);
        } catch (\RuntimeException $e) {
            return response()->json([
                'schema_version' => self::TRANSFER_SCHEMA,
                'status' => 'blocked',
                'reason' => 'transfer_already_active',
                'area_id' => $area,
                'focus' => $focus,
                'detail' => $e->getMessage(),
            ], 409);
        }
        $public = $this->loopRunner->handoff()->publicRecord((string) $record['handoff_id']) ?? [];

        return response()->json([
            'schema_version' => self::TRANSFER_SCHEMA,
            'status' => 'transfer_requested',
            'transfer_requested' => true,
            'started' => false,
            'area_id' => $area,
            'focus' => $focus,
            'handoff' => $public,
            'source' => $public['source'] ?? [],
            'target' => $public['target'] ?? [],
            'note' => 'The current worker must release at a safe boundary. A successor remains unassigned until a queue worker acquires the same mission lock.',
        ], 202);
    }

    /** GET transfer receipt — a live read of the durable handoff state, never an optimistic projection. */
    public function transferStatus(string $area, string $handoffId): JsonResponse
    {
        $record = $this->loopRunner->handoff()->publicRecord($handoffId);
        if ($record === null || (string) ($record['area_id'] ?? '') !== $area) {
            return response()->json([
                'schema_version' => self::TRANSFER_SCHEMA,
                'status' => 'blocked',
                'reason' => 'unknown_handoff',
                'area_id' => $area,
            ], 404);
        }

        return response()->json([
            'schema_version' => self::TRANSFER_SCHEMA,
            'status' => (string) ($record['status'] ?? 'unknown'),
            'area_id' => $area,
            'focus' => (string) ($record['focus'] ?? ''),
            'handoff' => $record,
            'started' => (string) ($record['status'] ?? '') === 'target_claimed',
        ]);
    }

    /**
     * (c) POST operator-decision — wrap AreaFocusOperatorDecisionService::decide (AP-724).
     *
     * The receipt is returned verbatim. An accept unlocks the next owner stage under operator
     * review; it NEVER executes here (executed=false, requires_owner_execution=true). The
     * controller dispatches no owner runtime — it only persists the operator decision receipt.
     */
    public function operatorDecision(Request $request, string $area): JsonResponse
    {
        $input = $this->body($request);
        // The path param anchors the area unless the body explicitly overrides it.
        if (! array_key_exists('area_id', $input) || trim((string) $input['area_id']) === '') {
            $input['area_id'] = $area;
        }

        try {
            $receipt = $this->operatorDecision->decide($input);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'schema_version' => AreaFocusOperatorDecisionService::RECEIPT_SCHEMA.'.error.v1',
                'status' => 'blocked',
                'reason' => $this->decisionBlockReason($e->getMessage()),
                'detail' => $e->getMessage(),
            ], 422);
        }

        return response()->json($receipt, 201);
    }

    /**
     * (d) POST run-control — pause|resume|kill|clear-kill via the runner's OWN signal-file paths.
     *
     * This is a SIGNAL only. It never starts/stops a process, never invokes a provider, never
     * merges. No existing service writes these files; the runner only checks them with is_file()
     * on each iteration boundary, so writing/deleting the runner's own pausePath()/killSwitchPath()
     * is the correct, non-duplicating composition. honest-stop intact.
     */
    public function runControl(Request $request, string $area): JsonResponse
    {
        $input = $this->body($request);
        $focus = $this->focusFrom($input['focus'] ?? null);
        $actor = trim((string) ($input['operator_actor'] ?? ''));
        $action = strtolower(trim((string) ($input['action'] ?? '')));
        $reason = trim((string) ($input['reason'] ?? ''));

        if ($actor === '') {
            return $this->blocked('operator_actor_required', 'operator_actor is required (the command must be operator-owned).');
        }
        if (! in_array($action, self::RUN_CONTROL_ACTIONS, true)) {
            return $this->blocked('invalid_action', 'action must be one of '.implode(', ', self::RUN_CONTROL_ACTIONS).'.');
        }

        $pausePath = $this->loopRunner->pausePath($area, $focus);
        $killPath = $this->loopRunner->killSwitchPath($area, $focus);
        $signal = (string) json_encode([
            'operator_actor' => $actor,
            'reason' => $reason,
            'action' => $action,
            'at' => $this->nowAtom(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        match ($action) {
            'pause' => $this->writeSignal($pausePath, $signal),
            'resume' => $this->deleteSignal($pausePath),
            'kill' => $this->writeSignal($killPath, $signal),
            'clear-kill' => $this->deleteSignal($killPath),
        };

        $signals = $this->publicRuntimeSurface([
            'kill_switch' => $this->loopRunner->killSwitchStatus($area, $focus),
            'pause' => $this->loopRunner->pauseStatus($area, $focus),
        ]);

        return response()->json([
            'schema_version' => self::RUN_CONTROL_SCHEMA,
            'area_id' => $area,
            'focus' => $focus,
            'action' => $action,
            'operator_actor' => $actor,
            'applied' => true,
            // TRUE post-state, re-read from disk after the write.
            'kill_switch' => $signals['kill_switch'],
            'pause' => $signals['pause'],
            'note' => 'Loop honors signal on next iteration boundary (<=5s mid-sleep via responsiveSleep).',
            'generated_at' => $this->nowAtom(),
        ], 200);
    }

    /**
     * (e) POST directive — persist a natural-language operator directive into the REAL store.
     *
     * HONEST contract: the 24h loop has NO durable free-text directive intake. Its only durable,
     * operator-authored natural-language finding source is canonical-doc frontmatter
     * (next_actions/allowed_changes) under env flags. Writing a doc commit from an HTTP handler
     * would violate the proposal-only/no-scaffold posture and is out of scope for a command surface.
     * So the directive is persisted into the REAL operational inbox (AtlasInboxService) as an
     * operator-review item, with a machine-readable block telling the operator how to make it
     * loop-consumable. We NEVER claim a merge or autonomous pickup.
     */
    public function directive(Request $request, string $area): JsonResponse
    {
        $input = $this->body($request);
        $directive = trim((string) ($input['directive'] ?? ''));
        $actor = trim((string) ($input['operator_actor'] ?? ''));
        $focus = $this->focusFrom($input['focus'] ?? null);
        $risk = $this->normalizeRisk($input['risk'] ?? null);
        $targetDoc = trim((string) ($input['target_doc'] ?? ''));

        if ($directive === '') {
            return $this->blocked('directive_required', 'directive is required and must be non-empty.');
        }
        if ($actor === '') {
            return $this->blocked('operator_actor_required', 'operator_actor is required (the directive must be operator-owned).');
        }

        $directiveId = 'lcd_'.substr(hash('sha256', implode('|', [
            'loop_command_directive',
            $area,
            $focus,
            $actor,
            $directive,
        ])), 0, 16);

        $toMakeConsumable = [
            'real_finding_source' => 'canonical_doc_frontmatter (next_actions/allowed_changes)',
            'reader' => 'CanonicalDocFrontmatterReader::extractDirectives',
            'docs_root' => 'docs/engineering-knowledge-base/',
            'required_flags' => [
                'ATLAS_STEWARDSHIP_SCAN_CANONICAL_DOC_BACKLOG',
                'ATLAS_STEWARDSHIP_AUTONOMOUS_DOC_BACKLOG_EXECUTION',
            ],
            'operator_step' => 'Commit this directive as a frontmatter list item in the area owner doc, then enable the flags. The loop never auto-edits docs.',
        ];

        // source_id in ai_inbox_items is a UUID column; the (non-UUID) directive_id is carried in the
        // payload + dedupe_key instead, mirroring StewardshipRuntimeResultBridgeService's inbox emit.
        $item = $this->inbox->create([
            'user_id' => 'vitor',
            'type' => 'insight',
            'category' => 'software_company_stewardship',
            'severity' => in_array($risk, ['high', 'critical'], true) ? 'warning' : 'info',
            'status' => 'unread',
            'initiator' => 'operator',
            'title' => 'Loop directive (operator review): '.$directive,
            'summary' => 'Operator natural-language directive for the '.$area.' / '.$focus.' loop. Not auto-consumed; see next steps.',
            'body' => $directive,
            'source_type' => 'loop_command_directive',
            'source_id' => null,
            'dedupe_key' => 'loop_command_directive:'.$directiveId,
            'payload' => [
                'directive_id' => $directiveId,
                'area_id' => $area,
                'focus' => $focus,
                'operator_actor' => $actor,
                'directive' => $directive,
                'risk' => $risk,
                'target_doc' => $targetDoc !== '' ? $targetDoc : null,
                'loop_autonomously_consumable_now' => false,
                'to_make_loop_consumable' => $toMakeConsumable,
                'auto_consumed' => false,
                'operator_review_required' => true,
            ],
        ]);

        return response()->json([
            'schema_version' => self::DIRECTIVE_SCHEMA,
            'area_id' => $area,
            'focus' => $focus,
            'directive_id' => $directiveId,
            'operator_actor' => $actor,
            'directive' => $directive,
            'persisted_to' => 'operational_inbox',
            'inbox_item_id' => (string) $item->id,
            // HONEST: the inbox is NOT a loop finding source. Never claim a merge or autonomous pickup.
            'loop_autonomously_consumable_now' => false,
            'to_make_loop_consumable' => $toMakeConsumable,
            'executed' => false,
            'provider_invoked' => false,
            'mutates_target_repo' => false,
            'auto_consumed' => false,
            'generated_at' => $this->nowAtom(),
        ], 201);
    }

    // ------------------------------------------------------------------
    // Shared helpers (ETag/304, deterministic hash, query coercion)
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $body
     */
    private function respond(Request $request, array $body): JsonResponse
    {
        $etag = '"'.(string) ($body['surface_hash'] ?? '').'"';
        if ((string) $request->header('If-None-Match', '') === $etag) {
            return response()->json(null, 304, ['ETag' => $etag]);
        }

        return response()->json($body, 200, ['ETag' => $etag, 'Cache-Control' => 'private, max-age=5']);
    }

    /**
     * Stamp a deterministic surface_hash (over the body minus volatile fields)
     * and a UTC generated_at, mirroring the read-model finalize pattern.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function finalize(array $payload): array
    {
        $hashPayload = $this->withoutVolatile($payload);
        unset($hashPayload['surface_hash']);

        $payload['surface_hash'] = 'sha256:'.MissionCanonicalHash::sha256($hashPayload);
        $payload['generated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);

        return $payload;
    }

    /**
     * The runner needs filesystem paths internally to honor signals and read the
     * ledger. The mobile command surface only needs the resulting state; paths
     * would expose local topology without enabling any operator action.
     *
     * @param array<string,mixed> $surface
     * @return array<string,mixed>
     */
    private function publicRuntimeSurface(array $surface): array
    {
        foreach ($surface as $key => $value) {
            if (in_array($key, ['path', 'ledger_path'], true)) {
                unset($surface[$key]);

                continue;
            }
            if (is_array($value)) {
                $surface[$key] = $this->publicRuntimeSurface($value);
            }
        }

        return $surface;
    }

    /**
     * O Product Mode cockpit completo é um read model interno, rico em filas e
     * diagnósticos. O Command Center móvel só precisa saber se essa projeção
     * está disponível; qualquer detalhe futuro exige contrato público próprio.
     *
     * @param array<string,mixed> $cockpit
     * @return array{schema_version:string,status:string}
     */
    private function publicCockpitSummary(array $cockpit): array
    {
        return [
            'schema_version' => 'atlas.autonomos.cockpit_summary.v1',
            'status' => $this->publicCycleCode($cockpit['status'] ?? null) ?: 'unknown',
        ];
    }

    /**
     * Projeção allow-list para o histórico visível de ciclos. O ledger pode
     * conter refs diagnósticas, backlog e identificadores de execução; esses
     * campos pertencem à auditoria interna, não ao iPhone.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private function publicCycleRecord(array $record): array
    {
        return [
            'cycle_index' => max(0, (int) ($record['cycle_index'] ?? 0)),
            'outcome' => $this->publicCycleCode($record['outcome'] ?? null),
            'cycle_final_status' => $this->publicCycleCode($record['cycle_final_status'] ?? null),
            'merge_performed' => (bool) ($record['merge_performed'] ?? false),
            'merge_hash' => $this->publicMergeHash($record['merge_hash'] ?? null),
            'loop_receipt_integrity' => $this->publicCycleCode($record['loop_receipt_integrity'] ?? null),
            'blockers' => $this->publicCycleCodes($record['blockers'] ?? null),
            'repaired' => (bool) ($record['repaired'] ?? false),
            'retried' => (bool) ($record['retried'] ?? false),
            'quarantined' => (bool) ($record['quarantined'] ?? false),
            'quarantine_reason' => $this->publicCycleCode($record['quarantine_reason'] ?? null),
            'recorded_at' => $this->publicCycleTimestamp($record['recorded_at'] ?? null),
        ];
    }

    private function publicCycleCode(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^[a-z0-9][a-z0-9_:-]{0,119}$/i', $value) === 1 ? $value : '';
    }

    /** @return list<string> */
    private function publicRepositoryNames(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $name): string => $this->publicCycleCode($name),
            $value,
        )));
    }

    /** @return list<string> */
    private function publicCycleCodes(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $code): string => $this->publicCycleCode($code),
            $value,
        )));
    }

    private function publicMergeHash(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';

        return preg_match('/^(?:sha256:)?[a-f0-9]{7,128}$/i', $value) === 1 ? $value : '';
    }

    private function publicCycleTimestamp(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($value))->format(DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Strip every `generated_at` (recursively) so the hash is stable across
     * calls that differ only by timestamp — including the nested cockpit and
     * source read-model timestamps composed into the body.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withoutVolatile(array $payload): array
    {
        unset($payload['generated_at']);

        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->withoutVolatile($value);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $record
     */
    private function recordedWithin(array $record, DateTimeImmutable $cutoff): bool
    {
        $recordedAt = (string) ($record['recorded_at'] ?? '');
        if ($recordedAt === '') {
            return false;
        }

        $parsed = DateTimeImmutable::createFromFormat(DateTimeInterface::ATOM, $recordedAt)
            ?: DateTimeImmutable::createFromFormat(DateTimeInterface::RFC3339_EXTENDED, $recordedAt);
        if ($parsed === false) {
            try {
                $parsed = new DateTimeImmutable($recordedAt);
            } catch (\Exception) {
                return false;
            }
        }

        return $parsed >= $cutoff;
    }

    private function focus(Request $request): string
    {
        $value = trim((string) ($request->query('focus') ?? ''));

        return $value !== '' ? $value : self::DEFAULT_FOCUS;
    }

    private function portfolio(Request $request): string
    {
        $value = trim((string) ($request->query('portfolio') ?? ''));

        return $value !== '' ? $value : self::DEFAULT_PORTFOLIO;
    }

    private function tail(Request $request): int
    {
        $raw = $request->query('tail');
        if ($raw === null || $raw === '') {
            return self::CYCLES_TAIL_DEFAULT;
        }

        return max(1, min((int) $raw, self::CYCLES_TAIL_MAX));
    }

    private function hours(Request $request): ?int
    {
        $raw = $request->query('hours');
        if ($raw === null || $raw === '') {
            return null;
        }

        return max(1, (int) $raw);
    }

    /**
     * Bounded ?limit= for paginate-friendly GETs (backlog/done). Clamps to [1, $max].
     */
    private function boundedLimit(Request $request, int $default, int $max): int
    {
        $raw = $request->query('limit');
        if ($raw === null || $raw === '') {
            return $default;
        }

        return max(1, min((int) $raw, $max));
    }

    /**
     * Stable unknown-area 404 (shared by live + backlog). The AP-739 cockpit blocks with
     * reason='area_focus_product_mode_blocked' and a nested area_focus.reason='unknown_area' +
     * supported_areas; mirror AreaFocusController's 404 exactly. Returns null when the area is known.
     *
     * @param  array<string,mixed>  $cockpit
     */
    private function unknownAreaResponse(array $cockpit, string $area): ?JsonResponse
    {
        if (($cockpit['status'] ?? null) === ProductModeCockpitSurfaceService::STATUS_BLOCKED
            && (string) ($cockpit['reason'] ?? '') === 'area_focus_product_mode_blocked'
            && (string) data_get($cockpit, 'area_focus.reason', '') === 'unknown_area') {
            return response()->json([
                'error' => [
                    'code' => 'unknown_area',
                    'message' => (string) (data_get($cockpit, 'area_focus.detail') ?: "Area '{$area}' is not supported."),
                    'supported_areas' => array_values((array) data_get($cockpit, 'area_focus.supported_areas', [])),
                ],
            ], 404);
        }

        return null;
    }

    /**
     * Decode the JSON request body into an associative array (tolerant of empty bodies).
     *
     * @return array<string,mixed>
     */
    private function body(Request $request): array
    {
        $data = $request->json()->all();

        return is_array($data) ? $data : [];
    }

    private function focusFrom(mixed $value): string
    {
        $focus = trim((string) ($value ?? ''));

        return $focus !== '' ? $focus : self::DEFAULT_FOCUS;
    }

    private function normalizeRisk(mixed $value): string
    {
        $risk = strtolower(trim((string) ($value ?? '')));

        return in_array($risk, ['low', 'medium', 'high', 'critical'], true) ? $risk : 'medium';
    }

    private function writeSignal(string $path, string $body): void
    {
        File::ensureDirectoryExists(dirname($path));
        // Atomic: write a temp sibling then rename into place so the runner never observes a
        // half-written signal file mid-iteration.
        $tmp = $path.'.'.bin2hex(random_bytes(6)).'.tmp';
        File::put($tmp, $body);
        File::move($tmp, $path);
    }

    private function deleteSignal(string $path): void
    {
        if (is_file($path)) {
            File::delete($path);
        }
    }

    /**
     * Map a decide() InvalidArgumentException message to the stable machine reason. The service
     * prefixes each message with its BLOCK_* token; fall back to invalid_decision otherwise.
     */
    private function decisionBlockReason(string $message): string
    {
        foreach ([
            AreaFocusOperatorDecisionService::BLOCK_ACTOR_REQUIRED,
            AreaFocusOperatorDecisionService::BLOCK_INVALID_DECISION,
            AreaFocusOperatorDecisionService::BLOCK_ITEM_WITHOUT_HASH,
            AreaFocusOperatorDecisionService::BLOCK_HIGH_RISK_ACCEPT_RATIONALE,
        ] as $reason) {
            if (str_starts_with($message, $reason)) {
                return $reason;
            }
        }

        return 'invalid_decision';
    }

    private function blocked(string $reason, string $detail): JsonResponse
    {
        return response()->json([
            'status' => 'blocked',
            'reason' => $reason,
            'detail' => $detail,
        ], 422);
    }

    private function nowAtom(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->format(DateTimeInterface::ATOM);
    }
}
