<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\RouterRuntime\DecisionReceiptService;
use App\Services\Ai\RouterRuntime\DomainRouterService;
use App\Services\Ai\RouterRuntime\FlowRouterService;
use App\Services\Ai\RouterRuntime\IntentKernelService;
use App\Services\Ai\RouterRuntime\RouterRuntimeCanon;
use App\Services\Ai\RouterRuntime\RouterRuntimeControlPlaneService;
use App\Services\Ai\RouterRuntime\RouterRuntimeReadinessService;
use App\Services\Ai\RouterRuntime\RuntimeDispatchService;
use Illuminate\Console\Command;
use Throwable;
use App\Support\YesNo;

class AtlasAiRouterRuntimeCommand extends Command
{
    use ReadsNonEmptyStringOption;

    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:router-runtime
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, classify, route, dispatch, smoke, control-plane}
        {--input= : Raw prompt for classify/route/dispatch/smoke}
        {--mission= : Optional mission uuid context}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Router / Runtime Dispatch (Meta 6): intent kernel, domain router, flow router, runtime dispatch, decision receipts.';

    public function handle(
        IntentKernelService $intentKernel,
        DomainRouterService $domainRouter,
        FlowRouterService $flowRouter,
        RuntimeDispatchService $runtimeDispatch,
        DecisionReceiptService $receipts,
        RouterRuntimeReadinessService $readiness,
        RouterRuntimeControlPlaneService $controlPlane,
    ): int {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');

        try {
            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'classify' => $this->renderClassify($intentKernel),
                'route' => $this->renderRoute($intentKernel, $domainRouter, $flowRouter, $receipts),
                'dispatch' => $this->renderDispatch(
                    $intentKernel,
                    $domainRouter,
                    $flowRouter,
                    $runtimeDispatch,
                    $receipts,
                ),
                'smoke' => $this->renderSmoke(
                    $intentKernel,
                    $domainRouter,
                    $flowRouter,
                    $runtimeDispatch,
                    $receipts,
                    $controlPlane,
                ),
                'control-plane' => $this->renderControlPlane($controlPlane),
                default => $this->invalidAction($action),
            };
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

    private function renderReadiness(RouterRuntimeReadinessService $readiness): int
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

    private function renderClassify(IntentKernelService $intentKernel): int
    {
        $input = $this->stringOption('input');
        if ($input === null) {
            return $this->failWith('classify requires --input="..."');
        }
        $intent = $intentKernel->classify($input, [
            'mission_id' => $this->stringOption('mission'),
            'source' => 'cli',
        ]);
        $payload = [
            'ok' => true,
            'action' => 'classify',
            'schema' => 'atlas.ai.router_runtime.classify.v1',
            'intent' => [
                'uuid' => $intent->uuid,
                'intent_type' => $intent->intent_type,
                'confidence' => (float) ($intent->confidence ?? 0.0),
                'ambiguity_score' => (float) ($intent->ambiguity_score ?? 0.0),
                'normalized_intent' => $intent->normalized_intent,
                'matched_keywords' => (array) ($intent->signals['matched_keywords'] ?? []),
            ],
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('intent_type', (string) $payload['intent']['intent_type']);
            $this->components->twoColumnDetail('confidence', (string) $payload['intent']['confidence']);
            $this->components->twoColumnDetail('ambiguity', (string) $payload['intent']['ambiguity_score']);
        });

        return self::SUCCESS;
    }

    private function renderRoute(
        IntentKernelService $intentKernel,
        DomainRouterService $domainRouter,
        FlowRouterService $flowRouter,
        DecisionReceiptService $receipts,
    ): int {
        $input = $this->stringOption('input');
        if ($input === null) {
            return $this->failWith('route requires --input="..."');
        }
        $intent = $intentKernel->classify($input, [
            'mission_id' => $this->stringOption('mission'),
            'source' => 'cli',
        ]);
        $decision = $domainRouter->route($intent);
        $flowRoute = $flowRouter->decideFlow($decision, $intent);
        $receipt = $receipts->recordRouterDecision($decision);

        $payload = [
            'ok' => true,
            'action' => 'route',
            'schema' => 'atlas.ai.router_runtime.route.v1',
            'intent_uuid' => $intent->uuid,
            'intent_type' => $intent->intent_type,
            'router_decision' => [
                'uuid' => $decision->uuid,
                'primary_domain' => $decision->primary_domain,
                'secondary_domains' => $decision->secondary_domains,
                'routing_mode' => $decision->routing_mode,
                'policy_required' => (bool) $decision->policy_required,
                'evidence_required' => (bool) $decision->evidence_required,
                'tool_plan_required' => (bool) $decision->tool_plan_required,
                'receipt_hash' => $decision->receipt_hash,
            ],
            'flow_route' => [
                'uuid' => $flowRoute->uuid,
                'flow_id' => $flowRoute->flow_id,
                'flow_profile' => $flowRoute->flow_profile,
                'runtime_mode' => $flowRoute->runtime_mode,
                'required_gates' => $flowRoute->required_gates,
                'fallback_flows' => $flowRoute->fallback_flows,
            ],
            'receipt_uuid' => $receipt->uuid,
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('primary_domain', (string) $payload['router_decision']['primary_domain']);
            $this->components->twoColumnDetail('routing_mode', (string) $payload['router_decision']['routing_mode']);
            $this->components->twoColumnDetail('flow_id', (string) $payload['flow_route']['flow_id']);
        });

        return self::SUCCESS;
    }

    private function renderDispatch(
        IntentKernelService $intentKernel,
        DomainRouterService $domainRouter,
        FlowRouterService $flowRouter,
        RuntimeDispatchService $runtimeDispatch,
        DecisionReceiptService $receipts,
    ): int {
        $input = $this->stringOption('input');
        if ($input === null) {
            return $this->failWith('dispatch requires --input="..."');
        }
        $intent = $intentKernel->classify($input, [
            'mission_id' => $this->stringOption('mission'),
            'source' => 'cli',
        ]);
        $decision = $domainRouter->route($intent);
        $flowRoute = $flowRouter->decideFlow($decision, $intent);
        $dispatch = $runtimeDispatch->dispatch($decision, $flowRoute, $intent);
        $routerReceipt = $receipts->recordRouterDecision($decision);
        $dispatchReceipt = $receipts->recordRuntimeDispatch($dispatch, $decision);

        $payload = [
            'ok' => true,
            'action' => 'dispatch',
            'schema' => 'atlas.ai.router_runtime.dispatch.v1',
            'intent_uuid' => $intent->uuid,
            'intent_type' => $intent->intent_type,
            'router_decision_uuid' => $decision->uuid,
            'primary_domain' => $decision->primary_domain,
            'routing_mode' => $decision->routing_mode,
            'flow_route_uuid' => $flowRoute->uuid,
            'flow_id' => $flowRoute->flow_id,
            'dispatch' => [
                'uuid' => $dispatch->uuid,
                'dispatch_target' => $dispatch->dispatch_target,
                'dispatch_status' => $dispatch->dispatch_status,
                'blockers' => $dispatch->blockers,
                'receipt_hash' => $dispatch->receipt_hash,
            ],
            'router_receipt_uuid' => $routerReceipt->uuid,
            'dispatch_receipt_uuid' => $dispatchReceipt->uuid,
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('dispatch_target', (string) $payload['dispatch']['dispatch_target']);
            $this->components->twoColumnDetail('dispatch_status', (string) $payload['dispatch']['dispatch_status']);
        });

        return self::SUCCESS;
    }

    private function renderSmoke(
        IntentKernelService $intentKernel,
        DomainRouterService $domainRouter,
        FlowRouterService $flowRouter,
        RuntimeDispatchService $runtimeDispatch,
        DecisionReceiptService $receipts,
        RouterRuntimeControlPlaneService $controlPlane,
    ): int {
        $input = $this->stringOption('input')
            ?? 'Atlas Router smoke: implemente um exporter csv com testes e documente o plano';

        $intent = $intentKernel->classify($input, ['source' => 'cli-smoke']);
        $decision = $domainRouter->route($intent);
        $flowRoute = $flowRouter->decideFlow($decision, $intent);
        $dispatch = $runtimeDispatch->dispatch($decision, $flowRoute, $intent);
        $receipts->recordRouterDecision($decision);
        $receipts->recordRuntimeDispatch($dispatch, $decision);

        $snapshot = $controlPlane->snapshot();

        $payload = [
            'ok' => in_array($dispatch->dispatch_status, [
                RouterRuntimeCanon::DISPATCH_SIMULATED,
                RouterRuntimeCanon::DISPATCH_PLANNED,
            ], true),
            'action' => 'smoke',
            'schema' => 'atlas.ai.router_runtime.smoke.v1',
            'intent_type' => $intent->intent_type,
            'primary_domain' => $decision->primary_domain,
            'routing_mode' => $decision->routing_mode,
            'flow_id' => $flowRoute->flow_id,
            'dispatch_status' => $dispatch->dispatch_status,
            'router_receipt_hash' => $decision->receipt_hash,
            'dispatch_receipt_hash' => $dispatch->receipt_hash,
            'snapshot' => $snapshot,
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('intent_type', (string) $payload['intent_type']);
            $this->components->twoColumnDetail('flow_id', (string) $payload['flow_id']);
            $this->components->twoColumnDetail('dispatch_status', (string) $payload['dispatch_status']);
        });

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function renderControlPlane(RouterRuntimeControlPlaneService $controlPlane): int
    {
        $payload = $controlPlane->snapshot();
        $payload['ok'] = true;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('classifications', (string) $payload['classifications']['count']);
            $this->components->twoColumnDetail('decisions', (string) $payload['decisions']['count']);
            $this->components->twoColumnDetail('routes', (string) $payload['routes']['count']);
            $this->components->twoColumnDetail('dispatches', (string) $payload['dispatches']['count']);
            $this->components->twoColumnDetail('receipts', (string) $payload['receipts']['count']);
        });

        return self::SUCCESS;
    }

    private function failWith(string $message): int
    {
        $this->line($this->encodeOrEmptyObject([
            'ok' => false,
            'error' => 'invalid_arguments',
            'message' => $message,
        ]));

        return self::FAILURE;
    }

    private function invalidAction(string $action): int
    {
        return $this->failWith("invalid action [{$action}] for atlas:ai:router-runtime");
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

}
