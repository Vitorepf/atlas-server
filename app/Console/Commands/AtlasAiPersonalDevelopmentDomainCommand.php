<?php

namespace App\Console\Commands;

use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use App\Services\Ai\PersonalDevelopment\AtlasPersonalDevelopmentOrchestrator;
use App\Services\Ai\PersonalDevelopment\PersonalDevelopmentFlowCatalog;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiPersonalDevelopmentDomainCommand extends Command
{
    protected $signature = 'atlas:ai:personal-development-domain
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, smoke, control-plane, enterprise-analysis}
        {--fixture : Run an enterprise flow fixture action}
        {--runtime-mode=internal : enterprise flow runtime mode: internal or fixture}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Personal Development Company Runtime: non-clinical goals, habits, focus, learning, energy and review loops.';

    public function handle(
        AtlasPersonalDevelopmentOrchestrator $orchestrator,
        AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout,
    ): int
    {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');
        $fixtureRuntime = app(\App\Services\Ai\Holding\EnterpriseFlowFixtureActionRuntimeService::class);

        try {
            if ($fixtureRuntime->supports('personal_development', $action)) {
                $this->line($this->encode($fixtureRuntime->run(
                    'personal_development',
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
                default => $this->renderError('invalid_arguments', "invalid action [{$action}] for atlas:ai:personal-development-domain"),
            };
        } catch (Throwable $e) {
            return $this->renderError('exception', $e->getMessage(), $e::class);
        }
    }

    private function renderReadiness(AtlasPersonalDevelopmentOrchestrator $orchestrator): int
    {
        $flows = $orchestrator->supportedFlows();
        $payload = [
            'ok' => count($flows) >= 5,
            'schema' => 'atlas.ai.personal_development.readiness.v1',
            'domain' => 'personal_development',
            'orchestrator' => $orchestrator->orchestratorId(),
            'maturity' => $orchestrator->maturity(),
            'supported_flow_count' => count($flows),
            'supported_flows' => $flows,
            'invariants' => [
                'non_clinical' => true,
                'no_calendar_or_task_mutation' => true,
                'sensitive_or_forge_requires_human_review' => true,
                'provider_safe_requires_redaction' => true,
            ],
        ];

        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('ok', $payload['ok'] ? 'true' : 'false');
            $this->components->twoColumnDetail('supported_flows', (string) $payload['supported_flow_count']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderSmoke(AtlasPersonalDevelopmentOrchestrator $orchestrator): int
    {
        $result = $orchestrator->executeFlow('personal_development.forge', [
            'intent' => 'Build a weekly operating rhythm for Atlas work.',
            'goal' => 'Maintain focus, deliberate practice and weekly review.',
            'constraints' => ['non_clinical', 'no_external_side_effects'],
        ], [
            'human_approved' => false,
            'evidence_refs' => [
                ['type' => 'runbook', 'id' => 'personal-development-smoke'],
            ],
        ]);

        $payload = [
            'ok' => ($result['status'] ?? null) === 'needs_human_review',
            'schema' => 'atlas.ai.personal_development.smoke.v1',
            'domain' => 'personal_development',
            'status' => $result['status'] ?? 'unknown',
            'flow' => data_get($result, 'runtime.flow'),
            'approval_required' => (bool) data_get($result, 'runtime.approval_required'),
            'artifact_count' => count((array) data_get($result, 'runtime.artifacts', [])),
            'blocked_actions' => data_get($result, 'runtime.blocked_actions', []),
            'result' => $result,
        ];

        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('status', (string) $payload['status']);
            $this->components->twoColumnDetail('flow', (string) $payload['flow']);
            $this->components->twoColumnDetail('approval_required', $payload['approval_required'] ? 'true' : 'false');
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderControlPlane(AtlasPersonalDevelopmentOrchestrator $orchestrator): int
    {
        $flows = $orchestrator->supportedFlows();
        $payload = [
            'ok' => true,
            'schema' => 'atlas.ai.personal_development.control_plane.v1',
            'domain' => 'personal_development',
            'orchestrator' => $orchestrator->orchestratorId(),
            'totals' => [
                'flows' => count($flows),
                'agent_roles' => 5,
                'blocked_side_effect_classes' => 4,
            ],
            'cadences' => array_values(array_unique(array_map(
                static fn (string $flow): string => (string) (PersonalDevelopmentFlowCatalog::get($flow)['cadence'] ?? 'ad_hoc'),
                $flows,
            ))),
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
        $payload = $enterpriseBuildout->companyPacket('personal_development');
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
