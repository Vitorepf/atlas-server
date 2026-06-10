<?php

namespace App\Services\Ai\Cyber;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Str;

/**
 * Registers the Cyber Security Company Runtime manifest with the canonical
 * Domain Runtime registry (Meta 2). Tolerant: if Meta 2 tables aren't present
 * yet the seeder returns a degraded payload.
 *
 * Charter is explicitly DEFENSIVE-first: AppSec, GRC, remediation, defensive
 * security and authorized bug bounty INTAKE/PLANNING. Atlas Cyber Runtime does
 * NOT execute exploit, scan, credential collection or any offensive action
 * automatically.
 */
class CyberDomainManifestSeeder
{
    public const DOMAIN_ID = 'cyber';

    public const NAME = 'Cyber Security Company Runtime';

    public const MATURITY_STAGE = 'specialist';

    public const STATUS = 'planned';

    /**
     * @return array<string,mixed>
     */
    public function seed(): array
    {
        if (! DatabaseTableAvailability::has('ai_domain_manifests')) {
            return [
                'status' => 'missing',
                'detail' => 'ai_domain_manifests table not available (Meta 2 not bootstrapped).',
            ];
        }

        $manifestModel = '\\App\\Models\\AiDomainManifest';
        if (! class_exists($manifestModel)) {
            return [
                'status' => 'missing',
                'detail' => 'AiDomainManifest model class not available.',
            ];
        }

        $payload = $this->manifestPayload();
        $hash = CyberCanonicalHash::sha256($payload);

        $existing = $manifestModel::query()->where('domain_id', self::DOMAIN_ID)->first();
        if ($existing !== null) {
            if ($existing->manifest_hash !== $hash) {
                $existing->fill(array_merge($payload, ['manifest_hash' => $hash]));
                $existing->save();
            }

            return [
                'status' => 'updated',
                'manifest_id' => $existing->id,
                'domain_id' => $existing->domain_id,
                'manifest_hash' => $hash,
            ];
        }

        $created = $manifestModel::query()->create(array_merge($payload, [
            'uuid' => (string) Str::uuid(),
            'manifest_hash' => $hash,
        ]));

        return [
            'status' => 'created',
            'manifest_id' => $created->id,
            'domain_id' => $created->domain_id,
            'manifest_hash' => $hash,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function manifestPayload(): array
    {
        return [
            'domain_id' => self::DOMAIN_ID,
            'name' => self::NAME,
            'charter' => [
                'mission' => 'Defensive cyber: AppSec, GRC, remediation, defensive review and authorized bug bounty/pentest intake with documented scope, RoE, legal and privacy gates.',
                'frontier' => 'Defensive-first. Offensive engagement is intake/planning only, never execution. Composes with security domain (review-only) and programming.security (code-level).',
                'users' => ['operator', 'security_team', 'cyber_consultant'],
                'outcomes' => [
                    'engagement_record',
                    'scope_and_rules_of_engagement',
                    'appsec_review',
                    'grc_mapping',
                    'remediation_plan',
                    'defensive_security_review',
                    'authorized_bug_bounty_intake',
                    'evidence_chain',
                ],
                'forbidden' => [
                    'execute_exploit',
                    'execute_scan',
                    'collect_credentials',
                    'disable_security_control',
                    'unauthorized_pentest',
                    'unauthorized_recon',
                    'unauthorized_supply_chain_action',
                ],
            ],
            'ontology' => [
                'Engagement', 'ScopeAndRoE', 'AppSecReview', 'GRCMapping',
                'RemediationPlan', 'DefensiveSecurityReview',
                'AuthorizedBugBountyIntake', 'EvidenceChainEntry',
            ],
            'departments' => [
                'engagement_intake',
                'scope_and_roe',
                'appsec',
                'grc_compliance',
                'remediation',
                'defensive_security',
                'authorized_bug_bounty_intake',
                'evidence_chain',
            ],
            'capabilities' => [
                'cyber.engagement.intake',
                'cyber.scope_and_roe.define',
                'cyber.appsec.review',
                'cyber.grc.map_control',
                'cyber.remediation.plan',
                'cyber.defensive_security.review',
                'cyber.bug_bounty.intake',
                'cyber.evidence_chain.append',
            ],
            'flow_profiles' => [
                'defensive_review' => [
                    'entry' => 'mission|objective',
                    'gates' => ['authorization-present', 'evidence-chain-started'],
                    'exit' => 'remediation_plan|blocker',
                ],
                'authorized_bug_bounty_intake' => [
                    'entry' => 'mission with program authorization',
                    'gates' => [
                        'authorization-present',
                        'scope-parsed',
                        'roe-documented',
                        'legal-gate-passed',
                        'privacy-gate-passed',
                    ],
                    'exit' => 'intake_handoff|blocker',
                ],
            ],
            'tools_allowed' => [
                'research.web_search',
                'docs.read',
                'compliance.framework_lookup',
            ],
            'policy_profile' => 'cyber.default',
            'memory_scope' => ['engagement_records', 'compliance_evidence'],
            'evidence_schema' => [
                'authorization_doc', 'scope_doc', 'roe_doc', 'legal_review',
                'privacy_review', 'appsec_finding', 'grc_evidence',
                'remediation_proof', 'defensive_finding', 'evidence_chain_entry',
            ],
            'quality_gates' => [
                'authorization-present',
                'scope-parsed',
                'roe-documented',
                'legal-gate-passed',
                'privacy-gate-passed',
                'evidence-chain-started',
                'no-offensive-action-executed',
            ],
            'handoff_rules' => [
                'allowed_targets' => ['security', 'software', 'operations', 'finance'],
                'forbidden_targets' => [],
                'forbidden_outbound' => ['execute_exploit', 'execute_scan'],
            ],
            'delivery_types' => [
                'appsec_review_report',
                'grc_compliance_report',
                'remediation_plan',
                'defensive_security_report',
                'bug_bounty_intake_packet',
            ],
            'metrics' => [
                'engagements_count', 'appsec_findings', 'grc_controls_mapped',
                'remediations_proposed', 'authorized_intakes_count',
                'evidence_chain_length',
            ],
            'forbidden_actions' => [
                'execute_exploit', 'execute_scan', 'collect_credentials',
                'disable_security_control', 'unauthorized_pentest',
                'unauthorized_recon', 'unauthorized_supply_chain_action',
                'publish_zero_day_without_disclosure',
            ],
            'maturity_stage' => self::MATURITY_STAGE,
            'owner' => 'atlas-ai',
            'status' => self::STATUS,
            'schema_version' => 'atlas.ai.domain_manifest.v1',
        ];
    }
}
