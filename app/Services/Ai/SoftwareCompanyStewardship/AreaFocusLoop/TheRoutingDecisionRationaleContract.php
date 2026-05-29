<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * Minimal data contract for the routing decision rationale attached to
 * {@see AreaFocusDevForgeRouterService} work orders. Step 1 of 3: shape only —
 * no router service wiring in this class.
 *
 * Records owner, risk, authority availability and the chosen route so an
 * operator can audit why a finding went to forge handoff versus dev execution.
 */
final class TheRoutingDecisionRationaleContract
{
    public const SCHEMA = 'atlas.software_company_stewardship.routing_decision_rationale.v1';

    public const RUNBOOK_CANONICAL = 'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md';

    public const CONTRACT_ID = 'routing_decision_rationale';

    public const FINDING_ID = 'aaeos_dev_forge_router_decision_rationale_contract';

    public const WORK_ORDER_SCHEMA = AreaFocusDevForgeRouterService::WORK_ORDER_SCHEMA;

    public const REPORT_SCHEMA = AreaFocusDevForgeRouterService::REPORT_SCHEMA;

    public const OWNER_ATLAS_DEV = 'atlas_dev';

    public const OWNER_FORGE = 'forge';

    public const OWNER_SELF_DIRECTED_EVOLUTION = 'self_directed_evolution';

    public const OWNER_OPERATOR = 'operator';

    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly string $owner,
        public readonly string $risk,
        public readonly bool $authorityAvailable,
        public readonly string $route,
    ) {}

    public static function defaults(
        string $areaId = 'agentic_engineering_os',
        string $focus = 'dev_forge',
    ): self {
        return new self(
            areaId: $areaId,
            focus: $focus,
            owner: '',
            risk: 'unknown',
            authorityAvailable: false,
            route: '',
        );
    }

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $route = trim((string) ($input['route'] ?? ($input['route_hint'] ?? '')));
        $risk = trim((string) ($input['risk'] ?? ($input['risk_level'] ?? ($input['severity'] ?? 'unknown'))));
        $owner = trim((string) ($input['owner'] ?? ''));
        if ($owner === '' && $route !== '') {
            $owner = self::resolveOwner($route);
        }

        $authorityAvailable = array_key_exists('authority_available', $input)
            ? (bool) $input['authority_available']
            : self::defaultAuthorityAvailable($route);

        return new self(
            areaId: trim((string) ($input['area_id'] ?? 'agentic_engineering_os')),
            focus: trim((string) ($input['focus'] ?? 'dev_forge')),
            owner: $owner,
            risk: $risk !== '' ? $risk : 'unknown',
            authorityAvailable: $authorityAvailable,
            route: $route,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA,
            'contract_id' => self::CONTRACT_ID,
            'finding_id' => self::FINDING_ID,
            'runbook_canonical' => self::RUNBOOK_CANONICAL,
            'work_order_schema' => self::WORK_ORDER_SCHEMA,
            'report_schema' => self::REPORT_SCHEMA,
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'inputs' => [
                'owner' => $this->owner,
                'risk' => $this->risk,
                'authority_available' => $this->authorityAvailable,
                'route' => $this->route,
            ],
            'outputs' => [
                'owner' => $this->owner,
                'risk' => $this->risk,
                'authority_available' => $this->authorityAvailable,
                'route' => $this->route,
                'surfaces_authority_gap' => self::surfacesAuthorityGap($this->route, $this->authorityAvailable),
                'routing_rationale_auditable' => $this->route !== '' && $this->owner !== '',
            ],
        ];
    }

    private static function resolveOwner(string $route): string
    {
        return match ($route) {
            AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV => self::OWNER_ATLAS_DEV,
            AreaFocusDevForgeRouterService::ROUTE_FORGE => self::OWNER_FORGE,
            AreaFocusDevForgeRouterService::ROUTE_SELF_DIRECTED_EVOLUTION => self::OWNER_SELF_DIRECTED_EVOLUTION,
            default => self::OWNER_OPERATOR,
        };
    }

    private static function defaultAuthorityAvailable(string $route): bool
    {
        return match ($route) {
            AreaFocusDevForgeRouterService::ROUTE_SELF_DIRECTED_EVOLUTION => true,
            default => false,
        };
    }

    private static function surfacesAuthorityGap(string $route, bool $authorityAvailable): bool
    {
        return in_array($route, [
            AreaFocusDevForgeRouterService::ROUTE_ATLAS_DEV,
            AreaFocusDevForgeRouterService::ROUTE_FORGE,
        ], true) && ! $authorityAvailable;
    }
}
