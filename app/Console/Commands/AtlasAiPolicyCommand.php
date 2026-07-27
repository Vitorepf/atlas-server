<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Console\Concerns\EmitsCanonicalJson;
use App\Console\Concerns\RendersReadinessReport;
use App\Services\Ai\Policy\ApprovalRequestService;
use App\Services\Ai\Policy\PermissionGateService;
use App\Services\Ai\Policy\PolicyControlPlaneService;
use App\Services\Ai\Policy\PolicyProfileRegistryService;
use App\Services\Ai\Policy\PolicyReadinessService;
use App\Services\Ai\Policy\SafetyDecisionService;
use Illuminate\Console\Command;
use Throwable;

class AtlasAiPolicyCommand extends Command
{
    use EmitsCanonicalJson;
    use ReadsNonEmptyStringOption;
    use RendersReadinessReport;

    protected $signature = 'atlas:ai:policy
        {positional? : Optional positional action (alternative to --action)}
        {--action=readiness : readiness, seed-defaults, evaluate, request-approval, control-plane}
        {--action-key= : Action key for evaluate or request-approval (e.g. finance.live_trade)}
        {--requested-action= : Alias for --action-key used by evaluate/request-approval}
        {--domain-id= : Optional domain hint for evaluate}
        {--tool-id= : Optional tool id for evaluate}
        {--mission= : Optional mission uuid context}
        {--work-order= : Optional work_order uuid context}
        {--risk-level=low : Risk level hint for evaluate (low, medium, high, critical)}
        {--gate-type=permission : Gate type for evaluate (permission, budget, risk, tool)}
        {--approval-type=permission : Approval type for request-approval}
        {--reason= : Reason for request-approval}
        {--expires-in= : Expiry minutes for request-approval (default 60)}
        {--json : Machine-readable JSON output}';

    protected $description = 'Atlas Policy / Permission / Budget plane: readiness, seed-defaults, evaluate, request-approval, control-plane.';

    public function handle(
        PolicyReadinessService $readiness,
        PolicyProfileRegistryService $profiles,
        PermissionGateService $permission,
        SafetyDecisionService $safety,
        ApprovalRequestService $approvals,
        PolicyControlPlaneService $controlPlane,
    ): int {
        $positional = $this->argument('positional');
        $action = is_string($positional) && trim($positional) !== ''
            ? trim($positional)
            : (string) $this->option('action');

        try {
            return match ($action) {
                'readiness' => $this->renderReadiness($readiness),
                'seed-defaults' => $this->renderSeedDefaults($profiles),
                'evaluate' => $this->renderEvaluate($safety),
                'request-approval' => $this->renderRequestApproval($approvals),
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

    private function renderReadiness(PolicyReadinessService $readiness): int
    {
        return $this->renderReadinessReport($readiness->report());
    }

    private function renderSeedDefaults(PolicyProfileRegistryService $profiles): int
    {
        $seeded = $profiles->seedDefaults();
        $payload = [
            'ok' => true,
            'action' => 'seed-defaults',
            'schema' => 'atlas.ai.policy.seed.v1',
            'profiles' => $seeded->map(static fn ($p): array => [
                'uuid' => $p->uuid,
                'policy_id' => $p->policy_id,
                'scope_type' => $p->scope_type,
                'scope_ref' => $p->scope_ref,
                'autonomy_level' => $p->autonomy_level,
                'risk_tolerance' => $p->risk_tolerance,
                'forbidden_action_count' => is_array($p->forbidden_actions) ? count($p->forbidden_actions) : 0,
            ])->all(),
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('profiles_seeded', (string) count($payload['profiles']));
        });

        return self::SUCCESS;
    }

    private function renderEvaluate(SafetyDecisionService $safety): int
    {
        $action = $this->stringOption('action-key') ?? $this->stringOption('requested-action');
        if ($action === null) {
            return $this->failWith('evaluate requires --action-key="..." or --requested-action="..."');
        }
        $request = array_filter([
            'requested_action' => $action,
            'gate_type' => $this->stringOption('gate-type') ?? 'permission',
            'risk_level' => $this->stringOption('risk-level') ?? 'low',
            'domain_id' => $this->stringOption('domain-id'),
            'tool_id' => $this->stringOption('tool-id'),
            'mission_id' => $this->stringOption('mission'),
            'work_order_id' => $this->stringOption('work-order'),
        ], static fn ($value) => $value !== null && $value !== '');

        $decision = $safety->decide($request);
        $payload = [
            'ok' => in_array($decision->decision, ['allow', 'require_approval'], true),
            'action' => 'evaluate',
            'schema' => 'atlas.ai.policy.evaluate.v1',
            'requested_action' => $decision->requested_action,
            'decision' => $decision->decision,
            'reasons' => $decision->reasons,
            'receipt_hash' => $decision->receipt_hash,
            'policy_profile_id' => $decision->policy_profile_id,
            'risk_assessment_id' => $decision->risk_assessment_id,
            'safety_decision_uuid' => $decision->uuid,
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('decision', (string) $payload['decision']);
            $this->components->twoColumnDetail('receipt_hash', (string) $payload['receipt_hash']);
        });

        return self::SUCCESS;
    }

    private function renderRequestApproval(ApprovalRequestService $approvals): int
    {
        $action = $this->stringOption('action-key') ?? $this->stringOption('requested-action');
        if ($action === null) {
            return $this->failWith('request-approval requires --action-key="..." or --requested-action="..."');
        }
        $request = $approvals->request([
            'approval_type' => $this->stringOption('approval-type') ?? 'permission',
            'requested_action' => $action,
            'mission_id' => $this->stringOption('mission'),
            'work_order_id' => $this->stringOption('work-order'),
            'reason' => $this->stringOption('reason'),
            'expires_in_minutes' => (int) ($this->option('expires-in') ?? 60),
        ]);
        $payload = [
            'ok' => true,
            'action' => 'request-approval',
            'schema' => 'atlas.ai.policy.approval_request.v1',
            'approval' => [
                'uuid' => $request->uuid,
                'approval_type' => $request->approval_type,
                'requested_action' => $request->requested_action,
                'status' => $request->status,
                'expires_at' => $request->expires_at?->toJSON(),
            ],
        ];
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('approval_uuid', (string) $payload['approval']['uuid']);
            $this->components->twoColumnDetail('status', (string) $payload['approval']['status']);
        });

        return self::SUCCESS;
    }

    private function renderControlPlane(PolicyControlPlaneService $controlPlane): int
    {
        $payload = $controlPlane->snapshot();
        $payload['ok'] = true;
        $this->emit($payload, function () use ($payload): void {
            $this->components->twoColumnDetail('profiles', (string) $payload['profiles']['count']);
            $this->components->twoColumnDetail('gates', (string) $payload['gates']['count']);
            $this->components->twoColumnDetail('approvals', (string) $payload['approvals']['count']);
            $this->components->twoColumnDetail('budgets', (string) $payload['budgets']['count']);
            $this->components->twoColumnDetail('risks', (string) $payload['risks']['count']);
            $this->components->twoColumnDetail('decisions', (string) $payload['decisions']['count']);
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
        return $this->failWith("invalid action [{$action}] for atlas:ai:policy");
    }
}
