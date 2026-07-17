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
        $artifacts = AiValueNormalizer::arrayOrEmpty($this->manifest['artifacts'] ?? null);
        $rows = array_values(array_map(function ($entry): array {
            return $this->verifyOne(AiValueNormalizer::arrayOrEmpty($entry));
        }, $artifacts));

        $status = array_count_values(array_map(static fn (array $row): string => AiValueNormalizer::trimmedScalarStringOrNull($row['status'] ?? null) ?? '', $rows));

        return [
            'schema_version' => AiValueNormalizer::trimmedStringOrNull($this->manifest['schema_version'] ?? null) ?? self::MANIFEST_SCHEMA,
            'total' => count($rows),
            self::FIELD_VERIFIED => $status[self::STATUS_VERIFIED] ?? 0,
            self::FIELD_MISMATCHED => $status[self::STATUS_MISMATCHED] ?? 0,
            self::FIELD_MISSING => $status[self::STATUS_MISSING] ?? 0,
            self::FIELD_UNPINNED => $status[self::STATUS_UNPINNED] ?? 0,
            'artifacts' => $rows,
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    public function verifyOne(array $entry): array
    {
        $modelId = AiValueNormalizer::trimmedStringOrNull($entry['model_id'] ?? null) ?? '';
        $path = AiValueNormalizer::trimmedStringOrNull($entry['path'] ?? null) ?? '';
        $pin = AiValueNormalizer::lowerTrimmedString($entry['sha256_pin'] ?? '');

        $row = [
            'model_id' => $modelId !== '' ? $modelId : self::FALLBACK_MODEL_ID,
            'function' => (AiValueNormalizer::trimmedStringOrNull($entry['function'] ?? null) ?? ''),
            'license' => (AiValueNormalizer::trimmedStringOrNull($entry['license'] ?? null) ?? ''),
            'source_url' => (AiValueNormalizer::trimmedStringOrNull($entry['source_url'] ?? null) ?? ''),
            'path_declared' => $path,
            'path_resolved' => null,
            'pin_present' => $pin !== '',
            'sha256_pin' => $pin !== '' ? $pin : null,
            'sha256_computed' => null,
            'model_verified' => false,
            'status' => self::STATUS_UNKNOWN,
            'reason' => null,
        ];

        if ($modelId === '') {
            $row['status'] = self::STATUS_INVALID;
            $row['reason'] = 'model_id_missing';

            return $row;
        }

        if ($pin === '') {
            $row['status'] = self::STATUS_UNPINNED;
            $row['reason'] = 'sha256_pin_absent';

            return $row;
        }

        if ($path === '') {
            $row['status'] = self::STATUS_MISSING;
            $row['reason'] = 'path_not_configured';

            return $row;
        }

        $resolved = AiValueNormalizer::trimmedStringOrNull($this->resolvePath($path));
        $row['path_resolved'] = $resolved;

        if ($resolved === null || ! is_file($resolved) || ! is_readable($resolved)) {
            $row['status'] = self::STATUS_MISSING;
            $row['reason'] = 'artifact_unreadable';

            return $row;
        }

        $computed = @hash_file('sha256', $resolved);
        if (AiValueNormalizer::trimmedStringOrNull($computed) === null) {
            $row['status'] = self::STATUS_MISSING;
            $row['reason'] = 'hash_failed';

            return $row;
        }

        $row['sha256_computed'] = $computed;
        if (hash_equals($pin, $computed)) {
            $row['model_verified'] = true;
            $row['status'] = self::STATUS_VERIFIED;
        } else {
            $row['status'] = self::STATUS_MISMATCHED;
            $row['reason'] = 'sha256_mismatch';
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
