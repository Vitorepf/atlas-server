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
        $this->versions = array_merge($seed, $extraVersions);
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
}
