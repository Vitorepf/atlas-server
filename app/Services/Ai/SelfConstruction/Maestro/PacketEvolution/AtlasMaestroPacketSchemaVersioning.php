<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Maestro\PacketEvolution;

/**
 * Canonical registry of Maestro packet contract versions. Replaces the single hard-coded constant
 * that previously lived on AgentControlPlaneTaskPacketBuilder. Every future contract change MUST
 * land here as a new version row.
 *
 * Each version row declares:
 *   - id (string)               canonical schema id (e.g. atlas.self_construction.agent_control_plane_task_packet.v1)
 *   - version (int)             monotonic version number
 *   - status (string)           active | preview | deprecated | retired
 *   - required_fields (list)    required-field set
 *   - additive_fields (list)    fields added vs predecessor (informational)
 *   - removed_fields (list)     fields removed vs predecessor (informational)
 *   - introduced_at (string)    commit/date marker
 *   - successor (?string)       id of the next version when retired/deprecated
 *
 * current() returns the version operator-controlled via config('atlas.maestro.packet_schema.current')
 * (defaults to the latest active row); latest() returns the highest version among active|preview.
 */
final class AtlasMaestroPacketSchemaVersioning
{
    public const CANONICAL_V1 = 'atlas.self_construction.agent_control_plane_task_packet.v1';

    public const STATUS_ACTIVE = 'active';
    public const STATUS_PREVIEW = 'preview';
    public const STATUS_DEPRECATED = 'deprecated';
    public const STATUS_RETIRED = 'retired';

    /** @var list<array<string,mixed>> */
    private array $versions;

    /** @var callable(): ?string */
    private $currentResolver;

    /**
     * @param  list<array<string,mixed>>  $extraVersions  optional extra versions to merge in (test seam).
     * @param  callable(): ?string|null  $currentResolver returns the operator-pinned current id, or null to default to latest active.
     */
    public function __construct(array $extraVersions = [], ?callable $currentResolver = null)
    {
        $seed = [
            [
                'id' => self::CANONICAL_V1,
                'version' => 1,
                'status' => self::STATUS_ACTIVE,
                'required_fields' => ['task_packet_id', 'objective', 'allowed_files', 'acceptance_criteria', 'required_evidence'],
                'additive_fields' => [],
                'removed_fields' => [],
                'introduced_at' => '2025-12-01',
                'successor' => null,
            ],
        ];
        $byId = [];
        foreach (array_merge($seed, $extraVersions) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $byId[(string) ($row['id'] ?? '')] = $row;
        }
        $this->versions = array_values($byId);
        $this->currentResolver = $currentResolver ?? static function (): ?string {
            if (function_exists('config')) {
                $value = config('atlas.maestro.packet_schema.current');

                return is_string($value) && $value !== '' ? $value : null;
            }

            return null;
        };
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function versions(): array
    {
        return $this->versions;
    }

    /**
     * @return array<string,mixed>
     */
    public function current(): array
    {
        $pinned = ($this->currentResolver)();
        if (is_string($pinned) && $this->supports($pinned)) {
            return $this->describe($pinned);
        }
        // Default: latest active row.
        $active = array_values(array_filter($this->versions, static fn (array $r): bool => (string) $r['status'] === self::STATUS_ACTIVE));
        usort($active, static fn (array $a, array $b): int => (int) $b['version'] <=> (int) $a['version']);

        return $active[0] ?? $this->versions[0];
    }

    /**
     * @return array<string,mixed>
     */
    public function latest(): array
    {
        $surfaced = array_values(array_filter(
            $this->versions,
            static fn (array $r): bool => in_array((string) $r['status'], [self::STATUS_ACTIVE, self::STATUS_PREVIEW], true),
        ));
        usort($surfaced, static fn (array $a, array $b): int => (int) $b['version'] <=> (int) $a['version']);

        return $surfaced[0] ?? $this->versions[0];
    }

    public function supports(string $versionId): bool
    {
        foreach ($this->versions as $row) {
            if ((string) $row['id'] === $versionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string,mixed>
     */
    public function describe(string $versionId): array
    {
        foreach ($this->versions as $row) {
            if ((string) $row['id'] === $versionId) {
                return $row;
            }
        }

        return [];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function successorOf(string $versionId): ?array
    {
        $row = $this->describe($versionId);
        $successorId = (string) ($row['successor'] ?? '');
        if ($successorId === '') {
            return null;
        }
        $successor = $this->describe($successorId);

        return $successor === [] ? null : $successor;
    }

    /**
     * Returns a categorical compatibility label for a given version id:
     *   current     — the operator-pinned (or defaulted) active current version
     *   supported   — active or preview, but not the current version
     *   deprecated  — in the registry with status=deprecated
     *   unsupported — retired, or not in the registry at all
     */
    public function compatibilityStatus(string $versionId): string
    {
        if (! $this->supports($versionId)) {
            return 'unsupported';
        }
        $status = (string) ($this->describe($versionId)['status'] ?? '');
        if ($status === self::STATUS_RETIRED) {
            return 'unsupported';
        }
        if ($status === self::STATUS_DEPRECATED) {
            return 'deprecated';
        }
        // active or preview
        if ((string) ($this->current()['id'] ?? '') === $versionId) {
            return 'current';
        }

        return 'supported';
    }

    /**
     * Generates a draft for the next schema version based on the current active version.
     * Inherits all required_fields for lossless forward compatibility.
     * Returns human-readable reasons; never emits a numeric score as a proxy for decisions.
     *
     * @return array<string,mixed>
     */
    public function draftNextVersion(): array
    {
        $current = $this->current();
        $currentId = (string) ($current['id'] ?? '');
        $currentVersion = (int) ($current['version'] ?? 1);
        $nextVersion = $currentVersion + 1;
        $nextId = (string) preg_replace('/\.v\d+$/', '.v'.$nextVersion, $currentId);
        if ($nextId === $currentId) {
            $nextId = $currentId.'.v'.$nextVersion;
        }

        return [
            'draft_id' => $nextId,
            'version' => $nextVersion,
            'status' => self::STATUS_PREVIEW,
            'predecessor' => $currentId,
            'required_fields' => (array) ($current['required_fields'] ?? []),
            'additive_fields' => [],
            'removed_fields' => [],
            'reasons' => [
                'inherits all required_fields from '.$currentId.' for lossless forward compatibility',
                'status=preview until operator promotes to active via config pin',
                'set successor on '.$currentId.' before retiring it to preserve upgrade path',
            ],
            'introduced_at' => '',
            'successor' => null,
        ];
    }
}
