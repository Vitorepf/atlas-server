<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * ELEV-19 — Manifest de integridade dos modelos locais.
 *
 * Boot-time e read-time verification: para cada artefato pinado no manifest,
 * confere sha256 do arquivo em disco contra `sha256_pin`. Mismatch = alert +
 * `verified=false`; artefato ausente = `missing`; pin ausente = `unpinned`
 * (advisory, não bloqueia). NUNCA fabrica hash silencioso.
 *
 * Fail-open no boot: uma falha aqui não derruba o serviço. O alerta é publicado
 * via WDG (`elev-19.local_model_integrity`).
 */
final class AtlasLocalModelIntegrityService
{
    public const MANIFEST_SCHEMA = 'atlas.model_integrity_manifest.v1';

    public const MANIFEST_CONFIG_KEY = 'atlas_model_manifest';

    public const FALLBACK_MODEL_ID = 'unknown';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_UNPINNED = 'unpinned';

    public const STATUS_MISSING = 'missing';

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_MISMATCHED = 'mismatched';

    public const FIELD_VERIFIED = 'verified';

    public const FIELD_MISMATCHED = 'mismatched';

    public const FIELD_MISSING = 'missing';

    public const FIELD_UNPINNED = 'unpinned';
    public const FIELD_STATUS = 'status';
    public const FIELD_REASON = 'reason';
    public const FIELD_OK = 'ok';
    public const FIELD_CHECKS = 'checks';
    public const FIELD_MODEL_ID = 'model_id';
    public const FIELD_INTEGRITY = 'integrity';
    public const FIELD_HASH = 'hash';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_ARTIFACTS = 'artifacts';
    public const FIELD_FUNCTION = 'function';
    public const FIELD_LICENSE = 'license';
    public const FIELD_SOURCE_URL = 'source_url';
    public const FIELD_PATH_RESOLVED = 'path_resolved';
    public const FIELD_MODEL_VERIFIED = 'model_verified';
    public const FIELD_SHA256_COMPUTED = 'sha256_computed';
    public const FIELD_SHA256_PIN = 'sha256_pin';

    /** @var array<string, mixed> */
    private array $manifest;

    /** @param array<string,mixed>|null $manifest */
    public function __construct(?array $manifest = null)
    {
        $this->manifest = $manifest ?? AiValueNormalizer::arrayOrEmpty(config(self::MANIFEST_CONFIG_KEY, []));
    }

    /**
     * @return array{
     *     schema_version:string,
     *     total:int,
     *     verified:int,
     *     mismatched:int,
     *     missing:int,
     *     unpinned:int,
     *     artifacts:list<array<string,mixed>>
     * }
     */
    public function verifyAll(): array
    {
        $artifacts = AiValueNormalizer::arrayOrEmpty($this->manifest[self::FIELD_ARTIFACTS] ?? null);
        $rows = array_values(array_map(function ($entry): array {
            return $this->verifyOne(AiValueNormalizer::arrayOrEmpty($entry));
        }, $artifacts));

        $status = array_count_values(array_map(static fn (array $row): string => AiValueNormalizer::trimmedScalarStringOrNull($row[self::FIELD_STATUS] ?? null) ?? '', $rows));

        return [
            self::FIELD_SCHEMA_VERSION => AiValueNormalizer::trimmedStringOrNull($this->manifest[self::FIELD_SCHEMA_VERSION] ?? null) ?? self::MANIFEST_SCHEMA,
            'total' => count($rows),
            self::FIELD_VERIFIED => $status[self::STATUS_VERIFIED] ?? 0,
            self::FIELD_MISMATCHED => $status[self::STATUS_MISMATCHED] ?? 0,
            self::FIELD_MISSING => $status[self::STATUS_MISSING] ?? 0,
            self::FIELD_UNPINNED => $status[self::STATUS_UNPINNED] ?? 0,
            self::FIELD_ARTIFACTS => $rows,
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    public function verifyOne(array $entry): array
    {
        $modelId = AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_MODEL_ID] ?? null) ?? '';
        $path = AiValueNormalizer::trimmedStringOrNull($entry['path'] ?? null) ?? '';
        $pin = AiValueNormalizer::lowerTrimmedString($entry[self::FIELD_SHA256_PIN] ?? '');

        $row = [
            self::FIELD_MODEL_ID => $modelId !== '' ? $modelId : self::FALLBACK_MODEL_ID,
            self::FIELD_FUNCTION => (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_FUNCTION] ?? null) ?? ''),
            self::FIELD_LICENSE => (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_LICENSE] ?? null) ?? ''),
            self::FIELD_SOURCE_URL => (AiValueNormalizer::trimmedStringOrNull($entry[self::FIELD_SOURCE_URL] ?? null) ?? ''),
            'path_declared' => $path,
            self::FIELD_PATH_RESOLVED => null,
            'pin_present' => $pin !== '',
            self::FIELD_SHA256_PIN => $pin !== '' ? $pin : null,
            self::FIELD_SHA256_COMPUTED => null,
            self::FIELD_MODEL_VERIFIED => false,
            self::FIELD_STATUS => self::STATUS_UNKNOWN,
            self::FIELD_REASON => null,
        ];

        if ($modelId === '') {
            $row[self::FIELD_STATUS] = self::STATUS_INVALID;
            $row[self::FIELD_REASON] = 'model_id_missing';

            return $row;
        }

        if ($pin === '') {
            $row[self::FIELD_STATUS] = self::STATUS_UNPINNED;
            $row[self::FIELD_REASON] = 'sha256_pin_absent';

            return $row;
        }

        if ($path === '') {
            $row[self::FIELD_STATUS] = self::STATUS_MISSING;
            $row[self::FIELD_REASON] = 'path_not_configured';

            return $row;
        }

        $resolved = AiValueNormalizer::trimmedStringOrNull($this->resolvePath($path));
        $row[self::FIELD_PATH_RESOLVED] = $resolved;

        if ($resolved === null || ! is_file($resolved) || ! is_readable($resolved)) {
            $row[self::FIELD_STATUS] = self::STATUS_MISSING;
            $row[self::FIELD_REASON] = 'artifact_unreadable';

            return $row;
        }

        $computed = @hash_file('sha256', $resolved);
        if (AiValueNormalizer::trimmedStringOrNull($computed) === null) {
            $row[self::FIELD_STATUS] = self::STATUS_MISSING;
            $row[self::FIELD_REASON] = 'hash_failed';

            return $row;
        }

        $row[self::FIELD_SHA256_COMPUTED] = $computed;
        if (hash_equals($pin, $computed)) {
            $row[self::FIELD_MODEL_VERIFIED] = true;
            $row[self::FIELD_STATUS] = self::STATUS_VERIFIED;
        } else {
            $row[self::FIELD_STATUS] = self::STATUS_MISMATCHED;
            $row[self::FIELD_REASON] = 'sha256_mismatch';
        }

        return $row;
    }

    private function resolvePath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        if ($this->isAbsolute($path)) {
            return $path;
        }

        if (function_exists('base_path')) {
            return base_path($path);
        }

        return $path;
    }

    private function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        return $path[0] === DIRECTORY_SEPARATOR
            || (strlen($path) > 1 && $path[1] === ':')
            || str_starts_with($path, '/');
    }
}
