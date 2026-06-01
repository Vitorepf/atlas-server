<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasForgeObraEnterpriseLoopUpgradeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Forge Obra Enterprise Loop Upgrade CLI — runs the Forge enterprise
 * engineering audit gate (Fluxo step 3-4) for one Obra and emits the
 * `enterprise_engineering_audit.v1` receipt with honest blockers.
 *
 *   php artisan atlas:aaeos:forge-obra-enterprise-loop-upgrade
 *     [--obra-id=obra-001]
 *     [--intent="reestruture billing enterprise"]
 *     [--risk-level=high]
 *     [--sdd-ready] [--workspace-ready] [--provider-topology-ready]
 *     [--rollback-ready] [--work-packets-ready] [--completion-gate-ready]
 *     [--evidence=ledger:abc,packet:def]
 *     [--no-strict]
 *     [--json]
 *
 * Read-only, deterministic. Under strict mode a blocked audit is a hard stop.
 *
 * @see docs/engineering-knowledge-base/atlas-forge-obra-enterprise-loop-upgrade.md
 */
class AtlasForgeObraEnterpriseLoopUpgradeCommand extends Command
{
    protected $signature = 'atlas:aaeos:forge-obra-enterprise-loop-upgrade
        {--obra-id= : Obra identifier}
        {--intent= : human intent / prompt for the Obra}
        {--risk-level= : risk level (low|medium|high)}
        {--sdd-ready : SDD is generated/validated}
        {--workspace-ready : workspace certified ready}
        {--provider-topology-ready : provider topology decided}
        {--rollback-ready : rollback path is ready}
        {--work-packets-ready : Obra is split into work packets}
        {--completion-gate-ready : completion gate is wired}
        {--evidence= : comma-separated required-evidence refs}
        {--no-strict : disable strict certification (allow proceed on blockers)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = 'Atlas Forge · Obra enterprise engineering audit gate (ready|blocked) before heavy execution.';

    public function handle(AtlasForgeObraEnterpriseLoopUpgradeService $service): int
    {
        try {
            $evidenceOpt = $this->option('evidence');
            $evidence = is_string($evidenceOpt) && trim($evidenceOpt) !== ''
                ? array_values(array_filter(array_map('trim', explode(',', $evidenceOpt)), static fn ($v) => $v !== ''))
                : [];

            $receipt = $service->auditEnterpriseReadiness([
                'obra_id' => $this->option('obra-id') ?? 'obra-001',
                'intent' => $this->option('intent') ?? '',
                'risk_level' => $this->option('risk-level') ?? 'high',
                'sdd_ready' => (bool) $this->option('sdd-ready'),
                'workspace_ready' => (bool) $this->option('workspace-ready'),
                'provider_topology_ready' => (bool) $this->option('provider-topology-ready'),
                'rollback_ready' => (bool) $this->option('rollback-ready'),
                'work_packets_ready' => (bool) $this->option('work-packets-ready'),
                'completion_gate_ready' => (bool) $this->option('completion-gate-ready'),
                'required_evidence' => $evidence,
            ], strict: ! (bool) $this->option('no-strict'));

            $this->line((string) json_encode(
                ['ok' => true, 'audit' => $receipt],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            ));

            return $receipt['may_proceed'] === true ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'forge_obra_enterprise_loop_upgrade_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
