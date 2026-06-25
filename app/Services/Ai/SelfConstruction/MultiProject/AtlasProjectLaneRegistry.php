<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\MultiProject;

use RuntimeException;

/**
 * Append-only registry of admitted Atlas project stewardship lanes. Pure in-memory; the SAME instance
 * across calls is the state. No code mutation, no workers, no providers, no shell, no git.
 *
 *   register(record)   — add a lane. Idempotent for an identical record. Duplicate project_id with a
 *                        DIFFERENT repo_root is REFUSED unless record.supersedes_prior_lane === true.
 *   get(projectId)     — return the current record for project_id (or null).
 *   listActive()       — return all currently active lanes in INSERTION ORDER.
 */
final class AtlasProjectLaneRegistry
{
    /** @var array<string, array<string,mixed>> */
    private array $byId = [];

    /** @var list<string> insertion-order list of project_ids */
    private array $order = [];

    /**
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    public function register(array $record): array
    {
        $projectId = (string) ($record['project_id'] ?? '');
        $repoRoot = (string) ($record['repo_root'] ?? '');
        if ($projectId === '' || $repoRoot === '') {
            throw new RuntimeException('atlas_project_lane_registry:project_id_and_repo_root_required');
        }
        $supersedes = (bool) ($record['supersedes_prior_lane'] ?? false);
        $sanitized = $this->sanitize($record);

        if (! isset($this->byId[$projectId])) {
            $this->byId[$projectId] = $sanitized;
            $this->order[] = $projectId;

            return $sanitized;
        }

        $existing = $this->byId[$projectId];
        if ($this->isIdentical($existing, $sanitized)) {
            // Idempotent re-registration ⇒ no-op; return the existing record verbatim.
            return $existing;
        }
        if ((string) $existing['repo_root'] !== $repoRoot && ! $supersedes) {
            throw new RuntimeException(
                'atlas_project_lane_registry:duplicate_project_id_with_different_repo_root_requires_supersedes_prior_lane:'.$projectId,
            );
        }

        $this->byId[$projectId] = $sanitized;

        return $sanitized;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $projectId): ?array
    {
        return $this->byId[$projectId] ?? null;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listActive(): array
    {
        $out = [];
        foreach ($this->order as $projectId) {
            if (! isset($this->byId[$projectId])) {
                continue;
            }
            $row = $this->byId[$projectId];
            if (($row['status'] ?? 'active') !== 'active') {
                continue;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $record
     * @return array<string,mixed>
     */
    private function sanitize(array $record): array
    {
        // PROVIDER-SAFE metadata only — strip any payload that suggests live execution.
        $forbidden = ['provider_key', 'shell_cmd', 'git_credentials', 'session_token', 'api_key'];
        foreach ($forbidden as $key) {
            unset($record[$key]);
        }

        return $record;
    }

    /**
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    private function isIdentical(array $a, array $b): bool
    {
        ksort($a);
        ksort($b);

        return json_encode($a) === json_encode($b);
    }
}
