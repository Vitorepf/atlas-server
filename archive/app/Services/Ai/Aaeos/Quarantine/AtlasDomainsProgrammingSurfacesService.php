<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas AI Programming Surfaces — surface-to-flow contract resolver.
 *
 * This service turns the doc's surface→flow contract table into a deterministic
 * decision: given a Programming surface name, emit its canonical flow contract
 * and the documented invariants, and answer whether a concrete invocation of
 * that surface is admissible under those invariants.
 *
 * The doc's load-bearing rules this code enforces (each is tested):
 *   - Each surface is a THIN adapter into a `programming.*` flow; "none becomes a
 *     parallel product" (frontmatter decision). Every resolved contract carries
 *     domain_id=programming and a programming.* flow; never a parallel domain.
 *   - `Atlas Code` / `Atlas Code SCOR-1` binds `programming.forge` ONLY, with
 *     routing_task=forge, programming_profile=forge, obra_id REQUIRED, Forge
 *     Workspace binding and evidence required. An Atlas Code invocation missing
 *     obra_id (or trying any non-forge flow) is INADMISSIBLE.
 *   - `atlas fix` is a thin alias of `atlas dev --repair` → flow programming.repair,
 *     runtime dev_repair_executor. The resolver records the alias target.
 *   - `atlas forge` → programming.forge, runtime engineering_harness, evidence
 *     required.
 *   - `atlas continue` is a resume contract that PRESERVES profile, intent, model
 *     and Open Brain (carries no fresh flow of its own).
 *   - `App/API/MCP` → domain_id=programming, flow_id=programming.* (generic).
 *   - "surfaces do not own model selection." Manual model overrides are audited
 *     through ModelSelectionContractFactory with authority=atlas_decide. Asking a
 *     surface contract to own a model is a boundary violation; the override is
 *     re-routed to atlas_decide with owns_model_selection=false on every surface.
 *
 * Pure / deterministic: no DB, no provider, no IO.
 *
 * @see docs/engineering-knowledge-base/domains/programming-surfaces.md
 */
final class AtlasDomainsProgrammingSurfacesService
{
    /** Stable evidence schema id this resolver emits. */
    public const SCHEMA = 'atlas.programming_surfaces.contract.v1';

    /** The only domain a Programming surface may bind (doc: no parallel product). */
    public const DOMAIN_ID = 'programming';

    /**
     * Authority that owns model selection — never the surface (doc: "surfaces do
     * not own model selection", audited via ModelSelectionContractFactory).
     */
    public const MODEL_SELECTION_AUTHORITY = 'atlas_decide';
    public const MODEL_SELECTION_OWNER = 'ModelSelectionContractFactory';

    /** Admissibility outcomes (closed set). */
    public const OUTCOME_ADMISSIBLE = 'admissible';
    public const OUTCOME_INADMISSIBLE = 'inadmissible';
    public const OUTCOME_UNKNOWN_SURFACE = 'unknown_surface';

    /** Documented violation ids. */
    public const VIOLATION_OBRA_REQUIRED = 'atlas_code_requires_obra_id';
    public const VIOLATION_FORGE_ONLY = 'atlas_code_forge_flow_only';
    public const VIOLATION_SURFACE_OWNS_MODEL = 'surface_must_not_own_model_selection';
    public const VIOLATION_PARALLEL_DOMAIN = 'surface_must_bind_programming_domain';

    /**
     * Canonical surface→flow contract table, transcribed verbatim from the doc's
     * top table. Each row is the thin-adapter contract for one surface.
     *
     * @var array<string,array<string,mixed>>
     */
    private const SURFACES = [
        'atlas_code' => [
            'surface' => 'atlas_code',
            'aliases' => ['Atlas Code', 'Atlas Code SCOR-1', 'atlas_code_scor_1'],
            'flow' => 'programming.forge',
            'forge_only' => true,
            'routing_task' => 'forge',
            'programming_profile' => 'forge',
            'requires_obra_id' => true,
            'workspace_binding' => 'forge_workspace',
            'evidence_required' => true,
            'is_alias_of' => null,
        ],
        'atlas_dev' => [
            'surface' => 'atlas_dev',
            'aliases' => ['atlas dev'],
            'flow' => 'programming.dev',
            'forge_only' => false,
            'routing_task' => null,
            'programming_profile' => null,
            'requires_obra_id' => false,
            'workspace_binding' => null,
            'evidence_required' => false,
            'is_alias_of' => null,
        ],
        'atlas_forge' => [
            'surface' => 'atlas_forge',
            'aliases' => ['atlas forge', 'atlas_cli_forge'],
            'flow' => 'programming.forge',
            'forge_only' => true,
            'routing_task' => null,
            'programming_profile' => null,
            'requires_obra_id' => false,
            'workspace_binding' => null,
            'runtime' => 'engineering_harness',
            'evidence_required' => true,
            'is_alias_of' => null,
        ],
        'atlas_fix' => [
            'surface' => 'atlas_fix',
            'aliases' => ['atlas fix'],
            'flow' => 'programming.repair',
            'forge_only' => false,
            'routing_task' => null,
            'programming_profile' => null,
            'requires_obra_id' => false,
            'workspace_binding' => null,
            'runtime' => 'dev_repair_executor',
            'evidence_required' => false,
            // Thin alias of `atlas dev --repair` (doc).
            'is_alias_of' => 'atlas dev --repair',
        ],
        'atlas_continue' => [
            'surface' => 'atlas_continue',
            'aliases' => ['atlas continue'],
            // Resume contract: preserves the prior flow, owns none of its own.
            'flow' => null,
            'forge_only' => false,
            'routing_task' => null,
            'programming_profile' => null,
            'requires_obra_id' => false,
            'workspace_binding' => null,
            'evidence_required' => false,
            'is_alias_of' => null,
            'is_resume' => true,
            'preserves' => ['programming_profile', 'programming_intent', 'model', 'open_brain'],
        ],
        'atlas_ai_chat_dev' => [
            'surface' => 'atlas_ai_chat_dev',
            'aliases' => ['atlas:ai:chat --dev'],
            // programming.* selected by mode/task with the chat contract.
            'flow' => 'programming.*',
            'forge_only' => false,
            'routing_task' => null,
            'programming_profile' => null,
            'requires_obra_id' => false,
            'workspace_binding' => null,
            'evidence_required' => false,
            'is_alias_of' => null,
        ],
        'app_api_mcp' => [
            'surface' => 'app_api_mcp',
            'aliases' => ['App', 'API', 'MCP', 'app', 'api', 'mcp'],
            'flow' => 'programming.*',
            'forge_only' => false,
            'routing_task' => null,
            'programming_profile' => null,
            'requires_obra_id' => false,
            'workspace_binding' => null,
            'evidence_required' => false,
            'is_alias_of' => null,
        ],
    ];

    /**
     * Resolve a surface name to its canonical flow contract.
     *
     * @return array{
     *     schema:string,
     *     known:bool,
     *     surface:string|null,
     *     domain_id:string,
     *     flow:string|null,
     *     forge_only:bool,
     *     routing_task:string|null,
     *     programming_profile:string|null,
     *     requires_obra_id:bool,
     *     evidence_required:bool,
     *     is_alias_of:string|null,
     *     is_resume:bool,
     *     owns_model_selection:false,
     *     model_selection_authority:string,
     *     parallel_product:false
     * }
     */
    public function resolve(string $surface): array
    {
        $key = $this->canonicalKey($surface);
        $row = $key === null ? null : self::SURFACES[$key];

        if ($row === null) {
            return [
                'schema' => self::SCHEMA,
                'known' => false,
                'surface' => null,
                'domain_id' => self::DOMAIN_ID,
                'flow' => null,
                'forge_only' => false,
                'routing_task' => null,
                'programming_profile' => null,
                'requires_obra_id' => false,
                'evidence_required' => false,
                'is_alias_of' => null,
                'is_resume' => false,
                'owns_model_selection' => false,
                'model_selection_authority' => self::MODEL_SELECTION_AUTHORITY,
                'parallel_product' => false,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'known' => true,
            'surface' => (string) $row['surface'],
            // Doc invariant: every surface binds the Programming domain only.
            'domain_id' => self::DOMAIN_ID,
            'flow' => $row['flow'] ?? null,
            'forge_only' => (bool) ($row['forge_only'] ?? false),
            'routing_task' => $row['routing_task'] ?? null,
            'programming_profile' => $row['programming_profile'] ?? null,
            'requires_obra_id' => (bool) ($row['requires_obra_id'] ?? false),
            'evidence_required' => (bool) ($row['evidence_required'] ?? false),
            'is_alias_of' => $row['is_alias_of'] ?? null,
            'is_resume' => (bool) ($row['is_resume'] ?? false),
            // Doc invariant: surfaces never own model selection.
            'owns_model_selection' => false,
            'model_selection_authority' => self::MODEL_SELECTION_AUTHORITY,
            // Doc decision: none of these surfaces becomes a parallel product.
            'parallel_product' => false,
        ];
    }

    /**
     * Decide whether a concrete invocation of a surface is admissible under the
     * documented invariants. Records every violation; admissible only when none.
     *
     * Accepted invocation keys:
     *   surface (string)        — required
     *   flow (string)           — the flow the caller wants to run on the surface
     *   obra_id (string|null)   — Obra binding for Atlas Code
     *   domain_id (string|null) — the domain the caller wants to bind
     *   owns_model_selection (bool) — true if the surface tries to own model choice
     *
     * @param array<string,mixed> $invocation
     * @return array{
     *     schema:string,
     *     outcome:string,
     *     surface:string|null,
     *     flow:string|null,
     *     contract:array<string,mixed>,
     *     violations:array<int,array{rule:string,detail:string}>,
     *     model_selection:array{owner:string,authority:string,owned_by_surface:false}
     * }
     */
    public function admit(array $invocation): array
    {
        $surfaceName = is_string($invocation['surface'] ?? null) ? (string) $invocation['surface'] : '';
        $contract = $this->resolve($surfaceName);

        $modelSelection = [
            'owner' => self::MODEL_SELECTION_OWNER,
            'authority' => self::MODEL_SELECTION_AUTHORITY,
            'owned_by_surface' => false,
        ];

        if (! $contract['known']) {
            return [
                'schema' => self::SCHEMA,
                'outcome' => self::OUTCOME_UNKNOWN_SURFACE,
                'surface' => null,
                'flow' => null,
                'contract' => $contract,
                'violations' => [],
                'model_selection' => $modelSelection,
            ];
        }

        $violations = [];

        // Invariant: surfaces never own model selection — re-route to atlas_decide.
        if (($invocation['owns_model_selection'] ?? false) === true) {
            $violations[] = [
                'rule' => self::VIOLATION_SURFACE_OWNS_MODEL,
                'detail' => 'Surface tried to own model selection. Model choice is '
                    . 'owned by ' . self::MODEL_SELECTION_OWNER . ' with authority='
                    . self::MODEL_SELECTION_AUTHORITY . '; the override is re-routed.',
            ];
        }

        // Invariant: a surface may only bind the Programming domain (no parallel).
        $wantedDomain = $invocation['domain_id'] ?? null;
        if (is_string($wantedDomain) && $wantedDomain !== '' && $wantedDomain !== self::DOMAIN_ID) {
            $violations[] = [
                'rule' => self::VIOLATION_PARALLEL_DOMAIN,
                'detail' => 'Surface must bind domain_id=' . self::DOMAIN_ID
                    . '; requested "' . $wantedDomain . '" would create a parallel product.',
            ];
        }

        // Atlas Code specific invariants: forge flow only + obra_id required.
        if ($contract['surface'] === 'atlas_code') {
            $wantedFlow = is_string($invocation['flow'] ?? null) ? (string) $invocation['flow'] : null;
            if ($wantedFlow !== null && $wantedFlow !== '' && $wantedFlow !== $contract['flow']) {
                $violations[] = [
                    'rule' => self::VIOLATION_FORGE_ONLY,
                    'detail' => 'Atlas Code binds ' . (string) $contract['flow']
                        . ' ONLY; flow "' . $wantedFlow . '" is not allowed on this surface.',
                ];
            }

            $obraId = $invocation['obra_id'] ?? null;
            if (! is_string($obraId) || trim($obraId) === '') {
                $violations[] = [
                    'rule' => self::VIOLATION_OBRA_REQUIRED,
                    'detail' => 'Atlas Code / Atlas Code SCOR-1 requires a valid obra_id '
                        . '(Forge Workspace binding). None was provided.',
                ];
            }
        }

        return [
            'schema' => self::SCHEMA,
            'outcome' => $violations === [] ? self::OUTCOME_ADMISSIBLE : self::OUTCOME_INADMISSIBLE,
            'surface' => $contract['surface'],
            'flow' => $contract['flow'],
            'contract' => $contract,
            'violations' => $violations,
            'model_selection' => $modelSelection,
        ];
    }

    /**
     * The full contract table, one resolved row per canonical surface, for audit.
     *
     * @return array<int,array<string,mixed>>
     */
    public function table(): array
    {
        $out = [];
        foreach (array_keys(self::SURFACES) as $key) {
            $out[] = $this->resolve($key);
        }

        return $out;
    }

    /**
     * Canonical surface keys (closed set).
     *
     * @return array<int,string>
     */
    public function surfaceKeys(): array
    {
        return array_keys(self::SURFACES);
    }

    /**
     * Map any documented spelling / alias of a surface to its canonical table key.
     */
    private function canonicalKey(string $surface): ?string
    {
        $needle = $this->fold($surface);
        if ($needle === '') {
            return null;
        }

        foreach (self::SURFACES as $key => $row) {
            if ($this->fold($key) === $needle) {
                return $key;
            }

            foreach ((array) ($row['aliases'] ?? []) as $alias) {
                if ($this->fold((string) $alias) === $needle) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Normalize a surface spelling for matching: lower-case, collapse spaces,
     * dashes and the ":" / "--" CLI separators to underscores.
     */
    private function fold(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[\s:\-]+/', '_', $value);
        $value = (string) preg_replace('/_+/', '_', $value);

        return trim($value, '_');
    }
}
