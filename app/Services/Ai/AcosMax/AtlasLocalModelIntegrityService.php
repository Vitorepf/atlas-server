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
    /** @var array<string, mixed> */
    private array $manifest;

    /** @param array<string,mixed>|null $manifest */
    public function __construct(?array $manifest = null)
    {
        $this->manifest = $manifest ?? AiValueNormalizer::arrayOrEmpty(config('atlas_model_manifest', []));
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

        $status = array_count_values(array_map(static fn (array $row): string => (string) $row['status'], $rows));

        return [
            'schema_version' => (string) ($this->manifest['schema_version'] ?? 'atlas.model_integrity_manifest.v1'),
            'total' => count($rows),
            'verified' => $status['verified'] ?? 0,
            'mismatched' => $status['mismatched'] ?? 0,
            'missing' => $status['missing'] ?? 0,
            'unpinned' => $status['unpinned'] ?? 0,
            'artifacts' => $rows,
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    public function verifyOne(array $entry): array
    {
        $modelId = AiValueNormalizer::trimmedString($entry['model_id'] ?? '');
        $path = AiValueNormalizer::trimmedString($entry['path'] ?? '');
        $pin = AiValueNormalizer::lowerTrimmedString($entry['sha256_pin'] ?? '');

        $row = [
            'model_id' => $modelId !== '' ? $modelId : 'unknown',
            'function' => (string) ($entry['function'] ?? ''),
            'license' => (string) ($entry['license'] ?? ''),
            'source_url' => (string) ($entry['source_url'] ?? ''),
            'path_declared' => $path,
            'path_resolved' => null,
            'pin_present' => $pin !== '',
            'sha256_pin' => $pin !== '' ? $pin : null,
            'sha256_computed' => null,
            'model_verified' => false,
            'status' => 'unknown',
            'reason' => null,
        ];

        if ($modelId === '') {
            $row['status'] = 'invalid';
            $row['reason'] = 'model_id_missing';

            return $row;
        }

        if ($pin === '') {
            $row['status'] = 'unpinned';
            $row['reason'] = 'sha256_pin_absent';

            return $row;
        }

        if ($path === '') {
            $row['status'] = 'missing';
            $row['reason'] = 'path_not_configured';

            return $row;
        }

        $resolved = AiValueNormalizer::trimmedStringOrNull($this->resolvePath($path));
        $row['path_resolved'] = $resolved;

        if ($resolved === null || ! is_file($resolved) || ! is_readable($resolved)) {
            $row['status'] = 'missing';
            $row['reason'] = 'artifact_unreadable';

            return $row;
        }

        $computed = @hash_file('sha256', $resolved);
        if (AiValueNormalizer::trimmedStringOrNull($computed) === null) {
            $row['status'] = 'missing';
            $row['reason'] = 'hash_failed';

            return $row;
        }

        $row['sha256_computed'] = $computed;
        if (hash_equals($pin, $computed)) {
            $row['model_verified'] = true;
            $row['status'] = 'verified';
        } else {
            $row['status'] = 'mismatched';
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
