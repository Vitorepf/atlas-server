<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Forge Operating System Contracts — pure, deterministic conformance checker for
 * the umbrella Forge OS contract catalog.
 *
 * The doc is the authoring boundary for the persistent objects and invariants the
 * Forge must satisfy BEFORE any AP or runtime is treated as ready. This service
 * turns that catalog into runtime: it checks a candidate Forge object set against
 * the documented required fields, the packet state machine, the release gate and
 * the "Regras para IA". It is read-only — it classifies and emits blocking
 * reasons; it never mutates state, approves execution or expands scope.
 *
 * Documented contracts this code enforces (one method per contract surface):
 *   - Contract 1 (Mother Spec): the 10 documented fields are all required.
 *       "Nenhum trabalho Forge inicia sem spec-mae legivel por humano, IA e runtime."
 *   - Contract 2 (Work Packet): the documented packet fields are required, and in
 *       particular allowed_files, forbidden_files and evidence must be present.
 *       "Packet sem escopo ou evidence nao entra em execucao."
 *   - Contract 4 (Packet State Machine): 18 canonical states; a transition is
 *       valid only when it appears in the documented adjacency.
 *       "Estado muda apenas por evento registrado." A transition with no recorded
 *       event is rejected.
 *   - Contract 11 (Release Gate): the 12 documented requirements must all hold.
 *       "Release sem evidence e impossivel."
 *   - Regras para IA: the 5 hard invariants (allowed+forbidden+evidence on every
 *       packet; provider holds no policy authority; permission/sandbox gate is
 *       never skipped; release needs artifacts + provenance; `future` is never
 *       treated as implemented).
 *
 * Non-goals honoured: it does not run providers, does not write evidence, does
 * not decide code correctness and does not relax any gate.
 *
 * @see docs/engineering-knowledge-base/atlas-forge-operating-system-contracts.md
 */
final class ForgeOperatingSystemContractsService
{
    /** Stable evidence schema id this checker emits. */
    public const SCHEMA = 'atlas.forge.operating_system_contracts.v1';

    /** Conformance verdicts (closed set). */
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    /**
     * Contract 1 — Mother Spec required fields (the documented bullet list).
     *
     * @var list<string>
     */
    public const MOTHER_SPEC_FIELDS = [
        'objetivo_maior',
        'motivacao',
        'escopo',
        'fora_de_escopo',
        'arquitetura_afetada',
        'docs_canonicos',
        'code_intelligence_context',
        'riscos',
        'gates',
        'estrategia_de_divisao',
        'criterio_de_conclusao_global',
    ];

    /**
     * Contract 2 — Work Packet required fields (the documented "Cada packet
     * precisa ter" list, flattened to atomic keys).
     *
     * @var list<string>
     */
    public const PACKET_FIELDS = [
        'id',
        'titulo',
        'objetivo',
        'mother_spec_id',
        'owner',
        'allowed_files',
        'forbidden_files',
        'reserved_symbols',
        'dependencies',
        'inputs',
        'outputs',
        'validation_commands',
        'acceptance_criteria',
        'evidence',
        'risk',
        'rollback',
        'idempotency_key',
        'permission_profile',
        'sandbox_profile',
        'secret_policy',
        'artifact_outputs',
        'integration_notes',
    ];

    /**
     * Contract 4 — the 17 canonical packet states (closed set, in doc order).
     *
     * @var list<string>
     */
    public const PACKET_STATES = [
        'draft',
        'approved',
        'claimed',
        'reserved',
        'in_progress',
        'waiting_for_input',
        'waiting_for_tool_permission',
        'submitted',
        'under_review',
        'changes_requested',
        'verified',
        'queued_for_integration',
        'integrated',
        'released',
        'failed',
        'cancelled',
        'deferred',
    ];

    /**
     * Contract 4 — documented packet state machine adjacency. A transition is
     * valid only when `to` is in the list for `from`. The happy path follows the
     * doc order (draft -> approved -> claimed -> reserved -> in_progress -> ...);
     * `failed`, `cancelled` and `deferred` are reachable from any live state.
     *
     * @var array<string,list<string>>
     */
    private const TRANSITIONS = [
        'draft' => ['approved', 'cancelled', 'deferred'],
        'approved' => ['claimed', 'cancelled', 'deferred'],
        'claimed' => ['reserved', 'failed', 'cancelled', 'deferred'],
        'reserved' => ['in_progress', 'failed', 'cancelled', 'deferred'],
        'in_progress' => ['waiting_for_input', 'waiting_for_tool_permission', 'submitted', 'failed', 'cancelled', 'deferred'],
        'waiting_for_input' => ['in_progress', 'failed', 'cancelled', 'deferred'],
        'waiting_for_tool_permission' => ['in_progress', 'failed', 'cancelled', 'deferred'],
        'submitted' => ['under_review', 'failed', 'cancelled', 'deferred'],
        'under_review' => ['changes_requested', 'verified', 'failed', 'cancelled', 'deferred'],
        'changes_requested' => ['in_progress', 'failed', 'cancelled', 'deferred'],
        'verified' => ['queued_for_integration', 'failed', 'cancelled', 'deferred'],
        'queued_for_integration' => ['integrated', 'failed', 'cancelled', 'deferred'],
        'integrated' => ['released', 'failed', 'cancelled', 'deferred'],
        // Terminal states: no onward transitions.
        'released' => [],
        'failed' => [],
        'cancelled' => [],
        'deferred' => [],
    ];

    /**
     * Contract 11 — the 12 documented release gate requirements (atomic keys).
     * Every one must be satisfied (true) before a release is conformant.
     *
     * @var list<string>
     */
    public const RELEASE_REQUIREMENTS = [
        'mother_spec_complete',
        'packets_closed_or_deferred',
        'conflicts_resolved',
        'diffs_integrated',
        'tests_gates_green',
        'docs_updated',
        'code_intelligence_updated',
        'cartography_published_when_affected',
        'evidence_ledger_complete',
        'learning_recorded',
        'rollback_known',
        'residual_risk_accepted',
    ];

    /**
     * Validate a Mother Spec against Contract 1.
     *
     * @param array<string,mixed> $motherSpec
     * @return array<string,mixed>
     */
    public function checkMotherSpec(array $motherSpec): array
    {
        $missing = $this->missingOrEmpty($motherSpec, self::MOTHER_SPEC_FIELDS);
        $status = $missing === [] ? self::STATUS_PASS : self::STATUS_FAIL;

        $reasons = [];
        if ($missing !== []) {
            // "Nenhum trabalho Forge inicia sem spec-mae legivel por humano, IA e runtime."
            $reasons[] = 'Mother spec missing required fields: ' . implode(', ', $missing);
        }

        return [
            'contract' => 'mother_spec',
            'status' => $status,
            'missing_fields' => $missing,
            'work_may_start' => $status === self::STATUS_PASS,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Validate a Work Packet against Contract 2 + the first "Regra para IA"
     * (allowed_files, forbidden_files and evidence are mandatory).
     *
     * @param array<string,mixed> $packet
     * @return array<string,mixed>
     */
    public function checkWorkPacket(array $packet): array
    {
        // Structural fields must be DECLARED (the key must exist and be non-null).
        // A list field declared empty — e.g. dependencies: [] meaning "no
        // dependencies" — is a valid declaration, so emptiness alone is not a
        // miss here. The doc-forbidden case (empty scope/evidence) is enforced
        // separately by the triad below.
        $missing = $this->missingKeys($packet, self::PACKET_FIELDS);

        // The scope/evidence triad is called out explicitly by the doc and the
        // "Regras para IA"; it must be present AND non-empty ("Packet sem escopo
        // ou evidence nao entra em execucao."). Surface it as its own flag.
        $hasAllowed = $this->present($packet, 'allowed_files');
        $hasForbidden = $this->present($packet, 'forbidden_files');
        $hasEvidence = $this->present($packet, 'evidence');
        $scopeAndEvidenceOk = $hasAllowed && $hasForbidden && $hasEvidence;

        $reasons = [];
        if ($missing !== []) {
            $reasons[] = 'Packet missing required fields: ' . implode(', ', $missing);
        }
        if (! $scopeAndEvidenceOk) {
            // "Packet sem escopo ou evidence nao entra em execucao."
            $reasons[] = 'Packet has no scope/evidence triad (allowed_files + forbidden_files + evidence); it cannot enter execution.';
        }

        $status = $reasons === [] ? self::STATUS_PASS : self::STATUS_FAIL;

        return [
            'contract' => 'work_packet',
            'status' => $status,
            'missing_fields' => $missing,
            'scope_and_evidence_present' => $scopeAndEvidenceOk,
            'may_enter_execution' => $status === self::STATUS_PASS,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Validate a packet state transition against Contract 4.
     *
     * "Estado muda apenas por evento registrado." => an event id is required and
     * the (from -> to) edge must be in the documented adjacency.
     *
     * @return array<string,mixed>
     */
    public function checkStateTransition(string $from, string $to, ?string $eventId = null): array
    {
        $from = strtolower(trim($from));
        $to = strtolower(trim($to));
        $reasons = [];

        $fromKnown = in_array($from, self::PACKET_STATES, true);
        $toKnown = in_array($to, self::PACKET_STATES, true);

        if (! $fromKnown) {
            $reasons[] = "Unknown source state '{$from}'.";
        }
        if (! $toKnown) {
            $reasons[] = "Unknown target state '{$to}'.";
        }

        // "Estado muda apenas por evento registrado." — no event, no transition.
        $hasEvent = is_string($eventId) && trim($eventId) !== '';
        if (! $hasEvent) {
            $reasons[] = 'Transition has no recorded event id; state changes only by recorded event.';
        }

        $edgeAllowed = false;
        if ($fromKnown && $toKnown) {
            $edgeAllowed = in_array($to, self::TRANSITIONS[$from] ?? [], true);
            if (! $edgeAllowed) {
                $reasons[] = "Transition '{$from}' -> '{$to}' is not in the documented packet state machine.";
            }
        }

        $status = $reasons === [] ? self::STATUS_PASS : self::STATUS_FAIL;

        return [
            'contract' => 'packet_state_machine',
            'status' => $status,
            'from' => $from,
            'to' => $to,
            'event_id' => $hasEvent ? trim((string) $eventId) : null,
            'edge_allowed' => $edgeAllowed,
            'terminal_source' => $fromKnown && (self::TRANSITIONS[$from] ?? []) === [],
            'transition_permitted' => $status === self::STATUS_PASS,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Validate a release against Contract 11 + the release-related "Regras para
     * IA" (artifacts + provenance required; no red gate may be ignored).
     *
     * @param array<string,mixed> $release  the 12 requirement booleans + optional
     *                                       artifacts/provenance flags
     * @return array<string,mixed>
     */
    public function checkReleaseGate(array $release): array
    {
        $unmet = [];
        foreach (self::RELEASE_REQUIREMENTS as $req) {
            if (($release[$req] ?? false) !== true) {
                $unmet[] = $req;
            }
        }

        $reasons = [];
        if ($unmet !== []) {
            $reasons[] = 'Release gate requirements not met: ' . implode(', ', $unmet);
        }

        // "Nao marcar release sem artifacts e provenance." (Regra para IA)
        $hasArtifacts = ($release['artifacts_present'] ?? false) === true;
        $hasProvenance = ($release['provenance_present'] ?? false) === true;
        if (! $hasArtifacts || ! $hasProvenance) {
            $reasons[] = 'Release lacks artifacts and/or provenance; release without provenance is impossible.';
        }

        $status = $reasons === [] ? self::STATUS_PASS : self::STATUS_FAIL;

        return [
            'contract' => 'release_gate',
            'status' => $status,
            'unmet_requirements' => $unmet,
            'artifacts_present' => $hasArtifacts,
            'provenance_present' => $hasProvenance,
            'release_permitted' => $status === self::STATUS_PASS,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Enforce the five "Regras para IA" against a candidate Forge action context.
     * Each rule maps to one boolean violation check; any violation blocks.
     *
     * @param array<string,mixed> $ctx
     *        packet                 : array  (checked for scope/evidence triad)
     *        provider_has_policy_authority : bool  (rule 2 — must be false)
     *        permission_gate_skipped       : bool  (rule 3 — must be false)
     *        release                       : array (rule 4 — artifacts+provenance)
     *        treats_future_as_implemented  : bool  (rule 5 — must be false)
     * @return array<string,mixed>
     */
    public function checkAiRules(array $ctx): array
    {
        $violations = [];

        // Rule 1: "Nao criar packet sem allowed files, forbidden files e evidence."
        $packet = is_array($ctx['packet'] ?? null) ? $ctx['packet'] : [];
        if (! ($this->present($packet, 'allowed_files')
            && $this->present($packet, 'forbidden_files')
            && $this->present($packet, 'evidence'))) {
            $violations[] = 'packet_missing_scope_or_evidence';
        }

        // Rule 2: "Nao dar autoridade de politica a provider, tool ou surface."
        if (($ctx['provider_has_policy_authority'] ?? false) === true) {
            $violations[] = 'provider_granted_policy_authority';
        }

        // Rule 3: "Nao pular permission/sandbox gate por conveniencia."
        if (($ctx['permission_gate_skipped'] ?? false) === true) {
            $violations[] = 'permission_or_sandbox_gate_skipped';
        }

        // Rule 4: "Nao marcar release sem artifacts e provenance."
        $release = is_array($ctx['release'] ?? null) ? $ctx['release'] : [];
        if (($release['artifacts_present'] ?? false) !== true
            || ($release['provenance_present'] ?? false) !== true) {
            $violations[] = 'release_without_artifacts_or_provenance';
        }

        // Rule 5: "Nao tratar `future` como implementado."
        if (($ctx['treats_future_as_implemented'] ?? false) === true) {
            $violations[] = 'future_treated_as_implemented';
        }

        $status = $violations === [] ? self::STATUS_PASS : self::STATUS_FAIL;

        return [
            'contract' => 'ai_rules',
            'status' => $status,
            'violations' => $violations,
            'compliant' => $status === self::STATUS_PASS,
        ];
    }

    /**
     * Whole-catalog conformance: runs the contract surfaces that the candidate
     * bundle provides and aggregates a single verdict. A bundle is conformant
     * only when every supplied surface passes (no surface may be quietly skipped
     * to "go green").
     *
     * @param array<string,mixed> $bundle
     *        mother_spec      : array (optional)
     *        work_packet      : array (optional)
     *        state_transition : array{from,to,event_id} (optional)
     *        release          : array (optional)
     *        ai_rules_context : array (optional)
     * @return array<string,mixed>
     */
    public function checkBundle(array $bundle): array
    {
        $surfaces = [];

        if (is_array($bundle['mother_spec'] ?? null)) {
            $surfaces['mother_spec'] = $this->checkMotherSpec($bundle['mother_spec']);
        }
        if (is_array($bundle['work_packet'] ?? null)) {
            $surfaces['work_packet'] = $this->checkWorkPacket($bundle['work_packet']);
        }
        if (is_array($bundle['state_transition'] ?? null)) {
            $t = $bundle['state_transition'];
            $surfaces['packet_state_machine'] = $this->checkStateTransition(
                is_string($t['from'] ?? null) ? $t['from'] : '',
                is_string($t['to'] ?? null) ? $t['to'] : '',
                is_string($t['event_id'] ?? null) ? $t['event_id'] : null,
            );
        }
        if (is_array($bundle['release'] ?? null)) {
            $surfaces['release_gate'] = $this->checkReleaseGate($bundle['release']);
        }
        if (is_array($bundle['ai_rules_context'] ?? null)) {
            $surfaces['ai_rules'] = $this->checkAiRules($bundle['ai_rules_context']);
        }

        $failed = [];
        foreach ($surfaces as $name => $result) {
            if (($result['status'] ?? self::STATUS_FAIL) !== self::STATUS_PASS) {
                $failed[] = $name;
            }
        }

        $status = ($surfaces !== [] && $failed === []) ? self::STATUS_PASS : self::STATUS_FAIL;

        return [
            'schema' => self::SCHEMA,
            'status' => $status,
            'surfaces_checked' => array_keys($surfaces),
            'failed_surfaces' => $failed,
            'surfaces' => $surfaces,
            'forge_ready' => $status === self::STATUS_PASS,
        ];
    }

    /**
     * A field is satisfied when present AND non-empty (a documented packet field
     * that is null / "" / [] is treated as missing, since an empty scope or empty
     * evidence is exactly what the doc forbids).
     *
     * @param array<string,mixed> $bag
     * @param list<string> $fields
     * @return list<string>
     */
    private function missingOrEmpty(array $bag, array $fields): array
    {
        $missing = [];
        foreach ($fields as $field) {
            if (! $this->present($bag, $field)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * A structural field is satisfied when its key is DECLARED (present and
     * non-null). An empty list is an acceptable declaration (e.g. "no
     * dependencies"); only an absent or null key counts as missing.
     *
     * @param array<string,mixed> $bag
     * @param list<string> $fields
     * @return list<string>
     */
    private function missingKeys(array $bag, array $fields): array
    {
        $missing = [];
        foreach ($fields as $field) {
            if (! array_key_exists($field, $bag) || $bag[$field] === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param array<string,mixed> $bag
     */
    private function present(array $bag, string $field): bool
    {
        if (! array_key_exists($field, $bag)) {
            return false;
        }

        $value = $bag[$field];

        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }
}
