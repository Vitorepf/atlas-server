<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution;

use RuntimeException;

/**
 * Records and restores AAEL execution safe-state checkpoints.
 *
 * snapshot(state) writes a content-addressed JSON blob (atomic temp + rename) under
 * `config('atlas.aael.execution.safe_state_dir', storage_path('atlas/aael/safe-state'))`, returning
 * a `checkpoint_id` = sha1 of the canonical-encoded payload. The same input always produces the
 * same id — byte-identical — and the writer refuses to overwrite an existing checkpoint with the
 * same id (idempotent).
 *
 * recover(id) reads the requested checkpoint (or the most-recent by mtime when id is null) and
 * returns FACTS: { checkpoint_id, recovered_state, recovered_at_iso, source_path }. If the file's
 * sha1 does not match its id (tampered), recover() throws a RuntimeException whose message includes
 * the literal 'integrity' AND the checkpoint id.
 */
final class AtlasAaelExecutionSafeStateRecoverer
{
    public const SCHEMA = 'atlas.aael.execution.safe_state.v1';

    public function __construct(private readonly string $safeStateDir)
    {
        if (! is_dir($this->safeStateDir)) {
            @mkdir($this->safeStateDir, 0o755, true);
        }
    }

    /**
     * @param  array<string,mixed>  $state
     */
    public function snapshot(array $state): string
    {
        $payload = $this->canonical($state);
        $id = sha1($payload);
        $path = $this->pathFor($id);

        if (is_file($path)) {
            // Idempotent: same id ⇒ same canonical bytes ⇒ no rewrite.
            if ((string) @file_get_contents($path) !== $payload) {
                throw new RuntimeException('safe_state_id_collision_mismatch:'.$id);
            }

            return $id;
        }

        $tmp = $path.'.tmp.'.bin2hex(random_bytes(6));
        if (@file_put_contents($tmp, $payload, LOCK_EX) === false) {
            throw new RuntimeException('safe_state_write_failed:'.$path);
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('safe_state_rename_failed:'.$path);
        }

        return $id;
    }

    /**
     * @return array<string,mixed>
     */
    public function recover(?string $id = null): array
    {
        $path = $id !== null ? $this->pathFor($id) : $this->mostRecentPath();
        if ($path === null || ! is_file($path)) {
            throw new RuntimeException('safe_state_not_found:'.($id ?? '<latest>'));
        }
        $resolvedId = $id ?? basename($path, '.json');

        $bytes = (string) @file_get_contents($path);
        $observedSha1 = sha1($bytes);
        if ($observedSha1 !== $resolvedId) {
            throw new RuntimeException('safe_state_integrity_violation:checkpoint='.$resolvedId.' observed='.$observedSha1);
        }

        $decoded = json_decode($bytes, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('safe_state_payload_not_array:'.$resolvedId);
        }

        return [
            'schema_version' => self::SCHEMA,
            'checkpoint_id' => $resolvedId,
            'recovered_state' => $decoded,
            'recovered_at_iso' => date('c'),
            'source_path' => $path,
        ];
    }

    private function pathFor(string $id): string
    {
        return rtrim($this->safeStateDir, '/').'/'.$id.'.json';
    }

    private function mostRecentPath(): ?string
    {
        $files = glob(rtrim($this->safeStateDir, '/').'/*.json');
        if ($files === false || $files === []) {
            return null;
        }
        usort($files, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

        return $files[0];
    }

    /**
     * @param  array<string,mixed>  $state
     */
    private function canonical(array $state): string
    {
        $sorted = $this->ksortDeep($state);

        return (string) json_encode($sorted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param  array<mixed,mixed>  $value
     * @return array<mixed,mixed>
     */
    private function ksortDeep(array $value): array
    {
        $isAssoc = $value !== [] && array_keys($value) !== range(0, count($value) - 1);
        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = $this->ksortDeep($v);
            }
        }
        if ($isAssoc) {
            ksort($value);
        }

        return $value;
    }
}
