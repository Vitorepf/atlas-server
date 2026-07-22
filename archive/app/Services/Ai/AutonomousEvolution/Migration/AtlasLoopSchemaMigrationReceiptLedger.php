<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Migration;

use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AtlasLoopSchemaMigrationReceiptLedger
{
    public function __construct(
        private readonly string $path,
    ) {
        if ($this->path === '') {
            throw new InvalidArgumentException('AtlasLoopSchemaMigrationReceiptLedger.path must not be empty.');
        }
    }

    public function append(AtlasLoopSchemaMigrationReceipt $receipt): AtlasLoopSchemaMigrationReceipt
    {
        $stored = null;
        try {
            // Prev-hash derivation runs INSIDE the store's exclusive lock (TOCTOU-safe).
            (new JsonlReceiptStore($this->path))->appendWith(function (?string $lastLine) use ($receipt, &$stored): array {
                $previousHash = null;
                if ($lastLine !== null) {
                    $decoded = json_decode($lastLine, true);
                    if (is_array($decoded)) {
                        $previousHash = AtlasLoopSchemaMigrationReceipt::fromArray($decoded)->postHash;
                    }
                }
                $stored = $receipt->withPostHash($this->hashChainFor($receipt, $previousHash));

                return $stored->toArray();
            });
        } catch (RuntimeException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RuntimeException($e->getMessage(), 0, $e);
        }

        return $stored;
    }

    /**
     * @return list<AtlasLoopSchemaMigrationReceipt>
     */
    public function list(?string $artifactKind = null, ?string $fromTs = null, ?string $toTs = null): array
    {
        $receipts = [];
        foreach ((new JsonlReceiptStore($this->path))->replay() as $decoded) {
            $receipt = AtlasLoopSchemaMigrationReceipt::fromArray($decoded);
            if ($artifactKind !== null && $receipt->artifactKind !== $artifactKind) {
                continue;
            }
            if ($fromTs !== null && strcmp($receipt->startedAt, $fromTs) < 0) {
                continue;
            }
            if ($toTs !== null && strcmp($receipt->finishedAt, $toTs) > 0) {
                continue;
            }
            $receipts[] = $receipt;
        }

        return $receipts;
    }

    public function verify(): bool
    {
        $previousHash = null;
        foreach ($this->list() as $receipt) {
            $expected = $this->hashChainFor($receipt->withPostHash(null), $previousHash);
            if ($receipt->postHash !== $expected) {
                return false;
            }
            $previousHash = $receipt->postHash;
        }

        return true;
    }

    private function hashChainFor(AtlasLoopSchemaMigrationReceipt $receipt, ?string $previousHash): string
    {
        return hash(
            'sha256',
            ($previousHash ?? 'genesis').'|'.CanonicalJson::encode($receipt->toArrayWithoutPostHash()),
        );
    }
}

final readonly class AtlasLoopSchemaMigrationReceipt
{
    public function __construct(
        public string $artifactKind,
        public int $fromVersion,
        public int $toVersion,
        public string $preHash,
        public ?string $postHash,
        public string $operatorTokenFingerprint,
        public string $verifierResult,
        public string $startedAt,
        public string $finishedAt,
        public string $evidenceReceiptId,
        public string $outcome,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'artifact_kind' => $this->artifactKind,
            'from_version' => $this->fromVersion,
            'to_version' => $this->toVersion,
            'pre_hash' => $this->preHash,
            'post_hash' => $this->postHash,
            'operator_token_fingerprint' => $this->operatorTokenFingerprint,
            'verifier_result' => $this->verifierResult,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'evidence_receipt_id' => $this->evidenceReceiptId,
            'outcome' => $this->outcome,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArrayWithoutPostHash(): array
    {
        $payload = $this->toArray();
        $payload['post_hash'] = null;

        return $payload;
    }

    public function withPostHash(?string $postHash): self
    {
        return new self(
            artifactKind: $this->artifactKind,
            fromVersion: $this->fromVersion,
            toVersion: $this->toVersion,
            preHash: $this->preHash,
            postHash: $postHash,
            operatorTokenFingerprint: $this->operatorTokenFingerprint,
            verifierResult: $this->verifierResult,
            startedAt: $this->startedAt,
            finishedAt: $this->finishedAt,
            evidenceReceiptId: $this->evidenceReceiptId,
            outcome: $this->outcome,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            artifactKind: (string) ($payload['artifact_kind'] ?? ''),
            fromVersion: (int) ($payload['from_version'] ?? 0),
            toVersion: (int) ($payload['to_version'] ?? 0),
            preHash: (string) ($payload['pre_hash'] ?? ''),
            postHash: isset($payload['post_hash']) ? (string) $payload['post_hash'] : null,
            operatorTokenFingerprint: (string) ($payload['operator_token_fingerprint'] ?? ''),
            verifierResult: (string) ($payload['verifier_result'] ?? ''),
            startedAt: (string) ($payload['started_at'] ?? ''),
            finishedAt: (string) ($payload['finished_at'] ?? ''),
            evidenceReceiptId: (string) ($payload['evidence_receipt_id'] ?? ''),
            outcome: (string) ($payload['outcome'] ?? ''),
        );
    }
}
