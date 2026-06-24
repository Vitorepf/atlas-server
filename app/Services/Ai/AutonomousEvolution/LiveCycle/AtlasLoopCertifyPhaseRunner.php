<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\LiveCycle;

use RuntimeException;
use Throwable;

/**
 * Thrown when {@see AtlasLoopCertifyPhaseRunner::run()} sees a regressed verdict from the certifier. The
 * exception ALWAYS surfaces — the live-cycle is short-circuited; a regressed candidate is never silently
 * swallowed (operator/orchestrator decides how to react). Co-located with the runner so a single allowed_file
 * owns the contract.
 */
final class CertificationFailedException extends RuntimeException
{
    /** @param  array<string,mixed>  $receipt the structured receipt the runner was about to return */
    public function __construct(public readonly array $receipt, ?string $message = null)
    {
        parent::__construct($message ?? 'Certification regressed: '.($receipt['reason'] ?? 'no_reason_recorded'));
    }
}

/**
 * CERTIFY PHASE RUNNER — phase 6 of the canonical 8-phase live-cycle (orientar → compreender →
 * decidir-alavancagem → arquitetar → decompor → implementar → CERTIFICAR → fechar-na-main → aprender).
 *
 * Delegates to a held-out delta certifier (duck-typed; in production
 * {@see \App\Services\Ai\AutonomousEvolution\AtlasLoopHeldOutDeltaCertifier}) and appends a structured
 * receipt to the UnifiedReceiptChain (also duck-typed). The runner CANNOT certify without a positive
 * certifier verdict: a `certified=false` or `regressed=true` result from the certifier maps to status
 * inconclusive / regressed, never certified.
 *
 * PÉTREO: a regressed verdict ALWAYS raises {@see CertificationFailedException} — but the receipt is appended
 * to the chain FIRST so the audit trail captures the regression even on the throw path.
 */
final class AtlasLoopCertifyPhaseRunner
{
    public const STATUS_CERTIFIED = 'certified';

    public const STATUS_REGRESSED = 'regressed';

    public const STATUS_INCONCLUSIVE = 'inconclusive';

    /** @var callable(array<string,mixed>):array<string,mixed> */
    private $certifier;

    /** @var callable(array<string,mixed>):void */
    private $receiptChainAppender;

    /**
     * @param  callable(array<string,mixed>):array<string,mixed>  $certifier
     *         The HeldOutDeltaCertifier entrypoint — takes the implement-phase receipt or a derived held-out
     *         spec, returns {armed:bool, improved:bool, regressed?:bool, baseline?:float, candidate?:float,
     *         improvement?:float, reason?:?string, frozen_judge_verdict?:?string, net_diff_summary?:array}.
     * @param  callable(array<string,mixed>):void  $receiptChainAppender
     *         The UnifiedReceiptChain::append entry (duck-typed). Always called BEFORE the runner returns or
     *         throws — the audit chain captures every certify attempt.
     */
    public function __construct(callable $certifier, callable $receiptChainAppender)
    {
        $this->certifier = $certifier;
        $this->receiptChainAppender = $receiptChainAppender;
    }

    /**
     * @param  array<string,mixed>  $implementReceipt  the implement-phase runner's receipt
     * @return array{
     *     phase:string,
     *     delegate:string,
     *     task_packet_id:string,
     *     certified:bool,
     *     regressed:bool,
     *     frozen_judge_verdict:?string,
     *     net_diff_summary:array<string,mixed>,
     *     status:string,
     *     reason:?string
     * }
     */
    public function run(array $implementReceipt): array
    {
        $packetId = (string) ($implementReceipt['task_packet_id'] ?? '');

        try {
            $certifierResult = ($this->certifier)($implementReceipt);
        } catch (Throwable $e) {
            $receipt = $this->receipt($packetId, false, false, null, [], self::STATUS_INCONCLUSIVE, 'certifier_threw:'.$e->getMessage());
            $this->appendToChain($receipt);

            return $receipt;
        }

        if (! is_array($certifierResult)) {
            $receipt = $this->receipt($packetId, false, false, null, [], self::STATUS_INCONCLUSIVE, 'certifier_returned_non_array');
            $this->appendToChain($receipt);

            return $receipt;
        }

        $armed = (bool) ($certifierResult['armed'] ?? false);
        $improved = (bool) ($certifierResult['improved'] ?? false);
        $regressed = (bool) ($certifierResult['regressed'] ?? false);
        $frozenVerdict = isset($certifierResult['frozen_judge_verdict']) ? (string) $certifierResult['frozen_judge_verdict'] : null;
        $netDiff = is_array($certifierResult['net_diff_summary'] ?? null) ? $certifierResult['net_diff_summary'] : [];
        $reason = isset($certifierResult['reason']) ? (string) $certifierResult['reason'] : null;

        if ($regressed) {
            $receipt = $this->receipt($packetId, false, true, $frozenVerdict, $netDiff, self::STATUS_REGRESSED, $reason ?? 'certifier_regressed');
            $this->appendToChain($receipt);
            throw new CertificationFailedException($receipt);
        }

        if (! $armed || ! $improved) {
            // Pétreo: a certified=true outcome REQUIRES a positive certifier verdict (armed + improved).
            $receipt = $this->receipt($packetId, false, false, $frozenVerdict, $netDiff, self::STATUS_INCONCLUSIVE, $reason ?? 'no_positive_delta');
            $this->appendToChain($receipt);

            return $receipt;
        }

        $receipt = $this->receipt($packetId, true, false, $frozenVerdict, $netDiff, self::STATUS_CERTIFIED, $reason);
        $this->appendToChain($receipt);

        return $receipt;
    }

    /**
     * @param  array<string,mixed>  $netDiff
     * @return array{phase:string,delegate:string,task_packet_id:string,certified:bool,regressed:bool,frozen_judge_verdict:?string,net_diff_summary:array<string,mixed>,status:string,reason:?string}
     */
    private function receipt(string $packetId, bool $certified, bool $regressed, ?string $frozenVerdict, array $netDiff, string $status, ?string $reason): array
    {
        return [
            'phase' => 'certify',
            'delegate' => 'held_out_delta_certifier',
            'task_packet_id' => $packetId,
            'certified' => $certified,
            'regressed' => $regressed,
            'frozen_judge_verdict' => $frozenVerdict,
            'net_diff_summary' => $netDiff,
            'status' => $status,
            'reason' => $reason,
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
            // Chain failure is non-load-bearing for the runner's return value; the runner already has the
            // receipt in hand. A real chain outage is observable elsewhere; we never block the live-cycle
            // on a chain hiccup.
        }
    }
}
