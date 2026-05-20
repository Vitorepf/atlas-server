<?php

namespace App\Console\Commands;

use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use App\Services\Ai\Cyber\AppSecReviewService;
use App\Services\Ai\Cyber\CyberControlPlaneProjection;
use App\Services\Ai\Cyber\CyberDomainException;
use App\Services\Ai\Cyber\CyberDomainManifestSeeder;
use App\Services\Ai\Cyber\CyberEngagementIntakeService;
use App\Services\Ai\Cyber\CyberReadinessService;
use App\Services\Ai\Cyber\CyberRuntimeService;
use App\Services\Ai\Cyber\DefensiveSecurityReviewService;
use App\Services\Ai\Cyber\GRCMappingService;
use App\Services\Ai\Cyber\RemediationPlanService;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiCyberDomainCommand extends Command
{
    protected $signature = 'atlas:ai:cyber-domain
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, smoke, control-plane, seed-manifest, enterprise-analysis}
        {--fixture : Run an enterprise flow fixture action}
        {--runtime-mode=internal : enterprise flow runtime mode: internal or fixture}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Cyber Security Company Runtime: defensive review, AppSec, GRC, remediation, authorized bug bounty intake. NEVER executes offensive actions.';

    public function handle(
        CyberReadinessService $readiness,
        CyberRuntimeService $runtime,
        CyberControlPlaneProjection $controlPlane,
        CyberDomainManifestSeeder $manifestSeeder,
        AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout,
    ): int {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');
        $fixtureRuntime = app(\App\Services\Ai\Holding\EnterpriseFlowFixtureActionRuntimeService::class);

        try {
            if ($fixtureRuntime->supports(CyberDomainManifestSeeder::DOMAIN_ID, $action)) {
                $this->line($this->encode($fixtureRuntime->run(
                    CyberDomainManifestSeeder::DOMAIN_ID,
                    $action,
                    $this->fixtureRequested(),
                )));

                return self::SUCCESS;
            }

            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'smoke' => $this->renderSmoke($runtime, $controlPlane),
                'control-plane' => $this->renderControlPlane($controlPlane),
                'seed-manifest' => $this->renderSeedManifest($manifestSeeder),
                'enterprise-analysis' => $this->renderEnterpriseAnalysis($enterpriseBuildout),
                default => $this->invalidAction($action),
            };
        } catch (CyberDomainException $e) {
            $payload = ['ok' => false, 'error' => 'cyber_exception', 'message' => $e->getMessage()];
            $this->line($this->encode($payload));

            return self::FAILURE;
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
                'type' => $e::class,
            ];
            $this->line($this->encode($payload));

            return self::FAILURE;
        }
    }

    private function renderReadiness(CyberReadinessService $service): int
    {
        $payload = $service->report();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('ok', $payload['ok'] ? 'true' : 'false');
            $this->components->twoColumnDetail('passed', (string) $payload['summary']['passed']);
            $this->components->twoColumnDetail('failed', (string) $payload['summary']['failed']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderSmoke(
        CyberRuntimeService $runtime,
        CyberControlPlaneProjection $controlPlane,
    ): int {
        $seed = 'cyber-smoke-'.now()->format('YmdHisv');

        $result = $runtime->driveDefensiveReview([
            'engagement' => [
                'engagement_id' => 'eng-'.$seed,
                'engagement_kind' => CyberEngagementIntakeService::KIND_DEFENSIVE_REVIEW,
                'title' => 'Atlas Cyber Runtime defensive review smoke',
                'summary' => 'CLI smoke for defensive review chain.',
                'requester' => 'atlas-operator',
                'targets' => ['internal-atlas-stack'],
                'authorization_present' => true,
                'authorization' => [
                    'doc_url' => 'internal://atlas-cyber-smoke-auth',
                    'authorized_by' => 'atlas-operator',
                    'effective_from' => now()->toDateString(),
                ],
                'legal_review' => ['status' => 'cleared', 'notes' => 'internal-only'],
                'privacy_review' => ['status' => 'cleared', 'notes' => 'no PII'],
            ],
            'scope_rules' => [
                'in_scope_targets' => ['internal-atlas-stack'],
                'out_of_scope_targets' => ['production-customer-data'],
                'allowed_techniques' => ['static-review', 'config-audit', 'doc-review'],
                'forbidden_techniques' => ['exploit', 'scan', 'credential-collection'],
                'escalation_contacts' => [['name' => 'atlas-operator', 'channel' => 'cli']],
            ],
            'appsec_review' => [
                'title' => 'Atlas Cyber smoke AppSec review',
                'target_kind' => AppSecReviewService::TARGET_REPO,
                'target_ref' => 'atlas-server',
                'owasp_categories' => ['A01:2021', 'A05:2021'],
                'findings' => [['id' => 'f-1', 'severity' => 'low', 'title' => 'sample finding']],
                'recommendations' => [['id' => 'r-1', 'action' => 'rotate sample secret']],
                'risk_score' => 3.5,
            ],
            'grc_mapping' => [
                'framework' => GRCMappingService::FRAMEWORK_NIST_CSF,
                'control_id' => 'PR.AC-1',
                'control_title' => 'Identities and credentials are managed',
                'compliance_status' => GRCMappingService::STATUS_PARTIAL,
            ],
            'defensive_review' => [
                'title' => 'Atlas Cyber smoke defensive review',
                'review_kind' => DefensiveSecurityReviewService::KIND_THREAT_MODEL,
                'scope' => ['internal-atlas-stack'],
                'controls_inspected' => ['auth', 'secrets', 'audit-trail'],
                'findings' => [],
                'recommendations' => [['id' => 'r-2', 'action' => 'document threat model']],
            ],
            'remediation_plan' => [
                'title' => 'Rotate sample secret + document threat model',
                'severity' => RemediationPlanService::SEVERITY_LOW,
                'findings_refs' => [['id' => 'f-1']],
                'actions' => [['action' => 'rotate', 'target' => 'sample-secret']],
                'owners' => [['name' => 'atlas-operator']],
                'timeline' => ['due_in_days' => 7],
            ],
            'bug_bounty_intake' => [
                'program' => 'Internal Atlas Authorized Self-Test',
                'program_url' => 'internal://atlas-cyber-smoke',
                'authorization_present' => true,
                'authorization_doc' => [
                    'url' => 'internal://atlas-cyber-smoke-auth',
                    'contacts' => ['atlas-operator'],
                    'effective_from' => now()->toDateString(),
                ],
                'scope_parsed' => true,
                'roe_documented' => true,
                'legal_gate_passed' => true,
                'privacy_gate_passed' => true,
                'handoff_plan' => ['target' => 'manual-external-pentester', 'when' => 'after-approval'],
            ],
        ]);

        $snapshot = $controlPlane->snapshot();
        $bbStatus = $result['bug_bounty_intake']?->status;

        $payload = [
            'ok' => true,
            'action' => 'smoke',
            'engagement_id' => $result['engagement']->id,
            'engagement_status' => $result['engagement']->status,
            'scope_rules_id' => $result['scope_rules']->id,
            'appsec_review_id' => $result['appsec_review']?->id,
            'grc_mapping_id' => $result['grc_mapping']?->id,
            'defensive_review_id' => $result['defensive_review']->id,
            'remediation_plan_id' => $result['remediation_plan']?->id,
            'bug_bounty_intake_id' => $result['bug_bounty_intake']?->id,
            'bug_bounty_status' => $bbStatus,
            'evidence_chain_integrity_ok' => $result['evidence_chain_verification']['integrity_ok'],
            'evidence_chain_entry_count' => $result['evidence_chain_verification']['entry_count'],
            'evidence_runtime' => $result['evidence_runtime'],
            'control_plane_totals' => $snapshot['totals'] ?? null,
        ];

        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('engagement_status', (string) $payload['engagement_status']);
            $this->components->twoColumnDetail('bug_bounty_status', (string) ($payload['bug_bounty_status'] ?? ''));
            $this->components->twoColumnDetail('evidence_chain_ok', $payload['evidence_chain_integrity_ok'] ? 'true' : 'false');
            $this->components->twoColumnDetail('evidence_attached', ($payload['evidence_runtime']['attached'] ?? false) ? 'true' : 'false');
        });

        return self::SUCCESS;
    }

    private function renderControlPlane(CyberControlPlaneProjection $controlPlane): int
    {
        $payload = $controlPlane->snapshot();
        $payload['ok'] = ($payload['status'] ?? 'missing') === 'ready';
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? ''));
            foreach (($payload['totals'] ?? []) as $key => $value) {
                $this->components->twoColumnDetail('totals:'.$key, (string) $value);
            }
        });

        return self::SUCCESS;
    }

    private function renderSeedManifest(CyberDomainManifestSeeder $seeder): int
    {
        $payload = $seeder->seed();
        $payload['ok'] = in_array($payload['status'] ?? null, ['created', 'updated'], true);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? ''));
            $this->components->twoColumnDetail('domain_id', (string) ($payload['domain_id'] ?? CyberDomainManifestSeeder::DOMAIN_ID));
            $this->components->twoColumnDetail('manifest_hash', (string) ($payload['manifest_hash'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function renderEnterpriseAnalysis(AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout): int
    {
        $payload = $enterpriseBuildout->companyPacket(CyberDomainManifestSeeder::DOMAIN_ID);
        $payload['ok'] = (bool) ($payload['readiness']['ok'] ?? false);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('company_id', (string) $payload['company_id']);
            $this->components->twoColumnDetail('flows', (string) $payload['readiness']['flow_count']);
            $this->components->twoColumnDetail('connectors', (string) $payload['readiness']['connector_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function invalidAction(string $action): int
    {
        $payload = ['ok' => false, 'error' => 'invalid_action', 'message' => "invalid action [{$action}]"];
        $this->line($this->encode($payload));

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ($this->json()) {
            $this->line($this->encode($payload));

            return;
        }
        $human();
    }

    private function json(): bool
    {
        return (bool) $this->option('json');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function fixtureRequested(): bool
    {
        return (string) $this->input->getParameterOption('--runtime-mode', (string) $this->option('runtime-mode')) === 'fixture'
            || (bool) $this->option('fixture');
    }
}
