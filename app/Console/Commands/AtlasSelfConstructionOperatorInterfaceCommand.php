<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LoadsFactsJsonOption;
use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAutonomyDependencyAudit;
use App\Services\Ai\SelfConstruction\OperatorInterface\AtlasSelfConstructionEmergencyOverrideEnvelope;
use App\Services\Ai\SelfConstruction\OperatorInterface\AtlasSelfConstructionOperatorDashboardSnapshot;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only operator surface for the Self-Construction Operator Interface.
 *
 *   snapshot        AtlasSelfConstructionOperatorDashboardSnapshot
 *   visibility      reports the canonical surface_role + interface_role labels (no facts needed)
 *   emergency       AtlasSelfConstructionEmergencyOverrideEnvelope::envelopeFor(action)
 *   dependency-gate AtlasSelfConstructionAutonomyDependencyAudit::audit(evidence)
 *
 * Every action is read-only. The CLI's payload always carries:
 *   final_runtime_owner = "atlas_native"
 *   interface_role      = "visibility_emergency_only"
 *
 * Fail-closed on missing/invalid facts.
 */
final class AtlasSelfConstructionOperatorInterfaceCommand extends Command
{
    use LoadsFactsJsonOption;

    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    public const FINAL_RUNTIME_OWNER = 'atlas_native';

    public const INTERFACE_ROLE = 'visibility_emergency_only';

    protected $signature = 'atlas:self-construction:operator-interface {action : snapshot|visibility|emergency|dependency-gate} {--facts= : path to a JSON facts payload} {--json}';

    protected $description = 'Read-only Operator Interface CLI: snapshot | visibility | emergency | dependency-gate.';

    public function handle(
        AtlasSelfConstructionOperatorDashboardSnapshot $dashboard,
        AtlasSelfConstructionEmergencyOverrideEnvelope $emergency,
        AtlasSelfConstructionAutonomyDependencyAudit $audit,
    ): int {
        $action = (string) $this->argument('action');

        $payload = match ($action) {
            'visibility' => $this->visibility(),
            'snapshot' => $this->snapshot($dashboard),
            'emergency' => $this->emergency($emergency),
            'dependency-gate' => $this->dependencyGate($audit),
            default => null,
        };
        if ($payload === null) {
            $this->refuseUsage('unknown action: '.$action);

            return self::EXIT_USAGE;
        }
        if (isset($payload['__usage_error__'])) {
            return self::EXIT_USAGE;
        }

        $payload['final_runtime_owner'] = self::FINAL_RUNTIME_OWNER;
        $payload['interface_role'] = self::INTERFACE_ROLE;

        $this->emit($payload);

        return self::EXIT_OK;
    }

    /**
     * @return array<string,mixed>
     */
    private function visibility(): array
    {
        return [
            'surface_role' => AtlasSelfConstructionOperatorDashboardSnapshot::SURFACE_ROLE,
            'emergency_surface_role' => AtlasSelfConstructionEmergencyOverrideEnvelope::SURFACE_ROLE,
            'allowed_emergency_actions' => AtlasSelfConstructionEmergencyOverrideEnvelope::ALLOWED_EMERGENCY_ACTIONS,
            'rejected_ordinary_actions' => AtlasSelfConstructionEmergencyOverrideEnvelope::REJECTED_ORDINARY_ACTIONS,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(AtlasSelfConstructionOperatorDashboardSnapshot $dashboard): array
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return ['__usage_error__' => true];
        }

        return $dashboard->snapshot($facts);
    }

    /**
     * @return array<string,mixed>
     */
    private function emergency(AtlasSelfConstructionEmergencyOverrideEnvelope $emergency): array
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return ['__usage_error__' => true];
        }
        $action = (string) ($facts['action'] ?? '');

        return $emergency->envelopeFor($action);
    }

    /**
     * @return array<string,mixed>
     */
    private function dependencyGate(AtlasSelfConstructionAutonomyDependencyAudit $audit): array
    {
        $facts = $this->loadFacts();
        if ($facts === null) {
            return ['__usage_error__' => true];
        }
        $evidence = (array) ($facts['evidence'] ?? []);

        return $audit->audit($evidence);
    }


    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        unset($payload['__usage_error__']);
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($payload as $k => $v) {
            $this->line($k.': '.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }
}
