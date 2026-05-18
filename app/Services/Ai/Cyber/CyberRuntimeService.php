<?php

namespace App\Services\Ai\Cyber;

use App\Models\AiAppSecReview;
use App\Models\AiCyberEngagement;
use App\Models\AiCyberScopeRules;
use App\Models\AiDefensiveSecurityReview;
use App\Models\AiGrcMapping;
use App\Models\AiRemediationPlan;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Orchestrates the Cyber Security Company Runtime.
 *
 * DEFENSIVE-FIRST. This runtime never executes exploits, scans, secret
 * collection or offensive operations. Offensive engagement is documented via
 * AuthorizedBugBountyIntakeService gating; actual execution must be carried
 * out by external authorized professionals using their own infrastructure.
 *
 * Offensive verbs are explicitly refused via `refuseOffensive()`.
 */
class CyberRuntimeService
{
    /** @var array<int,string> */
    private const FORBIDDEN_OFFENSIVE_VERBS = [
        'execute_exploit',
        'execute_scan',
        'launch_nuclei',
        'launch_nmap',
        'launch_ffuf',
        'launch_metasploit',
        'collect_credentials',
        'dump_secrets',
        'disable_control',
        'unauthorized_pentest',
        'unauthorized_recon',
        'supply_chain_publish',
        'register_typosquat',
        'submit_malicious_pr',
    ];

    public function __construct(
        private readonly CyberEngagementIntakeService $intake,
        private readonly CyberScopeRulesOfEngagementService $scopeService,
        private readonly AppSecReviewService $appsec,
        private readonly GRCMappingService $grc,
        private readonly RemediationPlanService $remediation,
        private readonly DefensiveSecurityReviewService $defensiveReview,
        private readonly AuthorizedBugBountyIntakeService $bountyIntake,
        private readonly CyberEvidenceChainService $evidenceChain,
    ) {}

    /**
     * Refusal gate — call this anywhere offensive action might be requested.
     */
    public function refuseOffensive(string $action): never
    {
        throw CyberDomainException::forbiddenOffensive($action);
    }

    public function isForbiddenOffensive(string $action): bool
    {
        return in_array($action, self::FORBIDDEN_OFFENSIVE_VERBS, true);
    }

    /**
     * Drive a defensive review chain end-to-end. Produces engagement +
     * scope/RoE + AppSec + GRC + remediation + defensive review + evidence
     * chain entries. Does NOT plan bug bounty unless authorization is supplied.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function driveDefensiveReview(array $payload): array
    {
        $engagement = $this->intake->intake($payload['engagement'] ?? []);

        $this->evidenceChain->append($engagement, [
            'entry_kind' => CyberEvidenceChainService::KIND_AUTHORIZATION,
            'actor' => 'system',
            'payload' => [
                'authorization_present' => $engagement->authorization_present,
                'engagement_kind' => $engagement->engagement_kind,
            ],
        ]);

        $scope = $this->scopeService->define($engagement, $payload['scope_rules'] ?? []);
        $this->evidenceChain->append($engagement, [
            'entry_kind' => CyberEvidenceChainService::KIND_SCOPE,
            'actor' => 'system',
            'payload' => ['scope_id' => $scope->scope_id, 'rules_hash' => $scope->rules_hash],
        ]);
        $this->evidenceChain->append($engagement, [
            'entry_kind' => CyberEvidenceChainService::KIND_ROE,
            'actor' => 'system',
            'payload' => [
                'allowed_techniques' => $scope->allowed_techniques,
                'forbidden_techniques' => $scope->forbidden_techniques,
            ],
        ]);

        $appsec = null;
        if (! empty($payload['appsec_review'])) {
            $appsec = $this->appsec->review(array_merge(
                $payload['appsec_review'],
                ['engagement_id' => $engagement->id],
            ));
            $this->evidenceChain->append($engagement, [
                'entry_kind' => CyberEvidenceChainService::KIND_APPSEC_REVIEW,
                'actor' => 'system',
                'payload' => [
                    'review_id' => $appsec->review_id,
                    'risk_score' => $appsec->risk_score,
                ],
            ]);
        }

        $grcMapping = null;
        if (! empty($payload['grc_mapping'])) {
            $grcMapping = $this->grc->map(array_merge(
                $payload['grc_mapping'],
                ['engagement_id' => $engagement->id],
            ));
            $this->evidenceChain->append($engagement, [
                'entry_kind' => CyberEvidenceChainService::KIND_GRC_EVIDENCE,
                'actor' => 'system',
                'payload' => [
                    'framework' => $grcMapping->framework,
                    'control_id' => $grcMapping->control_id,
                    'compliance_status' => $grcMapping->compliance_status,
                ],
            ]);
        }

        $defensive = $this->defensiveReview->review(array_merge(
            $payload['defensive_review'] ?? [],
            ['engagement_id' => $engagement->id],
        ));
        $this->evidenceChain->append($engagement, [
            'entry_kind' => CyberEvidenceChainService::KIND_DEFENSIVE_REVIEW,
            'actor' => 'system',
            'payload' => [
                'review_id' => $defensive->review_id,
                'review_kind' => $defensive->review_kind,
            ],
        ]);

        $remediation = null;
        if (! empty($payload['remediation_plan'])) {
            $remediation = $this->remediation->propose(array_merge(
                $payload['remediation_plan'],
                [
                    'engagement_id' => $engagement->id,
                    'appsec_review_id' => $appsec?->id,
                ],
            ));
            $this->evidenceChain->append($engagement, [
                'entry_kind' => CyberEvidenceChainService::KIND_REMEDIATION,
                'actor' => 'system',
                'payload' => [
                    'plan_id' => $remediation->plan_id,
                    'severity' => $remediation->severity,
                ],
            ]);
        }

        $bbIntake = null;
        if (! empty($payload['bug_bounty_intake'])) {
            $bbIntake = $this->bountyIntake->intake($engagement, $payload['bug_bounty_intake']);
            $this->evidenceChain->append($engagement, [
                'entry_kind' => CyberEvidenceChainService::KIND_BUG_BOUNTY_INTAKE,
                'actor' => 'system',
                'payload' => [
                    'intake_id' => $bbIntake->intake_id,
                    'status' => $bbIntake->status,
                    'all_gates_passed' => $bbIntake->status === AuthorizedBugBountyIntakeService::STATUS_AUTHORIZED,
                ],
            ]);
        }

        $evidence = $this->attachEvidenceRuntime($engagement, $appsec, $grcMapping, $defensive, $remediation, $bbIntake, $scope);

        return [
            'engagement' => $engagement->refresh(),
            'scope_rules' => $scope,
            'appsec_review' => $appsec,
            'grc_mapping' => $grcMapping,
            'defensive_review' => $defensive,
            'remediation_plan' => $remediation,
            'bug_bounty_intake' => $bbIntake,
            'evidence_chain_verification' => $this->evidenceChain->verify($engagement),
            'evidence_runtime' => $evidence,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function attachEvidenceRuntime(
        AiCyberEngagement $engagement,
        ?AiAppSecReview $appsec,
        ?AiGrcMapping $grcMapping,
        AiDefensiveSecurityReview $defensive,
        ?AiRemediationPlan $remediation,
        $bbIntake,
        AiCyberScopeRules $scope,
    ): array {
        $packService = '\\App\\Services\\Ai\\Evidence\\EvidencePackService';
        $certService = '\\App\\Services\\Ai\\Evidence\\CertificationRuntimeService';
        $receiptService = '\\App\\Services\\Ai\\Evidence\\ReceiptService';

        $tablesPresent = Schema::hasTable('ai_evidence_packs')
            && Schema::hasTable('ai_certifications')
            && Schema::hasTable('ai_audit_events');

        if (! class_exists($packService) || ! class_exists($certService) || ! $tablesPresent) {
            return ['attached' => false, 'detail' => 'Evidence Runtime not available (services or tables missing).'];
        }

        try {
            $packs = app($packService);
            $certs = app($certService);
            $receipts = class_exists($receiptService) ? app($receiptService) : null;

            $receipt = null;
            if ($receipts !== null) {
                $receipt = $receipts->emit([
                    'receipt_type' => 'domain_step',
                    'action' => 'cyber.defensive_review',
                    'target_type' => 'domain_delivery',
                    'target_id' => (string) $engagement->id,
                    'status' => 'ok',
                ]);
            }

            $artifactRefs = [
                ['kind' => 'engagement', 'id' => $engagement->id, 'hash' => $engagement->engagement_hash],
                ['kind' => 'scope_rules', 'id' => $scope->id, 'hash' => $scope->rules_hash],
                ['kind' => 'defensive_review', 'id' => $defensive->id, 'hash' => $defensive->review_hash],
            ];
            if ($appsec !== null) {
                $artifactRefs[] = ['kind' => 'appsec_review', 'id' => $appsec->id, 'hash' => $appsec->review_hash];
            }
            if ($grcMapping !== null) {
                $artifactRefs[] = ['kind' => 'grc_mapping', 'id' => $grcMapping->id, 'hash' => $grcMapping->mapping_hash];
            }
            if ($remediation !== null) {
                $artifactRefs[] = ['kind' => 'remediation_plan', 'id' => $remediation->id, 'hash' => $remediation->plan_hash];
            }

            $pack = $packs->build([
                'target_type' => 'domain_delivery',
                'target_id' => (string) $engagement->id,
                'mission_id' => $engagement->mission_id,
                'domain_id' => CyberDomainManifestSeeder::DOMAIN_ID,
                'artifact_refs' => $artifactRefs,
                'receipt_refs' => $receipt !== null
                    ? [['kind' => 'receipt', 'id' => $receipt->id, 'hash' => $receipt->receipt_hash]]
                    : [],
                'command_refs' => [
                    ['kind' => 'engagement_hash', 'value' => $engagement->engagement_hash],
                ],
            ]);

            $provided = ['authorization_evaluated', 'scope_defined', 'defensive_review_recorded'];
            if ($appsec !== null) {
                $provided[] = 'appsec_review_recorded';
            }
            if ($grcMapping !== null) {
                $provided[] = 'grc_mapping_recorded';
            }
            if ($remediation !== null) {
                $provided[] = 'remediation_plan_recorded';
            }
            if ($bbIntake !== null && $bbIntake->status === AuthorizedBugBountyIntakeService::STATUS_AUTHORIZED) {
                $provided[] = 'bug_bounty_intake_authorized';
            }

            $cert = $certs->certify([
                'target_type' => 'domain_delivery',
                'target_id' => (string) $engagement->id,
                'mission_id' => $engagement->mission_id,
                'evidence_pack_id' => $pack->id,
                'required_requirements' => [
                    'authorization_evaluated',
                    'scope_defined',
                    'defensive_review_recorded',
                ],
                'provided_requirements' => $provided,
            ]);

            return [
                'attached' => true,
                'evidence_pack_id' => $pack->id,
                'evidence_hash' => $pack->evidence_hash,
                'certification_id' => $cert->id,
                'certification_status' => $cert->status,
            ];
        } catch (Throwable $e) {
            return ['attached' => false, 'detail' => 'evidence attach failed: '.$e->getMessage()];
        }
    }
}
