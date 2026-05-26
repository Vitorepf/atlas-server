<?php

declare(strict_types=1);

namespace App\Services\Ai\Governance;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;

/**
 * Atlas Constitutional Vault Service — Patamar 4 separation canon.
 *
 * Vault assinado separado do código-fonte. Os invariantes pétreos vivem
 * em `storage/atlas/governance/constitutional_vault.json` (fora do path
 * de auto-modificação) com HMAC-SHA256 derivado de chave externa
 * (env `ATLAS_KERNEL_VAULT_KEY` ou arquivo `storage/atlas/governance/.vault_key`).
 *
 * On-boot:
 *   1. Load vault arquivo.
 *   2. Verify signature contra chave externa.
 *   3. Compara conteúdo com invariantes in-code do Kernel (snapshot fonte).
 *   4. Emite violation se mismatch.
 *
 * O service NÃO modifica o Kernel. É só uma camada externa de verificação.
 * O caller (AppServiceProvider.boot) pode escolher abortar boot se
 * signature inválida — pétreo invariant: vault tampered = kernel inválido.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-constitutional-vault.md
 *
 * Schema: atlas.constitutional.vault.v1
 */
final class AtlasConstitutionalVaultService
{
    public const VAULT_SCHEMA = 'atlas.constitutional.vault.v1';

    public const VERIFY_OK = 'ok';

    public const VERIFY_MISSING = 'vault_missing';

    public const VERIFY_KEY_MISSING = 'key_missing';

    public const VERIFY_SIGNATURE_INVALID = 'signature_invalid';

    public const VERIFY_KERNEL_DRIFT = 'kernel_drift';

    public const VERIFY_MALFORMED = 'malformed';

    private ?string $vaultPathOverride = null;

    private ?string $keyPathOverride = null;

    private ?string $envKeyOverride = null;

    public function __construct(
        private readonly AtlasConstitutionalKernelService $kernel,
    ) {}

    public function setVaultPathForTesting(?string $path): void
    {
        $this->vaultPathOverride = $path;
    }

    public function setKeyPathForTesting(?string $path): void
    {
        $this->keyPathOverride = $path;
    }

    public function setEnvKeyForTesting(?string $key): void
    {
        $this->envKeyOverride = $key;
    }

    public function vaultPath(): string
    {
        if ($this->vaultPathOverride !== null) {
            return $this->vaultPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/governance')
            : sys_get_temp_dir().'/atlas/governance';

        return $base.DIRECTORY_SEPARATOR.'constitutional_vault.json';
    }

    public function keyPath(): string
    {
        if ($this->keyPathOverride !== null) {
            return $this->keyPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/governance')
            : sys_get_temp_dir().'/atlas/governance';

        return $base.DIRECTORY_SEPARATOR.'.vault_key';
    }

    /**
     * Resolve HMAC key: env override → env var → file.
     */
    public function resolveKey(): ?string
    {
        if ($this->envKeyOverride !== null) {
            return $this->envKeyOverride;
        }
        $envKey = getenv('ATLAS_KERNEL_VAULT_KEY');
        if (is_string($envKey) && $envKey !== '') {
            return $envKey;
        }
        $kp = $this->keyPath();
        if (is_file($kp)) {
            $content = trim((string) @file_get_contents($kp));

            return $content !== '' ? $content : null;
        }

        return null;
    }

    /**
     * Compute deterministic snapshot of canonical invariants from the
     * in-code Kernel (source of truth at PR time). Used both to sign and
     * to compare against vault content on verification.
     *
     * @return array<string,mixed>
     */
    public function kernelSnapshot(): array
    {
        $invariants = $this->kernel->listInvariants();
        // Strip schema_version + freeze canonical fields.
        $canonical = array_map(static fn (array $i): array => [
            'id' => $i['id'],
            'class' => $i['class'],
            'statement' => $i['statement'],
            'enabled' => $i['enabled'],
        ], $invariants);
        usort($canonical, static fn ($a, $b): int => strcmp($a['id'], $b['id']));

        return [
            'invariants' => $canonical,
            'kernel_hash' => $this->kernel->kernelHash(),
        ];
    }

    /**
     * Sign current Kernel snapshot and write vault file. Operator-driven —
     * never called automatically. Re-signs only when invariants change
     * intentionally (PR + redeploy).
     *
     * @return array<string,mixed>
     */
    public function sign(string $actor, string $reason): array
    {
        if (trim($actor) === '' || trim($reason) === '') {
            throw new InvalidArgumentException('sign requires non-empty actor and reason.');
        }
        $key = $this->resolveKey();
        if ($key === null) {
            throw new RuntimeException('vault key not resolvable — set ATLAS_KERNEL_VAULT_KEY env var or create key file.');
        }
        $snapshot = $this->kernelSnapshot();
        $signedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $payload = [
            'schema_version' => self::VAULT_SCHEMA,
            'signed_at' => $signedAt,
            'actor' => $actor,
            'reason' => $reason,
            'invariants' => $snapshot['invariants'],
            'kernel_hash' => $snapshot['kernel_hash'],
        ];
        $payload['signature'] = $this->hmac($payload, $key);

        $dir = dirname($this->vaultPath());
        if (! is_dir($dir)) {
            if (function_exists('app')) {
                File::ensureDirectoryExists($dir);
            } else {
                @mkdir($dir, 0775, true);
            }
        }
        $bytes = file_put_contents(
            $this->vaultPath(),
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        if ($bytes === false) {
            throw new RuntimeException('failed to write vault file');
        }

        return $payload;
    }

    /**
     * Verify vault: file exists, valid JSON, signature matches key,
     * AND vault invariants match current Kernel in-code snapshot.
     *
     * @return array<string,mixed>
     */
    public function verify(): array
    {
        $startedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $key = $this->resolveKey();
        if ($key === null) {
            return $this->buildVerify(self::VERIFY_KEY_MISSING, $startedAt, null, 'vault key not resolvable');
        }

        $vp = $this->vaultPath();
        if (! is_file($vp)) {
            return $this->buildVerify(self::VERIFY_MISSING, $startedAt, null, 'vault file does not exist');
        }
        $raw = (string) @file_get_contents($vp);
        $payload = json_decode($raw, true);
        if (! is_array($payload) || ! isset($payload['invariants'], $payload['signature'])) {
            return $this->buildVerify(self::VERIFY_MALFORMED, $startedAt, null, 'vault json malformed');
        }

        $storedSig = (string) $payload['signature'];
        $verifyPayload = $payload;
        unset($verifyPayload['signature']);
        $computedSig = $this->hmac($verifyPayload, $key);
        if (! hash_equals($computedSig, $storedSig)) {
            return $this->buildVerify(self::VERIFY_SIGNATURE_INVALID, $startedAt, $payload, 'HMAC signature mismatch');
        }

        // Compare canonical content with current in-code Kernel.
        $current = $this->kernelSnapshot();
        $storedInvariants = (array) ($payload['invariants'] ?? []);
        $currentInvariants = $current['invariants'];
        if (json_encode($storedInvariants) !== json_encode($currentInvariants)) {
            return $this->buildVerify(self::VERIFY_KERNEL_DRIFT, $startedAt, $payload, 'vault invariants differ from current Kernel in-code snapshot');
        }

        return $this->buildVerify(self::VERIFY_OK, $startedAt, $payload, 'vault signature matches; kernel in sync');
    }

    /**
     * Convenience: read raw vault payload (no verification).
     *
     * @return array<string,mixed>|null
     */
    public function read(): ?array
    {
        $vp = $this->vaultPath();
        if (! is_file($vp)) {
            return null;
        }
        $raw = (string) @file_get_contents($vp);
        $payload = json_decode($raw, true);

        return is_array($payload) ? $payload : null;
    }

    // ---------- internals ----------

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hmac(array $payload, string $key): string
    {
        $canonical = json_encode([
            'schema_version' => $payload['schema_version'] ?? null,
            'signed_at' => $payload['signed_at'] ?? null,
            'actor' => $payload['actor'] ?? null,
            'invariants' => $payload['invariants'] ?? null,
            'kernel_hash' => $payload['kernel_hash'] ?? null,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'sha256:'.hash_hmac('sha256', (string) $canonical, $key);
    }

    /**
     * @param  array<string,mixed>|null  $payload
     * @return array<string,mixed>
     */
    private function buildVerify(string $status, string $startedAt, ?array $payload, string $note): array
    {
        return [
            'schema_version' => self::VAULT_SCHEMA,
            'verify_status' => $status,
            'verified_at' => $startedAt,
            'vault_path' => $this->vaultPath(),
            'note' => $note,
            'vault_signed_at' => $payload['signed_at'] ?? null,
            'vault_actor' => $payload['actor'] ?? null,
            'vault_kernel_hash' => $payload['kernel_hash'] ?? null,
            'current_kernel_hash' => $this->kernel->kernelHash(),
        ];
    }
}
