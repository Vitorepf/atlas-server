<?php

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\Holding\AutonomousHoldingEnterpriseBuildoutService;
use App\Services\Ai\Strategy\ExperimentPlanService;
use App\Services\Ai\Strategy\OpportunityRadarService;
use App\Services\Ai\Strategy\StrategyControlPlaneProjection;
use App\Services\Ai\Strategy\StrategyDomainException;
use App\Services\Ai\Strategy\StrategyDomainManifestSeeder;
use App\Services\Ai\Strategy\StrategyMemoService;
use App\Services\Ai\Strategy\StrategyReadinessService;
use App\Services\Ai\Strategy\StrategyRuntimeService;
use Illuminate\Console\Command;
use Throwable;
use App\Support\YesNo;

class AtlasAiStrategyDomainCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:strategy-domain
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, smoke, control-plane, seed-manifest, enterprise-analysis}
        {--fixture : Run an enterprise flow fixture action}
        {--runtime-mode=internal : enterprise flow runtime mode: internal or fixture}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Corporate Strategy / Venture Studio Runtime: readiness, smoke and control-plane projection.';

    public function handle(
        StrategyReadinessService $readiness,
        StrategyRuntimeService $runtime,
        StrategyControlPlaneProjection $controlPlane,
        StrategyDomainManifestSeeder $manifestSeeder,
        AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout,
    ): int {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');
        $fixtureRuntime = app(\App\Services\Ai\Holding\EnterpriseFlowFixtureActionRuntimeService::class);

        try {
            if ($fixtureRuntime->supports(StrategyDomainManifestSeeder::DOMAIN_ID, $action)) {
                $this->line($this->encodeOrEmptyObject($fixtureRuntime->run(
                    StrategyDomainManifestSeeder::DOMAIN_ID,
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
        } catch (StrategyDomainException $e) {
            $payload = ['ok' => false, 'error' => 'strategy_exception', 'message' => $e->getMessage()];
            $this->line($this->encodeOrEmptyObject($payload));

            return self::FAILURE;
        } catch (Throwable $e) {
            $payload = [
                'ok' => false,
                'error' => 'exception',
                'message' => $e->getMessage(),
                'type' => $e::class,
            ];
            $this->line($this->encodeOrEmptyObject($payload));

            return self::FAILURE;
        }
    }

    private function renderReadiness(StrategyReadinessService $service): int
    {
        $payload = $service->report();
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('schema', (string) $payload['schema']);
            $this->components->twoColumnDetail('ok', YesNo::trueFalse($payload['ok']));
            $this->components->twoColumnDetail('passed', (string) $payload['summary']['passed']);
            $this->components->twoColumnDetail('failed', (string) $payload['summary']['failed']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderSmoke(
        StrategyRuntimeService $runtime,
        StrategyControlPlaneProjection $controlPlane,
    ): int {
        $tokenSeed = 'strategy:smoke:'.now()->format('YmdHisv');

        $result = $runtime->driveOpportunityToDecision([
            'hash_seed' => $tokenSeed,
            'opportunity' => [
                'title' => 'Atlas-driven smoke opportunity '.$tokenSeed,
                'opportunity_id' => 'opp-'.now()->format('YmdHisv'),
                'problem' => 'Operators need faster strategy-to-decision loops on Atlas.',
                'icp' => 'Solo operators running multi-domain Atlas instances.',
                'pain' => 'Strategy stays in conversation and never becomes hypothesis/experiment/decision.',
                'urgency' => OpportunityRadarService::URGENCY_HIGH,
                'market' => ['tam_segment' => 'AI operators', 'geographies' => ['global']],
                'competitors' => [['name' => 'Manual strategy memo', 'note' => 'humans only']],
                'risks' => ['adoption_risk', 'scope_creep'],
                'confidence' => 0.6,
            ],
            'venture_blueprint' => [
                'title' => 'Atlas Strategy Runtime venture blueprint',
                'product' => [
                    'core_value' => 'opportunity-to-decision chain in one command',
                    'mvp_scope' => ['opportunity', 'blueprint', 'experiment', 'memo'],
                ],
                'gtm' => [
                    'positioning' => 'Atlas-native strategy runtime',
                    'icp' => 'Solo operators using Atlas',
                    'channels' => ['cli', 'cockpit'],
                    'pricing' => ['model' => 'bundled-with-atlas'],
                    'motion' => 'product_led',
                ],
                'unit_economics' => ['cac' => 0, 'ltv' => 0, 'note' => 'internal capability'],
                'hiring_plan' => ['phase_1' => ['atlas-ai-strategy-owner']],
                'operations' => ['runbook' => 'atlas:ai:strategy-domain --action=smoke'],
            ],
            'experiment_plan' => [
                'hypothesis' => 'Operator can take a strategy from opportunity to decided memo in under 30s using atlas:ai:strategy-domain.',
                'hypothesis_kind' => ExperimentPlanService::KIND_FEASIBILITY,
                'success_metric' => ['name' => 'time_to_decision_seconds', 'target' => '< 30'],
                'design' => ['type' => 'cli_smoke', 'steps' => ['run_command', 'inspect_payload']],
            ],
            'experiment_result' => [
                'outcome' => 'positive',
                'observed_metric' => ['name' => 'time_to_decision_seconds', 'value' => 'cli_smoke_pass'],
            ],
            'experiment_decision' => [
                'kind' => ExperimentPlanService::DECISION_SCALE,
                'rationale' => 'CLI smoke passes deterministically; promote runtime to active use.',
            ],
            'strategy_memo' => [
                'title' => 'Decision: Atlas Strategy Runtime smoke',
                'memo_kind' => 'experiment_decision',
                'decision' => 'scale',
                'rationale' => ['cli_smoke_passes', 'evidence_pack_attached', 'no_blockers'],
                'assumptions' => ['atlas_cli_available'],
                'next_actions' => [
                    ['action' => 'document_in_canonical_doc', 'owner' => 'atlas-ai'],
                    ['action' => 'wire_to_control_plane', 'owner' => 'atlas-ai'],
                ],
                'status' => StrategyMemoService::STATUS_DECIDED,
            ],
        ]);

        $snapshot = $controlPlane->snapshot();

        $payload = [
            'ok' => true,
            'action' => 'smoke',
            'run_id' => $result['run']->id,
            'opportunity_id' => $result['opportunity']->id,
            'venture_blueprint_id' => $result['venture_blueprint']->id,
            'experiment_plan_id' => $result['experiment_plan']->id,
            'strategy_memo_id' => $result['strategy_memo']->id,
            'experiment_status' => $result['experiment_plan']->status,
            'memo_status' => $result['strategy_memo']->status,
            'run_status' => $result['run']->status,
            'evidence' => $result['evidence'],
            'control_plane_totals' => $snapshot['totals'] ?? null,
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('run_status', (string) $payload['run_status']);
            $this->components->twoColumnDetail('experiment_status', (string) $payload['experiment_status']);
            $this->components->twoColumnDetail('memo_status', (string) $payload['memo_status']);
            $this->components->twoColumnDetail(
                'evidence_attached',
                YesNo::trueFalse($payload['evidence']['attached'] ?? false),
            );
        });

        return self::SUCCESS;
    }

    private function renderControlPlane(StrategyControlPlaneProjection $controlPlane): int
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

    private function renderSeedManifest(StrategyDomainManifestSeeder $seeder): int
    {
        $payload = $seeder->seed();
        $payload['ok'] = in_array($payload['status'] ?? null, ['created', 'updated'], true);
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? ''));
            $this->components->twoColumnDetail('domain_id', (string) ($payload['domain_id'] ?? StrategyDomainManifestSeeder::DOMAIN_ID));
            $this->components->twoColumnDetail('manifest_hash', (string) ($payload['manifest_hash'] ?? ''));
        });

        return self::SUCCESS;
    }

    private function renderEnterpriseAnalysis(AutonomousHoldingEnterpriseBuildoutService $enterpriseBuildout): int
    {
        $payload = $enterpriseBuildout->companyPacket(StrategyDomainManifestSeeder::DOMAIN_ID);
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
        $this->line($this->encodeOrEmptyObject($payload));

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ($this->json()) {
            $this->line($this->encodeOrEmptyObject($payload));

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
}
