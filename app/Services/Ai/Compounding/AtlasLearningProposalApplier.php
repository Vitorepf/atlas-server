<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Models\AiLearningProposal;
use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Throwable;

/**
 * Closes the compounding flywheel: takes an APPROVED learning proposal and actually
 * applies it to runtime behaviour, so the next run is measurably better — the last
 * wire between "Atlas collects learning" and "Atlas learns".
 *
 * The hard law (AiLearningProposal) is preserved: nothing auto-mutates — the applier
 * acts ONLY on a proposal the operator already approved (`status=approved`), and the
 * application is governed (receipted) and reversible. First kind wired: `routing`
 * (an operator-approved learned route becomes the conductor's preferred route, ahead
 * of the raw success-rate heuristic). Other kinds report `kind_applier_pending` until
 * their behaviour appliers are wired — honest and extensible.
 *
 * Distinct from AtlasLearningProposalService::markApplied (a pure DB status flip with
 * its own lifecycle consumers): THIS applier pairs the status transition with the real
 * runtime route write + receipt. Do not call markApplied expecting the route to go live.
 */
final class AtlasLearningProposalApplier
{
    public const SCHEMA_VERSION = 'atlas.ai.learning_proposal_applier.v1';

    public function __construct(
        private readonly AtlasConductorRoutingMemory $routing,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function apply(AiLearningProposal $proposal, string $operator = ''): array
    {
        if ($proposal->status !== 'approved') {
            return $this->refuse('proposal_not_approved');
        }

        $kind = (string) $proposal->kind;
        $change = $kind === 'routing' ? $this->applyRouting($proposal) : null;

        if ($change === null) {
            return $this->refuse($kind === 'routing' ? 'routing_proposed_state_incomplete' : 'kind_applier_pending:'.$kind);
        }

        $persisted = $this->transition($proposal, 'applied', $operator);
        $this->recordReceipt($proposal, $operator, 'apply', $change);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'applied' => true,
            'reason' => null,
            'kind' => $kind,
            'change' => $change,
            'reversible' => true,
            // The route is live regardless; `persisted=false` flags that the DB status
            // bookkeeping lagged (fail-safe: re-apply is idempotent, last-set-wins).
            'persisted' => $persisted,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function reverse(AiLearningProposal $proposal, string $operator = ''): array
    {
        if ($proposal->status !== 'applied') {
            return $this->refuse('proposal_not_applied');
        }
        if ((string) $proposal->kind !== 'routing') {
            return $this->refuse('kind_reverser_pending:'.(string) $proposal->kind);
        }

        $ps = is_array($proposal->proposed_state) ? $proposal->proposed_state : [];
        $this->routing->clearPreferred((string) ($ps['task_category'] ?? ''), (string) ($ps['role'] ?? ''));

        $persisted = $this->transition($proposal, 'approved', $operator);
        $this->recordReceipt($proposal, $operator, 'reverse', [
            'task_category' => (string) ($ps['task_category'] ?? ''),
            'role' => (string) ($ps['role'] ?? ''),
        ]);

        return ['schema_version' => self::SCHEMA_VERSION, 'reversed' => true, 'kind' => 'routing', 'persisted' => $persisted];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function applyRouting(AiLearningProposal $proposal): ?array
    {
        $ps = is_array($proposal->proposed_state) ? $proposal->proposed_state : [];
        $route = [
            'task_category' => trim((string) ($ps['task_category'] ?? '')),
            'role' => trim((string) ($ps['role'] ?? '')),
            'provider' => trim((string) ($ps['provider'] ?? '')),
            'model' => (string) ($ps['model'] ?? ''),
        ];
        if ($route['task_category'] === '' || $route['role'] === '' || $route['provider'] === '') {
            return null;
        }

        $this->routing->applyPreferred($route);

        return $route;
    }

    private function transition(AiLearningProposal $proposal, string $status, string $operator): bool
    {
        $proposal->forceFill([
            'status' => $status,
            'decided_by' => $operator !== '' ? $operator : $proposal->decided_by,
        ]);
        if (! $proposal->exists) {
            return true; // no persisted row (in-memory context) — nothing to lag
        }
        try {
            $proposal->save();

            return true;
        } catch (Throwable) {
            // bookkeeping save is best-effort; the behaviour change is the contract.
            return false;
        }
    }

    /**
     * @param  array<string,mixed>  $change
     */
    private function recordReceipt(AiLearningProposal $proposal, string $operator, string $action, array $change): void
    {
        try {
            app(AtlasEvidenceLedger::class)->record(
                LedgerEventType::DecisionIssued,
                [
                    'schema_version' => self::SCHEMA_VERSION,
                    'decision' => 'learning_'.$action,
                    'kind' => (string) $proposal->kind,
                    'proposal_hash' => (string) ($proposal->proposal_hash ?? ''),
                    'change' => $change,
                ],
                [
                    'operator_id' => $operator,
                    'emitter_stage' => 'atlas.ai.learning_applier',
                    'emitter_version' => 'learning-applier-v1',
                ],
            );
        } catch (Throwable) {
            // receipt is best-effort; the gate's contract does not depend on it.
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function refuse(string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'applied' => false,
            'reason' => $reason,
            'kind' => null,
            'change' => null,
            'reversible' => false,
        ];
    }
}
