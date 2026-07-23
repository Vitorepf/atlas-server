<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Architecture Audit — index decider.
 *
 * Pure, deterministic runtime for the top-level audit *index* doc (not its child
 * docs, which have their own deciders). The index makes four concrete, testable
 * claims; this service turns each into an enforceable function and never lies.
 *
 *  1. Read Order routing (doc "Read Order" table). Five audit needs each map to
 *     exactly ONE canonical doc to read. `routeReadOrder` resolves a need to its
 *     canonical path; an unknown need is a gap, not a guess — it never invents a
 *     path, because guessing would defeat "one governed pipeline".
 *
 *  2. Architecture Direction layers (doc "Current Architecture Direction" table).
 *     Six layers — Core, Domains, Surfaces, Runtime, Evidence, Learning — each
 *     with the responsibility the doc assigns it. `layerDirection` resolves a
 *     layer; `auditLayers` proves the full six are present and none drifted.
 *
 *  3. Non-Negotiable Conclusion (doc "Non-Negotiable Conclusion"): "Commands and
 *     surfaces must not own business flow. They collect input and call the
 *     canonical Atlas AI pipeline." `auditSurfaceOwnership` makes a surface that
 *     owns/decides/executes business flow a violation, and a surface that only
 *     collects input and calls the pipeline a pass.
 *
 *  4. Implementation Rule (doc "Implementation Rule"): "If a feature is found in
 *     one surface but missing in another, do not copy it. Promote it to the
 *     correct Core/Domain/Runtime capability and enforce it with a registry,
 *     contract or architecture validation check." `decideFeatureGap` returns the
 *     verdict — never "copy". A real cross-surface gap yields verdict=promote
 *     with copy_forbidden=true; a feature already promoted to a shared layer is
 *     already_governed; a single-surface, surface-local feature is no_action.
 *
 * The Core Diagnosis is also encoded: the weakness is NOT missing capability, it
 * is insufficient unified orchestration (`coreDiagnosis`), so the only legal
 * remedy for a duplicate is promotion + an enforcement check, never a copy.
 *
 * NEVER calls a provider. NEVER executes a command, mutates code, or touches the
 * database. It emits classification plus an audit receipt; callers decide whether
 * to consolidate, promote or block.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-architecture-audit.md
 */
final class AtlasAiArchitectureAuditService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.architecture_audit_index.v1';

    /** Feature-gap verdict tokens (doc "Implementation Rule"). */
    public const VERDICT_PROMOTE = 'promote';

    public const VERDICT_ALREADY_GOVERNED = 'already_governed';

    public const VERDICT_NO_ACTION = 'no_action';

    /** Surface-ownership verdict tokens (doc "Non-Negotiable Conclusion"). */
    public const SURFACE_PASS = 'pass';

    public const SURFACE_VIOLATION = 'violation';

    /**
     * Doc "Read Order" table — each audit need resolves to exactly one canonical
     * doc. Order and paths preserved from the doc body.
     *
     * @var array<string,array{need:string,read:string}>
     */
    public const READ_ORDER = [
        'fast_audit_orientation' => [
            'need' => 'Fast audit orientation',
            'read' => 'docs/engineering-knowledge-base/architecture-audit/README.md',
        ],
        'canonical_truths_and_observed_disorder' => [
            'need' => 'Canonical truths and observed disorder',
            'read' => 'docs/engineering-knowledge-base/architecture-audit/canonical-findings.md',
        ],
        'capability_owner_map' => [
            'need' => 'Capability owner map',
            'read' => 'docs/engineering-knowledge-base/architecture-audit/capability-ownership-map.md',
        ],
        'programming_pipeline_target' => [
            'need' => 'Programming pipeline target',
            'read' => 'docs/engineering-knowledge-base/architecture-audit/programming-pipeline-target.md',
        ],
        'historical_full_audit' => [
            'need' => 'Historical full audit',
            'read' => 'docs/engineering-knowledge-base/archive/source-material/atlas-ai-architecture-audit-full-2026-05-08.md',
        ],
    ];

    /**
     * Doc "Current Architecture Direction" table — six layers, each with its
     * documented responsibility. Order preserved from the doc.
     *
     * @var array<string,array{layer:string,direction:string}>
     */
    public const LAYER_DIRECTION = [
        'core' => [
            'layer' => 'Core',
            'direction' => 'Domain/intent, policy handoff, capability registry, context contract, evidence contract.',
        ],
        'domains' => [
            'layer' => 'Domains',
            'direction' => 'Programming, Finance, Personal Development, Marketing, Learning, Self-Improvement and future domains.',
        ],
        'surfaces' => [
            'layer' => 'Surfaces',
            'direction' => 'CLI, App, API, Mobile, MCP, Voice and Vault adapters.',
        ],
        'runtime' => [
            'layer' => 'Runtime',
            'direction' => 'Provider drivers, harnesses, Super Tool Runtime and language-specific services.',
        ],
        'evidence' => [
            'layer' => 'Evidence',
            'direction' => 'Append-only ledger plus projections and telemetry.',
        ],
        'learning' => [
            'layer' => 'Learning',
            'direction' => 'Memory signals, proposals and Curator review.',
        ],
    ];

    /**
     * Shared (non-surface) layers a feature may legally be promoted to
     * (doc "Implementation Rule": "the correct Core/Domain/Runtime capability").
     *
     * @var list<string>
     */
    public const PROMOTABLE_LAYERS = ['core', 'domain', 'runtime'];

    /**
     * Core Diagnosis (doc "Core Diagnosis"): the weakness is not missing
     * capability, it is insufficient unified orchestration. This is the premise
     * that forbids "copy" as a remedy.
     *
     * @return array{schema_version:string,problem:string,is_missing_capability:bool,is_unified_orchestration_gap:bool,remedy:string}
     */
    public function coreDiagnosis(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'problem' => 'insufficient_unified_orchestration',
            'is_missing_capability' => false,
            'is_unified_orchestration_gap' => true,
            'remedy' => 'promote_to_shared_owner_and_enforce_with_check',
        ];
    }

    /**
     * Resolve an audit need to its single canonical doc (doc "Read Order").
     * An unknown need returns found=false with a null path — the index never
     * invents a path.
     *
     * @return array{schema_version:string,key:string,found:bool,need:?string,read:?string}
     */
    public function routeReadOrder(string $need): array
    {
        $key = $this->normalizeKey($need);
        $row = self::READ_ORDER[$key] ?? null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'key' => $key,
            'found' => $row !== null,
            'need' => $row['need'] ?? null,
            'read' => $row['read'] ?? null,
        ];
    }

    /**
     * Resolve a layer to its documented responsibility (doc "Current
     * Architecture Direction"). Unknown layer -> found=false, null direction.
     *
     * @return array{schema_version:string,key:string,found:bool,layer:?string,direction:?string}
     */
    public function layerDirection(string $layer): array
    {
        $key = $this->normalizeKey($layer);
        $row = self::LAYER_DIRECTION[$key] ?? null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'key' => $key,
            'found' => $row !== null,
            'layer' => $row['layer'] ?? null,
            'direction' => $row['direction'] ?? null,
        ];
    }

    /**
     * Audit the full six-layer Architecture Direction model. Given the set of
     * layers an architecture declares, prove all six documented layers are
     * present and flag any extra (drifted) layer not in the doc.
     *
     * @param  list<string>  $declaredLayers
     * @return array{schema_version:string,status:string,expected_count:int,present:list<string>,missing:list<string>,unexpected:list<string>}
     */
    public function auditLayers(array $declaredLayers): array
    {
        $declared = [];
        foreach ($declaredLayers as $raw) {
            $declared[$this->normalizeKey((string) $raw)] = true;
        }

        $expected = array_keys(self::LAYER_DIRECTION);

        $present = [];
        $missing = [];
        foreach ($expected as $layer) {
            if (isset($declared[$layer])) {
                $present[] = $layer;
            } else {
                $missing[] = $layer;
            }
        }

        $unexpected = [];
        foreach (array_keys($declared) as $layer) {
            if (! isset(self::LAYER_DIRECTION[$layer])) {
                $unexpected[] = $layer;
            }
        }

        $status = ($missing === [] && $unexpected === []) ? 'ok' : 'drift';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'expected_count' => count($expected),
            'present' => $present,
            'missing' => $missing,
            'unexpected' => $unexpected,
        ];
    }

    /**
     * Audit a surface/command against the Non-Negotiable Conclusion: "Commands
     * and surfaces must not own business flow. They collect input and call the
     * canonical Atlas AI pipeline."
     *
     * A surface is a VIOLATION when it owns business flow, decides business
     * outcomes, or executes runtime directly. A surface is a PASS only when it
     * stays an input collector that calls the pipeline. The strongest signal is
     * ownership/decision/execution: any one of them flips to violation regardless
     * of whether the surface also "calls the pipeline" (a surface cannot both own
     * the flow and be a thin adapter).
     *
     * @param  array{owns_business_flow?:bool,decides_business_outcome?:bool,executes_runtime?:bool,collects_input?:bool,calls_pipeline?:bool}  $signals
     * @return array<string,mixed>
     */
    public function auditSurfaceOwnership(array $signals): array
    {
        $ownsFlow = (bool) ($signals['owns_business_flow'] ?? false);
        $decides = (bool) ($signals['decides_business_outcome'] ?? false);
        $executes = (bool) ($signals['executes_runtime'] ?? false);
        $collectsInput = (bool) ($signals['collects_input'] ?? false);
        $callsPipeline = (bool) ($signals['calls_pipeline'] ?? false);

        $violations = [];
        if ($ownsFlow) {
            $violations[] = 'surface_owns_business_flow';
        }
        if ($decides) {
            $violations[] = 'surface_decides_business_outcome';
        }
        if ($executes) {
            $violations[] = 'surface_executes_runtime_directly';
        }

        $verdict = $violations === [] ? self::SURFACE_PASS : self::SURFACE_VIOLATION;

        // The doc's positive contract: a clean surface collects input AND calls
        // the pipeline. Flag a surface that neither owns flow nor delegates as
        // not-yet-compliant (it must call the pipeline to be governed).
        $delegatesToPipeline = $collectsInput && $callsPipeline;

        $remedy = $verdict === self::SURFACE_VIOLATION
            ? 'move_flow_to_pipeline_surface_collects_input_only'
            : ($delegatesToPipeline ? 'compliant_thin_adapter' : 'wire_surface_to_call_pipeline');

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'violations' => $violations,
            'delegates_to_pipeline' => $delegatesToPipeline,
            'remedy' => $remedy,
        ];
    }

    /**
     * Apply the doc "Implementation Rule" to a feature parity gap.
     *
     * Doc: "If a feature is found in one surface but missing in another, do not
     * copy it. Promote it to the correct Core/Domain/Runtime capability and
     * enforce it with a registry, contract or architecture validation check."
     *
     * Inputs describe where the feature lives. The verdict is NEVER "copy":
     *   - present in one surface, missing in another, not yet on a shared layer
     *     -> verdict=promote, copy_forbidden=true, requires an enforcement check.
     *   - already promoted to a shared (Core/Domain/Runtime) layer
     *     -> verdict=already_governed.
     *   - present in a single surface only with no other surface needing it
     *     -> verdict=no_action (nothing to promote yet).
     *
     * @param  array{present_surfaces?:list<string>,missing_surfaces?:list<string>,owning_layer?:?string,target_layer?:?string}  $gap
     * @return array<string,mixed>
     */
    public function decideFeatureGap(array $gap): array
    {
        $present = $this->normalizeList($gap['present_surfaces'] ?? []);
        $missing = $this->normalizeList($gap['missing_surfaces'] ?? []);
        $owningLayer = isset($gap['owning_layer']) ? $this->normalizeKey((string) $gap['owning_layer']) : null;
        $targetLayer = isset($gap['target_layer']) ? $this->normalizeKey((string) $gap['target_layer']) : null;

        $alreadyShared = $owningLayer !== null && in_array($owningLayer, self::PROMOTABLE_LAYERS, true);
        $crossSurfaceGap = $present !== [] && $missing !== [];

        if ($alreadyShared) {
            $verdict = self::VERDICT_ALREADY_GOVERNED;
        } elseif ($crossSurfaceGap) {
            $verdict = self::VERDICT_PROMOTE;
        } else {
            $verdict = self::VERDICT_NO_ACTION;
        }

        $copyForbidden = $verdict === self::VERDICT_PROMOTE;

        // Promotion target must be a shared layer; if the caller proposed a
        // surface (or nothing) for a promote verdict, the target is invalid and
        // the audit reports the rule violation rather than blessing a copy.
        $proposedTargetValid = $targetLayer !== null && in_array($targetLayer, self::PROMOTABLE_LAYERS, true);
        $promotionTarget = $verdict === self::VERDICT_PROMOTE
            ? ($proposedTargetValid ? $targetLayer : null)
            : null;

        // Enforcement is mandatory for a promotion (doc: "enforce it with a
        // registry, contract or architecture validation check").
        $requiresEnforcementCheck = $verdict === self::VERDICT_PROMOTE;
        $enforcementOptions = $requiresEnforcementCheck
            ? ['registry', 'contract', 'architecture_validation_check']
            : [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $verdict,
            'copy_forbidden' => $copyForbidden,
            'cross_surface_gap' => $crossSurfaceGap,
            'already_shared' => $alreadyShared,
            'present_surfaces' => $present,
            'missing_surfaces' => $missing,
            'owning_layer' => $owningLayer,
            'promotion_target' => $promotionTarget,
            'promotion_target_valid' => $verdict === self::VERDICT_PROMOTE ? $proposedTargetValid : true,
            'requires_enforcement_check' => $requiresEnforcementCheck,
            'enforcement_options' => $enforcementOptions,
        ];
    }

    /**
     * Composite audit receipt for the index doc. Runs the four documented
     * deciders over one observed architecture sample and returns a single
     * receipt callers can persist. Safe defaults model the canonical violation
     * the audit exists to catch: a surface that owns a business flow which is
     * duplicated across surfaces and not yet promoted.
     *
     * @param  array<string,mixed>  $sample
     * @return array<string,mixed>
     */
    public function audit(array $sample = []): array
    {
        /** @var array<string,mixed> $surfaceSignals */
        $surfaceSignals = is_array($sample['surface'] ?? null) ? $sample['surface'] : [
            'owns_business_flow' => true,
            'collects_input' => true,
            'calls_pipeline' => false,
        ];

        /** @var array<string,mixed> $gap */
        $gap = is_array($sample['feature_gap'] ?? null) ? $sample['feature_gap'] : [
            'present_surfaces' => ['cli'],
            'missing_surfaces' => ['api', 'mcp'],
            'owning_layer' => 'surface',
            'target_layer' => 'core',
        ];

        /** @var list<string> $declaredLayers */
        $declaredLayers = is_array($sample['declared_layers'] ?? null)
            ? array_map('strval', $sample['declared_layers'])
            : array_keys(self::LAYER_DIRECTION);

        $diagnosis = $this->coreDiagnosis();
        $surface = $this->auditSurfaceOwnership($surfaceSignals);
        $feature = $this->decideFeatureGap($gap);
        $layers = $this->auditLayers($declaredLayers);

        $clean = $surface['verdict'] === self::SURFACE_PASS
            && $feature['verdict'] !== self::VERDICT_PROMOTE
            && $layers['status'] === 'ok';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $clean ? 'clean' : 'action_required',
            'core_diagnosis' => $diagnosis,
            'surface_ownership' => $surface,
            'feature_gap' => $feature,
            'layers' => $layers,
        ];
    }

    /**
     * Normalize a free-text key to snake_case ascii (lower, non-alnum -> _).
     */
    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }

    /**
     * Normalize a list of tokens: trim, lowercase, drop blanks, dedupe, reindex.
     *
     * @param  mixed  $list
     * @return list<string>
     */
    private function normalizeList(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $item) {
            $key = $this->normalizeKey((string) $item);
            if ($key !== '') {
                $out[$key] = true;
            }
        }

        return array_keys($out);
    }
}
