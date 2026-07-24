<?php

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use App\Services\Ai\AutomationDomain\AutomationControlPlaneProjection;
use App\Services\Ai\AutomationDomain\AutomationDomainCanon;
use App\Services\Ai\AutomationDomain\AutomationDomainException;
use App\Services\Ai\AutomationDomain\AutomationDomainManifestSeeder;
use App\Services\Ai\AutomationDomain\AutomationReadinessService;
use App\Services\Ai\AutomationDomain\AutomationRuntimeService;
use Illuminate\Console\Command;
use Throwable;
use App\Support\YesNo;

class AtlasAiAutomationDomainCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:automation-domain
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, seed-manifest, smoke, control-plane, enterprise-analysis}
        {--fixture : Run an enterprise flow fixture action}
        {--runtime-mode=internal : enterprise flow runtime mode: internal or fixture}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Automation / Tool Factory Runtime (Meta 8 · Automation): readiness, seed-manifest, smoke and control-plane projection.';

    public function handle(
        AutomationReadinessService $readiness,
        AutomationDomainManifestSeeder $manifestSeeder,
        AutomationRuntimeService $runtime,
        AutomationControlPlaneProjection $controlPlane,
        AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout,
    ): int {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');
        $fixtureRuntime = app(\App\Services\Ai\Holding\EnterpriseFlowFixtureActionRuntimeService::class);

        try {
            if ($fixtureRuntime->supports(AutomationDomainCanon::DOMAIN_ID, $action)) {
                $this->line($this->encodeOrEmptyObject($fixtureRuntime->run(
                    AutomationDomainCanon::DOMAIN_ID,
                    $action,
                    $this->fixtureRequested(),
                )));

                return self::SUCCESS;
            }

            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'seed-manifest' => $this->renderSeedManifest($manifestSeeder),
                'smoke' => $this->renderSmoke($runtime, $controlPlane),
                'control-plane' => $this->renderControlPlane($controlPlane),
                'enterprise-analysis' => $this->renderEnterpriseAnalysis($enterpriseBuildout),
                default => $this->invalidAction($action),
            };
        } catch (AutomationDomainException $e) {
            $this->line($this->encodeOrEmptyObject([
                'ok' => false,
                'error' => 'automation_exception',
                'message' => $e->getMessage(),
            ]));

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->line($this->encodeOrEmptyObject([
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
                'type' => $e::class,
            ]));

            return self::FAILURE;
        }
    }

    private function renderReadiness(AutomationReadinessService $readiness): int
    {
        $payload = $readiness->report();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('ok', YesNo::trueFalse($payload['ok']));
            $this->components->twoColumnDetail('passed', (string) $payload['summary']['passed']);
            $this->components->twoColumnDetail('failed', (string) $payload['summary']['failed']);
            foreach ($payload['checks'] as $check) {
                $this->components->twoColumnDetail((string) $check['name'], (string) $check['status']);
            }
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderSeedManifest(AutomationDomainManifestSeeder $seeder): int
    {
        $manifest = $seeder->seed();
        $payload = [
            'ok' => true,
            'action' => 'seed-manifest',
            'schema' => 'atlas.ai.automation_domain.seed_manifest.v1',
            'manifest' => [
                'uuid' => $manifest->uuid,
                'domain_id' => $manifest->domain_id,
                'name' => $manifest->name,
                'status' => $manifest->status,
                'maturity_stage' => $manifest->maturity_stage,
                'manifest_hash' => $manifest->manifest_hash,
            ],
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('domain_id', (string) $payload['manifest']['domain_id']);
            $this->components->twoColumnDetail('manifest_hash', (string) ($payload['manifest']['manifest_hash'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function renderSmoke(
        AutomationRuntimeService $runtime,
        AutomationControlPlaneProjection $controlPlane,
    ): int {
        $result = $runtime->smokeRun();
        $snapshot = $controlPlane->snapshot();
        $run = $result['run'];
        $summary = $result['summary'];

        $body = [
            'ok' => $run->status === 'closed',
            'action' => 'smoke',
            'schema' => 'atlas.ai.automation_domain.smoke.v1',
            'automation_run' => [
                'uuid' => $run->uuid,
                'run_kind' => $run->run_kind,
                'status' => $run->status,
                'next_action' => $run->next_action,
                'receipt_hash' => $run->receipt_hash,
            ],
            'summary' => $summary,
            'snapshot_summary' => [
                'runs' => $snapshot['runs']['count'] ?? 0,
                'plans' => $snapshot['plans']['count'] ?? 0,
                'plans_blocked' => $snapshot['plans']['blocked'] ?? 0,
                'tool_decisions' => $snapshot['tool_decisions']['count'] ?? 0,
                'evolution_events' => $snapshot['evolution_events']['count'] ?? 0,
            ],
        ];
        $this->emit($body, function () use ($body): void {
            $this->components->twoColumnDetail('run_status', (string) $body['automation_run']['status']);
            $this->components->twoColumnDetail('plans_blocked', (string) ($body['snapshot_summary']['plans_blocked'] ?? '0'));
            $this->components->twoColumnDetail('tool_decision_kind', (string) ($body['summary']['tool_decision_kind'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function renderControlPlane(AutomationControlPlaneProjection $controlPlane): int
    {
        $payload = $controlPlane->snapshot();
        $payload['ok'] = true;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('runs', (string) ($payload['runs']['count'] ?? 0));
            $this->components->twoColumnDetail('plans', (string) ($payload['plans']['count'] ?? 0));
            $this->components->twoColumnDetail('plans_blocked', (string) ($payload['plans']['blocked'] ?? 0));
            $this->components->twoColumnDetail('tool_decisions', (string) ($payload['tool_decisions']['count'] ?? 0));
            $this->components->twoColumnDetail('evolution_events', (string) ($payload['evolution_events']['count'] ?? 0));
        });

        return self::SUCCESS;
    }

    private function renderEnterpriseAnalysis(AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout): int
    {
        $payload = $enterpriseBuildout->companyPacket(AutomationDomainCanon::DOMAIN_ID);
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
        $this->line($this->encodeOrEmptyObject([
            'ok' => false,
            'error' => 'invalid_arguments',
            'message' => "invalid action [{$action}] for atlas:ai:automation-domain",
        ]));

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encodeOrEmptyObject($payload));

            return;
        }
        $human();
    }

    private function fixtureRequested(): bool
    {
        return (string) $this->input->getParameterOption('--runtime-mode', (string) $this->option('runtime-mode')) === 'fixture'
            || (bool) $this->option('fixture');
    }
}
