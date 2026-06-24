<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use Throwable;

/**
 * CLOSE PHASE RUNNER — phase 7 of the canonical 8-phase live-cycle (orientar → compreender → decidir →
 * arquitetar → decompor → implementar → certificar → FECHAR-NA-MAIN → aprender). Delegates the actual merge
 * to a duck-typed auto-merge service (production: AtlasLoopAutoMergeService); honors {@see AtlasLoopMasterSwitch}
 * as a byte-identical no-op gate; refuses to close any prior receipt that did not certify clean.
 *
 * PÉTREO INVARIANTS:
 *   - {@see AtlasLoopMasterSwitch::enabled()} is FALSE ⇒ short-circuit with status=skipped /
 *     abort_reason='master_switch_off'. The merge delegate is NEVER called.
 *   - prior certify receipt status != 'certified' ⇒ status=aborted / abort_reason names the missing
 *     certification. The merge delegate is NEVER called.
 *   - Any auto-merge service refusal (preflight, conflict, reverse-audit rollback) flows through as
 *     status=aborted with the delegate's reason.
 */
final class AtlasLoopClosePhaseRunner
{
    public const STATUS_MERGED = 'merged';

    public const STATUS_ABORTED = 'aborted';

    public const STATUS_SKIPPED = 'skipped';

    /** @var callable(array<string,mixed>):array<string,mixed> */
    private $autoMergeDelegate;

    /** @var callable(array<string,mixed>):void */
    private $receiptChainAppender;

    /** @var null|callable():bool */
    private $masterSwitchOverride;

    /**
     * @param  callable(array<string,mixed>):array<string,mixed>  $autoMergeDelegate
     *         The AtlasLoopAutoMergeService::autoMerge entrypoint (duck-typed). Takes the proposal/certify
     *         payload, returns {merged:bool, reason:?string, merge_result?:{merge_sha?:string}, ...}.
     * @param  callable(array<string,mixed>):void  $receiptChainAppender
     *         The UnifiedReceiptChain::append entry. Always called before the runner returns.
     * @param  null|callable():bool  $masterSwitchOverride
     *         Test seam — returns the master switch state. Defaults to {@see AtlasLoopMasterSwitch::enabled()}.
     */
    public function __construct(callable $autoMergeDelegate, callable $receiptChainAppender, ?callable $masterSwitchOverride = null)
    {
        $this->autoMergeDelegate = $autoMergeDelegate;
        $this->receiptChainAppender = $receiptChainAppender;
        $this->masterSwitchOverride = $masterSwitchOverride;
    }

    /**
     * @param  array<string,mixed>  $certifyReceipt  the certify-phase runner's receipt
     * @return array{
     *     phase:string,
     *     delegate:string,
     *     task_packet_id:string,
     *     base_sha:?string,
     *     merged_sha:?string,
     *     status:string,
     *     abort_reason:?string
     * }
     */
    public function run(array $certifyReceipt): array
    {
        $packetId = (string) ($certifyReceipt['task_packet_id'] ?? '');
        $baseSha = isset($certifyReceipt['base_sha']) ? (string) $certifyReceipt['base_sha'] : null;

        if (! $this->masterSwitchOn()) {
            $receipt = $this->receipt($packetId, $baseSha, null, self::STATUS_SKIPPED, 'master_switch_off');
            $this->appendToChain($receipt);

            return $receipt;
        }

        $certifyStatus = (string) ($certifyReceipt['status'] ?? '');
        if ($certifyStatus !== 'certified') {
            $receipt = $this->receipt($packetId, $baseSha, null, self::STATUS_ABORTED, 'prior_certify_status_not_certified:'.($certifyStatus === '' ? 'unknown' : $certifyStatus));
            $this->appendToChain($receipt);

            return $receipt;
        }

        try {
            $mergeResult = ($this->autoMergeDelegate)($certifyReceipt);
        } catch (Throwable $e) {
            $receipt = $this->receipt($packetId, $baseSha, null, self::STATUS_ABORTED, 'auto_merge_threw:'.$e->getMessage());
            $this->appendToChain($receipt);

            return $receipt;
        }

        if (! is_array($mergeResult)) {
            $receipt = $this->receipt($packetId, $baseSha, null, self::STATUS_ABORTED, 'auto_merge_returned_non_array');
            $this->appendToChain($receipt);

            return $receipt;
        }

        $merged = (bool) ($mergeResult['merged'] ?? false);
        if (! $merged) {
            $reason = isset($mergeResult['reason']) ? (string) $mergeResult['reason'] : 'auto_merge_refused';
            $receipt = $this->receipt($packetId, $baseSha, null, self::STATUS_ABORTED, $reason);
            $this->appendToChain($receipt);

            return $receipt;
        }

        $mergedSha = (string) ($mergeResult['merge_result']['merge_sha'] ?? ($mergeResult['merge_sha'] ?? ''));
        $mergedShaOrNull = $mergedSha === '' ? null : $mergedSha;
        $receipt = $this->receipt($packetId, $baseSha, $mergedShaOrNull, self::STATUS_MERGED, null);
        $this->appendToChain($receipt);

        return $receipt;
    }

    private function masterSwitchOn(): bool
    {
        $override = $this->masterSwitchOverride;
        if (is_callable($override)) {
            return (bool) $override();
        }

        return AtlasLoopMasterSwitch::enabled();
    }

    /**
     * @return array{phase:string,delegate:string,task_packet_id:string,base_sha:?string,merged_sha:?string,status:string,abort_reason:?string}
     */
    private function receipt(string $packetId, ?string $baseSha, ?string $mergedSha, string $status, ?string $abortReason): array
    {
        return [
            'phase' => 'close',
            'delegate' => 'auto_merge_service',
            'task_packet_id' => $packetId,
            'base_sha' => $baseSha,
            'merged_sha' => $mergedSha,
            'status' => $status,
            'abort_reason' => $abortReason,
        ];
    }

    /**
     * @param  array<string,mixed>  $receipt
     */
    private function appendToChain(array $receipt): void
    {
        try {
            ($this->receiptChainAppender)($receipt);
        } catch (Throwable) {
            // Chain failure must NOT block the runner's return — the runner already has the receipt.
        }
    }
}
