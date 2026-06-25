<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Federation;

use RuntimeException;

/**
 * Federation FACT sync protocol — publish/subscribe between Loops.
 *
 * Publish: writes envelopes `{peer_id, fact_kind, fact_id, seq, content_hash, observed_at, payload}`.
 * Subscribe: validates envelope (schema, content_hash, peer registered) and idempotently records
 * `(peer_id, fact_id)` to the seen-set. Fail-closed on unregistered peer or hash mismatch.
 *
 * Local-first: transport is pluggable but defaults to file:// (publish to disk, subscribe from
 * disk). NO scores are sent — facts only.
 */
final class AtlasLoopFederationFactSyncProtocol
{
    public const SCHEMA = 'atlas.loop.federation_fact_envelope.v1';

    private int $seqCounter = 0;

    public function __construct(
        private readonly AtlasLoopFederationPeerRegistry $peerRegistry,
        private readonly string $outboxPath,
        private readonly string $seenSetPath,
    ) {
        foreach ([dirname($this->outboxPath), dirname($this->seenSetPath)] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0o755, true);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $fact  {fact_id, fact_kind, payload, observed_at?}
     * @return array<string,mixed>  the published envelope
     */
    public function publish(string $peerId, array $fact): array
    {
        $peer = $this->peerRegistry->get($peerId);
        if ($peer === null) {
            throw new FederationFactRejectedException('publish_target_peer_not_registered:'.$peerId);
        }

        $factId = (string) ($fact['fact_id'] ?? '');
        $factKind = (string) ($fact['fact_kind'] ?? '');
        if ($factId === '' || $factKind === '') {
            throw new FederationFactRejectedException('publish_missing_required_fact_fields');
        }

        $payload = $fact['payload'] ?? null;
        $contentHash = $this->contentHash($payload);

        $this->seqCounter++;
        $envelope = [
            'schema' => self::SCHEMA,
            'peer_id' => $peerId,
            'fact_kind' => $factKind,
            'fact_id' => $factId,
            'seq' => $this->seqCounter,
            'content_hash' => $contentHash,
            'observed_at' => (string) ($fact['observed_at'] ?? gmdate('Y-m-d\TH:i:s\Z')),
            'payload' => $payload,
        ];

        $this->appendLine($this->outboxPath, $envelope);

        return $envelope;
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array{accepted:bool, reason:string}
     */
    public function subscribe(array $envelope): array
    {
        foreach (['peer_id', 'fact_kind', 'fact_id', 'seq', 'content_hash', 'observed_at', 'payload'] as $field) {
            if (! array_key_exists($field, $envelope)) {
                throw new FederationFactRejectedException('subscribe_envelope_missing_field:'.$field);
            }
        }

        $peerId = (string) $envelope['peer_id'];
        if ($this->peerRegistry->get($peerId) === null) {
            throw new FederationFactRejectedException('subscribe_peer_not_registered:'.$peerId);
        }

        $expectedHash = $this->contentHash($envelope['payload']);
        if (! hash_equals($expectedHash, (string) $envelope['content_hash'])) {
            throw new FederationFactRejectedException('subscribe_content_hash_mismatch');
        }

        $seenKey = $peerId.'|'.(string) $envelope['fact_id'];
        if ($this->alreadySeen($seenKey)) {
            return ['accepted' => true, 'reason' => 'idempotent_replay'];
        }
        $this->markSeen($seenKey);

        return ['accepted' => true, 'reason' => 'recorded'];
    }

    private function contentHash(mixed $payload): string
    {
        $canonical = is_array($payload)
            ? $this->canonicalize($payload)
            : (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return hash('sha256', is_string($canonical) ? $canonical : json_encode($canonical, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @param  array<string,mixed>  $value
     */
    private function canonicalize(array $value): string
    {
        // Recursively sort keys so identical content ⇒ identical bytes.
        $walk = function ($v) use (&$walk) {
            if (! is_array($v)) {
                return $v;
            }
            $isAssoc = array_keys($v) !== range(0, count($v) - 1);
            if ($isAssoc) {
                ksort($v, SORT_STRING);
            }
            foreach ($v as $k => $sub) {
                $v[$k] = $walk($sub);
            }

            return $v;
        };

        return (string) json_encode($walk($value), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<string,mixed>  $envelope
     */
    private function appendLine(string $path, array $envelope): void
    {
        $line = json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $handle = @fopen($path, 'a');
        if ($handle === false) {
            throw new RuntimeException('AtlasLoopFederationFactSyncProtocol: cannot open '.$path);
        }
        try {
            fwrite($handle, $line."\n");
            fflush($handle);
        } finally {
            fclose($handle);
        }
    }

    private function alreadySeen(string $key): bool
    {
        if (! is_file($this->seenSetPath)) {
            return false;
        }
        $handle = @fopen($this->seenSetPath, 'r');
        if ($handle === false) {
            return false;
        }
        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) === $key) {
                    return true;
                }
            }
        } finally {
            fclose($handle);
        }

        return false;
    }

    private function markSeen(string $key): void
    {
        $handle = @fopen($this->seenSetPath, 'a');
        if ($handle === false) {
            return;
        }
        try {
            fwrite($handle, $key."\n");
            fflush($handle);
        } finally {
            fclose($handle);
        }
    }
}

final class FederationFactRejectedException extends RuntimeException
{
}
