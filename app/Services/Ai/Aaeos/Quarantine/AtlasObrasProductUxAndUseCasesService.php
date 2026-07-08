<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Obras — Product UX And Use Cases: pure, deterministic guards for the
 * product-surface rules of the Obras workspace.
 *
 * The doc is the authoring boundary. This service turns its concrete UX
 * contracts into runtime. Each method enforces ONE documented surface and
 * returns a typed, blocking verdict. It is read-only: it classifies and lists
 * blocking reasons; it never mutates Obra state or relaxes a rule.
 *
 * Documented surfaces this code enforces (one method per surface):
 *   - Entity classification: the mandatory Note/Task/Project/Obra separation,
 *       each answering exactly one documented question. The doc decision
 *       "Obras must not be confused with notes, tasks or simple projects"
 *       means a captured item must resolve to exactly one entity, and an Obra
 *       is the only entity that builds a relevant artifact until it is ready.
 *       -> classifyEntity()
 *   - Obra card schema: the 13 fields every strategic production card "should
 *       show". A card that omits any of them is non-conformant.
 *       -> auditObraCard()
 *   - Inside-an-Obra workspace: the 12 numbered sections the mature workspace
 *       must expose, in documented order. -> auditWorkspaceSections()
 *   - Composer / AI-session rule: the doc decision "Every AI session inside
 *       Obras must produce or improve an artifact, not remain loose
 *       conversation" plus the Composer rule "AI works inside the Obra, with
 *       the Obra's sources, decisions, policies and context". A session that
 *       neither produces nor improves an artifact, or that runs without the
 *       Obra context, is rejected. -> validateAiSession()
 *   - Quick actions surface: the documented mapping "these actions should
 *       appear as contextual buttons and AI actions, not raw commands". A
 *       conceptual CLI action must be surfaced as a button, never as a raw
 *       command the operator must type. -> resolveQuickActionSurface()
 *
 * Non-goals honoured: it does not run providers, does not write evidence, does
 * not re-implement the contracts/invariants owned by
 * AtlasObrasContractsAndInvariantsService, and does not own the metrics owned
 * by AtlasObrasMetricsRisksAndExcellenceService.
 *
 * @see docs/engineering-knowledge-base/obras/product-ux-and-use-cases.md
 */
final class AtlasObrasProductUxAndUseCasesService
{
    /** Stable evidence schema id this guard emits. */
    public const SCHEMA = 'atlas.obras.product_ux_and_use_cases.v1';

    /** Conformance verdicts (closed set). */
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    /**
     * The mandatory entity separation. Each entity answers exactly one
     * documented question; only an Obra builds a relevant artifact until ready.
     *
     * @var array<string, string>
     */
    public const ENTITY_QUESTIONS = [
        'note' => 'What did I capture, think or learn?',
        'task' => 'What needs to be done?',
        'project' => 'Which set of actions must be executed?',
        'obra' => 'Which relevant artifact am I building until it is ready?',
    ];

    /**
     * The 13 fields every Obra card "should show" on the main strategic
     * production screen.
     *
     * @var list<string>
     */
    public const CARD_FIELDS = [
        'name',
        'type',
        'domain',
        'status',
        'deadline',
        'progress',
        'next_step',
        'main_risk',
        'current_quality',
        'last_activity',
        'source_count',
        'review_pending_count',
        'ai_alerts',
    ];

    /**
     * The 12 numbered sections the mature workspace must expose, in order.
     *
     * @var list<string>
     */
    public const WORKSPACE_SECTIONS = [
        'overview',
        'structure',
        'composer',
        'sources',
        'notes',
        'tasks',
        'decisions',
        'feedbacks',
        'versions',
        'quality_gates',
        'ai',
        'output',
    ];

    /**
     * Conceptual CLI actions the doc lists. In the product these must appear as
     * contextual buttons / AI actions, never as raw commands.
     *
     * @var list<string>
     */
    public const QUICK_ACTIONS = [
        'advance_next_stage',
        'review_structure',
        'create_execution_plan',
        'generate_tasks',
        'register_decision',
        'run_quality_gates',
        'find_gaps',
        'create_output',
        'compare_versions',
        'publish_version',
        'archive_obra',
    ];

    /**
     * Entity classification. A captured item must resolve to exactly ONE entity
     * (Note, Task, Project or Obra). The doc forbids confusing an Obra with a
     * note, task or simple project, so:
     *   - an item flagged as building a relevant artifact until ready is an Obra;
     *   - an item must not claim to be both an Obra and a plain project/task;
     *   - exactly one entity signal must be present.
     *
     * @param array{builds_artifact_until_ready?: bool, is_set_of_actions?: bool, is_actionable_todo?: bool, is_captured_thought?: bool} $signals
     * @return array{schema: string, surface: string, status: string, entity: string|null, question: string|null, reasons: list<string>}
     */
    public function classifyEntity(array $signals): array
    {
        $isObra = ($signals['builds_artifact_until_ready'] ?? false) === true;
        $isProject = ($signals['is_set_of_actions'] ?? false) === true;
        $isTask = ($signals['is_actionable_todo'] ?? false) === true;
        $isNote = ($signals['is_captured_thought'] ?? false) === true;

        $active = array_keys(array_filter([
            'obra' => $isObra,
            'project' => $isProject,
            'task' => $isTask,
            'note' => $isNote,
        ]));

        $reasons = [];

        if ($active === []) {
            return [
                'schema' => self::SCHEMA,
                'surface' => 'entity_classification',
                'status' => self::STATUS_FAIL,
                'entity' => null,
                'question' => null,
                'reasons' => ['unclassified: item matches no documented entity (note, task, project or obra).'],
            ];
        }

        // The Obra signal is the strongest: an item that builds a relevant
        // artifact until ready is an Obra and must not be downgraded to a plain
        // project/task/note. Mixing Obra with a lower entity is the documented
        // confusion the doc forbids.
        if ($isObra && count($active) > 1) {
            $reasons[] = 'confusion: an Obra must not be confused with notes, tasks or simple projects.';

            return [
                'schema' => self::SCHEMA,
                'surface' => 'entity_classification',
                'status' => self::STATUS_FAIL,
                'entity' => 'obra',
                'question' => self::ENTITY_QUESTIONS['obra'],
                'reasons' => $reasons,
            ];
        }

        if (count($active) > 1) {
            return [
                'schema' => self::SCHEMA,
                'surface' => 'entity_classification',
                'status' => self::STATUS_FAIL,
                'entity' => null,
                'question' => null,
                'reasons' => ['ambiguous: a captured item must resolve to exactly one entity, got ' . implode(', ', $active) . '.'],
            ];
        }

        $entity = $active[0];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'entity_classification',
            'status' => self::STATUS_PASS,
            'entity' => $entity,
            'question' => self::ENTITY_QUESTIONS[$entity],
            'reasons' => [],
        ];
    }

    /**
     * Obra card audit. A strategic production card is conformant only when it
     * exposes every one of the 13 documented fields.
     *
     * @param array<string, bool> $card
     * @return array{schema: string, surface: string, status: string, present: list<string>, missing: list<string>, total: int, present_count: int, blocking_reasons: list<string>}
     */
    public function auditObraCard(array $card): array
    {
        $present = [];
        $missing = [];
        foreach (self::CARD_FIELDS as $field) {
            if (($card[$field] ?? false) === true) {
                $present[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        $reasons = [];
        foreach ($missing as $field) {
            $reasons[] = 'card_field_missing: ' . $field;
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'obra_card',
            'status' => $missing === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'present' => $present,
            'missing' => $missing,
            'total' => count(self::CARD_FIELDS),
            'present_count' => count($present),
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Inside-an-Obra workspace audit. The mature workspace must expose the 12
     * documented sections, in the documented order. Passes only when all 12 are
     * present AND their order matches the canonical sequence.
     *
     * @param list<string> $sections
     * @return array{schema: string, surface: string, status: string, missing: list<string>, unexpected: list<string>, order_ok: bool, blocking_reasons: list<string>}
     */
    public function auditWorkspaceSections(array $sections): array
    {
        $normalized = array_values(array_map(
            static fn ($s): string => strtolower(trim((string) $s)),
            $sections,
        ));

        $missing = array_values(array_diff(self::WORKSPACE_SECTIONS, $normalized));
        $unexpected = array_values(array_diff($normalized, self::WORKSPACE_SECTIONS));

        // Order check: the subsequence of known sections must match canonical order.
        $known = array_values(array_filter(
            $normalized,
            static fn (string $s): bool => in_array($s, self::WORKSPACE_SECTIONS, true),
        ));
        $expectedOrder = array_values(array_filter(
            self::WORKSPACE_SECTIONS,
            static fn (string $s): bool => in_array($s, $known, true),
        ));
        $orderOk = $known === $expectedOrder;

        $reasons = [];
        foreach ($missing as $section) {
            $reasons[] = 'workspace_section_missing: ' . $section;
        }
        foreach ($unexpected as $section) {
            $reasons[] = 'workspace_section_unexpected: ' . $section;
        }
        if (! $orderOk) {
            $reasons[] = 'workspace_section_order: sections must follow the documented sequence.';
        }

        $status = ($missing === [] && $unexpected === [] && $orderOk)
            ? self::STATUS_PASS
            : self::STATUS_FAIL;

        return [
            'schema' => self::SCHEMA,
            'surface' => 'workspace_sections',
            'status' => $status,
            'missing' => $missing,
            'unexpected' => $unexpected,
            'order_ok' => $orderOk,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Composer / AI-session rule. Enforces the doc decision "Every AI session
     * inside Obras must produce or improve an artifact, not remain loose
     * conversation" together with the Composer rule "AI works inside the Obra,
     * with the Obra's sources, decisions, policies and context".
     *
     * A session is valid ONLY when:
     *   - it is bound to an Obra id (works inside the Obra), AND
     *   - it produces a new artifact OR improves an existing one (never loose
     *     conversation).
     * The "produce or improve" rule is enforced, not trusted: a session that
     * neither produces nor improves an artifact fails even if a caller marks it
     * productive.
     *
     * @param array{obra_id?: string|null, produces_artifact?: bool, improves_artifact?: bool} $session
     * @return array{schema: string, surface: string, status: string, bound_to_obra: bool, produces_or_improves: bool, reasons: list<string>}
     */
    public function validateAiSession(array $session): array
    {
        $obraId = $session['obra_id'] ?? null;
        $boundToObra = is_string($obraId) && trim($obraId) !== '';
        $produces = ($session['produces_artifact'] ?? false) === true;
        $improves = ($session['improves_artifact'] ?? false) === true;
        $producesOrImproves = $produces || $improves;

        $reasons = [];
        if (! $boundToObra) {
            $reasons[] = 'session_not_scoped: AI works inside the Obra, with the Obra context; an Obra id is required.';
        }
        if (! $producesOrImproves) {
            $reasons[] = 'loose_conversation: every AI session must produce or improve an artifact, not remain loose conversation.';
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'ai_session',
            'status' => ($boundToObra && $producesOrImproves) ? self::STATUS_PASS : self::STATUS_FAIL,
            'bound_to_obra' => $boundToObra,
            'produces_or_improves' => $producesOrImproves,
            'reasons' => $reasons,
        ];
    }

    /**
     * Quick-action surfacing rule. A documented action must reach the operator
     * as a contextual button / AI action, never as a raw command. Resolving an
     * action returns its product surface; a known action surfaced as a raw
     * command is a violation of "these actions should appear as contextual
     * buttons and AI actions, not raw commands".
     *
     * @return array{schema: string, surface: string, status: string, action: string, known: bool, render_as: string, reasons: list<string>}
     */
    public function resolveQuickActionSurface(string $action, bool $renderAsRawCommand = false): array
    {
        $normalized = strtolower(trim($action));
        $known = in_array($normalized, self::QUICK_ACTIONS, true);

        $reasons = [];
        if (! $known) {
            $reasons[] = 'unknown_action: not a documented quick action.';
        }
        if ($renderAsRawCommand) {
            $reasons[] = 'raw_command_surface: quick actions must appear as contextual buttons and AI actions, not raw commands.';
        }

        $status = ($known && ! $renderAsRawCommand) ? self::STATUS_PASS : self::STATUS_FAIL;

        return [
            'schema' => self::SCHEMA,
            'surface' => 'quick_action_surface',
            'status' => $status,
            'action' => $normalized,
            'known' => $known,
            'render_as' => 'contextual_button',
            'reasons' => $reasons,
        ];
    }

    /**
     * Whole-product audit: runs every UX surface over a candidate product
     * bundle and folds the per-surface verdicts into one pass|fail document.
     * Status is pass only when EVERY surface passes.
     *
     * @param array{
     *     entity?: array<string, bool>,
     *     card?: array<string, bool>,
     *     workspace_sections?: list<string>,
     *     ai_session?: array<string, mixed>,
     *     quick_action?: array{action?: string, render_as_raw_command?: bool}
     * } $bundle
     * @return array{schema: string, status: string, surfaces: array<string, mixed>, blocking_reasons: list<string>}
     */
    public function audit(array $bundle): array
    {
        $surfaces = [
            'entity' => $this->classifyEntity($bundle['entity'] ?? []),
            'card' => $this->auditObraCard($bundle['card'] ?? []),
            'workspace_sections' => $this->auditWorkspaceSections($bundle['workspace_sections'] ?? []),
            'ai_session' => $this->validateAiSession($bundle['ai_session'] ?? []),
        ];

        if (isset($bundle['quick_action'])) {
            $quickAction = $bundle['quick_action'];
            $surfaces['quick_action'] = $this->resolveQuickActionSurface(
                (string) ($quickAction['action'] ?? ''),
                ($quickAction['render_as_raw_command'] ?? false) === true,
            );
        }

        $reasons = [];
        $allPass = true;
        foreach ($surfaces as $surface) {
            if (($surface['status'] ?? self::STATUS_FAIL) !== self::STATUS_PASS) {
                $allPass = false;
            }
            foreach (($surface['blocking_reasons'] ?? $surface['reasons'] ?? []) as $reason) {
                $reasons[] = $reason;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $allPass ? self::STATUS_PASS : self::STATUS_FAIL,
            'surfaces' => $surfaces,
            'blocking_reasons' => $reasons,
        ];
    }
}
