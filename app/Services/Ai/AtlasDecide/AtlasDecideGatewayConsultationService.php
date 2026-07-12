<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Kernel\Decision\DecisionReceiptIssuer;
use App\Services\Ai\Kernel\Envelope\OperationEnvelopeFactory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Atlas Decide Gateway Consultation Service — Patamar 4 wiring.
 *
 * Thin consultation hook the AiGatewayService calls before each provider
 * resolution to learn whether ADML has an active learned route for the
 * (task_category, role, framework) scope. The gateway remains the
 * authority that actually selects the provider; this service only emits
 * a learned recommendation + Kernel/Admission gates.
 *
 * Doc: scaffold; canonical doc to land alongside gateway integration PR.
 *
 * Invariants:
 *   - never calls a provider;
 *   - returns null when ADML has no actionable signal;
 *   - persists every consultation as append-only ticket;
 *   - claim_policy provider-safe enforced via the Kernel gate.
 *
 * Schemas:
 *   - atlas.atlas_decide.gateway_consultation.v1
 */
final class AtlasDecideGatewayConsultationService
{
    public const ENVELOPE_SCHEMA = 'atlas.atlas_decide.gateway_consultation.v1';

    public const VERDICT_FOLLOW_LEARNED = 'follow_learned_route';

    public const VERDICT_FREE_TO_CHOOSE = 'free_to_choose';

    public const VERDICT_ABSTAINED_UNCERTAIN = 'abstained_uncertain';

    public const VERDICT_REQUIRES_APPROVAL = 'requires_approval';

    public const VERDICT_BLOCKED = 'blocked';

    private const MULTK04_FORMULA_VERSION = 'atlas.decide.abstention_uncertainty.v1';

    private const MULTK04_LOWER_BOUND_FLOOR = 0.8;

    private const MULTK04_WIDTH_CEILING = 0.35;

    private ?string $logPathOverride = null;

    public function __construct(
        private readonly AtlasDecideMetaLearningService $adml,
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomyAdmissionService $admission,
        private readonly ?OperationEnvelopeFactory $envelopes = null,
        private readonly ?DecisionReceiptIssuer $receiptIssuer = null,
        private readonly ?AtlasEvidenceLedger $ledger = null,
    ) {}

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/atlas_decide')
            : sys_get_temp_dir().'/atlas/atlas_decide';

        return $base.DIRECTORY_SEPARATOR.'gateway_consultations.jsonl';
    }

    /**
     * Consult ADML + Kernel + Admission before a provider call.
     *
     * @param  array{task_category:string, role:string, framework?:?string, privacy_class?:string, actor?:string}  $context
     * @return array<string,mixed>
     */
    public function consult(array $context): array
    {
        $taskCategory = (string) ($context['task_category'] ?? '');
        $role = (string) ($context['role'] ?? '');
        $framework = $context['framework'] ?? null;
        if ($framework === '') {
            $framework = null;
        }
        $privacy = (string) ($context['privacy_class'] ?? 'normal');
        $actor = (string) ($context['actor'] ?? 'ai_gateway');

        $activeRoute = ($taskCategory !== '' && $role !== '')
            ? $this->adml->activeRouteFor($taskCategory, $role, $framework)
            : null;

        // Pétreo gate: any provider routing that touches sensitive/secret/cyber must be reviewed.
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'gateway_provider_route',
            'proposed_effect' => sprintf('route provider call for task=%s role=%s framework=%s', $taskCategory, $role, $framework ?? 'null'),
            'scope' => ['privacy_class' => $privacy],
            'actor' => $actor,
        ]);

        // MULTK-07 — the request handed to Admission is DERIVED from evidence
        // (privacy_class, ASI-11 lineage reversal rate, sample size n)
        // MONOTONICALLY DOWNWARD from `'autonomous'` when the flag is on.
        // Default-OFF: byte-identical to the previous hardcoded literal.
        // Admission remains the ONLY authority — this changes the REQUEST,
        // never the verdict; floors from area 18 continue to win.
        $requestedAutonomy = $this->deriveRequestedAutonomy($context, $privacy);

        // Admission decides if the gateway can autonomously follow the learned route.
        $admissionEnv = $this->admission->admit([
            'change_kind' => 'gateway_provider_route',
            'proposed_effect' => 'follow ADML active route',
            'scope' => ['privacy_class' => $privacy],
            'actor' => $actor,
            'requested_autonomy' => $requestedAutonomy['level'],
        ]);

        $abstention = $this->uncertaintyAbstention($context);
        $verdict = $this->deriveVerdict($activeRoute, $kernelEnv['decision'], $admissionEnv['decision'], $abstention);
        $kernelHash = (string) ($kernelEnv['kernel_hash'] ?? $this->kernel->kernelHash());
        $routingBasis = $this->routingBasis($activeRoute, $verdict);
        $evidenceRefs = $this->evidenceRefs($activeRoute, (string) $kernelEnv['decision'], (string) $admissionEnv['decision'], $kernelHash);

        $envelope = [
            'schema_version' => self::ENVELOPE_SCHEMA,
            'consulted_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
            'scope' => [
                'task_category' => $taskCategory,
                'role' => $role,
                'framework' => $framework,
                'privacy_class' => $privacy,
            ],
            'verdict' => $verdict,
            'active_route' => $activeRoute,
            'routing_basis' => $routingBasis,
            'evidence_refs' => $evidenceRefs,
            'kernel_decision' => $kernelEnv['decision'],
            'admission_decision' => $admissionEnv['decision'],
            'kernel_hash' => $kernelHash,
            'requested_autonomy' => $requestedAutonomy,
        ];
        if ($abstention !== null) {
            $envelope['abstention'] = $abstention;
            $envelope['operational_equivalent'] = self::VERDICT_FREE_TO_CHOOSE;
        }
        $envelope['envelope_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::ENVELOPE_SCHEMA,
            'task_category' => $taskCategory,
            'role' => $role,
            'framework' => $framework,
            'verdict' => $verdict,
            'active_route_provider' => $activeRoute['provider'] ?? null,
            'kernel_decision' => $envelope['kernel_decision'],
        ], JSON_THROW_ON_ERROR));

        $receipt = $this->issueDecisionReceipt($context, $envelope, $routingBasis, $evidenceRefs);
        $envelope['decision_id'] = $receipt['receipt_id'];
        $envelope['decision_receipt'] = $receipt;

        AppendOnlyJsonlStore::append($this->logPath(), $envelope);

        return $envelope;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listConsultations(): array
    {
        return AppendOnlyJsonlStore::read($this->logPath());
    }

    // ---------- internals ----------

    /**
     * MULTK-07 — return the `requested_autonomy` handed to Admission.
     * Default-OFF (config `atlas.atlas_decide.requested_autonomy_shrink_enabled`):
     * hardcoded `'autonomous'` — byte-identical to the pre-MULTK-07 path.
     * ON: pure derivation from evidence, monotonically ≤ `'autonomous'`.
     *
     * @param  array<string,mixed>  $context
     * @return array{level:string, derivation:array<string,mixed>|null}
     */
    private function deriveRequestedAutonomy(array $context, string $privacy): array
    {
        $flagOn = (bool) config('atlas.atlas_decide.requested_autonomy_shrink_enabled', false);
        if (! $flagOn) {
            return ['level' => 'autonomous', 'derivation' => null];
        }

        $derivation = RequestedAutonomyDerivation::derive([
            'privacy_class' => $privacy,
            'reversal_rate' => $context['reversal_rate'] ?? null,
            'n' => $context['reversal_sample_size'] ?? null,
            'ceiling' => 'autonomous',
        ]);

        return [
            'level' => $derivation['requested_autonomy'],
            'derivation' => $derivation,
        ];
    }

    private function deriveVerdict(?array $activeRoute, string $kernelDecision, string $admissionDecision, ?array $abstention = null): string
    {
        if ($kernelDecision === AtlasConstitutionalKernelService::DECISION_BLOCK
            || $admissionDecision === AtlasAutonomyAdmissionService::DECISION_DENY) {
            return self::VERDICT_BLOCKED;
        }
        if ($abstention !== null) {
            return self::VERDICT_ABSTAINED_UNCERTAIN;
        }
        if ($activeRoute === null || empty($activeRoute['provider'])) {
            return self::VERDICT_FREE_TO_CHOOSE;
        }
        if ($admissionDecision === AtlasAutonomyAdmissionService::DECISION_ALLOW_AUTONOMOUS) {
            return self::VERDICT_FOLLOW_LEARNED;
        }

        return self::VERDICT_REQUIRES_APPROVAL;
    }

    /**
     * MULTK-04 — explicit abstention when every measured candidate remains below
     * the lower-bound floor and the evidence is still wide. This never blocks;
     * it only names the same operational path that used to be an unexplained
     * `free_to_choose`.
     *
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>|null
     */
    private function uncertaintyAbstention(array $context): ?array
    {
        $candidates = (array) ($context['uncertainty_candidates'] ?? []);
        if ($candidates === []) {
            return null;
        }

        $measured = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $interval = (array) ($candidate['uncertainty_interval'] ?? $candidate['interval'] ?? []);
            if (($interval['status'] ?? null) !== 'ok'
                || ! is_numeric($interval['lower_bound'] ?? null)
                || ! is_numeric($interval['upper_bound'] ?? null)) {
                continue;
            }
            $lower = max(0.0, min(1.0, (float) $interval['lower_bound']));
            $upper = max($lower, min(1.0, (float) $interval['upper_bound']));
            $measured[] = [
                'provider' => (string) ($candidate['provider'] ?? 'unknown'),
                'model' => (string) ($candidate['model'] ?? 'unknown'),
                'lower_bound' => round($lower, 6),
                'upper_bound' => round($upper, 6),
                'width' => round($upper - $lower, 6),
            ];
        }

        if ($measured === []) {
            return null;
        }

        foreach ($measured as $candidate) {
            if ((float) $candidate['lower_bound'] >= self::MULTK04_LOWER_BOUND_FLOOR) {
                return null;
            }
        }

        $maxWidth = max(array_map(static fn (array $candidate): float => (float) $candidate['width'], $measured));
        if ($maxWidth <= self::MULTK04_WIDTH_CEILING) {
            return null;
        }

        return [
            'schema_version' => self::MULTK04_FORMULA_VERSION,
            'status' => self::VERDICT_ABSTAINED_UNCERTAIN,
            'reason' => 'all_candidates_below_floor_with_wide_uncertainty',
            'operational_effect' => 'continue_gateway_default',
            'operational_equivalent' => self::VERDICT_FREE_TO_CHOOSE,
            'lower_bound_floor' => self::MULTK04_LOWER_BOUND_FLOOR,
            'width_ceiling' => self::MULTK04_WIDTH_CEILING,
            'candidate_count' => count($measured),
            'max_interval_width' => round($maxWidth, 6),
            'candidates' => $measured,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $consultation
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function issueDecisionReceipt(array $context, array $consultation, string $routingBasis, array $evidenceRefs): array
    {
        $scope = (array) ($consultation['scope'] ?? []);
        $actor = (string) ($context['actor'] ?? 'ai_gateway');
        $privacy = (string) ($scope['privacy_class'] ?? 'normal');
        $activeRoute = is_array($consultation['active_route'] ?? null) ? $consultation['active_route'] : [];
        $provider = trim((string) ($activeRoute['provider'] ?? 'gateway_default')) ?: 'gateway_default';
        $model = trim((string) ($activeRoute['model'] ?? 'selected-by-gateway')) ?: 'selected-by-gateway';
        $decisionId = $this->decisionId($consultation, $routingBasis, $evidenceRefs);

        $operationEnvelope = $this->envelopeFactory()->create([
            'operator' => [
                'tenant_id' => (string) ($context['tenant_id'] ?? 'atlas_decide'),
                'operator_id' => $actor,
                'default_privacy' => $privacy,
            ],
            'origin' => [
                'surface_id' => 'atlas_decide_gateway_consultation',
                'surface_version' => self::ENVELOPE_SCHEMA,
                'session_id' => (string) ($context['session_id'] ?? $decisionId),
            ],
            'input' => [
                'primary_type' => 'routing_decision',
                'text' => sprintf(
                    'task_category=%s role=%s framework=%s verdict=%s',
                    (string) ($scope['task_category'] ?? ''),
                    (string) ($scope['role'] ?? ''),
                    (string) ($scope['framework'] ?? 'null'),
                    (string) ($consultation['verdict'] ?? ''),
                ),
                'hints' => [
                    'domain' => 'atlas_decide',
                    'flow' => 'gateway_consultation',
                    'routing_basis' => $routingBasis,
                    'privacy_class' => $privacy,
                ],
                'locale' => 'pt-BR',
            ],
            'trace_id' => (string) ($consultation['envelope_hash'] ?? $decisionId),
        ]);
        $operationEnvelope->routing->domain = 'atlas_decide';
        $operationEnvelope->routing->flow = 'gateway_consultation';

        $receipt = $this->receiptIssuer()->issue($operationEnvelope, [
            'receipt_id' => $decisionId,
            'signed_by' => 'atlas.decide.gateway_consultation.v2',
            'domain' => 'atlas_decide',
            'flow' => 'gateway_consultation',
            'risk' => $this->riskForPrivacy($privacy),
            'provider_selection' => [
                'primary' => $provider,
                'model' => $model,
                'fallbacks' => $this->fallbacks($activeRoute),
                'selection_mode' => 'auto_best_allowed',
                'selection_reason' => 'Atlas Decide gateway consultation route decision.',
                'selection_explanation' => [
                    'routing_basis' => $routingBasis,
                    'verdict' => $consultation['verdict'] ?? null,
                    'kernel_decision' => $consultation['kernel_decision'] ?? null,
                    'admission_decision' => $consultation['admission_decision'] ?? null,
                ],
            ],
            'budgets' => [
                'max_cost_usd' => 0,
            ],
            'required_gates' => ['constitutional_kernel', 'autonomy_admission'],
            'required_evidence' => $evidenceRefs,
            'repair_policy' => [
                'enabled' => false,
                'max_attempts' => 0,
            ],
            'metadata' => [
                'tenant_id' => (string) ($context['tenant_id'] ?? 'atlas_decide'),
                'operator_id' => $actor,
                'decision_id' => $decisionId,
                'routing_basis' => $routingBasis,
                'evidence_refs' => $evidenceRefs,
                'kernel_decision' => $consultation['kernel_decision'] ?? null,
                'admission_decision' => $consultation['admission_decision'] ?? null,
                'gateway_consultation_hash' => $consultation['envelope_hash'] ?? null,
            ],
        ])->toArray();

        $this->evidenceLedger()->recordDecisionIssued($receipt, [
            'tenant_id' => (string) ($context['tenant_id'] ?? 'atlas_decide'),
            'operator_id' => $actor,
            'trace_id' => $operationEnvelope->audit->traceId,
            'correlation_id' => $operationEnvelope->audit->traceId,
            'emitter_stage' => 'atlas.decide.gateway_consultation',
            'emitter_version' => self::ENVELOPE_SCHEMA,
        ]);

        return $receipt;
    }

    /** @return list<string> */
    private function evidenceRefs(?array $activeRoute, string $kernelDecision, string $admissionDecision, string $kernelHash): array
    {
        $refs = [
            'kernel_decision:'.$kernelDecision,
            'admission_decision:'.$admissionDecision,
            'kernel_hash:'.$kernelHash,
        ];

        foreach ((array) ($activeRoute['evidence_refs'] ?? []) as $ref) {
            $ref = trim((string) $ref);
            if ($ref !== '') {
                $refs[] = $ref;
            }
        }

        return array_values(array_unique($refs));
    }

    private function routingBasis(?array $activeRoute, string $verdict): string
    {
        $basis = strtolower(trim((string) ($activeRoute['routing_basis'] ?? $activeRoute['basis'] ?? '')));
        if (in_array($basis, ['score', 'cost_outcome', 'exploration'], true)) {
            return $basis;
        }
        if ($verdict === self::VERDICT_ABSTAINED_UNCERTAIN) {
            return 'abstention_uncertainty';
        }

        return $verdict === self::VERDICT_FOLLOW_LEARNED ? 'score' : 'gateway_default';
    }

    /** @param array<string,mixed> $activeRoute @return list<string> */
    private function fallbacks(array $activeRoute): array
    {
        $fallbacks = [];
        foreach ((array) ($activeRoute['fallbacks'] ?? []) as $fallback) {
            $fallback = trim((string) $fallback);
            if ($fallback !== '') {
                $fallbacks[] = $fallback;
            }
        }

        return array_values(array_unique($fallbacks));
    }

    /**
     * @param  array<string,mixed>  $consultation
     * @param  list<string>  $evidenceRefs
     */
    private function decisionId(array $consultation, string $routingBasis, array $evidenceRefs): string
    {
        return 'decision_'.substr(hash('sha256', json_encode([
            'schema' => self::ENVELOPE_SCHEMA,
            'scope' => $consultation['scope'] ?? [],
            'verdict' => $consultation['verdict'] ?? null,
            'active_route' => $consultation['active_route'] ?? null,
            'routing_basis' => $routingBasis,
            'evidence_refs' => $evidenceRefs,
            'kernel_decision' => $consultation['kernel_decision'] ?? null,
            'admission_decision' => $consultation['admission_decision'] ?? null,
        ], JSON_THROW_ON_ERROR)), 0, 32);
    }

    private function riskForPrivacy(string $privacy): string
    {
        return match (strtolower($privacy)) {
            'cyber', 'secret' => 'critical',
            'sensitive', 'private' => 'high',
            'public' => 'low',
            default => 'medium',
        };
    }

    private function envelopeFactory(): OperationEnvelopeFactory
    {
        return $this->envelopes ?? app(OperationEnvelopeFactory::class);
    }

    private function receiptIssuer(): DecisionReceiptIssuer
    {
        return $this->receiptIssuer ?? app(DecisionReceiptIssuer::class);
    }

    private function evidenceLedger(): AtlasEvidenceLedger
    {
        return $this->ledger ?? app(AtlasEvidenceLedger::class);
    }
}
