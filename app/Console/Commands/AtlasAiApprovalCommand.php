<?php

namespace App\Console\Commands;

use App\Models\AiOperatorApproval;
use App\Services\Ai\OperatorApproval\OperatorApprovalCanon;
use App\Services\Ai\OperatorApproval\OperatorApprovalGateService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Atlas AI Operator Approval Gate CLI.
 *
 *   atlas:ai:approval list   [--status=pending] [--gate-mode=...] [--mission=<uuid>] [--limit=20] [--json]
 *   atlas:ai:approval show   --approval=<uuid> [--json]
 *   atlas:ai:approval decide --approval=<uuid> --decision=approve|deny [--operator=name] [--note="..."] [--json]
 *   atlas:ai:approval expire [--json]                     // run expireDue, audit-friendly
 *   atlas:ai:approval control-plane [--limit=20] [--json] // aggregated snapshot
 *
 * Always emits JSON. Exit codes: 0 success, 1 runtime error, 2 usage error.
 */
class AtlasAiApprovalCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:approval
        {action : list|show|decide|expire|control-plane}
        {--approval= : Approval uuid for show/decide}
        {--decision= : decision for decide (approve|deny)}
        {--operator= : Operator name for decide (defaults to "cli-operator")}
        {--note= : Operator note for decide}
        {--status= : Filter for list (pending, approved, denied, expired, cancelled, auto_approved)}
        {--gate-mode= : Filter for list (allow_auto, require_confirmation, require_review, block, escalate_to_forge)}
        {--mission= : Filter list by mission uuid}
        {--limit=20 : Max rows for list / control-plane}
        {--json : Machine-readable JSON output (default true; preserved for compat)}';

    protected $description = 'Atlas AI Operator Approval Gate · list/show/decide/expire/control-plane.';

    public function handle(OperatorApprovalGateService $gate): int
    {
        $action = (string) $this->argument('action');

        try {
            return match ($action) {
                'list' => $this->renderList($gate),
                'show' => $this->renderShow($gate),
                'decide' => $this->renderDecide($gate),
                'expire' => $this->renderExpire($gate),
                'control-plane' => $this->renderControlPlane($gate),
                default => $this->renderUsageError($action),
            };
        } catch (Throwable $e) {
            return $this->emit([
                'ok' => false,
                'action' => $action,
                'error' => 'exception',
                'exception_class' => $e::class,
                'message' => $e->getMessage(),
            ], exit: 1);
        }
    }

    private function renderList(OperatorApprovalGateService $gate): int
    {
        $gate->expireDue();

        $status = $this->option('status');
        $gateMode = $this->option('gate-mode');
        $missionUuid = $this->option('mission');
        $limit = max(1, min(100, (int) $this->option('limit')));

        $query = AiOperatorApproval::query()->orderByDesc('created_at')->limit($limit);
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }
        if (is_string($gateMode) && $gateMode !== '') {
            $query->where('gate_mode', $gateMode);
        }
        if (is_string($missionUuid) && $missionUuid !== '') {
            $query->where('mission_id', $missionUuid);
        }

        $approvals = $query->get();

        return $this->emit([
            'ok' => true,
            'action' => 'list',
            'filter' => [
                'status' => $status,
                'gate_mode' => $gateMode,
                'mission_id' => $missionUuid,
                'limit' => $limit,
            ],
            'count' => $approvals->count(),
            'approvals' => $approvals
                ->map(fn (AiOperatorApproval $a) => $gate->serialize($a))
                ->values()
                ->all(),
        ]);
    }

    private function renderShow(OperatorApprovalGateService $gate): int
    {
        $uuid = (string) ($this->option('approval') ?? '');
        if ($uuid === '') {
            return $this->renderUsageError('show requires --approval=<uuid>');
        }

        $approval = AiOperatorApproval::query()->where('uuid', $uuid)->first();
        if ($approval === null) {
            return $this->emit([
                'ok' => false,
                'action' => 'show',
                'error' => 'approval_not_found',
                'approval_uuid' => $uuid,
            ], exit: 1);
        }

        return $this->emit([
            'ok' => true,
            'action' => 'show',
            'approval' => $gate->serialize($approval),
        ]);
    }

    private function renderDecide(OperatorApprovalGateService $gate): int
    {
        $uuid = (string) ($this->option('approval') ?? '');
        $decision = strtolower((string) ($this->option('decision') ?? ''));
        $operator = trim((string) ($this->option('operator') ?? 'cli-operator'));
        $note = $this->option('note');

        if ($uuid === '') {
            return $this->renderUsageError('decide requires --approval=<uuid>');
        }
        if (! in_array($decision, OperatorApprovalCanon::DECISIONS, true)) {
            return $this->renderUsageError('decide requires --decision=approve|deny');
        }

        $approval = AiOperatorApproval::query()->where('uuid', $uuid)->first();
        if ($approval === null) {
            return $this->emit([
                'ok' => false,
                'action' => 'decide',
                'error' => 'approval_not_found',
                'approval_uuid' => $uuid,
            ], exit: 1);
        }

        try {
            $updated = $decision === OperatorApprovalCanon::DECISION_APPROVE
                ? $gate->approve($approval, $operator, is_string($note) ? $note : null)
                : $gate->deny($approval, $operator, is_string($note) ? $note : null);
        } catch (Throwable $e) {
            return $this->emit([
                'ok' => false,
                'action' => 'decide',
                'error' => 'decide_failed',
                'message' => $e->getMessage(),
                'approval_uuid' => $uuid,
            ], exit: 1);
        }

        return $this->emit([
            'ok' => true,
            'action' => 'decide',
            'decision' => $decision,
            'approval' => $gate->serialize($updated),
        ]);
    }

    private function renderExpire(OperatorApprovalGateService $gate): int
    {
        $count = $gate->expireDue();

        return $this->emit([
            'ok' => true,
            'action' => 'expire',
            'expired_count' => $count,
        ]);
    }

    private function renderControlPlane(OperatorApprovalGateService $gate): int
    {
        $limit = max(1, min(100, (int) $this->option('limit')));

        return $this->emit([
            'ok' => true,
            'action' => 'control-plane',
            'snapshot' => $gate->controlPlaneSnapshot($limit),
        ]);
    }

    private function renderUsageError(string $action): int
    {
        return $this->emit([
            'ok' => false,
            'action' => $action,
            'error' => 'usage_error',
            'usage' => 'atlas:ai:approval {list|show|decide|expire|control-plane} [--approval=<uuid>] [--decision=approve|deny] [--operator=name] [--note=...] [--status=...] [--gate-mode=...] [--mission=<uuid>] [--limit=N] [--json]',
        ], exit: 2);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit = 0): int
    {
        $this->line($this->encode($payload));

        return $exit;
    }
}
