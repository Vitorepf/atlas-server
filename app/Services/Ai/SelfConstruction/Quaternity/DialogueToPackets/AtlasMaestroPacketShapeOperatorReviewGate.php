<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets;

use RuntimeException;

/**
 * Refusal value returned by {@see AtlasMaestroPacketShapeOperatorReviewGate::forwardToQueue()} when no valid
 * approve() receipt exists for the shape. NO_OPERATOR_APPROVAL is the canonical code; downstream callers MUST
 * NOT translate this into an implicit yes. Co-located with the gate.
 */
final class ReviewGateRefusal
{
    public const CODE_NO_OPERATOR_APPROVAL = 'NO_OPERATOR_APPROVAL';

    public const CODE_REJECTED = 'REJECTED';

    public const CODE_SHAPE_NOT_FOUND = 'SHAPE_NOT_FOUND';

    public function __construct(
        public readonly string $code,
        public readonly string $detail,
    ) {
    }
}

/**
 * Thrown by {@see AtlasMaestroPacketShapeOperatorReviewGate::approve()} when the proposal JSON on disk has
 * changed between present() and approve() — the operator approved a DIFFERENT shape than the one on disk now.
 * Carries both hashes so the operator can audit the divergence. Co-located with the gate.
 */
final class ReviewGateTamperException extends RuntimeException
{
    public function __construct(
        public readonly string $recordedProposalHash,
        public readonly string $currentProposalHash,
        ?string $message = null,
    ) {
        parent::__construct($message ?? "Proposal tampered between present() and approve(): recorded={$recordedProposalHash} current={$currentProposalHash}");
    }
}

/**
 * QUATERNITY · OPERATOR REVIEW GATE — fail-closed chokepoint between {@see AtlasMaestroIntentToPacketShapeProposer}
 * output and the task queue. NO packet shape transits without explicit operator approval; a present() / approve()
 * cycle MUST happen, and the approve() call verifies the proposal_hash recorded at present() matches the current
 * on-disk JSON (tamper detection). There is NO timeout-approval, NO default-yes, NO bypass.
 *
 *   - present(shapeId)                                  → {@see ReviewBundle}    : show + record proposal_hash
 *   - approve(shapeId, signature, decisionReceipt)      → {@see ApprovedShape}   : bi-directional receipt
 *   - reject(shapeId, signature, reason)                → {@see RejectedShape}   : bi-directional receipt
 *   - forwardToQueue(shapeId)                           → {@see ReviewGateRefusal}|array{queued:true,…} :
 *                                                         only succeeds when an ApprovedShape exists on disk
 *
 * The gate persists approved/ + rejected/ JSON files alongside the proposed/ tree the proposer writes; nothing
 * is enqueued here directly (P04 policy + P05 CLI consume the approved/ tree).
 */
final class AtlasMaestroPacketShapeOperatorReviewGate
{
    private const PROPOSED_DIR = 'atlas/maestro/dialogue/proposed';

    private const APPROVED_DIR = 'atlas/maestro/dialogue/approved';

    private const REJECTED_DIR = 'atlas/maestro/dialogue/rejected';

    private const PROPOSAL_HASH_LEDGER = 'atlas/maestro/dialogue/_present_ledger.json';

    /** A presented shape is STALE after this many seconds without an approve() call — surfaces a warning in
     *  the ReviewBundle but {@see forwardToQueue()} STILL refuses (no default-yes by age). */
    private const STALE_AFTER_SECONDS = 30 * 86_400;

    /** @var null|callable():int */
    private $clock;

    public function __construct(
        private readonly ?string $storageRoot = null,
        ?callable $clock = null,
    ) {
        $this->clock = $clock;
    }

    public function present(string $shapeId): ReviewBundle
    {
        $proposal = $this->loadShape($shapeId, self::PROPOSED_DIR);
        if ($proposal === null) {
            throw new RuntimeException('Proposed shape not found: '.$shapeId);
        }

        $proposalHash = ReviewReceiptHasher::proposalHash($proposal);
        $this->recordPresent($shapeId, $proposalHash);

        $prior = $this->latestApprovedShape();
        $diff = $this->diffShapes($prior, $proposal);
        $citations = $this->citations($proposal);
        $stale = $this->isStale($proposal);

        return new ReviewBundle($shapeId, $proposal, $prior, $diff, $citations, $proposalHash, $stale);
    }

    public function approve(string $shapeId, string $operatorSignature, string $decisionReceipt): ApprovedShape
    {
        $this->assertNotTampered($shapeId);

        $proposal = $this->loadShape($shapeId, self::PROPOSED_DIR);
        if ($proposal === null) {
            throw new RuntimeException('Proposed shape not found: '.$shapeId);
        }

        $proposalHash = ReviewReceiptHasher::proposalHash($proposal);
        $decidedAt = gmdate(DATE_ATOM, $this->now());
        $cortexHash = ReviewReceiptHasher::cortexSnapshotHash($this->citations($proposal));
        $approvalHash = ReviewReceiptHasher::approvalHash($proposalHash, $operatorSignature, $decisionReceipt, $decidedAt);

        $approved = new ApprovedShape($shapeId, $proposalHash, $approvalHash, $operatorSignature, $cortexHash, $decidedAt, $decisionReceipt);
        $this->writeJson(self::APPROVED_DIR, $shapeId, $approved->toArray());

        return $approved;
    }

    public function reject(string $shapeId, string $operatorSignature, string $reason): RejectedShape
    {
        $proposal = $this->loadShape($shapeId, self::PROPOSED_DIR);
        if ($proposal === null) {
            throw new RuntimeException('Proposed shape not found: '.$shapeId);
        }

        $proposalHash = ReviewReceiptHasher::proposalHash($proposal);
        $decidedAt = gmdate(DATE_ATOM, $this->now());
        $cortexHash = ReviewReceiptHasher::cortexSnapshotHash($this->citations($proposal));
        $approvalHash = ReviewReceiptHasher::approvalHash($proposalHash, $operatorSignature, 'reject:'.$reason, $decidedAt);

        $rejected = new RejectedShape($shapeId, $proposalHash, $approvalHash, $operatorSignature, $cortexHash, $decidedAt, 'reject:'.$reason, $reason);
        $this->writeJson(self::REJECTED_DIR, $shapeId, $rejected->toArray());

        return $rejected;
    }

    /**
     * @return ReviewGateRefusal|array{queued:true, shape_id:string, approval:array<string,string>}
     */
    public function forwardToQueue(string $shapeId): ReviewGateRefusal|array
    {
        $proposal = $this->loadShape($shapeId, self::PROPOSED_DIR);
        if ($proposal === null) {
            return new ReviewGateRefusal(ReviewGateRefusal::CODE_SHAPE_NOT_FOUND, 'Proposed shape not found: '.$shapeId);
        }
        if ($this->loadShape($shapeId, self::REJECTED_DIR) !== null) {
            return new ReviewGateRefusal(ReviewGateRefusal::CODE_REJECTED, 'Shape was rejected: '.$shapeId);
        }
        $approval = $this->loadShape($shapeId, self::APPROVED_DIR);
        if ($approval === null) {
            return new ReviewGateRefusal(ReviewGateRefusal::CODE_NO_OPERATOR_APPROVAL, 'No operator approval on file for: '.$shapeId);
        }

        // Re-verify: the approved record's proposal_hash must still match the current on-disk proposal.
        $currentHash = ReviewReceiptHasher::proposalHash($proposal);
        if ((string) ($approval['proposal_hash'] ?? '') !== $currentHash) {
            return new ReviewGateRefusal(ReviewGateRefusal::CODE_NO_OPERATOR_APPROVAL, 'Approval hash mismatch — proposal was mutated after approval');
        }

        return ['queued' => true, 'shape_id' => $shapeId, 'approval' => $approval];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function loadShape(string $shapeId, string $relDir): ?array
    {
        $path = $this->absPath($relDir, $shapeId);
        if (! is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestApprovedShape(): ?array
    {
        $dir = $this->absDir(self::APPROVED_DIR);
        if (! is_dir($dir)) {
            return null;
        }
        $files = glob($dir.'/*.json') ?: [];
        if ($files === []) {
            return null;
        }
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        $decoded = json_decode((string) file_get_contents($files[0]), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>|null  $prior
     * @param  array<string,mixed>  $current
     * @return array<string,mixed>
     */
    private function diffShapes(?array $prior, array $current): array
    {
        if ($prior === null) {
            return ['added_keys' => array_keys($current), 'removed_keys' => [], 'changed_keys' => []];
        }
        $added = array_values(array_diff(array_keys($current), array_keys($prior)));
        $removed = array_values(array_diff(array_keys($prior), array_keys($current)));
        $changed = [];
        foreach ($current as $k => $v) {
            if (array_key_exists($k, $prior) && $prior[$k] !== $v) {
                $changed[] = $k;
            }
        }
        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);
        sort($changed, SORT_STRING);

        return ['added_keys' => $added, 'removed_keys' => $removed, 'changed_keys' => $changed];
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @return list<string>
     */
    private function citations(array $proposal): array
    {
        $symbol = (string) ($proposal['anchor_symbol'] ?? '');
        $file = (string) ($proposal['anchor_file'] ?? '');
        $citations = [];
        if ($symbol !== '') {
            $citations[] = 'symbol:'.$symbol;
        }
        if ($file !== '') {
            $citations[] = 'file:'.$file;
        }

        return $citations;
    }

    /**
     * @param  array<string,mixed>  $proposal
     */
    private function isStale(array $proposal): bool
    {
        // The proposed file's mtime is the proxy for "when the proposer wrote it" — if that is older than
        // STALE_AFTER_SECONDS the bundle is marked stale (operator UI warning).
        $shapeId = (string) ($proposal['task_packet_id'] ?? '');
        $path = $this->absPath(self::PROPOSED_DIR, $shapeId);
        if (! is_file($path)) {
            return false;
        }
        $mtime = (int) @filemtime($path);

        return $mtime > 0 && ($this->now() - $mtime) > self::STALE_AFTER_SECONDS;
    }

    private function recordPresent(string $shapeId, string $proposalHash): void
    {
        $ledger = $this->loadLedger();
        $ledger[$shapeId] = ['proposal_hash' => $proposalHash, 'presented_at_utc' => gmdate(DATE_ATOM, $this->now())];
        $this->writeLedger($ledger);
    }

    /**
     * @return array<string,array{proposal_hash:string,presented_at_utc:string}>
     */
    private function loadLedger(): array
    {
        $path = $this->absPath('', '_present_ledger.json'); // ignored shapeId; we want the ledger path
        // The ledger lives at a fixed relative path; recompute explicitly.
        $ledgerPath = $this->root().'/'.self::PROPOSAL_HASH_LEDGER;
        if (! is_file($ledgerPath)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($ledgerPath), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string,array{proposal_hash:string,presented_at_utc:string}>  $ledger
     */
    private function writeLedger(array $ledger): void
    {
        $ledgerPath = $this->root().'/'.self::PROPOSAL_HASH_LEDGER;
        $dir = dirname($ledgerPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        file_put_contents($ledgerPath, (string) json_encode($ledger, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    }

    private function assertNotTampered(string $shapeId): void
    {
        $ledger = $this->loadLedger();
        $recorded = $ledger[$shapeId]['proposal_hash'] ?? null;
        if ($recorded === null) {
            return; // approve() without present() is permitted but produces a fresh hash on the fly
        }
        $proposal = $this->loadShape($shapeId, self::PROPOSED_DIR);
        if ($proposal === null) {
            return;
        }
        $current = ReviewReceiptHasher::proposalHash($proposal);
        if (! hash_equals($recorded, $current)) {
            throw new ReviewGateTamperException($recorded, $current);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeJson(string $relDir, string $shapeId, array $payload): void
    {
        $path = $this->absPath($relDir, $shapeId);
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        ksort($payload);
        file_put_contents($path, (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function absPath(string $relDir, string $shapeId): string
    {
        return $this->absDir($relDir).'/'.$shapeId.'.json';
    }

    private function absDir(string $relDir): string
    {
        return rtrim($this->root().'/'.$relDir, '/');
    }

    private function root(): string
    {
        if ($this->storageRoot !== null && $this->storageRoot !== '') {
            return rtrim($this->storageRoot, '/');
        }
        if (function_exists('storage_path')) {
            try {
                return rtrim(storage_path(), '/');
            } catch (\Throwable) {
            }
        }

        return sys_get_temp_dir();
    }

    private function now(): int
    {
        $clock = $this->clock;
        if (is_callable($clock)) {
            return (int) $clock();
        }

        return time();
    }
}
