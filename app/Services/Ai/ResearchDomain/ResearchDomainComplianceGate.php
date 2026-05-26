<?php

declare(strict_types=1);

namespace App\Services\Ai\ResearchDomain;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Policy\PolicyCanon;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;

/**
 * Atlas Research Domain — Compliance Gate.
 *
 * Espelho ESTRUTURAL de AtlasFinanceComplianceGate. Não duplica regras
 * de fonte/claim (essas vivem em ResearchClaimService e
 * ResearchSourceQualityService) — apenas COMPÕE sobre os contratos canon
 * de ResearchDomainCanon (MIN_SOURCE_DIVERSITY, MIN_ACCEPTED_SOURCES,
 * MIN_CLAIMS) e atravessa Constitutional Kernel + Autonomy Admission para
 * cada decisão "permitir publicar claim".
 *
 * Outputs an append-only gate decision receipt at
 *   storage/atlas/research_domain/gates.jsonl
 *
 * Schema: atlas.research_domain.compliance_gate.v1
 *
 * Invariantes:
 *  - review-only sempre: nenhuma "execution intent" passa.
 *  - source_grounded=true obrigatório; sem source → bloqueia.
 *  - accepted_sources ≥ MIN_ACCEPTED_SOURCES (2).
 *  - source_diversity ≥ MIN_SOURCE_DIVERSITY (2).
 *  - claim_policy provider-safe travada.
 *  - JSONL append-only; nenhuma operação retroativa.
 */
final class ResearchDomainComplianceGate
{
    public const SCHEMA_VERSION = 'atlas.research_domain.compliance_gate.v1';

    public const OUTPUT_MODE = 'analysis_review_only';

    public const DECISION_ALLOW = 'allow';

    public const DECISION_BLOCK = 'block';

    public const DECISION_REQUIRES_EVIDENCE = 'requires_evidence';

    public const VALID_DECISIONS = [
        self::DECISION_ALLOW,
        self::DECISION_BLOCK,
        self::DECISION_REQUIRES_EVIDENCE,
    ];

    private ?string $gatesLogOverride = null;

    public function __construct(
        private readonly AtlasConstitutionalKernelService $kernel,
        private readonly AtlasAutonomyAdmissionService $admission,
    ) {}

    public function setGatesLogPathForTesting(?string $path): void
    {
        $this->gatesLogOverride = $path;
    }

    public function gatesLogPath(): string
    {
        if ($this->gatesLogOverride !== null) {
            return $this->gatesLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/research_domain')
            : sys_get_temp_dir().'/atlas/research_domain';

        return $base.DIRECTORY_SEPARATOR.'gates.jsonl';
    }

    /**
     * Evaluate a research publication plan and append a receipt.
     *
     * @param  array<string,mixed>  $plan
     * @param  array<int,string>  $requestedExecutionActions  any non-empty list = blocked execution attempt
     * @return array<string,mixed>
     */
    public function evaluate(array $plan, array $requestedExecutionActions = []): array
    {
        $reasons = $this->collectReasons($plan, $requestedExecutionActions);

        // Constitutional Kernel — pétreo gate.
        $kernelEnv = $this->kernel->validateChange([
            'change_kind' => 'research_publication',
            'proposed_effect' => 'publish_research_claims_review_only',
            'scope' => ['privacy_class' => (string) ($plan['privacy_class'] ?? 'normal')],
            'actor' => (string) ($plan['actor'] ?? 'research_domain'),
            'claims' => [],
            'outbound_data_classes' => array_map('strval', (array) ($plan['outbound_data_classes'] ?? [])),
        ]);

        // Autonomy Admission — research publications never go autonomous.
        $admissionEnv = $this->admission->admit([
            'change_kind' => 'research_publication',
            'proposed_effect' => 'publish_research_claims_review_only',
            'scope' => ['privacy_class' => (string) ($plan['privacy_class'] ?? 'normal')],
            'actor' => (string) ($plan['actor'] ?? 'research_domain'),
            'requested_autonomy' => PolicyCanon::AUTONOMY_SUGGEST,
        ]);

        // Compose final decision.
        $decision = $this->composeDecision($reasons, $kernelEnv, $requestedExecutionActions);

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'recorded_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->format(DateTimeInterface::ATOM),
            'domain' => ResearchDomainCanon::DOMAIN_ID,
            'output_mode' => self::OUTPUT_MODE,
            'decision' => $decision,
            'reasons' => $reasons,
            'actor' => (string) ($plan['actor'] ?? 'research_domain'),
            'kernel_decision' => $kernelEnv['decision'] ?? null,
            'kernel_violations' => $kernelEnv['violations'] ?? [],
            'admission_decision' => $admissionEnv['decision'] ?? null,
            'admission_effective_autonomy' => $admissionEnv['effective_autonomy'] ?? null,
            'review_only' => true,
            'execution_intent_allowed' => false,
            'claim_policy' => $this->claimPolicy(),
            'thresholds' => [
                'min_accepted_sources' => ResearchDomainCanon::MIN_ACCEPTED_SOURCES,
                'min_source_diversity' => ResearchDomainCanon::MIN_SOURCE_DIVERSITY,
                'min_claims' => ResearchDomainCanon::MIN_CLAIMS,
            ],
            'measured' => [
                'source_grounded' => (bool) ($plan['source_grounded'] ?? false),
                'accepted_sources_count' => (int) ($plan['accepted_sources_count'] ?? 0),
                'source_diversity' => (int) ($plan['source_diversity'] ?? 0),
                'claims_with_source_refs' => (int) ($plan['claims_with_source_refs'] ?? 0),
                'requested_execution_actions' => array_values(array_map('strval', $requestedExecutionActions)),
            ],
        ];
        $envelope['envelope_hash'] = $this->envelopeHash($envelope);

        $this->appendReceipt($envelope);

        return $envelope;
    }

    /**
     * @return array<string,mixed>
     */
    public function claimPolicy(): array
    {
        return [
            'benchmark_claim_allowed' => false,
            'rivals_claim_allowed' => false,
            'superiority_claim_allowed' => false,
            'external_rivals_certification_touched' => false,
            'concurrent_claim_allowed' => false,
            'cognitive_immune_law_enforced' => true,
            'provider_safe_only_enforced' => true,
            'review_only_enforced' => true,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listReceipts(): array
    {
        $path = $this->gatesLogPath();
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($lines as $line) {
            try {
                $row = json_decode($line, true, 64, JSON_THROW_ON_ERROR);
                if (is_array($row)) {
                    $out[] = $row;
                }
            } catch (JsonException) {
                // skip malformed line — append-only ledger never rewrites.
            }
        }

        return $out;
    }

    // ---------- internals ----------

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<int,string>  $requestedExecutionActions
     * @return list<string>
     */
    private function collectReasons(array $plan, array $requestedExecutionActions): array
    {
        $reasons = [];

        if (($plan['domain'] ?? null) !== ResearchDomainCanon::DOMAIN_ID) {
            $reasons[] = 'invalid_research_domain';
        }

        if (($plan['output_mode'] ?? null) !== self::OUTPUT_MODE) {
            $reasons[] = 'output_mode_must_be_analysis_review_only';
        }

        if ((bool) ($plan['execution_intent_allowed'] ?? false)) {
            $reasons[] = 'execution_intent_must_be_disabled';
        }

        if (! (bool) ($plan['compliance_gate_required'] ?? false)) {
            $reasons[] = 'research_compliance_gate_required';
        }

        if (! (bool) ($plan['source_grounded'] ?? false)) {
            $reasons[] = 'source_grounded_must_be_true';
        }

        $accepted = (int) ($plan['accepted_sources_count'] ?? 0);
        if ($accepted < ResearchDomainCanon::MIN_ACCEPTED_SOURCES) {
            $reasons[] = 'accepted_sources_below_threshold:'.$accepted
                .'<'.ResearchDomainCanon::MIN_ACCEPTED_SOURCES;
        }

        $diversity = (int) ($plan['source_diversity'] ?? 0);
        if ($diversity < ResearchDomainCanon::MIN_SOURCE_DIVERSITY) {
            $reasons[] = 'source_diversity_below_threshold:'.$diversity
                .'<'.ResearchDomainCanon::MIN_SOURCE_DIVERSITY;
        }

        $claimsWithSources = (int) ($plan['claims_with_source_refs'] ?? 0);
        if ($claimsWithSources < ResearchDomainCanon::MIN_CLAIMS) {
            $reasons[] = 'claims_with_source_refs_below_threshold:'.$claimsWithSources
                .'<'.ResearchDomainCanon::MIN_CLAIMS;
        }

        if ($requestedExecutionActions !== []) {
            $reasons[] = 'research_execution_request_blocked';
        }

        return array_values($reasons);
    }

    /**
     * @param  list<string>  $reasons
     * @param  array<string,mixed>  $kernelEnv
     * @param  array<int,string>  $requestedExecutionActions
     */
    private function composeDecision(array $reasons, array $kernelEnv, array $requestedExecutionActions): string
    {
        // Kernel block or execution intent are HARD BLOCK.
        if (($kernelEnv['decision'] ?? null) === AtlasConstitutionalKernelService::DECISION_BLOCK) {
            return self::DECISION_BLOCK;
        }
        if ($requestedExecutionActions !== []) {
            return self::DECISION_BLOCK;
        }

        if ($reasons === []) {
            return self::DECISION_ALLOW;
        }

        // Missing source-grounding / thresholds → not a hard block,
        // operator can supply more evidence and re-run.
        return self::DECISION_REQUIRES_EVIDENCE;
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function envelopeHash(array $envelope): string
    {
        $canonical = [
            'schema_version' => $envelope['schema_version'],
            'domain' => $envelope['domain'],
            'decision' => $envelope['decision'],
            'reasons' => $envelope['reasons'],
            'measured' => $envelope['measured'],
            'kernel_decision' => $envelope['kernel_decision'],
            'admission_decision' => $envelope['admission_decision'],
        ];

        return 'sha256:'.hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function appendReceipt(array $envelope): void
    {
        $path = $this->gatesLogPath();
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $line = json_encode($envelope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        file_put_contents($path, $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
