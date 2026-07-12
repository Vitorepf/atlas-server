<?php

declare(strict_types=1);

namespace App\Services\Ai\Autonomy;

use Carbon\CarbonImmutable;

/**
 * MAXK-05 — append-only ledger of signature receipts for the autonomy ladder.
 *
 * The autonomy-ladder services (`AutonomyLadderRuntimeService`,
 * `AtlasAutonomyLadderRuntimeService`, and the `promotion_gate.signatures`
 * payload on `AtlasAemorMemoryCandidate`) previously accepted **booleans** as
 * proof that operator/architect had signed off — a forgeable claim, because
 * any caller could hand-craft `['operator' => true, 'architect' => true]`
 * without producing evidence. This ledger closes that hole:
 *
 *   1. Each real off-line signature is `record()`ed as a receipt:
 *      `{actor, nonce, policy_hash, target_kind, target_id, issued_at}`.
 *   2. The auto-apply gate calls `verify()` with the pair
 *      (target, actor, nonce, policy_hash). Only a receipt present in the
 *      append-only ledger AND not previously consumed passes.
 *   3. Nonces are single-use — verifying with the same nonce twice returns
 *      `false` on the second call (`spent`), so replayed booleans cannot forge
 *      a second promotion.
 *
 * The ledger is append-only JSONL; the config `atlas.ai.autonomy_ladder.signature_ledger_path`
 * lets phpunit point at a tmp file so the real evidence ledger is never touched.
 *
 * @see \App\Services\Ai\Autonomy\AtlasAutonomousLearningApplier::decideCandidate()
 */
final class AtlasAutonomyLadderSignatureLedger
{
    public const SCHEMA = 'atlas.autonomy.ladder_signature_receipt.v1';

    public const KIND_RECEIPT = 'receipt';

    public const KIND_SPENT = 'spent';

    /** @var array<string,true> in-memory nonce cache to short-circuit replays without re-reading the ledger each time */
    private array $spentNonces = [];

    public function __construct(private readonly ?string $path = null) {}

    /**
     * Record a new signature receipt. Always append; never mutate.
     *
     * @param  array<string,mixed>  $context  optional provenance for audit only
     * @return array<string,mixed> the persisted receipt payload
     */
    public function record(
        string $signature,
        string $actor,
        string $nonce,
        string $policyHash,
        string $targetKind,
        string $targetId,
        array $context = [],
    ): array {
        $receipt = [
            'schema_version' => self::SCHEMA,
            'kind' => self::KIND_RECEIPT,
            'signature' => $signature,
            'actor' => $actor,
            'nonce' => $nonce,
            'policy_hash' => $policyHash,
            'target_kind' => $targetKind,
            'target_id' => $targetId,
            'issued_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'context' => $context,
        ];
        $this->append($receipt);

        return $receipt;
    }

    /**
     * Verify a signature claim against the ledger. Returns a verdict envelope
     * with the reason so the caller can propagate it verbatim.
     *
     * @return array{ok: bool, reason: string, receipt: array<string,mixed>|null}
     */
    public function verify(
        string $signature,
        string $actor,
        string $nonce,
        string $policyHash,
        string $targetKind,
        string $targetId,
    ): array {
        if ($nonce === '' || $policyHash === '' || $signature === '' || $actor === '' || $targetKind === '' || $targetId === '') {
            return ['ok' => false, 'reason' => 'signature_receipt_field_missing', 'receipt' => null];
        }

        if (isset($this->spentNonces[$nonce])) {
            return ['ok' => false, 'reason' => 'signature_nonce_reused', 'receipt' => null];
        }

        $matched = null;
        $spent = false;
        foreach ($this->replay() as $row) {
            if (($row['nonce'] ?? '') !== $nonce) {
                continue;
            }
            if (($row['kind'] ?? '') === self::KIND_SPENT) {
                $spent = true;
                continue;
            }
            if (($row['signature'] ?? '') !== $signature) {
                continue;
            }
            if (($row['actor'] ?? '') !== $actor) {
                continue;
            }
            if (($row['policy_hash'] ?? '') !== $policyHash) {
                continue;
            }
            if (($row['target_kind'] ?? '') !== $targetKind) {
                continue;
            }
            if (($row['target_id'] ?? '') !== $targetId) {
                continue;
            }
            $matched = $row;
        }

        if ($spent) {
            $this->spentNonces[$nonce] = true;

            return ['ok' => false, 'reason' => 'signature_nonce_reused', 'receipt' => null];
        }
        if ($matched === null) {
            return ['ok' => false, 'reason' => 'signature_receipt_missing', 'receipt' => null];
        }

        $this->markSpent($signature, $actor, $nonce, $policyHash, $targetKind, $targetId);
        $this->spentNonces[$nonce] = true;

        return ['ok' => true, 'reason' => 'signature_receipt_verified', 'receipt' => $matched];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function replay(): array
    {
        $path = $this->resolvePath();
        if ($path === '' || ! is_file($path)) {
            return [];
        }
        $rows = [];
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }
        while (($line = fgets($handle)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }
        fclose($handle);

        return $rows;
    }

    private function markSpent(string $signature, string $actor, string $nonce, string $policyHash, string $targetKind, string $targetId): void
    {
        $this->append([
            'schema_version' => self::SCHEMA,
            'kind' => self::KIND_SPENT,
            'signature' => $signature,
            'actor' => $actor,
            'nonce' => $nonce,
            'policy_hash' => $policyHash,
            'target_kind' => $targetKind,
            'target_id' => $targetId,
            'spent_at' => CarbonImmutable::now('UTC')->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function append(array $payload): void
    {
        $path = $this->resolvePath();
        if ($path === '') {
            return;
        }
        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
        if (! is_string($line)) {
            return;
        }
        @file_put_contents($path, $line."\n", FILE_APPEND | LOCK_EX);
    }

    private function resolvePath(): string
    {
        return $this->path
            ?? (string) config('atlas.ai.autonomy_ladder.signature_ledger_path', '');
    }
}
