<?php

namespace App\Console\Commands;

use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use App\Services\Ai\MarketingDomain\MarketingControlPlaneProjection;
use App\Services\Ai\MarketingDomain\MarketingDomainCanon;
use App\Services\Ai\MarketingDomain\MarketingDomainManifestSeeder;
use App\Services\Ai\MarketingDomain\MarketingLimitedAutonomyPolicyService;
use App\Services\Ai\MarketingDomain\MarketingReadinessService;
use App\Services\Ai\MarketingDomain\MarketingRuntimeService;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiMarketingDomainCommand extends Command
{
    protected $signature = 'atlas:ai:marketing-domain
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, seed-manifest, smoke, control-plane, limited-autonomy-policy, enterprise-analysis}
        {--fixture : Run an enterprise flow fixture action}
        {--runtime-mode=internal : enterprise flow runtime mode: internal or fixture}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Marketing / Growth Company Runtime: ICP, positioning, campaign, copy, creative, funnel, analytics, experiments and approval gates. No auto-publish, no auto-spend.';

    public function handle(
        MarketingReadinessService $readiness,
        MarketingDomainManifestSeeder $seeder,
        MarketingRuntimeService $runtime,
        MarketingControlPlaneProjection $controlPlane,
        MarketingLimitedAutonomyPolicyService $limitedAutonomy,
        AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout,
    ): int {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');
        $fixtureRuntime = app(\App\Services\Ai\Holding\EnterpriseFlowFixtureActionRuntimeService::class);

        try {
            if ($fixtureRuntime->supports(MarketingDomainCanon::DOMAIN_ID, $action)) {
                $this->line($this->encode($fixtureRuntime->run(
                    MarketingDomainCanon::DOMAIN_ID,
                    $action,
                    $this->fixtureRequested(),
                )));

                return self::SUCCESS;
            }

            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'seed-manifest' => $this->renderSeedManifest($seeder),
                'smoke' => $this->renderSmoke($runtime, $controlPlane),
                'control-plane' => $this->renderControlPlane($controlPlane),
                'limited-autonomy-policy' => $this->renderLimitedAutonomyPolicy($limitedAutonomy),
                'enterprise-analysis' => $this->renderEnterpriseAnalysis($enterpriseBuildout),
                default => $this->invalidAction($action),
            };
        } catch (Throwable $e) {
            $this->line($this->encode([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
                'type' => $e::class,
            ]));

            return self::FAILURE;
        }
    }

    private function renderReadiness(MarketingReadinessService $readiness): int
    {
        $payload = $readiness->report();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('ok', $payload['ok'] ? 'true' : 'false');
            $this->components->twoColumnDetail('passed', (string) $payload['summary']['passed']);
            $this->components->twoColumnDetail('failed', (string) $payload['summary']['failed']);
            foreach ($payload['checks'] as $check) {
                $this->components->twoColumnDetail((string) $check['name'], (string) $check['status']);
            }
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderSeedManifest(MarketingDomainManifestSeeder $seeder): int
    {
        $manifest = $seeder->seed();
        $payload = [
            'ok' => true,
            'action' => 'seed-manifest',
            'schema' => 'atlas.ai.marketing_domain.seed_manifest.v1',
            'manifest' => [
                'uuid' => $manifest->uuid,
                'domain_id' => $manifest->domain_id,
                'name' => $manifest->name,
                'status' => $manifest->status,
                'manifest_hash' => $manifest->manifest_hash,
            ],
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('domain_id', (string) $payload['manifest']['domain_id']);
        });

        return self::SUCCESS;
    }

    private function renderSmoke(
        MarketingRuntimeService $runtime,
        MarketingControlPlaneProjection $controlPlane,
    ): int {
        $payload = $runtime->smokeRun();
        $run = $payload['run'];
        $snapshot = $controlPlane->snapshot();

        $body = [
            'ok' => true,
            'action' => 'smoke',
            'schema' => 'atlas.ai.marketing_domain.smoke.v1',
            'marketing_run' => [
                'uuid' => $run->uuid,
                'product' => $run->product,
                'status' => $run->status,
                'certification_status' => $run->certification_status,
                'missing_requirements' => $run->missing_requirements,
                'certification_hash' => $run->certification_hash,
                'evidence_pack_hash' => $run->evidence_pack_hash,
            ],
            'snapshot_summary' => [
                'runs' => $snapshot['runs']['count'],
                'artifacts' => $snapshot['artifacts']['count'],
                'experiments' => $snapshot['experiments']['count'],
                'approval_gates' => $snapshot['approval_gates']['count'],
            ],
        ];
        $this->emit($body, function () use ($body): void {
            $this->components->twoColumnDetail('certification_status', (string) ($body['marketing_run']['certification_status'] ?? ''));
            $this->components->twoColumnDetail('artifacts', (string) ($body['snapshot_summary']['artifacts'] ?? ''));
            $this->components->twoColumnDetail('approval_gates', (string) ($body['snapshot_summary']['approval_gates'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function renderControlPlane(MarketingControlPlaneProjection $controlPlane): int
    {
        $payload = $controlPlane->snapshot();
        $payload['ok'] = true;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('runs', (string) $payload['runs']['count']);
            $this->components->twoColumnDetail('artifacts', (string) $payload['artifacts']['count']);
            $this->components->twoColumnDetail('experiments', (string) $payload['experiments']['count']);
            $this->components->twoColumnDetail('approval_gates', (string) $payload['approval_gates']['count']);
        });

        return self::SUCCESS;
    }

    private function renderLimitedAutonomyPolicy(MarketingLimitedAutonomyPolicyService $policy): int
    {
        $payload = $policy->policyPacket();
        $payload['ok'] = true;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('autonomy_level', (string) $payload['autonomy_level']);
            $this->components->twoColumnDetail('policy_hash', (string) $payload['policy_hash']);
            $this->components->twoColumnDetail(
                'external_spend_without_approval',
                (string) $payload['budget']['external_spend_ceiling_without_approval'],
            );
        });

        return self::SUCCESS;
    }

    private function renderEnterpriseAnalysis(AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout): int
    {
        $payload = $enterpriseBuildout->companyPacket(MarketingDomainCanon::DOMAIN_ID);
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
        $this->line($this->encode([
            'ok' => false,
            'error' => 'invalid_arguments',
            'message' => "invalid action [{$action}] for atlas:ai:marketing-domain",
        ]));

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

    private function fixtureRequested(): bool
    {
        return (string) $this->input->getParameterOption('--runtime-mode', (string) $this->option('runtime-mode')) === 'fixture'
            || (bool) $this->option('fixture');
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function encode(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
