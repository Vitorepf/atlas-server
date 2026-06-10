<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi;

use App\Services\Ai\Foundry\Rsi\ImmutableInvariantRegistryService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusAppendOnlyJsonlRecorder;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusJsonlReader;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSlugNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusUtcClock;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\MetricLedgerService;
use Illuminate\Support\Facades\File;

/**
 * Governed RSI · Part B (Build-RSI) · ComponentValueLedgerService.
 *
 * Per-component "value proven per token" attribution for the Atlas 24h loop.
 *
 * Every owner-flow cycle that reaches the post-merge measured-or-reverted gate
 * records ONE append-only event here: which named components of the loop
 * machinery participated in producing the cycle's outcome, the PROVEN value
 * delta (taken ONLY from {@see MetricLedgerService}
 * — never invented, never provider-scored), and the token cost attributed to
 * each component. From the append-only history it derives, deterministically,
 * each component's value-per-token and identifies the WEAKEST component (the
 * highest-leverage target a governed self-improvement proposal would aim at).
 *
 * Honesty is structural, mirroring the M keystone:
 *   - If the cycle's outcome was not provably MET (no contract, unmet, or a
 *     failed measurement) the event's proven value_delta is NULL and every
 *     component's measured_value_contribution is NULL — never zero-imputed.
 *   - Deterministic components (planner / integrator / metric-ledger) carry 0
 *     token cost because they make no provider call; their value-per-token is
 *     therefore not a finite ratio (reported as null = "no token spend") and
 *     they are EXCLUDED from weakest-component selection — you cannot make a
 *     zero-token component cheaper, only the token-spending session can.
 *   - A component whose repo source path is SACRED (per the Immutable Invariant
 *     Registry) is NEVER eligible to be the weakest target: the RSI loop may not
 *     point its own improvement machinery at a sacred gate.
 *
 * This service NEVER calls a provider, NEVER merges, NEVER reverts, NEVER
 * mutates git, and NEVER auto-applies anything. It only appends and reads.
 */
final class ComponentValueLedgerService
{
    public const LEDGER_SCHEMA = 'atlas.loop_component_value_ledger.v1';

    public const EVENT_SCHEMA = 'atlas.loop_component_value_ledger_event.v1';

    public const OUTCOME_PROVEN = 'proven';

    public const OUTCOME_UNPROVEN = 'unproven';

    /** Token-spending live component (the owner-flow / provider boundary). */
    public const SEAM_LIVE_PROVIDER = 'live_provider_instrumented';

    /** Deterministic component — no provider call, 0 token cost. */
    public const SEAM_DETERMINISTIC = 'deterministic';

    /**
     * Canonical component catalog: stable id => role + repo-relative source path
     * + seam type. The source path lets the sacred-guard exclude any component
     * whose code is protected by the Immutable Invariant Registry.
     *
     * @var array<string,array{role:string,path:string,seam:string}>
     */
    private const COMPONENTS = [
        'planner_axis_n' => [
            'role' => 'slice_selection_fleet_planning',
            'path' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PlanExecution/FleetSlicePlannerService.php',
            'seam' => self::SEAM_DETERMINISTIC,
        ],
        'integrator_axis_n' => [
            'role' => 'serial_merge_integration',
            'path' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PlanExecution/FleetIntegratorService.php',
            'seam' => self::SEAM_DETERMINISTIC,
        ],
        'metric_ledger' => [
            'role' => 'measured_or_reverted_outcome_proof',
            'path' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PlanExecution/MetricLedgerService.php',
            'seam' => self::SEAM_DETERMINISTIC,
        ],
        'session_ap786' => [
            'role' => 'owner_flow_provider_execution',
            'path' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php',
            'seam' => self::SEAM_LIVE_PROVIDER,
        ],
        // Token-spending repair-learning component (FASE 3 iterative repair). It
        // builds the provider repair context for a failing slice, so it DOES burn
        // tokens — and its source is NOT in the sacred set, which makes it a
        // legitimate, non-sacred self-improvement target (the highest-leverage
        // value-per-token component the loop may safely point a governed,
        // proposal-only self-improvement at).
        'repair_loop' => [
            'role' => 'iterative_repair_provider_context',
            'path' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/RepairAgentFeedbackContextBuilderService.php',
            'seam' => self::SEAM_LIVE_PROVIDER,
        ],
    ];

    private ?string $storageRootOverride = null;

    private ?ImmutableInvariantRegistryService $registry;

    public function __construct(?ImmutableInvariantRegistryService $registry = null)
    {
        $this->registry = $registry;
    }

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageRootOverride = $dir;
    }

    public function setRegistryForTesting(?ImmutableInvariantRegistryService $registry): void
    {
        $this->registry = $registry;
    }

    /**
     * Record one cycle's per-component value attribution (append-only).
     *
     * $participation maps a known component id => its per-cycle facts:
     *   ['tokens' => int, 'latency_ms' => int, 'flags' => array<string,mixed>].
     * Unknown component ids are ignored (the catalog is canonical). The proven
     * value delta is read ONLY from $outcome (the MetricLedger event): a non-met
     * or failed outcome yields a NULL proven delta and NULL per-component
     * contribution — never fabricated.
     *
     * @param  array<string,mixed>  $context  area_id/focus/finding_id/cycle_id/merge_hash
     * @param  array<string,mixed>|null  $outcome  MetricLedgerService event (or null = no contract)
     * @param  array<string,array<string,mixed>>  $participation
     * @return array<string,mixed> the appended event
     */
    public function recordCycle(array $context, ?array $outcome, array $participation): array
    {
        $proven = $this->provenDelta($outcome);
        $components = [];
        $totalTokens = 0;
        $live = [];
        $deterministic = [];

        foreach (self::COMPONENTS as $id => $spec) {
            if (! array_key_exists($id, $participation)) {
                continue;
            }
            $facts = $participation[$id];
            $tokens = max(0, (int) ($facts['tokens'] ?? 0));
            $totalTokens += $tokens;

            if ($spec['seam'] === self::SEAM_LIVE_PROVIDER) {
                $live[] = $id;
            } else {
                $deterministic[] = $id;
                // Honesty: a deterministic component makes no provider call.
                $tokens = 0;
            }

            $components[$id] = [
                'component_id' => $id,
                'role' => $spec['role'],
                'seam_type' => $spec['seam'],
                'tokens_consumed' => $tokens,
                'latency_ms' => max(0, (int) ($facts['latency_ms'] ?? 0)),
                // All participating components share the cycle's single proven
                // outcome; a non-proven cycle contributes NULL (never zero).
                'measured_value_contribution' => $proven,
                'participation_flags' => is_array($facts['flags'] ?? null) ? $facts['flags'] : [],
            ];
        }

        $valuePerTokenCycle = ($proven !== null && $totalTokens > 0)
            ? round($proven / $totalTokens, 8)
            : null;

        $event = [
            'schema_version' => self::EVENT_SCHEMA,
            'recorded_at' => AreaFocusUtcClock::atomNow(),
            'area_id' => (string) ($context['area_id'] ?? ''),
            'focus' => (string) ($context['focus'] ?? ''),
            'finding_id' => (string) ($context['finding_id'] ?? ''),
            'cycle_id' => (string) ($context['cycle_id'] ?? ''),
            'merge_hash' => (string) ($context['merge_hash'] ?? ''),
            'outcome_status' => $proven !== null ? self::OUTCOME_PROVEN : self::OUTCOME_UNPROVEN,
            'proven_value_delta' => $proven,
            'total_tokens_cycle' => $totalTokens,
            'value_per_token_cycle' => $valuePerTokenCycle,
            'live_components' => array_values($live),
            'deterministic_components' => array_values($deterministic),
            'components' => array_values($components),
        ];

        $this->append((string) ($context['area_id'] ?? 'unscoped'), (string) ($context['focus'] ?? 'unscoped'), $event);

        return $event;
    }

    /**
     * Replay the append-only ledger for an area/focus (deterministic fold, no
     * side effects).
     *
     * @return list<array<string,mixed>>
     */
    public function replay(string $areaId, string $focus): array
    {
        return AreaFocusJsonlReader::rows($this->ledgerPath($areaId, $focus));
    }

    /**
     * Per-component aggregate value-per-token over the recorded history.
     *
     * For each component that ever participated: total proven value across
     * PROVEN cycles, total tokens, value-per-token (null when the component
     * spent no tokens), participation count and proven-cycle count. Pure
     * computation over the supplied events (no I/O when records are injected).
     *
     * @param  list<array<string,mixed>>|null  $records  optional injected ledger
     * @return array<string,array<string,mixed>> component_id => stats
     */
    public function valuePerTokenByComponent(string $areaId, string $focus, ?array $records = null): array
    {
        $events = $records ?? $this->replay($areaId, $focus);
        $stats = [];

        foreach ($events as $event) {
            $proven = $event['outcome_status'] ?? null;
            $delta = $event['proven_value_delta'] ?? null;
            foreach ((array) ($event['components'] ?? []) as $component) {
                if (! is_array($component)) {
                    continue;
                }
                $id = (string) ($component['component_id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $stats[$id] ??= [
                    'component_id' => $id,
                    'role' => (string) ($component['role'] ?? ''),
                    'seam_type' => (string) ($component['seam_type'] ?? ''),
                    'participation_cycles' => 0,
                    'proven_cycles' => 0,
                    'total_proven_value' => 0.0,
                    'total_tokens' => 0,
                ];
                $stats[$id]['participation_cycles']++;
                $stats[$id]['total_tokens'] += max(0, (int) ($component['tokens_consumed'] ?? 0));
                if ($proven === self::OUTCOME_PROVEN && is_numeric($delta)) {
                    $stats[$id]['proven_cycles']++;
                    $stats[$id]['total_proven_value'] += (float) $delta;
                }
            }
        }

        foreach ($stats as $id => $row) {
            $tokens = (int) $row['total_tokens'];
            $stats[$id]['value_per_token'] = $tokens > 0
                ? round((float) $row['total_proven_value'] / $tokens, 8)
                : null;
            $stats[$id]['total_proven_value'] = round((float) $row['total_proven_value'], 6);
        }

        return $stats;
    }

    /**
     * Identify the WEAKEST token-spending component: the one delivering the
     * least proven value per token (lowest finite value-per-token). This is the
     * legitimate, highest-leverage self-improvement target.
     *
     * Excluded, by RSI safety design:
     *   - deterministic / zero-token components (no value-per-token to improve);
     *   - any component whose source path is SACRED (the loop may never target
     *     its own protective machinery for self-improvement);
     *   - components that never participated in a PROVEN cycle (no real signal).
     *
     * Returns null when no eligible target exists (honest: no weakest claimed).
     *
     * @param  list<array<string,mixed>>|null  $records
     * @return array<string,mixed>|null
     */
    public function weakestComponent(string $areaId, string $focus, ?array $records = null): ?array
    {
        $stats = $this->valuePerTokenByComponent($areaId, $focus, $records);

        $candidates = [];
        foreach ($stats as $row) {
            if ($row['value_per_token'] === null) {
                continue; // zero-token / deterministic — not a value-per-token target.
            }
            if ((int) $row['proven_cycles'] < 1) {
                continue; // no proven signal — never target on noise.
            }
            if ($this->isSacredComponent((string) $row['component_id'])) {
                continue; // sacred-guarded — RSI may not target its own machinery.
            }
            $candidates[] = $row;
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static function (array $a, array $b): int {
            $cmp = $a['value_per_token'] <=> $b['value_per_token'];

            // Deterministic tie-break by component id for replayable selection.
            return $cmp !== 0 ? $cmp : strcmp((string) $a['component_id'], (string) $b['component_id']);
        });

        return $candidates[0];
    }

    /**
     * Is the named component's source path protected by the Immutable Invariant
     * Registry? Without a registry bound, nothing is treated as sacred (the
     * caller is responsible for binding the real registry in the live path).
     */
    public function isSacredComponent(string $componentId): bool
    {
        if ($this->registry === null) {
            return false;
        }
        $spec = self::COMPONENTS[$componentId] ?? null;
        if ($spec === null) {
            return false;
        }

        return $this->registry->isSacredPath($spec['path']);
    }

    /**
     * Repo-relative source path of a catalogued component, or '' for an unknown
     * id. Read-only; used by the SelfTargetSelector to anchor a self gap's
     * proposed diff at the real component file the improvement would touch.
     */
    public function componentSourcePath(string $componentId): string
    {
        return (string) (self::COMPONENTS[$componentId]['path'] ?? '');
    }

    /**
     * The cycle_ids of every PROVEN cycle a component participated in, oldest
     * first (deterministic). Empty when the component never had a proven signal.
     * The SelfTargetSelector uses the most recent proven cycle as the self gap's
     * evidence anchor — a REAL recorded cycle, never a synthesized one.
     *
     * @param  list<array<string,mixed>>|null  $records
     * @return list<string>
     */
    public function provenCycleIdsForComponent(string $areaId, string $focus, string $componentId, ?array $records = null): array
    {
        $events = $records ?? $this->replay($areaId, $focus);
        $cycleIds = [];
        foreach ($events as $event) {
            if (($event['outcome_status'] ?? null) !== self::OUTCOME_PROVEN) {
                continue;
            }
            $participated = false;
            foreach ((array) ($event['components'] ?? []) as $component) {
                if (is_array($component) && (string) ($component['component_id'] ?? '') === $componentId) {
                    $participated = true;
                    break;
                }
            }
            if (! $participated) {
                continue;
            }
            $cycleId = (string) ($event['cycle_id'] ?? '');
            if ($cycleId !== '') {
                $cycleIds[] = $cycleId;
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($cycleIds);
    }

    /**
     * The proven value delta from a MetricLedger outcome event, or null when the
     * outcome is absent, unmet or unmeasurable. NEVER fabricates a value.
     *
     * @param  array<string,mixed>|null  $outcome
     */
    private function provenDelta(?array $outcome): ?float
    {
        if ($outcome === null) {
            return null;
        }
        if (($outcome['outcome_met'] ?? false) !== true) {
            return null;
        }
        $delta = $outcome['measured_delta'] ?? null;

        return is_numeric($delta) ? (float) $delta : null;
    }

    /**
     * @param  array<string,mixed>  $event
     */
    private function append(string $areaId, string $focus, array $event): void
    {
        $path = $this->ledgerPath($areaId, $focus);
        AreaFocusAppendOnlyJsonlRecorder::append($path, $event);
    }

    public function ledgerPath(string $areaId, string $focus): string
    {
        $root = $this->storageRootOverride
            ?? (function_exists('storage_path')
                ? storage_path('atlas/loop_component_value_ledger')
                : sys_get_temp_dir().'/atlas/loop_component_value_ledger');

        return rtrim($root, '/').'/'.AreaFocusSlugNormalizer::unscopedToken($areaId).'/'.AreaFocusSlugNormalizer::unscopedToken($focus).'.jsonl';
    }
}
