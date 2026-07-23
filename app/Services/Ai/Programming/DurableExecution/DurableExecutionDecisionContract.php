<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\DurableExecution;


/**
 * Programming Harness — Durable Execution Decision Contract (AP-284).
 *
 * The decision phase that follows a passing `DurableExecutionPreflight`.
 * It records the operator's (or governing service's) decision on what to
 * actually do with the work item: execute, defer, escalate to Forge, or
 * cancel. The contract is intentionally narrow — it doesn't execute; it
 * records the canonical decision so the runner can fail-fast if the
 * decision is missing or stale.
 *
 * Implements AP-284 under the canon naming policy (`<=50 chars`); the
 * original AP filename was 160+ chars, so this class names itself by
 * intent and references the AP via `AP_REFERENCE`.
 */
final class DurableExecutionDecisionContract
{
    public const SCHEMA_VERSION = 'atlas.programming.durable_execution_decision.v1';

    public const AP_REFERENCE = 'AP-284';

    public const DECISION_EXECUTE = 'execute_durable';

    public const DECISION_DEFER = 'defer_for_replan';

    public const DECISION_ESCALATE_FORGE = 'escalate_to_forge';

    public const DECISION_CANCEL = 'cancel_runtime';

    public const ALLOWED_DECISIONS = [
        self::DECISION_EXECUTE,
        self::DECISION_DEFER,
        self::DECISION_ESCALATE_FORGE,
        self::DECISION_CANCEL,
    ];

    /**
     * @param  array{
     *   schema_version?: string,
     *   may_execute?: bool,
     *   evidence?: array<string,mixed>
     * }  $preflight  Envelope from DurableExecutionPreflight::evaluate().
     * @return array{
     *   schema_version: string,
     *   ap_reference: string,
     *   decision: string,
     *   decided_at: string,
     *   actor: string,
     *   reason: string,
     *   preflight_status: string,
     *   work_item_id: ?string,
     *   plan_hash: ?string,
     *   spec_hash: ?string,
     *   forwards_to: ?string
     * }
     */
    public function decide(
        array $preflight,
        string $decision,
        string $actor,
        ?string $reason = null,
    ): array {
        if (! in_array($decision, self::ALLOWED_DECISIONS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Decision must be one of [%s], got "%s".', implode(',', self::ALLOWED_DECISIONS), $decision),
            );
        }
        if ($actor === '' || ! preg_match('/^(operator|service|agent):[A-Za-z0-9_.\\-]+$/', $actor)) {
            throw new \InvalidArgumentException(
                sprintf('Actor must match "operator|service|agent:<id>", got "%s".', $actor),
            );
        }

        $mayExecute = (bool) ($preflight['may_execute'] ?? false);
        $evidence = (array) ($preflight['evidence'] ?? []);

        // Refuse to record DECISION_EXECUTE when preflight did not pass —
        // honesty invariant: the decision envelope never claims authority
        // beyond what the preflight cleared.
        if ($decision === self::DECISION_EXECUTE && ! $mayExecute) {
            throw new \LogicException(
                'Cannot decide execute_durable when preflight.may_execute=false. Defer/escalate/cancel are the legal options.',
            );
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ap_reference' => self::AP_REFERENCE,
            'decision' => $decision,
            'decided_at' => now()->toAtomString(),
            'actor' => $actor,
            'reason' => $reason ?? $this->defaultReason($decision),
            'preflight_status' => $mayExecute ? 'preflight_passed' : 'preflight_blocked',
            'work_item_id' => DurableExecutionFieldReader::stringOrNull($evidence, 'work_item_id'),
            'plan_hash' => DurableExecutionFieldReader::stringOrNull($evidence, 'plan_hash'),
            'spec_hash' => DurableExecutionFieldReader::stringOrNull($evidence, 'spec_hash'),
            'forwards_to' => $this->forwardsTo($decision),
        ];
    }

    private function defaultReason(string $decision): string
    {
        return match ($decision) {
            self::DECISION_EXECUTE => 'preflight passed; runner authorised to execute',
            self::DECISION_DEFER => 'preflight blockers exist; defer until replanning',
            self::DECISION_ESCALATE_FORGE => 'work exceeds Atlas Dev fast-lane scope; escalate to Forge Obra',
            self::DECISION_CANCEL => 'operator cancellation of pending durable execution',
            default => 'unknown',
        };
    }

    private function forwardsTo(string $decision): ?string
    {
        return match ($decision) {
            self::DECISION_EXECUTE => 'DurableExecutionRunner',
            self::DECISION_DEFER => 'AtlasDevPlanProjectionService::reproject',
            self::DECISION_ESCALATE_FORGE => 'AtlasForgeHandoffAdapter::promoteWithPacket',
            self::DECISION_CANCEL => null,
            default => null,
        };
    }

}
