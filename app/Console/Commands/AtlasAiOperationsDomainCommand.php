<?php

namespace App\Console\Commands;

use App\Services\Ai\Domain\AtlasOperationsOrchestrator;
use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiOperationsDomainCommand extends Command
{
    protected $signature = 'atlas:ai:operations-domain
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, smoke, control-plane, enterprise-analysis}
        {--fixture : Run an enterprise flow fixture action}
        {--runtime-mode=internal : enterprise flow runtime mode: internal or fixture}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Operations Company Runtime: diagnostic, runbook, incident review and readiness review with no infra mutation.';

    public function handle(
        AtlasOperationsOrchestrator $orchestrator,
        AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout,
    ): int
    {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');
        $fixtureRuntime = app(\App\Services\Ai\Holding\EnterpriseFlowFixtureActionRuntimeService::class);

        try {
            if ($fixtureRuntime->supports('operations', $action)) {
                $this->line($this->encode($fixtureRuntime->run(
                    'operations',
                    $action,
                    $this->fixtureRequested(),
                )));

                return self::SUCCESS;
            }

            return match ($action) {
                'readiness' => $this->renderReadiness($orchestrator),
                'smoke' => $this->renderSmoke($orchestrator),
                'control-plane' => $this->renderControlPlane($orchestrator),
                'enterprise-analysis' => $this->renderEnterpriseAnalysis($enterpriseBuildout),
                default => $this->renderError('invalid_arguments', "invalid action [{$action}] for atlas:ai:operations-domain"),
            };
        } catch (Throwable $e) {
            return $this->renderError('exception', $e->getMessage(), $e::class);
        }
    }

    private function renderReadiness(AtlasOperationsOrchestrator $orchestrator): int
    {
        $flows = $orchestrator->supportedFlows();
        $payload = [
            'ok' => count($flows) >= 4,
            'schema' => 'atlas.ai.operations.readiness.v1',
            'domain' => 'operations',
            'orchestrator' => $orchestrator->orchestratorId(),
            'maturity' => $orchestrator->maturity(),
            'supported_flow_count' => count($flows),
            'supported_flows' => $flows,
            'invariants' => [
                'diagnostic_only_until_operator_acceptance' => true,
                'no_deploy_or_restart' => true,
                'no_infra_mutation' => true,
                'operational_action_requires_separate_receipt' => true,
            ],
        ];

        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('ok', $payload['ok'] ? 'true' : 'false');
            $this->components->twoColumnDetail('supported_flows', (string) $payload['supported_flow_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderSmoke(AtlasOperationsOrchestrator $orchestrator): int
    {
        $plan = $orchestrator->plan('operations.readiness_review', [
            'system' => 'atlas-server',
            'scope' => 'holding operating model readiness',
            'symptoms' => ['new strong target 9 criteria'],
            'signals' => ['autonomous_holding_score', 'blockers'],
            'evidence_refs' => ['atlas:ai:autonomous-holding --json'],
            'risk_class' => 'medium',
            'constraints' => ['diagnostic_only', 'no_infra_mutation'],
        ]);
        $result = $orchestrator->execute($plan, [
            'operator_id' => 'atlas-operator',
            'tenant_id' => 'default',
        ]);

        $payload = [
            'ok' => ($result['status'] ?? null) === 'succeeded',
            'schema' => 'atlas.ai.operations.smoke.v1',
            'domain' => 'operations',
            'status' => $result['status'] ?? 'unknown',
            'flow' => $result['flow'] ?? 'operations.readiness_review',
            'receipt_id' => data_get($result, 'result.receipt.receipt_id'),
            'ledger_events' => data_get($result, 'result.ledger.events', []),
            'diagnostic_only_until_operator_acceptance' => (bool) ($result['diagnostic_only_until_operator_acceptance'] ?? false),
        ];

        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('flow', (string) $payload['flow']);
            $this->components->twoColumnDetail('diagnostic_only', $payload['diagnostic_only_until_operator_acceptance'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderControlPlane(AtlasOperationsOrchestrator $orchestrator): int
    {
        $payload = [
            'ok' => true,
            'schema' => 'atlas.ai.operations.control_plane.v1',
            'domain' => 'operations',
            'orchestrator' => $orchestrator->orchestratorId(),
            'totals' => [
                'flows' => count($orchestrator->supportedFlows()),
                'agent_roles' => 5,
                'blocked_side_effect_classes' => 5,
            ],
            'supported_flows' => $orchestrator->supportedFlows(),
        ];

        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            foreach ($payload['totals'] as $key => $value) {
                $this->components->twoColumnDetail($key, (string) $value);
            }
        });

        return self::SUCCESS;
    }

    private function renderEnterpriseAnalysis(AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout): int
    {
        $payload = $enterpriseBuildout->companyPacket('operations');
        $payload['ok'] = (bool) ($payload['readiness']['ok'] ?? false);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('company_id', (string) $payload['company_id']);
            $this->components->twoColumnDetail('flows', (string) $payload['readiness']['flow_count']);
            $this->components->twoColumnDetail('connectors', (string) $payload['readiness']['connector_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderError(string $error, string $message, ?string $type = null): int
    {
        $payload = ['ok' => false, 'error' => $error, 'message' => $message];
        if ($type !== null) {
            $payload['type'] = $type;
        }
        $this->line($this->encode($payload));

        return self::FAILURE;
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return;
        }

        $human();
    }

    /**
     * @param array<string,mixed> $payload
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
