<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProject;
use Illuminate\Support\Carbon;

/**
 * Atlas Code Obra Command Center.
 *
 * Read-model que transforma o snapshot do Forge UX Orchestrator + metadata da
 * Obra em um Command Center humano-first: lifecycle de 8 fases, progresso duplo
 * (preparacao vs entrega comprovada), decision inbox, trust summary, operational
 * health, evidence digest e safety strip. Substitui o "centro vazio" do Atlas
 * Code por uma tela viva que responde 7 perguntas em 30s.
 *
 * NUNCA chama provider externo. NUNCA promove completion claim. NUNCA bypassa
 * review gate. Dados faltantes sao expostos como `unknown` honesto, jamais
 * inventados.
 *
 * Schema: atlas.code.obra_command_center.v1
 * Doc: docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md
 */
class AtlasCodeObraCommandCenterService
{
    public const SCHEMA_VERSION = 'atlas.code.obra_command_center.v1';

    public const STATUS_OK = 'ok';
    public const STATUS_BLOCKED = 'blocked';
    public const STATUS_NO_OBRA = 'no_obra';

    /** Lifecycle canônico de uma Obra do Atlas Code. */
    public const PHASE_INTAKE = 'intake';
    public const PHASE_ARCHITECTURE = 'architecture';
    public const PHASE_FORGE_PREP = 'forge_prep';
    public const PHASE_BUILD = 'build';
    public const PHASE_REVIEW = 'review';
    public const PHASE_PROOFS = 'proofs';
    public const PHASE_DECISION = 'decision';
    public const PHASE_LEARNING = 'learning';

    /** Status canonicos de fase. */
    public const PHASE_STATUS_NOT_STARTED = 'not_started';
    public const PHASE_STATUS_READY = 'ready';
    public const PHASE_STATUS_RUNNING = 'running';
    public const PHASE_STATUS_BLOCKED = 'blocked';
    public const PHASE_STATUS_PASSED = 'passed';
    public const PHASE_STATUS_NEEDS_HUMAN = 'needs_human';
    public const PHASE_STATUS_COMPLETED = 'completed';

    public static function normalizeObraIdInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * @return array<int,string>
     */
    public static function canonicalContextRefPaths(): array
    {
        return array_values(array_map(
            static fn (array $ref): string => (string) ($ref['path'] ?? ''),
            self::canonicalContextRefDefinitions(),
        ));
    }

    /**
     * @return array<int,string>
     */
    public static function canonicalChatMessageKinds(): array
    {
        return [
            'definition',
            'command',
            'question',
            'decision',
            'note',
            'restriction',
            'acceptance_criterion',
        ];
    }

    /**
     * @return array<int,array{path:string,kind:string,reason:string}>
     */
    private static function canonicalContextRefDefinitions(): array
    {
        return [
            ['path' => 'docs/engineering-knowledge-base/atlas-code-obra-command-center-v1.md', 'kind' => 'canonical_doc', 'reason' => 'Doc canonica do Obra Command Center v1.'],
            ['path' => 'app/Services/Ai/Programming/AtlasCodeObraCommandCenterService.php', 'kind' => 'service_implementation', 'reason' => 'Read-model human-first do Command Center da Obra.'],
            ['path' => 'app/Http/Controllers/AtlasCodeObraCommandCenterController.php', 'kind' => 'http_controller', 'reason' => 'Endpoint GET /obra-command-center.'],
            ['path' => 'tests/Feature/Ai/Programming/AtlasCodeObraCommandCenterTest.php', 'kind' => 'test_evidence', 'reason' => 'Suite feature que prova lifecycle, safety strip e gating.'],
            ['path' => 'tests/Unit/Ai/Programming/AtlasCodeObraCommandCenterServiceTest.php', 'kind' => 'test_evidence', 'reason' => 'Testes unitarios focados (obra fail-closed e refs canonicas).'],
        ];
    }

    public function __construct(
        private readonly AtlasCodeForgeUxOrchestratorService $orchestrator,
        private readonly ?\App\Services\Ai\SelfImprovement\AtlasSelfImprovementResultLedgerService $resultLedger = null,
    ) {}

    /**
     * @param  array<string,mixed>  $options  obra_id
     * @return array<string,mixed>
     */
    public function snapshot(array $options = []): array
    {
        $obraId = self::normalizeObraIdInput($options['obra_id'] ?? null);
        $generatedAt = now()->toIso8601String();

        if ($obraId === null) {
            return $this->finalizeNoObra($generatedAt, null);
        }

        /** @var AtlasProject|null $project */
        $project = AtlasProject::query()->whereKey($obraId)->first();
        if ($project === null) {
            return $this->finalizeNoObra($generatedAt, $obraId);
        }

        $ux = $this->orchestrator->snapshot(['obra_id' => $obraId]);
        $signals = (array) ($ux['signals'] ?? []);
        $state = (string) ($ux['state'] ?? 'idle');

        $phases = $this->lifecyclePhases($state, $signals);
        $milestones = $this->milestones($signals);
        $readiness = $this->readinessProgress($signals);
        $proven = $this->provenDeliveryProgress($signals);
        $decisionInbox = $this->decisionInbox($state, $signals);
        $operationalHealth = $this->operationalHealth($signals);
        $trustSummary = $this->trustSummary($signals);
        $evidenceDigest = $this->evidenceDigest($project, $signals);
        $blockerSummary = $this->blockerSummary($state, $ux, $signals);
        $selfImprovementOrigin = $this->resolveSelfImprovementOrigin($project);

        $status = $this->resolveStatus($state, $blockerSummary);
        [$currentPhase, $nextPhase] = $this->derivePhaseCursor($phases);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => $generatedAt,
            'obra_id' => $obraId,
            'obra_present' => true,
            'obra_title' => $this->stringOrNull($project->title) ?? 'Obra sem titulo',
            'objective_summary' => $this->stringOrNull(
                $project->goal ?? $project->desired_outcome ?? $project->title
            ) ?? 'Sem objetivo declarado',
            'human_status_label' => (string) ($ux['human_status_label'] ?? 'Estado desconhecido'),
            'human_status_detail' => (string) ($ux['human_status_detail'] ?? ''),
            'current_phase' => $currentPhase,
            'next_phase' => $nextPhase,
            'next_safe_action' => (string) ($ux['next_safe_step'] ?? 'Continue pelo botao primario.'),
            'primary_action_kind' => (string) ($ux['primary_action_kind'] ?? 'refresh_status'),
            'primary_action_label' => (string) ($ux['primary_action_label'] ?? 'Atualizar Status'),
            'primary_action_enabled' => (bool) ($ux['primary_action_enabled'] ?? false),
            'primary_action_disabled_reason' => $ux['primary_action_disabled_reason'] ?? null,
            'lifecycle_phases' => $phases,
            'milestones' => $milestones,
            'readiness_progress' => $readiness,
            'proven_delivery_progress' => $proven,
            'decision_inbox' => $decisionInbox,
            'blocker_summary' => $blockerSummary,
            'blocker_translation' => (array) ($ux['blocker_translation'] ?? []),
            'operational_health' => $operationalHealth,
            'trust_summary' => $trustSummary,
            'evidence_digest' => $evidenceDigest,
            'self_improvement_origin' => $selfImprovementOrigin,
            'provider_summary' => (array) ($ux['provider_summary'] ?? []),
            'safety_summary' => array_merge(
                (array) ($ux['safety_summary'] ?? []),
                [
                    'external_rivals_certification' => 'blocked_requires_operator_approval',
                ],
            ),
            'advanced_refs' => $this->advancedRefs($obraId, $ux),
            'chat_message_kinds' => $this->chatMessageKinds(),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'completion_claim_promoted' => false,
            'review_gate_preserved' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Code Obra Command Center v1: tela central viva da Obra. Lifecycle canonico, progresso duplo, decision inbox e safety strip — diagnostico tecnico em Avancado.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function finalizeNoObra(string $generatedAt, ?string $obraId): array
    {
        $human = $obraId === null
            ? 'Selecione ou crie uma Obra'
            : 'Obra nao encontrada';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => self::STATUS_NO_OBRA,
            'generated_at' => $generatedAt,
            'obra_id' => $obraId,
            'obra_present' => false,
            'obra_title' => null,
            'objective_summary' => null,
            'human_status_label' => $human,
            'human_status_detail' => 'O Command Center precisa de uma Obra vinculada antes de qualquer leitura humana.',
            'current_phase' => null,
            'next_phase' => self::PHASE_INTAKE,
            'next_safe_action' => 'Crie ou selecione uma Obra no rail esquerdo.',
            'primary_action_kind' => 'bind_obra',
            'primary_action_label' => $obraId === null ? 'Criar ou selecionar Obra' : 'Selecionar Obra',
            'primary_action_enabled' => false,
            'primary_action_disabled_reason' => 'obra_not_bound',
            'lifecycle_phases' => $this->lifecyclePhases('no_obra', []),
            'milestones' => [],
            'readiness_progress' => $this->emptyProgress('Preparacao da Obra'),
            'proven_delivery_progress' => $this->emptyProgress('Entrega comprovada'),
            'decision_inbox' => [
                [
                    'key' => 'bind_obra',
                    'label' => 'Vincular Obra',
                    'reason' => 'O Command Center nao pode operar sem uma Obra ativa.',
                    'risk' => 'none',
                    'recommended_action' => 'bind_obra',
                    'allowed_actions' => ['bind_obra'],
                ],
            ],
            'blocker_summary' => [
                'count' => 1,
                'kinds' => ['no_obra'],
                'primary_kind' => 'no_obra',
                'primary_human_title' => 'Sem Obra ativa',
            ],
            'blocker_translation' => [
                'kind' => 'no_obra',
                'human_title' => 'Sem Obra ativa',
                'human_detail' => 'O Command Center precisa de uma Obra vinculada.',
                'suggested_action_label' => 'Criar Obra',
                'suggested_action_kind' => 'bind_obra',
                'technical_detail' => 'obra_not_bound',
                'files_out_of_scope' => [],
                'is_blocking' => true,
            ],
            'operational_health' => $this->unknownOperationalHealth(),
            'trust_summary' => $this->trustSummary([]),
            'evidence_digest' => [
                'obra_evidence_ref_count' => 0,
                'obra_ledger_event_count' => 0,
                'latest_evidence_at' => null,
                'system_certifications_separated' => true,
                'note' => 'Provas desta Obra sao distintas das Certificacoes do sistema Atlas.',
            ],
            'self_improvement_origin' => null,
            'provider_summary' => [
                'provider' => null,
                'model' => null,
                'decision_source' => 'unknown',
                'capacity_state' => 'unknown',
                'driver_configured' => false,
            ],
            'safety_summary' => [
                'external_provider_call' => false,
                'provider_tokens_spent' => 0,
                'completion_claim_promoted' => false,
                'review_completion_gate_preserved' => true,
                'external_rivals_certification' => 'blocked_requires_operator_approval',
            ],
            'advanced_refs' => $this->advancedRefs($obraId, []),
            'chat_message_kinds' => $this->chatMessageKinds(),
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'completion_claim_promoted' => false,
            'review_gate_preserved' => true,
            'separated_from' => 'external_rivals_certification',
            'note' => 'Atlas Code Obra Command Center v1: sem Obra ativa. Bind no rail esquerdo.',
        ];
    }

    /**
     * Resolve the self-improvement origin block for an Obra.
     *
     * When the Obra was materialised through `AtlasSelfImprovementForgeActivationService::accept`,
     * its metadata carries a `self_improvement_activation` block. This
     * method projects that block PLUS the latest matching result entry
     * (if any) so the Command Center can show "Esta Obra veio de
     * Self-Improvement. Resultado medido / regressão detectada / ainda
     * não medido."
     *
     * Returns `null` for manually-created Obras.
     *
     * @return array<string,mixed>|null
     */
    private function resolveSelfImprovementOrigin(AtlasProject $project): ?array
    {
        $metadata = is_array($project->metadata ?? null) ? $project->metadata : [];
        $activationBlock = is_array($metadata['self_improvement_activation'] ?? null)
            ? $metadata['self_improvement_activation']
            : null;
        if ($activationBlock === null) {
            return null;
        }

        $obraId = (string) $project->getKey();
        $proposalId = $this->stringOrNull($activationBlock['proposal_id'] ?? null);
        $activationId = $this->stringOrNull($activationBlock['activation_id'] ?? null);

        $latestEntry = null;
        $deltaGrade = null;
        $resultEntryId = null;
        if ($this->resultLedger !== null) {
            try {
                $entries = $this->resultLedger->listForObra($obraId);
                $latestEntry = $entries[0] ?? null;
            } catch (\Throwable) {
                $latestEntry = null;
            }
        }
        if (is_array($latestEntry)) {
            $deltaGrade = $this->stringOrNull($latestEntry['delta_grade'] ?? null);
            $resultEntryId = $this->stringOrNull($latestEntry['result_entry_id'] ?? null);
        }

        $humanMessage = match (true) {
            $deltaGrade === null => 'Esta Obra veio de Self-Improvement. Resultado ainda não medido.',
            $deltaGrade === 'major_improvement' => 'Esta Obra veio de Self-Improvement. Major improvement medido.',
            $deltaGrade === 'improved' => 'Esta Obra veio de Self-Improvement. Improvement medido.',
            $deltaGrade === 'neutral' => 'Esta Obra veio de Self-Improvement. Resultado neutro — coletar mais evidência.',
            $deltaGrade === 'regressed' => 'Esta Obra veio de Self-Improvement. Regressão detectada.',
            $deltaGrade === 'invalid' => 'Esta Obra veio de Self-Improvement. Evidência insuficiente.',
            default => 'Esta Obra veio de Self-Improvement.',
        };

        $measureAction = [
            'enabled' => $latestEntry === null,
            'label' => $latestEntry === null ? 'Medir resultado' : 'Resultado já medido',
            'command_hint' => 'php artisan atlas:self-improvement:measure-result --proposal='
                .($proposalId ?? '<id>')
                .' --obra='.$obraId
                .' --before=@before.json --after=@after.json --reviewer=<who> --reason=<why> --json --strict',
        ];

        return [
            'schema_version' => 'atlas.code.obra_command_center_self_improvement_origin.v1',
            'proposal_id' => $proposalId,
            'activation_id' => $activationId,
            'before_snapshot_hash' => $this->stringOrNull($activationBlock['power_gate_hash'] ?? null),
            'target_capability' => $this->stringOrNull($activationBlock['target_capability'] ?? null),
            'expected_power_gain' => $this->stringOrNull($activationBlock['expected_power_gain'] ?? null),
            'strategy_bucket' => $this->stringOrNull($activationBlock['strategy_bucket'] ?? null),
            'reviewer' => $this->stringOrNull($activationBlock['reviewer'] ?? null),
            'approved_at' => $this->stringOrNull($activationBlock['approved_at'] ?? null),
            'result_entry_id' => $resultEntryId,
            'delta_grade' => $deltaGrade,
            'human_message' => $humanMessage,
            'measure_result_action' => $measureAction,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'auto_fast_path_executed' => false,
            'completion_claim_promoted' => false,
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function lifecyclePhases(string $state, array $signals): array
    {
        $statuses = $this->lifecyclePhaseStatuses($state, $signals);
        $labels = [
            self::PHASE_INTAKE => 'Definicao',
            self::PHASE_ARCHITECTURE => 'Arquitetura / Plano',
            self::PHASE_FORGE_PREP => 'Preparacao Forge',
            self::PHASE_BUILD => 'Construcao',
            self::PHASE_REVIEW => 'Revisao',
            self::PHASE_PROOFS => 'Provas',
            self::PHASE_DECISION => 'Decisao / Encerramento',
            self::PHASE_LEARNING => 'Learning / Self-Improvement',
        ];
        $descriptions = [
            self::PHASE_INTAKE => 'Definicao humana: objetivo, regras, criterios, escopo.',
            self::PHASE_ARCHITECTURE => 'Spec, plan e tasks gerados sem chamar provider externo.',
            self::PHASE_FORGE_PREP => 'Forge preparado, provider/driver/capacity validados.',
            self::PHASE_BUILD => 'Runtime governado executa dentro do contrato.',
            self::PHASE_REVIEW => 'Revisao humana decide aprovar, rejeitar ou rollback.',
            self::PHASE_PROOFS => 'Evidencias da Obra agregadas e separadas de certificacoes de sistema.',
            self::PHASE_DECISION => 'Completion claim gated pela decisao humana.',
            self::PHASE_LEARNING => 'Trust ledger, governance proposals e ciclo Self-Improvement.',
        ];
        $nextActions = [
            self::PHASE_INTAKE => 'Abrir aba Definir e completar campos faltantes.',
            self::PHASE_ARCHITECTURE => 'Preparar Forge para gerar spec/plan/tasks.',
            self::PHASE_FORGE_PREP => 'Executar Forge dentro do contrato.',
            self::PHASE_BUILD => 'Aguardar runtime ou inspecionar blocker traduzido.',
            self::PHASE_REVIEW => 'Abrir Revisar e decidir.',
            self::PHASE_PROOFS => 'Conferir provas desta Obra (nao certificacoes do sistema).',
            self::PHASE_DECISION => 'Promover completion ou rollback so via decisao humana.',
            self::PHASE_LEARNING => 'Registrar aprendizado no trust ledger.',
        ];

        $phases = [];
        foreach (array_keys($labels) as $key) {
            $phaseStatus = $statuses[$key] ?? self::PHASE_STATUS_NOT_STARTED;
            $phases[] = [
                'key' => $key,
                'label' => $labels[$key],
                'status' => $phaseStatus,
                'description' => $descriptions[$key],
                'evidence_count' => $this->phaseEvidenceCount($key, $signals),
                'blocker_count' => $this->phaseBlockerCount($key, $state, $signals),
                'next_action' => $nextActions[$key],
            ];
        }

        return $phases;
    }

    /**
     * @return array<string,string>
     */
    private function lifecyclePhaseStatuses(string $state, array $signals): array
    {
        $base = [
            self::PHASE_INTAKE => self::PHASE_STATUS_NOT_STARTED,
            self::PHASE_ARCHITECTURE => self::PHASE_STATUS_NOT_STARTED,
            self::PHASE_FORGE_PREP => self::PHASE_STATUS_NOT_STARTED,
            self::PHASE_BUILD => self::PHASE_STATUS_NOT_STARTED,
            self::PHASE_REVIEW => self::PHASE_STATUS_NOT_STARTED,
            self::PHASE_PROOFS => self::PHASE_STATUS_NOT_STARTED,
            self::PHASE_DECISION => self::PHASE_STATUS_NOT_STARTED,
            self::PHASE_LEARNING => self::PHASE_STATUS_NOT_STARTED,
        ];

        if ($state === 'no_obra' || $state === '') {
            return $base;
        }

        $intakeReady = (bool) ($signals['intake_ready'] ?? false);
        $specPlanReady = (bool) ($signals['spec_plan_ready'] ?? false);
        $providerKnown = ($signals['provider'] ?? null) !== null;
        $driverConfigured = (bool) ($signals['driver_configured_for_selected'] ?? false);
        $executionStatus = (string) ($signals['execution_status'] ?? '');
        $reviewStatus = (string) ($signals['review_status'] ?? '');
        $humanApproved = (bool) ($signals['human_approved'] ?? false);
        $finalAllowed = (bool) ($signals['final_completion_allowed'] ?? false);
        $completionStatus = (string) ($signals['completion_status'] ?? '');
        $evidenceCount = (int) ($signals['evidence_ref_count'] ?? 0);

        // Intake.
        $base[self::PHASE_INTAKE] = match (true) {
            $intakeReady => self::PHASE_STATUS_PASSED,
            in_array($state, ['blocked_definition', 'intake_required'], true) => self::PHASE_STATUS_NEEDS_HUMAN,
            $state === 'ready_to_define' => self::PHASE_STATUS_READY,
            default => self::PHASE_STATUS_NOT_STARTED,
        };

        // Architecture (= spec/plan ready or about to be prepared).
        $base[self::PHASE_ARCHITECTURE] = match (true) {
            $specPlanReady => self::PHASE_STATUS_PASSED,
            $intakeReady && in_array($state, ['ready_to_prepare', 'intake_ready'], true) => self::PHASE_STATUS_READY,
            $state === 'blocked_scope' => self::PHASE_STATUS_BLOCKED,
            default => self::PHASE_STATUS_NOT_STARTED,
        };

        // Forge prep.
        $base[self::PHASE_FORGE_PREP] = match (true) {
            in_array($state, ['prepared', 'ready_to_execute'], true) => self::PHASE_STATUS_PASSED,
            in_array($state, ['blocked_provider', 'blocked_driver', 'blocked_capacity'], true) => self::PHASE_STATUS_BLOCKED,
            $specPlanReady && $providerKnown && $driverConfigured => self::PHASE_STATUS_READY,
            $specPlanReady => self::PHASE_STATUS_READY,
            default => self::PHASE_STATUS_NOT_STARTED,
        };

        // Build / runtime.
        $base[self::PHASE_BUILD] = match (true) {
            in_array($executionStatus, ['passed', 'degraded'], true) => self::PHASE_STATUS_PASSED,
            $state === 'running' => self::PHASE_STATUS_RUNNING,
            $state === 'waiting_worker' => self::PHASE_STATUS_RUNNING,
            in_array($state, ['blocked_scope', 'blocked_governance', 'blocked_provider', 'blocked_driver', 'blocked_capacity'], true) => self::PHASE_STATUS_BLOCKED,
            $state === 'repair_required' => self::PHASE_STATUS_NEEDS_HUMAN,
            in_array($state, ['prepared', 'ready_to_execute'], true) => self::PHASE_STATUS_READY,
            $state === 'failed' => self::PHASE_STATUS_BLOCKED,
            default => self::PHASE_STATUS_NOT_STARTED,
        };

        // Review.
        $base[self::PHASE_REVIEW] = match (true) {
            $reviewStatus === 'approved' => self::PHASE_STATUS_PASSED,
            $reviewStatus === 'rejected' => self::PHASE_STATUS_BLOCKED,
            in_array($state, ['waiting_review', 'repair_required'], true) => self::PHASE_STATUS_NEEDS_HUMAN,
            default => self::PHASE_STATUS_NOT_STARTED,
        };

        // Proofs (evidence has been recorded).
        $base[self::PHASE_PROOFS] = match (true) {
            $evidenceCount > 0 && $reviewStatus === 'approved' => self::PHASE_STATUS_PASSED,
            $evidenceCount > 0 => self::PHASE_STATUS_READY,
            in_array($state, ['waiting_review', 'completed', 'rejected', 'rolled_back'], true) => self::PHASE_STATUS_READY,
            default => self::PHASE_STATUS_NOT_STARTED,
        };

        // Decision / completion.
        $base[self::PHASE_DECISION] = match (true) {
            $completionStatus === 'completed' || ($finalAllowed && $humanApproved) => self::PHASE_STATUS_COMPLETED,
            $state === 'rejected' => self::PHASE_STATUS_BLOCKED,
            $state === 'rolled_back' => self::PHASE_STATUS_BLOCKED,
            $reviewStatus === 'approved' => self::PHASE_STATUS_NEEDS_HUMAN,
            default => self::PHASE_STATUS_NOT_STARTED,
        };

        // Learning.
        $base[self::PHASE_LEARNING] = match (true) {
            $completionStatus === 'completed' => self::PHASE_STATUS_READY,
            $reviewStatus === 'approved' => self::PHASE_STATUS_READY,
            default => self::PHASE_STATUS_NOT_STARTED,
        };

        return $base;
    }

    /**
     * @param  array<int,array<string,mixed>>  $phases
     * @return array{0:?string,1:?string}
     */
    private function derivePhaseCursor(array $phases): array
    {
        $current = null;
        $next = null;
        foreach ($phases as $phase) {
            $status = (string) ($phase['status'] ?? '');
            if ($current === null && in_array($status, [
                self::PHASE_STATUS_RUNNING,
                self::PHASE_STATUS_NEEDS_HUMAN,
                self::PHASE_STATUS_BLOCKED,
                self::PHASE_STATUS_READY,
            ], true)) {
                $current = (string) $phase['key'];
            }
            if ($current !== null && $next === null && (string) $phase['key'] !== $current
                && in_array($status, [
                    self::PHASE_STATUS_NOT_STARTED,
                    self::PHASE_STATUS_READY,
                ], true)) {
                $next = (string) $phase['key'];
            }
        }
        if ($current === null) {
            foreach ($phases as $phase) {
                if (($phase['status'] ?? null) === self::PHASE_STATUS_PASSED
                    || ($phase['status'] ?? null) === self::PHASE_STATUS_COMPLETED) {
                    $current = (string) $phase['key'];
                }
            }
        }
        if ($current === null && $phases !== []) {
            $current = (string) $phases[0]['key'];
        }

        return [$current, $next];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function milestones(array $signals): array
    {
        $intakeReady = (bool) ($signals['intake_ready'] ?? false);
        $specPlanReady = (bool) ($signals['spec_plan_ready'] ?? false);
        $providerKnown = ($signals['provider'] ?? null) !== null;
        $executionStatus = (string) ($signals['execution_status'] ?? '');
        $reviewStatus = (string) ($signals['review_status'] ?? '');
        $completionStatus = (string) ($signals['completion_status'] ?? '');

        return [
            $this->milestone('intake_complete', 'Definicao completa', $intakeReady),
            $this->milestone('spec_plan_ready', 'Spec/Plan gerados', $specPlanReady),
            $this->milestone('provider_selected', 'Provider decidido', $providerKnown),
            $this->milestone('build_passed', 'Build governada passou', in_array($executionStatus, ['passed', 'degraded'], true)),
            $this->milestone('review_approved', 'Revisao humana aprovada', $reviewStatus === 'approved'),
            $this->milestone('completion_claim', 'Completion claim aprovada', $completionStatus === 'completed'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function milestone(string $key, string $label, bool $reached): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'reached' => $reached,
            'reached_at' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function readinessProgress(array $signals): array
    {
        $items = [
            ['key' => 'intake_ready', 'label' => 'Definicao completa', 'reached' => (bool) ($signals['intake_ready'] ?? false)],
            ['key' => 'spec_plan_ready', 'label' => 'Spec/Plan/Tasks gerados', 'reached' => (bool) ($signals['spec_plan_ready'] ?? false)],
            ['key' => 'provider_decided', 'label' => 'Provider/modelo decidido', 'reached' => ($signals['provider'] ?? null) !== null],
            ['key' => 'driver_configured', 'label' => 'Driver real configurado', 'reached' => (bool) ($signals['driver_configured_for_selected'] ?? false)],
            ['key' => 'capacity_available', 'label' => 'Capacity disponivel', 'reached' => ! (bool) ($signals['capacity_exhausted'] ?? false)],
        ];

        return $this->progressFromItems('Preparacao da Obra', $items);
    }

    /**
     * @return array<string,mixed>
     */
    private function provenDeliveryProgress(array $signals): array
    {
        $reviewStatus = (string) ($signals['review_status'] ?? '');
        $completionStatus = (string) ($signals['completion_status'] ?? '');
        $executionStatus = (string) ($signals['execution_status'] ?? '');

        $items = [
            ['key' => 'execution_passed', 'label' => 'Execucao governada passou', 'reached' => in_array($executionStatus, ['passed', 'degraded'], true)],
            ['key' => 'evidence_recorded', 'label' => 'Evidencia desta Obra registrada', 'reached' => ((int) ($signals['evidence_ref_count'] ?? 0)) > 0],
            ['key' => 'ledger_recorded', 'label' => 'Ledger event registrado', 'reached' => ((int) ($signals['ledger_event_count'] ?? 0)) > 0],
            ['key' => 'review_approved', 'label' => 'Revisao humana aprovada', 'reached' => $reviewStatus === 'approved'],
            ['key' => 'completion_claim', 'label' => 'Completion claim final', 'reached' => $completionStatus === 'completed'],
        ];

        return $this->progressFromItems('Entrega comprovada', $items);
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function progressFromItems(string $label, array $items): array
    {
        $total = count($items);
        $reached = 0;
        foreach ($items as $i) {
            if ((bool) ($i['reached'] ?? false)) {
                $reached++;
            }
        }
        $percent = $total === 0 ? 0 : (int) round(($reached / $total) * 100);

        return [
            'label' => $label,
            'reached' => $reached,
            'total' => $total,
            'percent' => $percent,
            'breakdown' => $items,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function emptyProgress(string $label): array
    {
        return [
            'label' => $label,
            'reached' => 0,
            'total' => 0,
            'percent' => 0,
            'breakdown' => [],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function decisionInbox(string $state, array $signals): array
    {
        $inbox = [];

        if (in_array($state, ['blocked_definition', 'intake_required', 'ready_to_define'], true)) {
            $inbox[] = $this->decision(
                key: 'complete_definition',
                label: 'Completar Definicao',
                reason: 'A Obra nao pode avancar enquanto objetivo/regra/criterios/escopo nao estiverem completos.',
                risk: 'low',
                recommendedAction: 'open_intake',
                allowedActions: ['open_intake'],
            );
        }
        if ($state === 'blocked_scope') {
            $inbox[] = $this->decision(
                key: 'fix_scope',
                label: 'Atualizar "Pode mexer"',
                reason: 'A execucao tentou tocar arquivo fora do escopo permitido.',
                risk: 'medium',
                recommendedAction: 'fix_scope',
                allowedActions: ['fix_scope', 'open_intake'],
            );
        }
        if (in_array($state, ['intake_ready', 'ready_to_prepare'], true)) {
            $inbox[] = $this->decision(
                key: 'prepare_forge',
                label: 'Preparar Forge',
                reason: 'A definicao esta completa. Preparar gera spec/plan/tasks sem chamar provider externo.',
                risk: 'none',
                recommendedAction: 'prepare_fast_path',
                allowedActions: ['prepare_fast_path'],
            );
        }
        if (in_array($state, ['prepared', 'ready_to_execute'], true)) {
            $inbox[] = $this->decision(
                key: 'execute_forge',
                label: 'Executar Forge',
                reason: 'Spec/Plan/Tasks prontos. Execucao continua governada e nunca chama provider sem confirmacao.',
                risk: 'low',
                recommendedAction: 'execute_fast_path',
                allowedActions: ['execute_fast_path'],
            );
        }
        if ($state === 'waiting_provider_confirmation') {
            $inbox[] = $this->decision(
                key: 'authorize_provider',
                label: 'Autorizar chamada de provider real',
                reason: 'Atlas Decide selecionou provider externo. Atlas nunca chama sem aprovacao explicita.',
                risk: 'high',
                recommendedAction: 'confirm_provider',
                allowedActions: ['confirm_provider', 'cancel'],
            );
        }
        if ($state === 'waiting_budget_confirmation') {
            $inbox[] = $this->decision(
                key: 'authorize_budget',
                label: 'Autorizar custo',
                reason: 'Antes de qualquer chamada paga, custo precisa de aprovacao explicita.',
                risk: 'high',
                recommendedAction: 'confirm_budget',
                allowedActions: ['confirm_budget', 'cancel'],
            );
        }
        if ($state === 'waiting_runtime_dispatch_confirmation') {
            $inbox[] = $this->decision(
                key: 'confirm_dispatch',
                label: 'Confirmar Runtime Dispatch',
                reason: 'Dispatch precisa ser explicitamente confirmado antes da invocacao do provider.',
                risk: 'medium',
                recommendedAction: 'confirm_runtime_dispatch',
                allowedActions: ['confirm_runtime_dispatch', 'cancel'],
            );
        }
        if (in_array($state, ['waiting_review'], true)
            || (bool) ($signals['review_required'] ?? false)) {
            $inbox[] = $this->decision(
                key: 'review_result',
                label: 'Revisar resultado',
                reason: 'O Forge terminou e precisa de decisao humana antes de qualquer completion.',
                risk: 'medium',
                recommendedAction: 'open_review',
                allowedActions: ['approve', 'reject', 'rollback'],
            );
        }
        if ($state === 'repair_required') {
            $inbox[] = $this->decision(
                key: 'plan_repair',
                label: 'Planejar reparo',
                reason: 'Atlas detectou falha reparavel. Planejar patch minimo antes de retentar.',
                risk: 'medium',
                recommendedAction: 'plan_repair',
                allowedActions: ['plan_repair', 'open_review'],
            );
        }
        if ($state === 'blocked_capacity') {
            $inbox[] = $this->decision(
                key: 'accept_capacity_risk',
                label: 'Aguardar capacity ou ajustar estrategia',
                reason: 'Nenhum provider tem capacity neste momento. Atlas Decide bloqueia honestamente.',
                risk: 'low',
                recommendedAction: 'wait_or_change_strategy',
                allowedActions: ['wait', 'open_advanced'],
            );
        }
        if (in_array($state, ['blocked_provider', 'blocked_driver', 'blocked_governance'], true)) {
            $inbox[] = $this->decision(
                key: 'inspect_blocker',
                label: 'Inspecionar bloqueio governado',
                reason: 'Forge bloqueado honestamente pelo runtime. Inspecionar antes de retomar.',
                risk: 'medium',
                recommendedAction: 'open_advanced',
                allowedActions: ['open_advanced'],
            );
        }
        if ($state === 'completed' && ! (bool) ($signals['human_approved'] ?? false)) {
            $inbox[] = $this->decision(
                key: 'confirm_completion',
                label: 'Confirmar completion humana',
                reason: 'Runtime sinalizou completion. Aprovacao humana final mantem o gate honesto.',
                risk: 'high',
                recommendedAction: 'approve_completion',
                allowedActions: ['approve_completion', 'rollback'],
            );
        }

        return $inbox;
    }

    /**
     * @param  list<string>  $allowedActions
     * @return array<string,mixed>
     */
    private function decision(
        string $key,
        string $label,
        string $reason,
        string $risk,
        string $recommendedAction,
        array $allowedActions,
    ): array {
        return [
            'key' => $key,
            'label' => $label,
            'reason' => $reason,
            'risk' => $risk,
            'recommended_action' => $recommendedAction,
            'allowed_actions' => array_values($allowedActions),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function operationalHealth(array $signals): array
    {
        $queueStaleSeconds = $signals['queue_stale_seconds'] ?? null;
        $fastPathStatus = (string) ($signals['fast_path_status'] ?? '');
        $execStatus = (string) ($signals['execution_status'] ?? '');
        $execAsyncStatus = (string) ($signals['execution_async_status'] ?? '');

        $queueStatus = match (true) {
            (bool) ($signals['queue_stale'] ?? false) => 'stale',
            in_array($execStatus, ['running'], true) || $execAsyncStatus === 'running' => 'running',
            $fastPathStatus === 'queued' || $execStatus === 'queued' => 'queued',
            $fastPathStatus === 'prepared' => 'idle',
            default => 'unknown',
        };

        $stale = (bool) ($signals['queue_stale'] ?? false);
        $heartbeat = $stale ? 'stale' : 'unknown';
        $watchdogNext = $stale ? 'wait_or_start_worker' : null;
        $human = match ($queueStatus) {
            'stale' => sprintf('A fila atlas-code-forge ficou %ds sem progresso. Aguarde ou inicie o worker em Avancado.', (int) ($queueStaleSeconds ?? 0)),
            'queued' => 'O run esta enfileirado em atlas-code-forge. Atlas nao executou nada ainda.',
            'running' => 'O Forge esta processando. Sem acao humana necessaria agora.',
            'idle' => 'Fila ociosa.',
            default => 'Estado da fila desconhecido (telemetria de worker nao exposta neste ambiente).',
        };

        return [
            'queue_name' => 'atlas-code-forge',
            'queue_status' => $queueStatus,
            'worker_status' => 'unknown',
            'last_event_at' => null,
            'current_state_age_seconds' => $queueStaleSeconds !== null ? (int) $queueStaleSeconds : null,
            'heartbeat_status' => $heartbeat,
            'stale' => $stale,
            'watchdog_next_action' => $watchdogNext,
            'human_message' => $human,
            'note' => 'Operational health honesto: campos nao instrumentados aparecem como unknown, nunca como verde.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function unknownOperationalHealth(): array
    {
        return [
            'queue_name' => 'atlas-code-forge',
            'queue_status' => 'unknown',
            'worker_status' => 'unknown',
            'last_event_at' => null,
            'current_state_age_seconds' => null,
            'heartbeat_status' => 'unknown',
            'stale' => false,
            'watchdog_next_action' => null,
            'human_message' => 'Sem Obra ativa — nao ha fila a inspecionar.',
            'note' => 'Operational health unknown honesto.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function trustSummary(array $signals): array
    {
        $evidence = (int) ($signals['evidence_ref_count'] ?? 0);
        $ledger = (int) ($signals['ledger_event_count'] ?? 0);
        $executionStatus = (string) ($signals['execution_status'] ?? '');
        $reviewStatus = (string) ($signals['review_status'] ?? 'pending');
        $completionStatus = (string) ($signals['completion_status'] ?? 'not_allowed');
        $humanApproved = (bool) ($signals['human_approved'] ?? false);

        $strength = match (true) {
            $evidence === 0 && $ledger === 0 => 'none',
            ($evidence + $ledger) < 3 => 'weak',
            ($evidence + $ledger) < 10 => 'partial',
            default => 'strong',
        };

        $missing = [];
        if (! in_array($executionStatus, ['passed', 'degraded'], true)) {
            $missing[] = 'execution_passed';
        }
        if ($reviewStatus !== 'approved') {
            $missing[] = 'review_approved';
        }
        if (! $humanApproved) {
            $missing[] = 'human_approval';
        }
        if ($evidence === 0) {
            $missing[] = 'evidence_refs';
        }

        $riskLevel = match (true) {
            $strength === 'none' => 'high',
            $strength === 'weak' => 'medium',
            $strength === 'partial' => 'medium',
            default => 'low',
        };

        return [
            'evidence_strength' => $strength,
            'tests_run_count' => 0,
            'receipts_count' => 0,
            'ledger_events_count' => $ledger,
            'missing_evidence' => array_values(array_unique($missing)),
            'risk_level' => $riskLevel,
            'claim_status' => $completionStatus,
            'review_status' => $reviewStatus,
            'provider_external_call' => false,
            'token_spend' => false,
            'note' => 'Trust summary derivado do read-model. Atlas nunca infla evidencia: ausente vira missing_evidence honesto.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceDigest(AtlasProject $project, array $signals): array
    {
        $evidence = (int) ($signals['evidence_ref_count'] ?? 0);
        $ledger = (int) ($signals['ledger_event_count'] ?? 0);
        $latest = null;
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $candidates = [
            data_get($metadata, 'latest_forge_live_execution.completed_at'),
            data_get($metadata, 'latest_atlas_code_forge_fast_path_run.completed_at'),
            data_get($metadata, 'latest_atlas_code_forge_completion_claim.recorded_at'),
        ];
        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }
            try {
                $latest = Carbon::parse($candidate)->toIso8601String();
                break;
            } catch (\Throwable) {
                continue;
            }
        }

        return [
            'obra_evidence_ref_count' => $evidence,
            'obra_ledger_event_count' => $ledger,
            'latest_evidence_at' => $latest,
            'system_certifications_separated' => true,
            'note' => 'Provas desta Obra sao distintas das Certificacoes do sistema Atlas. Certificacoes do sistema vivem em /api/atlas-code/certification e em professional-completion-audit.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blockerSummary(string $state, array $ux, array $signals): array
    {
        $blockers = (array) ($ux['blockers'] ?? []);
        $translation = (array) ($ux['blocker_translation'] ?? []);
        $kinds = [];
        foreach ($blockers as $b) {
            if (is_string($b) && $b !== '') {
                $kinds[] = $b;
            }
        }
        $primaryKind = $translation['kind'] ?? null;
        $primaryTitle = $translation['human_title'] ?? null;
        if ($primaryKind === null && $kinds !== []) {
            $primaryKind = $kinds[0];
            $primaryTitle = $kinds[0];
        }

        return [
            'count' => count($kinds),
            'kinds' => array_values(array_unique($kinds)),
            'primary_kind' => $primaryKind,
            'primary_human_title' => $primaryTitle,
        ];
    }

    /**
     * @param  array<string,mixed>  $blockerSummary
     */
    private function resolveStatus(string $state, array $blockerSummary): string
    {
        if ($state === 'no_obra') {
            return self::STATUS_NO_OBRA;
        }
        $count = (int) ($blockerSummary['count'] ?? 0);
        if ($count > 0 && str_starts_with($state, 'blocked')) {
            return self::STATUS_BLOCKED;
        }

        return self::STATUS_OK;
    }

    /**
     * @param  array<string,mixed>  $ux
     * @return array<string,mixed>
     */
    private function advancedRefs(?string $obraId, array $ux): array
    {
        $uxRefs = (array) ($ux['advanced_refs'] ?? []);

        return [
            'forge_ux_orchestrator_endpoint' => $obraId === null
                ? 'GET /atlas-code/works/{project}/forge/ux-orchestrator'
                : sprintf('GET /atlas-code/works/%s/forge/ux-orchestrator', $obraId),
            'forge_state_endpoint' => $obraId === null
                ? 'GET /atlas-code/works/{project}/state'
                : sprintf('GET /atlas-code/works/%s/state', $obraId),
            'command_center_endpoint' => $obraId === null
                ? 'GET /atlas-code/works/{project}/obra-command-center'
                : sprintf('GET /atlas-code/works/%s/obra-command-center', $obraId),
            'command_center_cli' => 'php artisan atlas:code:obra-command-center --obra=<uuid> --json --strict',
            'forge_ux_cli' => 'php artisan atlas:code:forge-ux --obra=<uuid> --json',
            'completion_audit_cli' => 'php artisan atlas:programming:completion-audit --json',
            'forge_topology_id' => $uxRefs['forge_provider_topology_id'] ?? null,
            'forge_runtime_dispatch_id' => $uxRefs['forge_runtime_dispatch_id'] ?? null,
            'forge_provider_invocation_id' => $uxRefs['forge_provider_invocation_id'] ?? null,
        ];
    }

    /**
     * @return list<string>
     */
    private function chatMessageKinds(): array
    {
        return self::canonicalChatMessageKinds();
    }

    private function phaseEvidenceCount(string $phase, array $signals): int
    {
        return match ($phase) {
            self::PHASE_PROOFS, self::PHASE_BUILD => (int) ($signals['evidence_ref_count'] ?? 0),
            self::PHASE_DECISION => (int) ($signals['ledger_event_count'] ?? 0),
            default => 0,
        };
    }

    private function phaseBlockerCount(string $phase, string $state, array $signals): int
    {
        return match ($phase) {
            self::PHASE_INTAKE => count((array) ($signals['intake_blockers'] ?? []))
                + count((array) ($signals['intake_missing_fields'] ?? [])),
            self::PHASE_BUILD => $state === 'blocked_scope' || $state === 'blocked_governance' || $state === 'failed' ? 1 : 0,
            self::PHASE_FORGE_PREP => in_array($state, ['blocked_provider', 'blocked_driver', 'blocked_capacity'], true) ? 1 : 0,
            self::PHASE_REVIEW => $state === 'waiting_review' ? 1 : 0,
            default => 0,
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
