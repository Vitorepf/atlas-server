<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * AP-786 · Autonomous Evolution Session — read-only read model.
 *
 * Reads the append-only session receipts written by
 * {@see AutonomousEvolutionSessionService::record()} so Product Mode and the
 * Operational Inbox can surface what a real loop run did, per cycle, without
 * coupling to the heavy execution service. It NEVER scans, executes providers,
 * materializes branches, merges or writes anything. Pure JSONL projection.
 *
 * The storage convention mirrors the writer exactly so a recorded session is
 * discoverable: `<storageDir>/<area-slug>.jsonl`.
 */
final class AutonomousEvolutionSessionReadModelService
{
    public const SCHEMA = 'atlas.software_company_stewardship.autonomous_evolution_session_read_model.v1';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    private ?string $storageDirOverride = null;

    public function setStorageRootForTesting(?string $dir): void
    {
        $this->storageDirOverride = $dir !== null ? rtrim($dir, DIRECTORY_SEPARATOR) : null;
    }

    public function storageDir(): string
    {
        if ($this->storageDirOverride !== null) {
            return $this->storageDirOverride;
        }

        return function_exists('storage_path')
            ? storage_path('atlas/software_company_stewardship/autonomous_evolution_sessions')
            : sys_get_temp_dir().'/atlas/software_company_stewardship/autonomous_evolution_sessions';
    }

    public function recordPath(string $areaId): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$this->slug($areaId).'.jsonl';
    }

    /**
     * Most-recent recorded sessions for an area, oldest-first within the window.
     *
     * @return list<array<string,mixed>>
     */
    public function listSessions(string $areaId, int $limit = 5): array
    {
        $limit = max(1, $limit);
        $path = $this->recordPath($areaId);
        if (! is_file($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $records = [];
        foreach (array_slice($lines, -$limit) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $records[] = $decoded;
            }
        }

        return $records;
    }

    /**
     * Read-only projection envelope (used by a CLI/inspection surface if needed).
     *
     * @return array<string,mixed>
     */
    public function project(string $areaId = self::DEFAULT_AREA_ID, int $limit = 5): array
    {
        $sessions = $this->listSessions($areaId, $limit);

        return [
            'schema_version' => self::SCHEMA,
            'area_id' => $this->slug($areaId),
            'read_only' => true,
            'session_count' => count($sessions),
            'sessions' => $sessions,
            'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
        ];
    }

    private function slug(string $value): string
    {
        $slug = strtolower((string) preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)));

        return trim($slug, '_') ?: self::DEFAULT_AREA_ID;
    }
}
