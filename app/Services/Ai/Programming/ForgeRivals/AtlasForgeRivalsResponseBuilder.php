<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsOperatorBatteryCertification;
use App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsPerfectBatteryCertification;

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
        $worst = $this->worstStatus($operator['status'] ?? '', $perfect['status'] ?? '');

        return $this->envelope($action, $this->mapCertStatusToActionStatus($worst), [
            'certification' => $operator,
            'perfect_battery_certification' => $perfect,
            'certifications' => [
                AtlasForgeRivalsOperatorBatteryCertification::CERTIFICATION_KEY => $operator,
                AtlasForgeRivalsPerfectBatteryCertification::CERTIFICATION_KEY => $perfect,
            ],
            'next_command' => 'php artisan atlas:forge:rivals audit --json',
        ]);
    }

    private function worstStatus(string $a, string $b): string
    {
        $rank = [
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_AVAILABLE => 0,
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_PENDING_IMPLEMENTATION => 1,
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_MISSING_ARTIFACTS => 2,
            AtlasForgeRivalsOperatorBatteryCertification::STATUS_BLOCKED => 3,
        ];
        $ra = $rank[$a] ?? 3;
        $rb = $rank[$b] ?? 3;

        return $ra >= $rb ? $a : $b;
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
