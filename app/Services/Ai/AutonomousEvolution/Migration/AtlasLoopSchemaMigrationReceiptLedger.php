<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Migration;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;
use RuntimeException;

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
        $this->ensureDirectory(dirname($this->path));

        $previousHash = null;
        $existing = $this->list();
        if ($existing !== []) {
            $previousHash = $existing[array_key_last($existing)]->postHash;
        }

        $stored = $receipt->withPostHash($this->hashChainFor($receipt, $previousHash));
        $line = CanonicalJson::encode($stored->toArray()).PHP_EOL;

        $fp = fopen($this->path, 'ab');
        if ($fp === false) {
            throw new RuntimeException("Could not open {$this->path} for append.");
        }

        try {
            if (! flock($fp, LOCK_EX)) {
                throw new RuntimeException("Could not lock {$this->path} for append.");
            }
            fwrite($fp, $line);
            fflush($fp);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        return $stored;
    }

    /**
     * @return list<AtlasLoopSchemaMigrationReceipt>
     */
    public function list(?string $artifactKind = null, ?string $fromTs = null, ?string $toTs = null): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $rows = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($rows === false) {
            throw new RuntimeException("Could not read {$this->path}.");
        }

        $receipts = [];
        foreach ($rows as $row) {
            $decoded = json_decode($row, true);
            if (! is_array($decoded)) {
                continue;
            }

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

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create directory {$dir}.");
        }
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
