<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use App\Services\Ai\Policy\PolicyCanon;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
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

    private ?string $ticketsLogOverride = null;

    public function __construct(
        private readonly AtlasConstitutionalKernelService $kernel,
    ) {}

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
        return $this->readJsonl($this->ticketsLogPath());
    }

    // ---------- internals ----------

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
        $this->appendJsonl($this->ticketsLogPath(), $ticket);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function appendJsonl(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir)) {
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        $fp = fopen($path, 'ab');
        if ($fp === false) {
            throw new \RuntimeException("Could not open {$path} for writing.");
        }
        try {
            if (flock($fp, LOCK_EX)) {
                fwrite($fp, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL);
                fflush($fp);
                flock($fp, LOCK_UN);
            }
        } finally {
            fclose($fp);
        }
    }
}
