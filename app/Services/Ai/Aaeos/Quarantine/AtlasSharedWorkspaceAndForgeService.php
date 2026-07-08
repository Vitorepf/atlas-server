<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Obras — Shared Workspace And Forge: pure, deterministic conformance
 * checker for the canonical contract of the Obras Shared Workspace (the office
 * where Forge and multiple providers collaborate through shared context,
 * artifacts, evidence and integration).
 *
 * The doc is the authoring boundary. This service turns its concrete contract
 * surfaces into runtime. Each method enforces ONE documented rule set and
 * returns a typed, blocking verdict. It is read-only: it classifies and lists
 * blocking reasons; it never runs providers, mutates workspace state, sends
 * context, validates a real signature or writes evidence.
 *
 * Documented contract surfaces this code enforces (one method per surface):
 *   - Canonical Name + boundary: the canonical component name is "Obras Shared
 *       Workspace"; Forge Workspace is its Programming specialization. Competing
 *       names ("provider office", "AI room", "Forge memory", "multi-agent
 *       project space") are forbidden as architecture. -> validateCanonicalName()
 *   - Governed-work link gate: a chat, terminal, SDD pane or provider session is
 *       NOT an Obra by itself; it becomes governed production work only when
 *       linked to obra_id, node/context, artifact/output and evidence.
 *       -> classifyGovernedWork()
 *   - Minimum Workspace Contract for heavy programming: the 10 points an Obra
 *       must have before using multiple providers; without all of them,
 *       multi-provider programming is only ad hoc chaining.
 *       -> auditMinimumWorkspaceContract()
 *   - Token and Context Law: never send the same large context to every provider
 *       by default; slice a provider-specific context pack per role.
 *       -> sliceContextForRole(), auditTokenLaw()
 *   - Artifact Bus: every artifact must carry id, source, timestamp, owning
 *       provider/session, input hash, output hash and status, and is not trusted
 *       Obra state until it validates against schema + evidence policy.
 *       -> validateArtifact()
 *
 * Non-goals honoured: it does not perform the heavy programming flow (owned by
 * atlas-programming-forge-flow.md), does not replace Kernel/Forge/Providers/
 * Evidence Ledger, and treats Markdown as an export, never as the source of
 * truth.
 *
 * @see docs/engineering-knowledge-base/obras/shared-workspace-and-forge.md
 */
final class AtlasSharedWorkspaceAndForgeService
{
    /** Stable evidence schema id this checker emits. */
    public const SCHEMA = 'atlas.obras.shared_workspace_and_forge.v1';

    /** Conformance verdicts (closed set). */
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    /** The one canonical component name (doc "Canonical Name"). */
    public const CANONICAL_NAME = 'Obras Shared Workspace';

    /** The Portuguese product label (doc "Canonical Name"). */
    public const CANONICAL_LABEL_PT = 'Workspace Compartilhado de Obras';

    /** The programming specialization name (doc "Canonical Name"). */
    public const FORGE_WORKSPACE_NAME = 'Forge Workspace';

    /**
     * Competing names the doc forbids: "Do not create competing names such as
     * ... Those may be metaphors, not architecture." Compared case-insensitively.
     *
     * @var list<string>
     */
    public const FORBIDDEN_NAMES = [
        'provider office',
        'ai room',
        'forge memory',
        'multi-agent project space',
    ];

    /**
     * Governed-work link requirements: a pane "becomes governed production work
     * only when linked to obra_id, node/context, artifact/output and evidence."
     * Maps each required link to its documented justification.
     *
     * @var array<string, string>
     */
    public const GOVERNED_WORK_LINKS = [
        'obra_id' => 'Work must be linked to an obra_id.',
        'node_context' => 'Work must be linked to a node / context.',
        'artifact_output' => 'Work must produce a linked artifact / output.',
        'evidence' => 'Work must be linked to evidence.',
    ];

    /**
     * Minimum Workspace Contract for heavy programming — the 10 documented points
     * (doc "Minimum Workspace Contract For Heavy Programming"), in order. All must
     * be present; otherwise multi-provider programming is only ad hoc chaining.
     *
     * @var array<string, string>
     */
    public const MINIMUM_WORKSPACE_CONTRACT = [
        'objective_and_definition_of_done' => 'objective and definition of done',
        'canonical_mother_contract' => 'canonical mother contract',
        'work_split_with_disjoint_scopes' => 'work split with disjoint scopes',
        'allowed_and_forbidden_files_per_packet' => 'allowed and forbidden files per packet',
        'dependency_and_collision_map' => 'dependency and collision map',
        'provider_role_per_packet' => 'provider role per packet',
        'required_gates_and_commands' => 'required gates and commands',
        'integration_queue' => 'integration queue',
        'evidence_normalization_contract' => 'evidence normalization contract',
        'rollback_repair_policy' => 'rollback/repair policy',
    ];

    /**
     * Token and Context Law — the documented per-role context each provider role
     * should receive (doc "Token And Context Law" table). The whole canonical raw
     * context must NOT be the default slice for every role.
     *
     * @var array<string, list<string>>
     */
    public const ROLE_CONTEXT = [
        'gemini_scout' => [
            'broad_repo_source_map',
            'alternatives',
            'long_context_synthesis',
        ],
        'claude_planner_reviewer' => [
            'architecture',
            'risks',
            'specs',
            'tradeoffs',
            'acceptance_criteria',
        ],
        'codex_implementer' => [
            'allowed_files',
            'task',
            'tests',
            'gates',
            'local_commands',
            'evidence_contract',
        ],
        'local_agent' => [
            'deterministic_command',
            'expected_output',
            'parser_rule',
        ],
    ];

    /**
     * Artifact Bus — the 8 fields every artifact "should have" (doc "Artifact
     * Bus"): id, source, timestamp, owning provider/session, input hash, output
     * hash and status. (provider/session is one owning field.)
     *
     * @var list<string>
     */
    public const ARTIFACT_FIELDS = [
        'id',
        'source',
        'timestamp',
        'owning_provider_session',
        'input_hash',
        'output_hash',
        'status',
    ];

    /**
     * The closed set of artifact kinds the bus exchanges (doc "Artifact Bus").
     * "Models must exchange artifacts, not vague conversation."
     *
     * @var list<string>
     */
    public const ARTIFACT_KINDS = [
        'research_brief',
        'codebase_map',
        'mother_contract',
        'spec',
        'plan',
        'task_packet',
        'implementation_diff',
        'test_output',
        'review_findings',
        'repair_request',
        'evidence_report',
        'integration_note',
    ];

    /**
     * Canonical Name guard. Returns pass only when the candidate component name is
     * exactly the canonical name (or the documented Forge Workspace
     * specialization, when declared as such), and never a forbidden competing
     * name.
     *
     * @param string $name             candidate component name describing multi-provider collaboration
     * @param bool   $declaredAsForgeSpecialization whether the caller explicitly states it is a specialization of Obras Shared Workspace
     * @return array{schema:string,status:string,canonical_name:string,is_canonical:bool,is_forbidden:bool,blocking_reasons:list<string>}
     */
    public function validateCanonicalName(string $name, bool $declaredAsForgeSpecialization = false): array
    {
        $normalized = strtolower(trim($name));
        $reasons = [];

        $isForbidden = in_array($normalized, self::FORBIDDEN_NAMES, true);
        if ($isForbidden) {
            $reasons[] = 'forbidden_competing_name: "' . trim($name)
                . '" is a metaphor, not architecture; use "' . self::CANONICAL_NAME . '".';
        }

        $isCanonical = $normalized === strtolower(self::CANONICAL_NAME);

        // Forge Workspace is allowed ONLY when declared as a specialization of the
        // canonical Obras Shared Workspace (doc Non-Negotiable Rule).
        $isAllowedForge = $normalized === strtolower(self::FORGE_WORKSPACE_NAME)
            && $declaredAsForgeSpecialization;
        if ($normalized === strtolower(self::FORGE_WORKSPACE_NAME) && ! $declaredAsForgeSpecialization) {
            $reasons[] = 'specialization_not_declared: "' . self::FORGE_WORKSPACE_NAME
                . '" must explicitly state it is a specialization of "' . self::CANONICAL_NAME . '".';
        }

        if (! $isCanonical && ! $isAllowedForge && ! $isForbidden) {
            $reasons[] = 'non_canonical_name: multi-provider collaboration must use "'
                . self::CANONICAL_NAME . '" or explicitly state it is a specialization of it.';
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $reasons === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'canonical_name' => self::CANONICAL_NAME,
            'is_canonical' => $isCanonical,
            'is_forbidden' => $isForbidden,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Governed-work link gate. A chat / terminal / SDD pane / provider session is
     * NOT an Obra by itself; it is governed production work only when all four
     * links are present.
     *
     * @param array<string, bool> $links caller assertions per GOVERNED_WORK_LINKS key (absent = false)
     * @return array{schema:string,status:string,is_governed_work:bool,missing_links:list<string>,blocking_reasons:list<string>}
     */
    public function classifyGovernedWork(array $links): array
    {
        $missing = [];
        $reasons = [];

        foreach (self::GOVERNED_WORK_LINKS as $key => $why) {
            if (($links[$key] ?? false) !== true) {
                $missing[] = $key;
                $reasons[] = 'unlinked_pane: ' . $why;
            }
        }

        $isGoverned = $missing === [];

        return [
            'schema' => self::SCHEMA,
            'status' => $isGoverned ? self::STATUS_PASS : self::STATUS_FAIL,
            'is_governed_work' => $isGoverned,
            'missing_links' => $missing,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Minimum Workspace Contract audit. The Obra must satisfy all 10 documented
     * points before multiple providers may be used for heavy programming.
     *
     * @param array<string, bool> $contract caller assertions per MINIMUM_WORKSPACE_CONTRACT key (absent = false)
     * @return array{schema:string,status:string,ready_for_multi_provider:bool,present_count:int,required_count:int,missing:list<string>,blocking_reasons:list<string>}
     */
    public function auditMinimumWorkspaceContract(array $contract): array
    {
        $missing = [];
        $reasons = [];
        $present = 0;

        foreach (self::MINIMUM_WORKSPACE_CONTRACT as $key => $label) {
            if (($contract[$key] ?? false) === true) {
                $present++;
                continue;
            }
            $missing[] = $key;
            $reasons[] = 'minimum_contract_missing: ' . $label;
        }

        $ready = $missing === [];
        if (! $ready) {
            $reasons[] = 'ad_hoc_chaining: without the full minimum contract, multi-provider programming is only ad hoc chaining.';
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $ready ? self::STATUS_PASS : self::STATUS_FAIL,
            'ready_for_multi_provider' => $ready,
            'present_count' => $present,
            'required_count' => count(self::MINIMUM_WORKSPACE_CONTRACT),
            'missing' => $missing,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Token and Context Law — slice the provider-specific context pack for a role.
     * Returns the curated slice (the documented fields for that role), never the
     * whole canonical raw context.
     *
     * @param string $role one of ROLE_CONTEXT keys
     * @return array{schema:string,status:string,role:string,context_slice:list<string>,is_known_role:bool,blocking_reasons:list<string>}
     */
    public function sliceContextForRole(string $role): array
    {
        $known = array_key_exists($role, self::ROLE_CONTEXT);
        $reasons = [];
        if (! $known) {
            $reasons[] = 'unknown_provider_role: "' . $role
                . '" has no documented context slice; define its role pack before routing.';
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $known ? self::STATUS_PASS : self::STATUS_FAIL,
            'role' => $role,
            'context_slice' => $known ? self::ROLE_CONTEXT[$role] : [],
            'is_known_role' => $known,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Token and Context Law guard. Fails a routing plan that violates "never send
     * the same large context to every provider by default" — i.e. that hands the
     * whole canonical raw context to more than one role, instead of curated
     * per-role slices.
     *
     * @param array<string, bool> $rolesGetWholeRawContext map of role => true if it receives the whole canonical raw context
     * @return array{schema:string,status:string,whole_context_roles:list<string>,blocking_reasons:list<string>}
     */
    public function auditTokenLaw(array $rolesGetWholeRawContext): array
    {
        $wholeContextRoles = [];
        foreach ($rolesGetWholeRawContext as $role => $getsWhole) {
            if ($getsWhole === true) {
                $wholeContextRoles[] = $role;
            }
        }

        $reasons = [];
        // Documented default: large context must be sliced. More than one role on
        // the whole raw context is the broadcast pattern the doc forbids.
        if (count($wholeContextRoles) > 1) {
            $reasons[] = 'broadcast_context_law_violation: the same large context is sent to '
                . count($wholeContextRoles) . ' providers; slice a provider-specific context pack per role.';
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $reasons === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'whole_context_roles' => $wholeContextRoles,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Artifact Bus validation. An artifact is trusted Obra state only when it
     * carries every documented field AND validates against schema + evidence
     * policy. Streaming partial updates do not count as trusted state.
     *
     * @param array<string, mixed> $artifact candidate artifact
     * @param bool                 $schemaValidated whether the artifact validated against its schema
     * @param bool                 $evidencePolicyMet whether the evidence policy is satisfied
     * @return array{schema:string,status:string,is_trusted_state:bool,missing_fields:list<string>,kind_known:bool,blocking_reasons:list<string>}
     */
    public function validateArtifact(array $artifact, bool $schemaValidated = false, bool $evidencePolicyMet = false): array
    {
        $missing = [];
        $reasons = [];

        foreach (self::ARTIFACT_FIELDS as $field) {
            $value = $artifact[$field] ?? null;
            if ($value === null || $value === '') {
                $missing[] = $field;
                $reasons[] = 'artifact_missing_field: ' . $field;
            }
        }

        $kind = (string) ($artifact['kind'] ?? '');
        $kindKnown = $kind !== '' && in_array($kind, self::ARTIFACT_KINDS, true);
        if ($kind === '') {
            $reasons[] = 'artifact_missing_field: kind';
        } elseif (! $kindKnown) {
            $reasons[] = 'artifact_unknown_kind: "' . $kind . '" is not an Artifact Bus kind.';
        }

        if (! $schemaValidated) {
            $reasons[] = 'artifact_not_trusted: not updated as trusted Obra state until it validates against its schema.';
        }
        if (! $evidencePolicyMet) {
            $reasons[] = 'artifact_not_trusted: not updated as trusted Obra state until it satisfies the evidence policy.';
        }

        $isTrusted = $missing === [] && $kindKnown && $schemaValidated && $evidencePolicyMet;

        return [
            'schema' => self::SCHEMA,
            'status' => $isTrusted ? self::STATUS_PASS : self::STATUS_FAIL,
            'is_trusted_state' => $isTrusted,
            'missing_fields' => $missing,
            'kind_known' => $kindKnown,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Whole-contract audit. Runs every surface over a candidate workspace bundle
     * and folds the verdicts into one pass|fail evidence document. Read-only.
     *
     * @param array{
     *     name?: string,
     *     declared_as_forge_specialization?: bool,
     *     governed_links?: array<string, bool>,
     *     minimum_contract?: array<string, bool>,
     *     whole_context_roles?: array<string, bool>,
     *     artifact?: array<string, mixed>,
     *     artifact_schema_validated?: bool,
     *     artifact_evidence_policy_met?: bool
     * } $bundle
     * @return array{schema:string,status:string,sections:array<string, array<string, mixed>>,blocking_reasons:list<string>}
     */
    public function audit(array $bundle): array
    {
        $sections = [
            'canonical_name' => $this->validateCanonicalName(
                (string) ($bundle['name'] ?? self::CANONICAL_NAME),
                (bool) ($bundle['declared_as_forge_specialization'] ?? false),
            ),
            'governed_work' => $this->classifyGovernedWork(
                $bundle['governed_links'] ?? [],
            ),
            'minimum_workspace_contract' => $this->auditMinimumWorkspaceContract(
                $bundle['minimum_contract'] ?? [],
            ),
            'token_law' => $this->auditTokenLaw(
                $bundle['whole_context_roles'] ?? [],
            ),
            'artifact_bus' => $this->validateArtifact(
                $bundle['artifact'] ?? [],
                (bool) ($bundle['artifact_schema_validated'] ?? false),
                (bool) ($bundle['artifact_evidence_policy_met'] ?? false),
            ),
        ];

        $reasons = [];
        foreach ($sections as $name => $section) {
            if (($section['status'] ?? self::STATUS_FAIL) !== self::STATUS_PASS) {
                foreach ($section['blocking_reasons'] as $reason) {
                    $reasons[] = $name . ': ' . $reason;
                }
            }
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $reasons === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'sections' => $sections,
            'blocking_reasons' => $reasons,
        ];
    }
}
