<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Minimal data contract for the backlog depth governor check in
 * {@see AlwaysOnLoopSupervisorService}. Step 1 of 3: shape only — no
 * supervisor wiring in this class.
 */
final class BacklogDepthGovernorCheckContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.backlog_depth_governor_check.v1';

    public const STACK_CANONICAL = 'docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md';

    public const CHECK_ID = 'backlog_depth_governor_check';

    public const GOVERNOR_REPORT_SCHEMA = BacklogDepthGovernorService::REPORT_SCHEMA;

    public const GOVERNOR_STATUS_OK = BacklogDepthGovernorService::STATUS_OK;

    public const GOVERNOR_STATUS_BELOW_FLOOR = BacklogDepthGovernorService::STATUS_BELOW_FLOOR;

    public const DEFAULT_FLOOR = BacklogDepthGovernorService::DEFAULT_FLOOR;

    public const BLOCKER_PACKET_DEPTH_BELOW_FLOOR = 'packet_depth_below_floor';

    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly int $packetsCount,
        public readonly int $floor,
        public readonly string $governorStatus,
        public readonly ?bool $blocks24h,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            packetsCount: self::DEFAULT_FLOOR,
            floor: self::DEFAULT_FLOOR,
            governorStatus: self::GOVERNOR_STATUS_OK,
            blocks24h: null,
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $floor = max(1, (int) ($input['floor'] ?? self::DEFAULT_FLOOR));
        $packetsCount = max(0, (int) ($input['packets_count'] ?? 0));
        $governorStatus = strtolower(trim((string) (
            $input['governor_status']
            ?? ($input['status'] ?? self::GOVERNOR_STATUS_OK)
        )));

        if (! in_array($governorStatus, [self::GOVERNOR_STATUS_OK, self::GOVERNOR_STATUS_BELOW_FLOOR], true)) {
            $governorStatus = $packetsCount < $floor
                ? self::GOVERNOR_STATUS_BELOW_FLOOR
                : self::GOVERNOR_STATUS_OK;
        }

        $explicitBlocks24h = $input['blocks_24h'] ?? null;
        $blocks24h = is_bool($explicitBlocks24h) ? $explicitBlocks24h : null;

        return new self(
            areaId: trim((string) ($input['area_id'] ?? 'agentic_engineering_os')),
            focus: trim((string) ($input['focus'] ?? 'dev_forge')),
            packetsCount: $packetsCount,
            floor: $floor,
            governorStatus: $governorStatus,
            blocks24h: $blocks24h,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $blocks24h = $this->blocks24h ?? $this->resolveBlocks24h();
        $blocksSupervisor24hCycle = $blocks24h;

        return [
            'schema_version' => self::SCHEMA,
            'check_id' => self::CHECK_ID,
            'governor_report_schema' => self::GOVERNOR_REPORT_SCHEMA,
            'stack_canonical' => self::STACK_CANONICAL,
            'default_floor' => self::DEFAULT_FLOOR,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'packets_count' => $this->packetsCount,
                'floor' => $this->floor,
                'governor_status' => $this->governorStatus,
                'blocks_24h' => $this->blocks24h,
            ],
            'outputs' => [
                'blocks_24h' => $blocks24h,
                'blocks_supervisor_24h_cycle' => $blocksSupervisor24hCycle,
                'blocker_id' => $blocksSupervisor24hCycle ? self::BLOCKER_PACKET_DEPTH_BELOW_FLOOR : null,
            ],
        ];
    }

    private function resolveBlocks24h(): bool
    {
        if ($this->governorStatus === self::GOVERNOR_STATUS_BELOW_FLOOR) {
            return true;
        }

        return $this->packetsCount < $this->floor;
    }
}
