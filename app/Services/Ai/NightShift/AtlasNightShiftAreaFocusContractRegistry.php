<?php

declare(strict_types=1);

namespace App\Services\Ai\NightShift;

/**
 * Night Shift · Area Focus Loop · Area Contract Registry (AP-712).
 *
 * Atlas Software Company Stewardship Stack is a stack/capability family inside
 * the Atlas Autonomous Software Company Runtime, not a new OS. This registry is
 * the canonical, deterministic source of the Area Contract that AP-712 requires
 * every Area Focus run to declare: area_id, owner docs, repo scope, autonomy
 * tier, Dev/Forge budgets, WIP limit, risk policy, stop conditions and Morning
 * Inbox destination.
 *
 * v1 registers ONLY `agentic_engineering_os` (Atlas itself). Any other area_id
 * resolves to null so the loop blocks it as `area_not_registered`. This enforces
 * the Night Shift v1 rule "Atlas must night-shift itself before it night-shifts
 * any company": external companies (e.g. BlackInk) are never registered here and
 * require the NS-v1 -> NS-v2 promotion receipt that does not yet exist.
 *
 * The registry holds no state, performs no I/O and never mutates anything.
 */
class AtlasNightShiftAreaFocusContractRegistry
{
    public const CONTRACT_SCHEMA = 'atlas.night_shift.area_focus_loop.contract.v1';

    public const AREA_AGENTIC_ENGINEERING_OS = 'agentic_engineering_os';

    /**
     * Resolve the canonical Area Contract for an area_id.
     *
     * @return array<string,mixed>|null  null when the area is not registered.
     */
    public function resolve(string $areaId): ?array
    {
        $contracts = $this->contracts();

        return $contracts[$areaId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function registeredAreas(): array
    {
        return array_keys($this->contracts());
    }

    public function isRegistered(string $areaId): bool
    {
        return array_key_exists($areaId, $this->contracts());
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function contracts(): array
    {
        return [
            self::AREA_AGENTIC_ENGINEERING_OS => $this->agenticEngineeringOsContract(),
        ];
    }

    /**
     * Canonical Agentic Engineering OS area contract.
     *
     * Mirrors the YAML in `atlas-autonomous-software-company-night-shift-product-mode.md`
     * (Agentic Engineering OS Area) and `atlas-area-stewardship-layer.md`
     * (owned_systems), plus the AP-712 Area Contract fields.
     *
     * @return array<string,mixed>
     */
    private function agenticEngineeringOsContract(): array
    {
        return [
            'schema_version' => self::CONTRACT_SCHEMA,
            'area_id' => self::AREA_AGENTIC_ENGINEERING_OS,
            'area_name' => 'Agentic Engineering OS',
            'objective' => 'Melhorar continuamente todo o fluxo de desenvolvimento de software do Atlas: '
                .'intake, docs, specs, AAEOS phases, Atlas Dev, Forge, Self-Construction, Mission Control, '
                .'Desktop surfaces, branch sandbox, replay, Evidence, governance gates e Morning Inbox.',
            'area_owner_docs' => [
                'docs/engineering-knowledge-base/atlas-agentic-engineering-os.md',
                'docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md',
                'docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md',
                'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md',
                'docs/engineering-knowledge-base/atlas-forge-operating-system.md',
            ],
            // Integrated systems the area stewards (Area Stewardship Layer · owned_systems).
            'owned_systems' => [
                'AAEOS',
                'Atlas Dev',
                'Atlas Forge',
                'Self-Directed Evolution',
                'Self-Construction',
                'Evidence',
                'Mission Control',
                'Night Shift',
            ],
            'repo_scope' => [
                // NS-v1: Atlas itself only. No external company.
                'target' => 'atlas_itself',
                'repos' => ['atlas-server', 'atlas-desktop'],
                'allowed_paths' => ['app/', 'docs/', 'tests/', 'config/', 'routes/', 'database/'],
                'forbidden_paths' => ['.env', 'storage/secrets', 'vendor/', 'node_modules/'],
            ],
            // Current authorized tier for the loop runtime is scan-only (read-only v1).
            'autonomy_tier' => 0,
            // Governed ceiling documented for the area (Tier 2 = spec drafts). The
            // runtime never operates above `autonomy_tier` regardless of this ceiling.
            'max_tier_for_area' => 2,
            'dev_mode' => 'max_governed',
            'forge_mode' => 'max_governed',
            // max_governed = maximum useful throughput inside the safety boundary,
            // not permissionless autonomy.
            'dev_budget' => [
                'mode' => 'max_governed',
                'max_concurrent_work_orders' => 3,
            ],
            'forge_budget' => [
                'mode' => 'max_governed',
                'max_concurrent_obras' => 1,
            ],
            'wip_limit' => [
                'max_findings' => 20,
                'max_spec_drafts' => 5,
                'max_branches' => 3,
            ],
            'risk_policy' => [
                'inbox_only_for_sensitive' => true,
                'block_external_company' => true,
                'sensitive_domains' => [
                    'auth',
                    'billing',
                    'secrets',
                    'production',
                    'deploy',
                    'legal',
                    'healthcare',
                    'finance',
                    'trading',
                    'cyber',
                    'data_deletion',
                    'migration',
                    'architecture_redesign',
                    'large_refactor',
                ],
            ],
            'stop_conditions' => [
                'budget_exhausted',
                'wip_limit_reached',
                'kill_switch',
                'operator_pause',
                'sensitive_domain_without_review',
            ],
            'inbox_destination' => 'morning_inbox',
        ];
    }
}
