<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use App\Services\Ai\Policy\PolicyCanon;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Atlas Autonomy Admission — Patamar 4 · 4.1 (composer fino).
 *
 * NÃO é fonte de verdade. É camada de composição que une o Constitutional
 * Kernel (4.0) ao stack `App\Services\Ai\Policy\*` (`PolicyCanon`,
 * `PermissionGateService`, `RiskAssessmentService`, `BudgetEnvelopeService`,
 * `ApprovalRequestService`) numa única pergunta canônica:
 *
 *   "Esse ator pode executar essa mudança autonomamente agora?
 *    Se não, qual o gap (pétreo, autonomy, risk, approval)?"
 *
 * Consumidores Patamar 4 (ASCB, ADML, Reconciliation, Swarm Conductor,
 * ACMF, TEOS-I4) chamam este admit() ao invés de orquestrar 5 serviços
 * por conta própria. Isso elimina drift de lógica entre consumers.
 *
 * Authority doc:
 *   docs/engineering-knowledge-base/atlas-autonomy-admission.md
 *
 * Schemas:
 *   - atlas.autonomy_admission.envelope.v1
 *   - atlas.autonomy_admission.ticket.v1
 *
 * Invariantes:
 *   - Constitutional Kernel é consultado SEMPRE primeiro (fail-closed pétreo);
 *   - Autonomy levels canônicos vêm de PolicyCanon (nunca redefinir aqui);
 *   - Append-only ticket log para auditoria;
 *   - decisão deterministica para input idêntico (sem random).
 */
final class AtlasAutonomyAdmissionService
{
    public const ENVELOPE_SCHEMA = 'atlas.autonomy_admission.envelope.v1';

    public const TICKET_SCHEMA = 'atlas.autonomy_admission.ticket.v1';

    public const DECISION_ALLOW_AUTONOMOUS = 'allow_autonomous';

    public const DECISION_ALLOW_WITH_APPROVAL = 'allow_with_approval';

    public const DECISION_DENY = 'deny';

    public const VALID_DECISIONS = [
        self::DECISION_ALLOW_AUTONOMOUS,
        self::DECISION_ALLOW_WITH_APPROVAL,
        self::DECISION_DENY,
    ];

    /**
     * Mapeamento canon de risk_level → autonomy máxima permitida para esse risco.
     * Reusa PolicyCanon::RISK_LEVELS / AUTONOMY_LEVELS — não redefine.
     *
     * NOTE: this is the cap for the per-change-CLASS ladder path (re-bound strictly
     * under it via min()). The GLOBAL operator-trust modifier (applyTrustModifier) may
     * still shift the effective autonomy by ±1 tier — a window SANCTIONED by the kernel
     * invariant `admission_trust_modifier_window`. So on the change_class-ABSENT path a
     * `high` trust band can sit one tier above this value BY KERNEL DESIGN, not by leak.
     * Turning this into an absolute ceiling for ALL paths is an operator policy decision
     * (it would contradict that kernel ±1 window), not a bug-fix.
     *
     * @var array<string,string>
     */
    private const RISK_TO_MAX_AUTONOMY = [
        PolicyCanon::RISK_LOW => PolicyCanon::AUTONOMY_AUTONOMOUS,
        PolicyCanon::RISK_MEDIUM => PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL,
        PolicyCanon::RISK_HIGH => PolicyCanon::AUTONOMY_DRAFT,
        PolicyCanon::RISK_CRITICAL => PolicyCanon::AUTONOMY_SUGGEST,
    ];

    /**
     * Rank explícito de autonomia. Menor = mais conservador.
     *
     * @var array<string,int>
     */
    private const AUTONOMY_RANK = [
        PolicyCanon::AUTONOMY_SUGGEST => 1,
        PolicyCanon::AUTONOMY_DRAFT => 2,
        PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL => 3,
        PolicyCanon::AUTONOMY_AUTONOMOUS => 4,
    ];

    public const TRUST_BAND_HIGH = 'high';

    public const TRUST_BAND_MEDIUM = 'medium';

    public const TRUST_BAND_LOW = 'low';

    private ?string $ticketsLogOverride = null;

    private ?AtlasSelfImprovementHumanTrustLedgerService $trustLedger = null;

    private ?AtlasChangeClassTrustLadder $classLadder = null;

    public function __construct(
        private readonly AtlasConstitutionalKernelService $kernel,
    ) {}

    /**
     * Optional Trust Ledger seam. When wired, Admission factors the operator
     * trust track-record into autonomy decisions: high trust may unlock
     * autonomous for low-risk changes; low trust caps to draft regardless.
     */
    public function setTrustLedger(?AtlasSelfImprovementHumanTrustLedgerService $ledger): void
    {
        $this->trustLedger = $ledger;
    }

    /**
     * Optional Self-Construction trust-ladder seam. When wired and a change carries
     * a `change_class`, Admission re-binds the max autonomy under the risk-canon cap
     * and lets the class relax friction only as it earns re-checkable evidence —
     * defaulting to max friction. Structurally cannot exceed the risk cap.
     */
    public function setChangeClassLadder(?AtlasChangeClassTrustLadder $ladder): void
    {
        $this->classLadder = $ladder;
    }

    public function setTicketsLogPathForTesting(?string $path): void
    {
        $this->ticketsLogOverride = $path;
    }

    public function ticketsLogPath(): string
    {
        if ($this->ticketsLogOverride !== null) {
            return $this->ticketsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/governance')
            : sys_get_temp_dir().'/atlas/governance';

        return $base.DIRECTORY_SEPARATOR.'autonomy_admissions.jsonl';
    }

    /**
     * Composição canônica. Retorna envelope determinístico + grava ticket.
     *
     * @param  array<string,mixed>  $change
     * @return array<string,mixed>
     */
    public function admit(array $change): array
    {
        $actor = (string) ($change['actor'] ?? 'unknown');
        $requestedAutonomy = (string) ($change['requested_autonomy'] ?? PolicyCanon::AUTONOMY_SUGGEST);
        if (! in_array($requestedAutonomy, PolicyCanon::AUTONOMY_LEVELS, true)) {
            throw new InvalidArgumentException("Unknown requested_autonomy '{$requestedAutonomy}'.");
        }

        // 1. Constitutional Kernel — pétreo gate primeiro.
        $kernelEnv = $this->kernel->validateChange($change);

        // 2. Derivar risk_level (canon PolicyCanon).
        $riskLevel = $this->deriveRiskLevel($change);

        // 3. Max autonomy permitida pelo risco.
        $maxAutonomy = self::RISK_TO_MAX_AUTONOMY[$riskLevel] ?? PolicyCanon::AUTONOMY_SUGGEST;

        // 3.5 Trust Ledger modifier (when wired).
        $trustBand = $this->queryTrustBand();
        $maxAutonomy = $this->applyTrustModifier($maxAutonomy, $trustBand);

        // 3.6 Per-change-class trust ladder (Self-Construction) — when wired and the
        //     change carries a change_class, RE-BIND the cap under the risk-canon
        //     ceiling and let the class relax friction only as it earns re-checkable
        //     evidence. Default = max friction. Structurally cannot exceed the canon
        //     cap (min with the un-lifted risk canon).
        $changeClass = trim((string) ($change['change_class'] ?? ''));
        $changeClassEarned = '';
        if ($this->classLadder !== null && $changeClass !== '') {
            $canonCap = self::RISK_TO_MAX_AUTONOMY[$riskLevel] ?? PolicyCanon::AUTONOMY_SUGGEST;
            $changeClassEarned = $this->classLadder->earnedAutonomy($changeClass);
            $maxAutonomy = $this->minAutonomy($canonCap, $changeClassEarned);
        }

        // 4. Compor decisão.
        $gaps = [];
        $decision = $this->composeDecision($kernelEnv, $requestedAutonomy, $maxAutonomy, $gaps);

        $effectiveAutonomy = $this->effectiveAutonomy($decision, $requestedAutonomy, $maxAutonomy);

        $envelope = [
            'schema_version' => self::ENVELOPE_SCHEMA,
            'decision' => $decision,
            'actor' => $actor,
            'change_kind' => (string) ($change['change_kind'] ?? ''),
            'requested_autonomy' => $requestedAutonomy,
            'effective_autonomy' => $effectiveAutonomy,
            'risk_level' => $riskLevel,
            'max_autonomy_for_risk' => $maxAutonomy,
            'trust_band' => $trustBand,
            'change_class' => $changeClass,
            'change_class_earned_autonomy' => $changeClassEarned,
            'kernel_decision' => $kernelEnv['decision'],
            'kernel_violations' => $kernelEnv['violations'] ?? [],
            'kernel_required_approvals' => $kernelEnv['required_approvals'] ?? [],
            'gaps' => array_values(array_unique($gaps)),
            'requires_human_approval' => $decision !== self::DECISION_ALLOW_AUTONOMOUS,
            'kernel_hash' => $kernelEnv['kernel_hash'] ?? $this->kernel->kernelHash(),
        ];
        $envelope['envelope_hash'] = $this->envelopeHash($envelope);

        $this->writeTicket($envelope);

        return $envelope;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listTickets(): array
    {
        return AppendOnlyJsonlStore::read($this->ticketsLogPath());
    }

    // ---------- internals ----------

    /**
     * Returns 'high'|'medium'|'low'|'unknown' based on the trust ledger snapshot
     * for the global (no project) bucket. Returns 'unknown' when the ledger is
     * not wired or the snapshot cannot be computed.
     */
    private function queryTrustBand(): string
    {
        if ($this->trustLedger === null) {
            return 'unknown';
        }
        try {
            $snap = $this->trustLedger->snapshot(null);
            $band = (string) ($snap['summary']['trust_band'] ?? 'unknown');
            if (in_array($band, [self::TRUST_BAND_HIGH, self::TRUST_BAND_MEDIUM, self::TRUST_BAND_LOW], true)) {
                return $band;
            }
        } catch (\Throwable $e) {
            // ledger may require DB tables not available in unit tests — gracefully degrade
        }

        return 'unknown';
    }

    /**
     * The lower-friction-of-two autonomy levels — i.e. the MORE restrictive (lower
     * rank). Used to re-bind the class ladder under the risk-canon cap.
     */
    private function minAutonomy(string $a, string $b): string
    {
        return (self::AUTONOMY_RANK[$a] ?? 1) <= (self::AUTONOMY_RANK[$b] ?? 1) ? $a : $b;
    }

    /**
     * Apply trust modifier to the max autonomy computed from risk.
     *  - high trust   : lift cap one tier up (e.g. medium-risk → autonomous allowed)
     *  - medium/unknown : no change
     *  - low trust    : lower cap one tier (e.g. low-risk → execute_with_approval cap)
     */
    private function applyTrustModifier(string $maxAutonomy, string $trustBand): string
    {
        // NOTE: the high-trust +1 lift can raise autonomy one tier ABOVE the per-risk
        // cap. This is sanctioned by the Constitutional Kernel runtime invariant
        // `admission_trust_modifier_window = ±1 tier`; clamping it to the cap is an
        // OPERATOR POLICY decision (it would contradict that invariant), not a fix.
        // The per-change-class ladder (3.6) re-binds under the un-lifted canon for
        // change_class-bearing changes without touching this kernel-sanctioned window.
        $rank = self::AUTONOMY_RANK[$maxAutonomy] ?? 1;
        if ($trustBand === self::TRUST_BAND_HIGH) {
            $rank = min(self::AUTONOMY_RANK[PolicyCanon::AUTONOMY_AUTONOMOUS], $rank + 1);
        } elseif ($trustBand === self::TRUST_BAND_LOW) {
            $rank = max(self::AUTONOMY_RANK[PolicyCanon::AUTONOMY_SUGGEST], $rank - 1);
        }
        $found = array_search($rank, self::AUTONOMY_RANK, true);

        return $found ?: $maxAutonomy;
    }

    /**
     * Deriva risk_level canon (low/medium/high/critical) a partir do change.
     * Heurística determinística baseada em campos do envelope; não chama DB.
     */
    private function deriveRiskLevel(array $change): string
    {
        if (isset($change['risk_level']) && in_array((string) $change['risk_level'], PolicyCanon::RISK_LEVELS, true)) {
            return (string) $change['risk_level'];
        }
        $scope = (array) ($change['scope'] ?? []);
        $privacy = (string) ($scope['privacy_class'] ?? '');

        // Classes sensitive/secret/cyber elevam risco.
        if ($privacy === 'cyber' || $privacy === 'secret') {
            return PolicyCanon::RISK_CRITICAL;
        }
        if ($privacy === 'sensitive') {
            return PolicyCanon::RISK_HIGH;
        }

        // change_kinds críticos.
        $kind = (string) ($change['change_kind'] ?? '');
        if (in_array($kind, ['schema_evolution', 'domain_bridge', 'provider_swap'], true)) {
            return PolicyCanon::RISK_MEDIUM;
        }
        if ($kind === 'subsystem_propose') {
            return PolicyCanon::RISK_MEDIUM;
        }

        return PolicyCanon::RISK_LOW;
    }

    /**
     * @param  array<string,mixed>  $kernelEnv
     * @param  list<string>  $gaps  passado por referência
     */
    private function composeDecision(array $kernelEnv, string $requested, string $maxAutonomy, array &$gaps): string
    {
        $kernelDecision = (string) ($kernelEnv['decision'] ?? AtlasConstitutionalKernelService::DECISION_BLOCK);

        // Kernel block ⇒ deny absoluto.
        if ($kernelDecision === AtlasConstitutionalKernelService::DECISION_BLOCK) {
            $gaps[] = 'petreo';

            return self::DECISION_DENY;
        }

        $requestedRank = self::AUTONOMY_RANK[$requested] ?? 1;
        $maxRank = self::AUTONOMY_RANK[$maxAutonomy] ?? 1;

        // Kernel exige aprovação humana ⇒ teto = execute_with_approval.
        if ($kernelDecision === AtlasConstitutionalKernelService::DECISION_ALLOW_WITH_APPROVAL) {
            $gaps[] = 'kernel_requires_approval';
            $maxRank = min($maxRank, self::AUTONOMY_RANK[PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL]);
        }

        // Se requested ≤ max ⇒ autorizado no nível pedido.
        if ($requestedRank <= $maxRank) {
            // Apenas AUTONOMOUS sem gaps vira full autonomous.
            if ($requested === PolicyCanon::AUTONOMY_AUTONOMOUS && $gaps === []) {
                return self::DECISION_ALLOW_AUTONOMOUS;
            }

            return self::DECISION_ALLOW_WITH_APPROVAL;
        }

        // Requested > max ⇒ downgrade obrigatório.
        $gaps[] = 'risk_exceeds_requested_autonomy';

        return self::DECISION_ALLOW_WITH_APPROVAL;
    }

    private function effectiveAutonomy(string $decision, string $requested, string $maxAutonomy): string
    {
        if ($decision === self::DECISION_DENY) {
            return PolicyCanon::AUTONOMY_SUGGEST;
        }
        if ($decision === self::DECISION_ALLOW_AUTONOMOUS) {
            return $requested;
        }
        // allow_with_approval ⇒ min(requested, max_autonomy_for_risk, execute_with_approval).
        $cap = self::AUTONOMY_RANK[PolicyCanon::AUTONOMY_EXECUTE_WITH_APPROVAL];
        $maxRank = self::AUTONOMY_RANK[$maxAutonomy] ?? 1;
        $reqRank = self::AUTONOMY_RANK[$requested] ?? 1;
        $finalRank = min($cap, $maxRank, $reqRank);

        return array_search($finalRank, self::AUTONOMY_RANK, true) ?: PolicyCanon::AUTONOMY_DRAFT;
    }

    private function envelopeHash(array $envelope): string
    {
        $canonical = [
            'schema' => self::ENVELOPE_SCHEMA,
            'decision' => $envelope['decision'],
            'change_kind' => $envelope['change_kind'],
            'requested_autonomy' => $envelope['requested_autonomy'],
            'effective_autonomy' => $envelope['effective_autonomy'],
            'risk_level' => $envelope['risk_level'],
            'kernel_decision' => $envelope['kernel_decision'],
            'gaps' => $envelope['gaps'],
            'kernel_hash' => $envelope['kernel_hash'],
        ];

        return 'sha256:'.hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    private function writeTicket(array $envelope): void
    {
        $ticket = [
            'schema_version' => self::TICKET_SCHEMA,
            'recorded_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'envelope' => $envelope,
        ];
        AppendOnlyJsonlStore::append($this->ticketsLogPath(), $ticket);
    }
}
