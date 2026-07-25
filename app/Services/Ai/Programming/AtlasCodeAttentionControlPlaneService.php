<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\Support\CodeAttentionClassifier;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\AtlasCode\AtlasCodeObservedSessionService;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use App\Services\AtlasCode\DevToForgePromotionService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Atlas Code Attention Control Plane.
 *
 * Read-model that serializes human decisions across multiple Programming Obras
 * so the operator is never asked to monitor parallel work. Atencao does NOT
 * fan out execution; it routes the next human-relevant decision to the front.
 *
 * Schema: atlas.code.attention_control_plane.v1
 * Doc:    docs/engineering-knowledge-base/atlas-code-attention-control-plane-v1.md
 *
 * Rules honored:
 *   - Atencao consumes Forge UX Orchestrator state; it never calls a provider.
 *   - Items are derived ONLY from states that genuinely need a human decision.
 *   - States that are running, waiting on a worker, prepared, completed or idle
 *     produce NO item (per canon §"Estados que nao devem gerar atencao").
 *   - Every item carries `allowed_actions` derived from the canon vocabulary —
 *     the controller refuses any action outside that list.
 *   - Mutating actions append a `human_decision_receipt` to the Obra metadata.
 *   - `dismiss_with_reason` snoozes an item *for the current state hash only*:
 *     when the Obra state changes the item key changes and the dismissal stops
 *     applying. Pause stops attention until `paused_until` expires.
 */
final class AtlasCodeAttentionControlPlaneService
{
    public const SCHEMA_VERSION = 'atlas.code.attention_control_plane.v1';

    // Canon item kinds.
    public const KIND_INTAKE_NEEDED = 'intake_needed';

    public const KIND_SCOPE_DECISION = 'scope_decision';

    public const KIND_RISK_APPROVAL = 'risk_approval';

    public const KIND_PROVIDER_APPROVAL = 'provider_approval';

    public const KIND_RUNTIME_APPROVAL = 'runtime_approval';

    public const KIND_REVIEW_NEEDED = 'review_needed';

    public const KIND_REPAIR_DECISION = 'repair_decision';

    public const KIND_FINAL_ACCEPTANCE = 'final_acceptance';

    public const KIND_BLOCKED_ATTENTION = 'blocked_attention';

    // Canon actions vocabulary. Controller enforces this set.
    public const ACTION_OPEN_OBRA = 'open_obra';

    public const ACTION_APPROVE = 'approve';

    public const ACTION_REJECT = 'reject';

    public const ACTION_REQUEST_REPAIR = 'request_repair';

    public const ACTION_PAUSE = 'pause';

    public const ACTION_ROLLBACK = 'rollback';

    public const ACTION_REFINE_INTAKE = 'refine_intake';

    public const ACTION_APPROVE_SCOPE_CHANGE = 'approve_scope_change';

    public const ACTION_DENY_SCOPE_CHANGE = 'deny_scope_change';

    public const ACTION_APPROVE_PROVIDER = 'approve_provider';

    public const ACTION_APPROVE_RUNTIME = 'approve_runtime';

    public const ACTION_DISMISS_WITH_REASON = 'dismiss_with_reason';

    public const SEVERITY_HIGH = 'high';

    public const SEVERITY_MEDIUM = 'medium';

    public const SEVERITY_LOW = 'low';

    /** Severity order used for queue sorting. Lower index = higher priority. */
    private const KIND_PRIORITY = [
        self::KIND_BLOCKED_ATTENTION => 0,
        self::KIND_REPAIR_DECISION => 1,
        self::KIND_REVIEW_NEEDED => 2,
        self::KIND_FINAL_ACCEPTANCE => 3,
        self::KIND_PROVIDER_APPROVAL => 4,
        self::KIND_RUNTIME_APPROVAL => 5,
        self::KIND_SCOPE_DECISION => 6,
        self::KIND_RISK_APPROVAL => 7,
        self::KIND_INTAKE_NEEDED => 8,
    ];

    /** Maximum Obras inspected per snapshot. Keeps the surface honest about scope. */
    private const MAX_OBRAS = 60;

    /** Maximum items returned in the queue. The operator focuses on one anyway. */
    private const MAX_QUEUE_ITEMS = 20;

    public function __construct(
        private readonly AtlasCodeForgeUxOrchestratorService $orchestrator,
        private readonly AtlasCodeWorkspaceProfileService $workspaces,
        // Observed Session source (Meta 2). Injected via Laravel's container;
        // optional via lazy-resolve so older callers / mocks still work.
        private readonly ?AtlasCodeObservedSessionService $observedSessions = null,
    ) {}

    private function observed(): ?AtlasCodeObservedSessionService
    {
        if ($this->observedSessions !== null) {
            return $this->observedSessions;
        }
        try {
            return app(AtlasCodeObservedSessionService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    private function promotion(): ?DevToForgePromotionService
    {
        try {
            return app(DevToForgePromotionService::class);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @return array<string, mixed>|null
     */
    private function promotionCandidateItem(array $candidate): ?array
    {
        $status = (string) ($candidate['candidate_status'] ?? '');
        // Only pending candidates are decision-relevant. Promoted/dismissed
        // are settled.
        if ($status !== 'pending_decision') {
            return null;
        }
        $target = (string) ($candidate['promotion_target'] ?? '');
        // quick_intervention is operator-confirmable inline and shouldn't
        // route through Attention.
        if ($target === '' || $target === 'quick_intervention') {
            return null;
        }
        $workspaceSlug = (string) ($candidate['workspace_slug'] ?? 'atlas');
        $candidateId = (string) ($candidate['id'] ?? '');
        if ($candidateId === '') {
            return null;
        }

        $kind = self::KIND_INTAKE_NEEDED;
        $severity = $target === 'forge_obra' ? self::SEVERITY_MEDIUM : self::SEVERITY_LOW;
        $title = (string) ($candidate['title'] ?? 'Candidato de Obra do Atlas Dev');
        $itemKey = CodeAttentionClassifier::itemKey('promotion:'.$candidateId, $kind, $target);

        return [
            'id' => $itemKey,
            'item_key' => $itemKey,
            'obra_id' => 'promotion_candidate:'.$candidateId,
            'obra_title' => $title,
            'obra_phase' => 'intake',
            'obra_status' => 'promotion_candidate:'.$target,
            'workspace_slug' => $workspaceSlug,
            'kind' => $kind,
            'severity' => $severity,
            'human_question' => $target === 'forge_obra'
                ? 'Promover esta thread Atlas Dev a Obra Forge?'
                : 'Promover esta thread a Candidato de Obra?',
            'why_now' => (string) ($candidate['suggested_next_step'] ?? 'Sinais de promoção detectados na conversa.'),
            'recommended_action' => self::ACTION_APPROVE,
            'allowed_actions' => [
                self::ACTION_OPEN_OBRA,
                self::ACTION_APPROVE,
                self::ACTION_REJECT,
                self::ACTION_DISMISS_WITH_REASON,
            ],
            'risk_if_ignored' => 'Atlas Dev continua sem governança formal; risco de virar chat solto sem evidência.',
            'evidence_refs' => array_values(array_filter([
                'thread:'.((string) ($candidate['source_thread_id'] ?? '')),
                ($candidate['signal_report']['recommended_target'] ?? null) !== null
                    ? 'recommended:'.$candidate['signal_report']['recommended_target']
                    : null,
            ])),
            'target_surface' => 'atlas_ai',
            'target_panel' => 'promotion_candidate',
            'receipt_required' => true,
            'expires_at' => null,
            'created_at' => (string) ($candidate['promoted_at'] ?? $candidate['generated_at'] ?? now()->toIso8601String()),
            'next_safe_step' => (string) ($candidate['suggested_next_step'] ?? ''),
            'blocker_translation' => [
                'source' => 'dev_to_forge_candidate',
                'candidate_id' => $candidateId,
                'thread_id' => (string) ($candidate['source_thread_id'] ?? ''),
                'workspace_slug' => $workspaceSlug,
                'promotion_target' => $target,
                'score' => (int) ($candidate['signal_report']['score'] ?? 0),
            ],
        ];
    }

    /**
     * Build the Attention Control Plane snapshot.
     *
     * @param  array<string,mixed>  $options  workspace_slug, limit
     * @return array<string,mixed>
     */
    public function snapshot(array $options = []): array
    {
        $generatedAt = now()->toIso8601String();
        $workspaceSlug = AiValueNormalizer::trimmedStringOrNull($options['workspace_slug'] ?? null);

        $obras = $this->candidateObras($workspaceSlug);
        $queue = [];
        $resolvedRecently = [];
        $blockedObras = 0;
        $missingEvidenceCount = 0;
        $unknownStateCount = 0;
        $obraSummaries = [];

        foreach ($obras as $obra) {
            $ux = $this->orchestrator->snapshot(['obra_id' => (string) $obra->getKey()]);
            $state = (string) ($ux['state'] ?? 'idle');
            $signals = (array) ($ux['signals'] ?? []);

            $obraWorkspaceSlug = $this->resolveWorkspaceSlugFor($obra);

            $obraSummary = [
                'obra_id' => (string) $obra->getKey(),
                'obra_title' => $this->obraTitle($obra),
                'workspace_slug' => $obraWorkspaceSlug,
                'state' => $state,
                'state_label' => (string) ($ux['human_status_label'] ?? 'Estado desconhecido'),
                'paused_until' => $obra->paused_until?->toIso8601String(),
            ];
            $obraSummaries[] = $obraSummary;

            if ($state === '' || $state === 'unknown') {
                $unknownStateCount++;
            }
            if (str_starts_with($state, 'blocked')) {
                $blockedObras++;
            }
            if ((int) ($signals['evidence_ref_count'] ?? 0) === 0
                && in_array($state, ['waiting_review', 'completed'], true)) {
                $missingEvidenceCount++;
            }

            if ($this->obraIsPaused($obra)) {
                continue;
            }

            $item = $this->deriveItem($obra, $ux, $obraWorkspaceSlug);
            if ($item === null) {
                if (in_array($state, ['completed'], true) && ((bool) ($signals['human_approved'] ?? false))) {
                    $resolvedRecently[] = $this->resolvedSnapshot($obra, $ux);
                }

                continue;
            }

            if ($this->isDismissed($obra, $item)) {
                continue;
            }

            $queue[] = $item;
        }

        // Observed Sessions adapter (Meta 2 → Meta 4 integration).
        // Each session in a human-blocking state becomes its own attention
        // item, mapped to the canonical kind vocabulary.
        $observed = $this->observed();
        if ($observed !== null) {
            foreach ($obras as $obra) {
                if ($this->obraIsPaused($obra)) {
                    continue;
                }
                $sessions = $observed->listForObra((string) $obra->getKey());
                $obraWorkspaceSlug = $this->resolveWorkspaceSlugFor($obra);
                foreach ($sessions as $session) {
                    $item = $this->observedSessionItem($obra, $session, $obraWorkspaceSlug);
                    if ($item === null) {
                        continue;
                    }
                    if ($this->isDismissed($obra, $item)) {
                        continue;
                    }
                    $queue[] = $item;
                }
            }
        }

        // Dev-to-Forge Promotion adapter (Meta 8 → Meta 4 integration).
        // Pending obra_candidate / forge_obra candidates that haven't been
        // promoted nor dismissed surface as attention items so the operator
        // decides in the canonical queue. We do NOT include quick_intervention
        // here — those are operator-confirmable inline and don't need the
        // attention router.
        $promotion = $this->promotion();
        if ($promotion !== null) {
            $candidates = $promotion->listCandidates($workspaceSlug);
            foreach ($candidates as $candidate) {
                $item = $this->promotionCandidateItem($candidate);
                if ($item === null) {
                    continue;
                }
                $queue[] = $item;
            }
        }

        usort($queue, function (array $a, array $b): int {
            $pa = self::KIND_PRIORITY[$a['kind']] ?? 99;
            $pb = self::KIND_PRIORITY[$b['kind']] ?? 99;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }
            $sa = CodeAttentionClassifier::severityRank((string) $a['severity']);
            $sb = CodeAttentionClassifier::severityRank((string) $b['severity']);
            if ($sa !== $sb) {
                return $sa <=> $sb;
            }

            return strcmp($a['created_at'] ?? '', $b['created_at'] ?? '');
        });

        $limitedQueue = array_slice($queue, 0, self::MAX_QUEUE_ITEMS);
        $activeFocusItem = $limitedQueue[0] ?? null;

        $waitingHumanCount = 0;
        foreach ($queue as $item) {
            if (! empty($item['receipt_required'])) {
                $waitingHumanCount++;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'generated_at' => $generatedAt,
            'workspace_filter' => $workspaceSlug,
            'active_focus_item' => $activeFocusItem,
            'queue_items' => $limitedQueue,
            'resolved_recently' => array_slice($resolvedRecently, 0, 10),
            'obra_summary' => array_slice($obraSummaries, 0, self::MAX_OBRAS),
            'health' => [
                'total_items' => count($queue),
                'returned_items' => count($limitedQueue),
                'blocked_obras' => $blockedObras,
                'waiting_human_count' => $waitingHumanCount,
                'stale_items_count' => 0, // expires_at staleness is reserved for later iterations
                'missing_evidence_count' => $missingEvidenceCount,
                'unknown_state_count' => $unknownStateCount,
            ],
            'allowed_actions_vocabulary' => $this->allowedActionsVocabulary(),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'completion_claim_promoted' => false,
            'review_gate_preserved' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Code Attention Control Plane v1: read-model que serializa decisoes humanas entre Obras. Nunca chama provider, nunca promove completion claim.',
        ];
    }

    /**
     * @return array<int,string>
     */
    public function allowedActionsVocabulary(): array
    {
        return [
            self::ACTION_OPEN_OBRA,
            self::ACTION_APPROVE,
            self::ACTION_REJECT,
            self::ACTION_REQUEST_REPAIR,
            self::ACTION_PAUSE,
            self::ACTION_ROLLBACK,
            self::ACTION_REFINE_INTAKE,
            self::ACTION_APPROVE_SCOPE_CHANGE,
            self::ACTION_DENY_SCOPE_CHANGE,
            self::ACTION_APPROVE_PROVIDER,
            self::ACTION_APPROVE_RUNTIME,
            self::ACTION_DISMISS_WITH_REASON,
        ];
    }

    /**
     * @return array<int,AtlasProject>
     */
    private function candidateObras(?string $workspaceSlug): array
    {
        $query = AtlasProject::query()
            ->orderByDesc('updated_at')
            ->limit(self::MAX_OBRAS * 3);

        $rows = $query->get()
            ->reject(fn (AtlasProject $p): bool => $this->isSystemProject($p))
            ->reject(fn (AtlasProject $p): bool => in_array((string) $p->status, ['archived'], true))
            ->values();

        if ($workspaceSlug !== null) {
            $rows = $rows->filter(fn (AtlasProject $p): bool => $this->resolveWorkspaceSlugFor($p) === $workspaceSlug);
        }

        return $rows->take(self::MAX_OBRAS)->all();
    }

    private function isSystemProject(AtlasProject $project): bool
    {
        return (string) data_get($project->metadata, 'origin', '') === 'atlas-code-enterprise-certification';
    }

    private function resolveWorkspaceSlugFor(AtlasProject $project): string
    {
        $candidates = [
            (string) (data_get($project->metadata, 'workspace_slug') ?? ''),
            (string) (data_get($project->metadata, 'workspace') ?? ''),
            (string) ($project->domain ?? ''),
        ];
        foreach ($candidates as $candidate) {
            $candidate = trim(strtolower($candidate));
            if ($candidate === '') {
                continue;
            }
            $profile = $this->workspaces->findBySlug($candidate);
            if ($profile !== null) {
                return (string) $profile['slug'];
            }
        }

        return $this->workspaces->defaultSlug();
    }

    private function obraTitle(AtlasProject $obra): string
    {
        $title = AiValueNormalizer::trimmedStringOrNull($obra->title);
        if ($title !== null) {
            return $title;
        }
        $goal = AiValueNormalizer::trimmedStringOrNull($obra->goal);
        if ($goal !== null) {
            return $goal;
        }

        return 'Obra sem titulo';
    }

    private function obraIsPaused(AtlasProject $obra): bool
    {
        $paused = $obra->paused_until;
        if ($paused === null) {
            return false;
        }

        return $paused->isFuture();
    }

    /**
     * @param  array<string,mixed>  $ux
     * @return array<string,mixed>|null
     */
    private function deriveItem(AtlasProject $obra, array $ux, string $workspaceSlug): ?array
    {
        $state = (string) ($ux['state'] ?? '');
        $signals = (array) ($ux['signals'] ?? []);
        $obraId = (string) $obra->getKey();
        $createdAt = now()->toIso8601String();

        [$kind, $severity, $humanQuestion, $whyNow, $recommendedAction, $allowedActions, $riskIfIgnored, $targetPanel] =
            CodeAttentionClassifier::classify($state, $signals);

        if ($kind === null) {
            return null;
        }

        $itemKey = CodeAttentionClassifier::itemKey($obraId, $kind, $state);

        return [
            'id' => $itemKey,
            'item_key' => $itemKey,
            'obra_id' => $obraId,
            'obra_title' => $this->obraTitle($obra),
            'obra_phase' => CodeAttentionClassifier::phaseForState($state),
            'obra_status' => $state,
            'workspace_slug' => $workspaceSlug,
            'kind' => $kind,
            'severity' => $severity,
            'human_question' => $humanQuestion,
            'why_now' => $whyNow,
            'recommended_action' => $recommendedAction,
            'allowed_actions' => $allowedActions,
            'risk_if_ignored' => $riskIfIgnored,
            'evidence_refs' => $this->evidenceRefs($obra, $signals),
            'target_surface' => 'atlas_code',
            'target_panel' => $targetPanel,
            'receipt_required' => CodeAttentionClassifier::actionMutates($recommendedAction),
            'expires_at' => null,
            'created_at' => $createdAt,
            'next_safe_step' => (string) ($ux['next_safe_step'] ?? ''),
            'blocker_translation' => (array) ($ux['blocker_translation'] ?? []),
        ];
    }

    /**
     * Project a single Observed Session into an Attention item, only when
     * the session state needs a human decision. States that are running
     * (`running`, `gates_running`) or already settled (`accepted`,
     * `rejected`, `gates_passed`) produce NO item — Atencao mostra apenas
     * decisões humanas pendentes.
     *
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>|null
     */
    private function observedSessionItem(AtlasProject $obra, array $session, string $workspaceSlug): ?array
    {
        $sessionId = (string) ($session['id'] ?? '');
        if ($sessionId === '') {
            return null;
        }
        $state = (string) ($session['state'] ?? '');
        $createdAt = (string) ($session['updated_at'] ?? $session['created_at'] ?? now()->toIso8601String());

        // Canonical mapping observed_session.state → attention kind/severity.
        $map = match ($state) {
            'waiting_operator' => [
                'kind' => self::KIND_RUNTIME_APPROVAL,
                'severity' => self::SEVERITY_MEDIUM,
                'question' => 'Iniciar o provider observado no terminal?',
                'why_now' => 'Atlas preparou packet + prompt copy-safe; falta o operador iniciar `'.((string) ($session['terminal_command_hint'] ?? 'claude')).'` no workspace.',
                'recommended' => self::ACTION_APPROVE_RUNTIME,
                'allowed' => [self::ACTION_OPEN_OBRA, self::ACTION_APPROVE_RUNTIME, self::ACTION_PAUSE, self::ACTION_DISMISS_WITH_REASON],
                'risk' => 'Sem início manual, a sessão fica em waiting_operator e o pacote nunca é executado.',
                'panel' => 'operating_room',
            ],
            'waiting_result_import' => [
                'kind' => self::KIND_REVIEW_NEEDED,
                'severity' => self::SEVERITY_MEDIUM,
                'question' => 'Importar o relatório+diff do provider?',
                'why_now' => 'Provider terminou. Atlas precisa do relatório colado pelo operador para rodar scope guard e gates.',
                'recommended' => self::ACTION_APPROVE,
                'allowed' => [self::ACTION_OPEN_OBRA, self::ACTION_APPROVE, self::ACTION_REJECT, self::ACTION_PAUSE],
                'risk' => 'Sem importação não há diff, scope guard ou gates — completion fica bloqueada.',
                'panel' => 'operating_room',
            ],
            'imported', 'review_required' => [
                'kind' => self::KIND_REVIEW_NEEDED,
                'severity' => self::SEVERITY_MEDIUM,
                'question' => 'Aprovar, rejeitar, pedir repair ou bloquear esta sessão observada?',
                'why_now' => 'Relatório foi importado. Atlas registrou scope guard/gates advisory. Falta o aceite humano.',
                'recommended' => self::ACTION_APPROVE,
                'allowed' => [self::ACTION_OPEN_OBRA, self::ACTION_APPROVE, self::ACTION_REQUEST_REPAIR, self::ACTION_REJECT, self::ACTION_PAUSE],
                'risk' => 'Sem decisão humana a entrega não vira completion; texto do provider nunca é aceite.',
                'panel' => 'operating_room',
            ],
            'gates_failed' => [
                'kind' => self::KIND_REPAIR_DECISION,
                'severity' => self::SEVERITY_HIGH,
                'question' => 'Gates advisory falharam — pedir repair, rejeitar ou aceitar mesmo assim?',
                'why_now' => 'Atlas detectou falha nos gates: scope/criteria/diff insuficientes.',
                'recommended' => self::ACTION_REQUEST_REPAIR,
                'allowed' => [self::ACTION_OPEN_OBRA, self::ACTION_REQUEST_REPAIR, self::ACTION_REJECT, self::ACTION_ROLLBACK, self::ACTION_PAUSE],
                'risk' => 'Aceitar com gates falhando promove evidência fraca para completion.',
                'panel' => 'operating_room',
            ],
            'repair_required' => [
                'kind' => self::KIND_REPAIR_DECISION,
                'severity' => self::SEVERITY_HIGH,
                'question' => 'A sessão pediu repair — reabrir, rejeitar ou bloquear?',
                'why_now' => 'A última revisão exigiu correção. Sessão aguarda decisão para continuar ou ser substituída.',
                'recommended' => self::ACTION_REQUEST_REPAIR,
                'allowed' => [self::ACTION_OPEN_OBRA, self::ACTION_REQUEST_REPAIR, self::ACTION_REJECT, self::ACTION_PAUSE],
                'risk' => 'Sem decisão a sessão fica parada e o packet não evolui.',
                'panel' => 'operating_room',
            ],
            'blocked' => [
                'kind' => self::KIND_BLOCKED_ATTENTION,
                'severity' => self::SEVERITY_HIGH,
                'question' => 'A sessão foi bloqueada — inspecionar e desbloquear?',
                'why_now' => 'Motivo: '.((string) ($session['blocker_reason'] ?? 'sem motivo declarado')),
                'recommended' => self::ACTION_OPEN_OBRA,
                'allowed' => [self::ACTION_OPEN_OBRA, self::ACTION_PAUSE, self::ACTION_REJECT, self::ACTION_DISMISS_WITH_REASON],
                'risk' => 'Manter bloqueado sem inspeção dificulta diagnóstico e atrasa packets dependentes.',
                'panel' => 'operating_room',
            ],
            default => null,
        };

        if ($map === null) {
            // running, gates_running, gates_passed (advisory ok), accepted,
            // rejected — nada para o humano decidir aqui.
            return null;
        }

        $itemKey = CodeAttentionClassifier::itemKey((string) $obra->getKey(), 'observed_session:'.$sessionId, $state);

        return [
            'id' => $itemKey,
            'item_key' => $itemKey,
            'obra_id' => (string) $obra->getKey(),
            'obra_title' => $this->obraTitle($obra),
            'obra_phase' => 'build',
            'obra_status' => 'observed_session:'.$state,
            'workspace_slug' => $workspaceSlug,
            'kind' => $map['kind'],
            'severity' => $map['severity'],
            'human_question' => $map['question'],
            'why_now' => $map['why_now'],
            'recommended_action' => $map['recommended'],
            'allowed_actions' => $map['allowed'],
            'risk_if_ignored' => $map['risk'],
            'evidence_refs' => array_values(array_filter([
                isset($session['diff_hash']) && is_string($session['diff_hash']) ? 'diff:'.substr((string) $session['diff_hash'], 0, 16) : null,
                isset($session['prompt_hash']) && is_string($session['prompt_hash']) && $session['prompt_hash'] !== '' ? 'prompt:'.substr((string) $session['prompt_hash'], 0, 16) : null,
            ])),
            'target_surface' => 'atlas_code',
            'target_panel' => $map['panel'],
            'receipt_required' => true,
            'expires_at' => null,
            'created_at' => $createdAt,
            'next_safe_step' => $map['question'],
            'blocker_translation' => [
                'source' => 'observed_session',
                'session_id' => $sessionId,
                'provider_id' => (string) ($session['provider_id'] ?? ''),
                'work_packet_id' => (string) ($session['work_packet_id'] ?? ''),
                'state' => $state,
                'workspace_path' => $session['workspace_path'] ?? null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $signals
     * @return list<string>
     */
    private function evidenceRefs(AtlasProject $obra, array $signals): array
    {
        $refs = [];
        $metadata = is_array($obra->metadata) ? $obra->metadata : [];

        foreach ([
            'latest_atlas_code_forge_fast_path_run.fast_path_run_id' => 'fast_path_run_id',
            'latest_forge_live_execution.run_id' => 'live_execution_run_id',
            'latest_atlas_code_forge_review_packet.review_packet_id' => 'review_packet_id',
            'latest_atlas_code_forge_completion_claim.completion_claim_id' => 'completion_claim_id',
            'latest_atlas_forge_provider_invocation.invocation_id' => 'provider_invocation_id',
        ] as $path => $_label) {
            $val = data_get($metadata, $path);
            if (is_string($val) && $val !== '') {
                $refs[] = $val;
            }
        }

        // Evidence ref counts also serve as proof signals.
        if (((int) ($signals['evidence_ref_count'] ?? 0)) > 0) {
            $refs[] = 'evidence_count='.(int) $signals['evidence_ref_count'];
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  array<string,mixed>  $item
     */
    private function isDismissed(AtlasProject $obra, array $item): bool
    {
        $metadata = is_array($obra->metadata) ? $obra->metadata : [];
        $receipts = (array) data_get($metadata, 'attention_receipts', []);
        $key = (string) $item['item_key'];
        foreach (array_reverse($receipts) as $receipt) {
            if (! is_array($receipt)) {
                continue;
            }
            if ((string) ($receipt['item_key'] ?? '') !== $key) {
                continue;
            }
            if ((string) ($receipt['action'] ?? '') === self::ACTION_DISMISS_WITH_REASON) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $ux
     * @return array<string,mixed>
     */
    private function resolvedSnapshot(AtlasProject $obra, array $ux): array
    {
        return [
            'obra_id' => (string) $obra->getKey(),
            'obra_title' => $this->obraTitle($obra),
            'state' => (string) ($ux['state'] ?? ''),
            'human_status_label' => (string) ($ux['human_status_label'] ?? ''),
            'completed_at' => $obra->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Validate an inbound action against an item's allowed_actions and append a
     * human_decision_receipt to the Obra metadata.
     *
     * @param  array<string,mixed>  $context  reason, deferred_until, decided_by, notes
     * @return array<string,mixed> receipt
     */
    public function recordDecision(
        AtlasProject $obra,
        string $itemKey,
        string $action,
        array $context = [],
    ): array {
        $snapshot = $this->snapshot([]);
        $matched = null;
        foreach ((array) $snapshot['queue_items'] as $item) {
            if (($item['item_key'] ?? null) === $itemKey && ($item['obra_id'] ?? null) === (string) $obra->getKey()) {
                $matched = $item;
                break;
            }
        }

        if ($matched === null) {
            throw new \InvalidArgumentException('attention_item_not_found');
        }

        $allowed = (array) ($matched['allowed_actions'] ?? []);
        if (! in_array($action, $allowed, true)) {
            throw new \InvalidArgumentException('action_not_allowed_for_item');
        }

        $receipt = [
            'receipt_id' => 'recpt_'.(string) Str::uuid(),
            'item_key' => $itemKey,
            'obra_id' => (string) $obra->getKey(),
            'kind' => (string) ($matched['kind'] ?? ''),
            'state_at_decision' => (string) ($matched['obra_status'] ?? ''),
            'action' => $action,
            'reason' => AiValueNormalizer::trimmedStringOrNull($context['reason'] ?? null),
            'decided_by' => AiValueNormalizer::trimmedStringOrNull($context['decided_by'] ?? null) ?? 'human',
            'decided_at' => Carbon::now()->toIso8601String(),
            'notes' => AiValueNormalizer::trimmedStringOrNull($context['notes'] ?? null),
            'evidence_refs' => array_values((array) ($matched['evidence_refs'] ?? [])),
            'schema_version' => 'atlas.code.attention_human_decision_receipt.v1',
        ];

        $metadata = is_array($obra->metadata) ? $obra->metadata : [];
        $receipts = (array) data_get($metadata, 'attention_receipts', []);
        $receipts[] = $receipt;
        $metadata['attention_receipts'] = array_values(array_slice($receipts, -200));

        // Pause is the only structural side effect we apply directly.
        if ($action === self::ACTION_PAUSE) {
            $hours = (int) ($context['pause_hours'] ?? 24);
            $hours = max(1, min(168, $hours));
            $obra->paused_until = Carbon::now()->addHours($hours);
        }

        $obra->metadata = $metadata;
        $obra->save();

        return $receipt;
    }
}
