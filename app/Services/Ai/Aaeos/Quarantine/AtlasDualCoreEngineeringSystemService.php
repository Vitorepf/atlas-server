<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Dual-Core Engineering System · routing matrix decider.
 *
 * Pure, deterministic enforcement of the "Fluxo" routing table from the
 * canonical contract. Given the signals of an incoming programming demand it
 * decides the single core — `dev`, `forge` or `dev_to_forge` — that should own
 * the work, and emits the `atlas.dual_core.route_decision.v1`-shaped payload
 * (route, reason, ambiguity, risk, duration, modules estimate, sdd_required,
 * evidence_required, operator_visible) so the choice is auditable BEFORE any
 * provider runs.
 *
 * This is the FRONT-DOOR decider. It is intentionally separate from:
 *   - {@see \App\Services\Ai\DualCore\DualCoreRouteDecisionService}, which only
 *     PERSISTS a route someone already chose ("Does NOT decide routing"); and
 *   - {@see \App\Services\Ai\Programming\AtlasDev\Escalation\EscalationDecisionEngine},
 *     which scores a Dev run that is ALREADY in flight to decide promotion.
 * This service is what produces the `route` value those collaborators consume.
 *
 * Concrete rules grounded in the doc:
 *   - "Fluxo" table (Sinal -> Rota padrao):
 *       Bug pequeno/local                                  -> dev
 *       Endpoint, comando, DTO, teste ou refactor pequeno  -> dev
 *       Prompt ambiguo mas pequeno/medio                   -> dev (Senior Engineer Loop)
 *       Mudanca em muitos modulos                          -> forge
 *       Sistema novo ou subsistema grande                  -> forge
 *       Trabalho de dias/semanas/meses                     -> forge
 *       Alto risco (dados, seguranca, billing, auth, ...)  -> forge
 *       Multi-provider/multiagente                         -> forge
 *       Dev detecta escopo expandindo                      -> dev_to_forge
 *   - "Contratos" rules:
 *       route=dev          quando a tarefa cabe em fast path governado.
 *       route=forge        quando a tarefa ja nasce como Obra.
 *       route=dev_to_forge quando o Dev comecou/analisou e descobriu que passou
 *                          do limite dele (escalonamento honesto).
 *   - Schema enums: route in {dev,forge,dev_to_forge}; ambiguity in {low,medium,high};
 *     risk in {low,medium,high,critical}; duration in {minutes,hours,days,weeks,months}.
 *   - `sdd_required` defaults true for any non-dev route (forge work needs an SDD).
 *   - "A decisao deve ser visivel para operador, API e Desktop" -> operator_visible
 *     is always true; the decision can NEVER be hidden in a provider prompt.
 *   - failure_modes / quality_gates: "manda tarefa pesada para Dev" and "manda
 *     tarefa pequena para Forge" are the two forbidden outcomes; the matrix
 *     guarantees a Forge-sized signal can never resolve to plain `dev`, and a
 *     small/clear demand never resolves to `forge`.
 *
 * @see docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
 */
final class AtlasDualCoreEngineeringSystemService
{
    public const SCHEMA_VERSION = 'atlas.dual_core.route_decision.v1';

    public const ROUTE_DEV = 'dev';

    public const ROUTE_FORGE = 'forge';

    public const ROUTE_DEV_TO_FORGE = 'dev_to_forge';

    /** @var list<string> */
    public const ROUTES = [self::ROUTE_DEV, self::ROUTE_FORGE, self::ROUTE_DEV_TO_FORGE];

    public const AMBIGUITY_LOW = 'low';

    public const AMBIGUITY_MEDIUM = 'medium';

    public const AMBIGUITY_HIGH = 'high';

    /** @var list<string> */
    public const AMBIGUITY_LEVELS = [self::AMBIGUITY_LOW, self::AMBIGUITY_MEDIUM, self::AMBIGUITY_HIGH];

    public const RISK_LOW = 'low';

    public const RISK_MEDIUM = 'medium';

    public const RISK_HIGH = 'high';

    public const RISK_CRITICAL = 'critical';

    /** @var list<string> */
    public const RISK_LEVELS = [self::RISK_LOW, self::RISK_MEDIUM, self::RISK_HIGH, self::RISK_CRITICAL];

    public const DURATION_MINUTES = 'minutes';

    public const DURATION_HOURS = 'hours';

    public const DURATION_DAYS = 'days';

    public const DURATION_WEEKS = 'weeks';

    public const DURATION_MONTHS = 'months';

    /** @var list<string> Ordered shortest -> longest. */
    public const EXPECTED_DURATIONS = [
        self::DURATION_MINUTES,
        self::DURATION_HOURS,
        self::DURATION_DAYS,
        self::DURATION_WEEKS,
        self::DURATION_MONTHS,
    ];

    /**
     * Doc "Fluxo": a change touching many modules is Forge. The boundary is
     * deliberate — a fast-path Dev patch is scope-limited and verifiable; once
     * the blast radius spans this many modules it "exige arquitetura e plano de
     * fases".
     */
    public const FORGE_MODULES_THRESHOLD = 4;

    /**
     * Doc "Fluxo": "Trabalho de dias/semanas/meses" is Forge ("exige
     * continuidade e Obra"). Minutes/hours stay inside the fast path.
     */
    public const FORGE_DURATIONS = [
        self::DURATION_DAYS,
        self::DURATION_WEEKS,
        self::DURATION_MONTHS,
    ];

    /**
     * Decide which core owns a demand and return the auditable
     * `route_decision.v1` payload.
     *
     * Signals (all optional; safe defaults model a small, clear Dev task):
     *   - intent_summary       string  what the user wants (required-ish; defaulted).
     *   - ambiguity_level      enum    low|medium|high.
     *   - risk_level           enum    low|medium|high|critical.
     *   - expected_duration    enum    minutes|hours|days|weeks|months.
     *   - modules_touched_estimate int (>=1).
     *   - new_system           bool    "Sistema novo ou subsistema grande".
     *   - sdd_required         bool    explicit SDD demand.
     *   - multi_provider       bool    needs multi-provider/multiagent.
     *   - dev_in_flight        bool    Dev already started this task.
     *   - scope_expanded       bool    Dev detected scope outgrowing the fast path.
     *
     * Precedence (doc-faithful):
     *   1. If Dev is already in flight AND scope expanded -> dev_to_forge
     *      ("escalonamento honesto"), regardless of other Forge signals: the
     *      handoff must be recorded as an escalation, not a fresh Forge route.
     *   2. Else if ANY born-as-Obra Forge signal holds -> forge.
     *   3. Else -> dev (fast path; Senior Engineer Loop covers ambiguous-but-small).
     *
     * @param  array<string,mixed>  $signals
     * @return array{
     *   schema:string, route:string, reason:string, intent_summary:string,
     *   ambiguity_level:string, risk_level:string, expected_duration:string,
     *   modules_touched_estimate:int, sdd_required:bool,
     *   evidence_required:list<string>, operator_visible:bool,
     *   forge_signals:list<string>, escalation:bool
     * }
     */
    public function decideRoute(array $signals = []): array
    {
        $intentSummary = $this->normalizeIntent($signals['intent_summary'] ?? null);
        $ambiguity = $this->normalizeEnum(
            $signals['ambiguity_level'] ?? null,
            self::AMBIGUITY_LEVELS,
            self::AMBIGUITY_LOW,
        );
        $risk = $this->normalizeEnum(
            $signals['risk_level'] ?? null,
            self::RISK_LEVELS,
            self::RISK_LOW,
        );
        $duration = $this->normalizeEnum(
            $signals['expected_duration'] ?? null,
            self::EXPECTED_DURATIONS,
            self::DURATION_MINUTES,
        );
        $modules = max(1, (int) ($signals['modules_touched_estimate'] ?? 1));
        $newSystem = (bool) ($signals['new_system'] ?? false);
        $explicitSdd = (bool) ($signals['sdd_required'] ?? false);
        $multiProvider = (bool) ($signals['multi_provider'] ?? false);
        $devInFlight = (bool) ($signals['dev_in_flight'] ?? false);
        $scopeExpanded = (bool) ($signals['scope_expanded'] ?? false);

        // Which "born-as-Obra" Forge signals fire, in doc order.
        $forgeSignals = $this->forgeSignals(
            modules: $modules,
            newSystem: $newSystem,
            duration: $duration,
            risk: $risk,
            multiProvider: $multiProvider,
            explicitSdd: $explicitSdd,
        );

        // Rule 1 — honest escalation. Dev started, then discovered it outgrew
        // the fast path. Doc: route=dev_to_forge "quando o Dev comecou ou
        // analisou e descobriu que passou do limite dele".
        if ($devInFlight && $scopeExpanded) {
            $route = self::ROUTE_DEV_TO_FORGE;
            $reason = 'dev_in_flight_scope_expanded_to_obra';
        } elseif ($forgeSignals !== []) {
            // Rule 2 — born as Obra.
            $route = self::ROUTE_FORGE;
            $reason = $this->forgeReason($forgeSignals);
        } else {
            // Rule 3 — fast path. Ambiguous-but-small is still Dev (Senior
            // Engineer Loop), never Forge.
            $route = self::ROUTE_DEV;
            $reason = $ambiguity === self::AMBIGUITY_LOW
                ? 'small_clear_task_fast_path'
                : 'ambiguous_but_small_dev_senior_engineer_loop';
        }

        $sddRequired = $this->resolveSddRequired($route, $explicitSdd);

        return [
            'schema' => self::SCHEMA_VERSION,
            'route' => $route,
            'reason' => $reason,
            'intent_summary' => $intentSummary,
            'ambiguity_level' => $ambiguity,
            'risk_level' => $risk,
            'expected_duration' => $duration,
            'modules_touched_estimate' => $modules,
            'sdd_required' => $sddRequired,
            'evidence_required' => $this->defaultEvidenceRequired($route),
            // Doc "Contratos": the decision MUST be visible to operator, API and
            // Desktop, and can never be hidden in a provider prompt.
            'operator_visible' => true,
            'forge_signals' => $forgeSignals,
            'escalation' => $route === self::ROUTE_DEV_TO_FORGE,
        ];
    }

    /**
     * Which born-as-Obra Forge signals are active, as canonical short codes in
     * the doc's "Fluxo" order. Empty list == nothing forces Forge.
     *
     * @return list<string>
     */
    public function forgeSignals(
        int $modules,
        bool $newSystem,
        string $duration,
        string $risk,
        bool $multiProvider,
        bool $explicitSdd,
    ): array {
        $signals = [];

        if ($modules >= self::FORGE_MODULES_THRESHOLD) {
            // "Mudanca em muitos modulos -> Forge".
            $signals[] = 'many_modules';
        }
        if ($newSystem) {
            // "Sistema novo ou subsistema grande -> Forge".
            $signals[] = 'new_system_or_large_subsystem';
        }
        if (in_array($duration, self::FORGE_DURATIONS, true)) {
            // "Trabalho de dias/semanas/meses -> Forge".
            $signals[] = 'long_duration';
        }
        if ($risk === self::RISK_HIGH || $risk === self::RISK_CRITICAL) {
            // "Alto risco, dados, seguranca, billing, auth, compliance -> Forge".
            $signals[] = 'high_risk';
        }
        if ($multiProvider) {
            // "Necessidade de multi-provider/multiagente -> Forge".
            $signals[] = 'multi_provider';
        }
        if ($explicitSdd) {
            // SDD-required work is, by definition, Obra-grade.
            $signals[] = 'sdd_required';
        }

        return $signals;
    }

    /**
     * Default `evidence_required` per route, aligned with the doc's "Shared
     * Evidence Contract": Dev produces plan/receipt/verification; Forge adds
     * sdd/work_packets/evidence_pack; the escalation route carries the
     * escalation_packet slot instead of a verification it never reached.
     *
     * @return list<string>
     */
    public function defaultEvidenceRequired(string $route): array
    {
        return match ($route) {
            self::ROUTE_FORGE => ['sdd', 'plan', 'work_packets', 'receipt', 'verification', 'evidence_pack'],
            self::ROUTE_DEV_TO_FORGE => ['plan', 'receipt', 'escalation_packet'],
            default => ['plan', 'receipt', 'verification'],
        };
    }

    /**
     * `sdd_required` is true whenever the route leaves the pure Dev fast path,
     * OR whenever the caller explicitly demanded an SDD. A plain `dev` route
     * with no explicit SDD demand stays light (false).
     */
    public function resolveSddRequired(string $route, bool $explicitSdd): bool
    {
        if ($route !== self::ROUTE_DEV) {
            return true;
        }

        return $explicitSdd;
    }

    /**
     * @param  list<string>  $forgeSignals
     */
    private function forgeReason(array $forgeSignals): string
    {
        // The first active signal in doc order is the headline reason.
        return 'born_as_obra:'.$forgeSignals[0];
    }

    private function normalizeIntent(mixed $value): string
    {
        $intent = is_string($value) ? trim($value) : '';

        return $intent === '' ? 'unspecified_programming_demand' : $intent;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function normalizeEnum(mixed $value, array $allowed, string $default): string
    {
        $candidate = is_string($value) ? strtolower(trim($value)) : '';

        return in_array($candidate, $allowed, true) ? $candidate : $default;
    }
}
