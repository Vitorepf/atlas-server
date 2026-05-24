<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsIndustrialBenchmarkSuiteCertification;
use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsIndustrialExecutionSuiteCertification;
use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsOperatorBatteryCertification;
use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsPerfectBatteryCertification;
use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsProviderArenaCoreCertification;
use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsProviderPerformanceLedgerCertification;

/**
 * Atlas Forge Rivals · Action Response Builder.
 *
 * Central envelope factory for every `atlas:forge:rivals <action>` response.
 * Every action returns an object with a stable envelope so that automation
 * can be written once and survives Slice 1–6 evolution:
 *
 *   schema_version, action, status, generated_at, blockers[],
 *   evidence_paths[], next_command, external_provider_call, ...action_extras
 *
 * Status codes Slice 0 emits:
 *   - ok              (currently unused; reserved for Slice 1+)
 *   - pending_slice_N (fail-closed; the action isn't implemented yet)
 *   - blocked         (invariants failed)
 *   - error           (bad input)
 */
final class AtlasForgeRivalsResponseBuilder
{
    public const SCHEMA_VERSION = 'atlas.forge.rivals.action_response.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_ERROR = 'error';

    public function __construct(
        private readonly AtlasForgeRivalsOperatorBatteryCertification $certification,
        private readonly AtlasForgeRivalsPerfectBatteryCertification $perfectBatteryCertification,
        private readonly AtlasForgeRivalsProviderArenaCoreCertification $arenaCoreCertification,
        private readonly AtlasForgeRivalsIndustrialBenchmarkSuiteCertification $industrialBenchmarkSuiteCertification,
        private readonly AtlasForgeRivalsIndustrialExecutionSuiteCertification $industrialExecutionSuiteCertification,
        private readonly AtlasForgeRivalsProviderPerformanceLedgerCertification $providerPerformanceLedgerCertification,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function pendingSlice(string $action, int $slice, ?string $note = null): array
    {
        return $this->envelope($action, 'pending_slice_'.$slice, [
            'pending_slice' => $slice,
            'next_command' => sprintf(
                'php artisan atlas:forge:rivals %s --json  # awaiting Slice %d implementation',
                $action,
                $slice
            ),
            'note' => $note ?? "Action '{$action}' is wired but not implemented yet — delivered in Slice {$slice}.",
        ]);
    }

    /**
     * @param  list<string>  $supportedActions
     * @return array<string,mixed>
     */
    public function unknownAction(string $action, array $supportedActions): array
    {
        return $this->envelope($action, self::STATUS_ERROR, [
            'error_code' => 'unknown_action',
            'requested_action' => $action,
            'supported_actions' => $supportedActions,
            'next_command' => 'php artisan atlas:forge:rivals doctor --json',
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function audit(string $action): array
    {
        $operator = $this->certification->evaluate();
        $perfect = $this->perfectBatteryCertification->evaluate();
        $arena = $this->arenaCoreCertification->evaluate();
        $industrial = $this->industrialBenchmarkSuiteCertification->evaluate();
        $industrialExecution = $this->industrialExecutionSuiteCertification->evaluate();
        $ledger = $this->providerPerformanceLedgerCertification->evaluate();
        $worst = $this->worstStatus(
            $operator['status'] ?? '',
            $perfect['status'] ?? '',
            $arena['status'] ?? '',
            $industrial['status'] ?? '',
            $industrialExecution['status'] ?? '',
            $ledger['status'] ?? ''
        );

        return $this->envelope($action, $this->mapCertStatusToActionStatus($worst), [
            'certification' => $operator,
            'perfect_battery_certification' => $perfect,
            'provider_arena_core_certification' => $arena,
            'industrial_benchmark_suite_certification' => $industrial,
            'industrial_execution_suite_certification' => $industrialExecution,
            'provider_performance_ledger_certification' => $ledger,
            'certifications' => [
                AtlasForgeRivalsOperatorBatteryCertification::CERTIFICATION_KEY => $operator,
                AtlasForgeRivalsPerfectBatteryCertification::CERTIFICATION_KEY => $perfect,
                AtlasForgeRivalsProviderArenaCoreCertification::CERTIFICATION_KEY => $arena,
                AtlasForgeRivalsIndustrialBenchmarkSuiteCertification::CERTIFICATION_KEY => $industrial,
                AtlasForgeRivalsIndustrialExecutionSuiteCertification::CERTIFICATION_KEY => $industrialExecution,
                AtlasForgeRivalsProviderPerformanceLedgerCertification::CERTIFICATION_KEY => $ledger,
            ],
            'next_command' => 'php artisan atlas:forge:rivals audit --json',
        ]);
    }

    private function worstStatus(string ...$statuses): string
    {
        $rank = [
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_AVAILABLE => 0,
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_PENDING_IMPLEMENTATION => 1,
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_MISSING_ARTIFACTS => 2,
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_BLOCKED => 3,
        ];
        $worst = $statuses[0] ?? '';
        $worstRank = $rank[$worst] ?? 3;
        foreach (array_slice($statuses, 1) as $s) {
            $r = $rank[$s] ?? 3;
            if ($r > $worstRank) {
                $worst = $s;
                $worstRank = $r;
            }
        }

        return $worst;
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    public function ok(string $action, array $extra = []): array
    {
        return $this->envelope($action, self::STATUS_OK, $extra);
    }

    /**
     * @param  list<string>  $blockers
     * @return array<string,mixed>
     */
    public function blocked(string $action, array $blockers, string $repairCommand): array
    {
        return $this->envelope($action, self::STATUS_BLOCKED, [
            'blockers' => $blockers,
            'next_command' => $repairCommand,
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function envelope(string $action, string $status, array $extra): array
    {
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'action' => $action,
            'status' => $status,
            'generated_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'blockers' => [],
            'evidence_paths' => [],
            'next_command' => null,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from_external_rivals_certification' => true,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
        ];

        foreach ($extra as $k => $v) {
            $base[$k] = $v;
        }

        return $base;
    }

    private function mapCertStatusToActionStatus(string $certStatus): string
    {
        return match ($certStatus) {
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_AVAILABLE => self::STATUS_OK,
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_PENDING_IMPLEMENTATION => 'pending_slice_0',
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_MISSING_ARTIFACTS,
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_BLOCKED => self::STATUS_BLOCKED,
            default => self::STATUS_BLOCKED,
        };
    }
}
