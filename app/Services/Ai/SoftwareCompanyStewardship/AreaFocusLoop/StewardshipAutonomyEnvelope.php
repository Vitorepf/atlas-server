<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use InvalidArgumentException;

/**
 * AP-806 — Stewardship Autonomy Envelope.
 *
 * Immutable, validated standing policy the operator configures ONCE so the loop
 * can run a week alone without per-cycle approval. It is the governance envelope
 * that turns per-cycle human gates into a one-time pre-authorization.
 *
 * SAFETY INVARIANT (non-negotiable): cross-system work may NEVER target main.
 * If the envelope admits cross-system work it MUST route merges to the governed
 * integration lane (AP-782/783), which by construction never mutates main; the
 * operator reviews and promotes the lane to main at the end of the window.
 * Constructing a cross-system envelope with merge_target=main throws.
 */
final class StewardshipAutonomyEnvelope
{
    public const MERGE_TARGET_INTEGRATION_LANE = 'integration_lane';

    public const MERGE_TARGET_MAIN = 'main';

    private const RISK_ORDER = ['low' => 1, 'medium' => 2, 'high' => 3];

    /** Safe-by-default prohibitions; an armed envelope always forbids these. */
    public const DEFAULT_FORBIDDEN_ACTIONS = [
        'merge_to_main',
        'force_push',
        'delete_branch_unmerged',
        'rewrite_history',
        'touch_secrets',
        'forge_real_execution',
        'provider_topology_authority_fabrication',
    ];

    public const DEFAULT_QUALITY_CRITERIA = [
        'judge_must_pass',
        'validation_must_pass',
        'evidence_pack_required',
        'no_trivial_or_docs_only_filler',
    ];

    /**
     * @param  list<string>  $allowedOwners
     * @param  list<string>  $allowedProviders
     * @param  list<string>  $forbiddenActions
     * @param  list<string>  $qualityCriteria
     */
    private function __construct(
        public readonly string $areaId,
        public readonly string $focus,
        public readonly string $mergeTarget,
        public readonly bool $admitCrossSystem,
        public readonly array $allowedOwners,
        public readonly string $riskCeiling,
        public readonly int $maxAutoMergeFiles,
        public readonly int $durationDays,
        public readonly array $allowedProviders,
        public readonly array $forbiddenActions,
        public readonly array $qualityCriteria,
        public readonly int $maxCycles,
        public readonly int $maxMerges,
        public readonly string $operatorActor,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     */
    public static function fromArray(array $input): self
    {
        $area = trim((string) ($input['area_id'] ?? $input['area'] ?? ''));
        if ($area === '') {
            throw new InvalidArgumentException('autonomy_envelope.area_id is required');
        }

        $mergeTarget = trim((string) ($input['merge_target'] ?? self::MERGE_TARGET_INTEGRATION_LANE)) ?: self::MERGE_TARGET_INTEGRATION_LANE;
        if (! in_array($mergeTarget, [self::MERGE_TARGET_INTEGRATION_LANE, self::MERGE_TARGET_MAIN], true)) {
            throw new InvalidArgumentException('autonomy_envelope.merge_target must be integration_lane or main');
        }

        $admitCrossSystem = (bool) ($input['admit_cross_system'] ?? false);

        // The one rule we never bend: cross-system autonomous work cannot go to main.
        if ($admitCrossSystem && $mergeTarget === self::MERGE_TARGET_MAIN) {
            throw new InvalidArgumentException(
                'unsafe envelope: cross-system autonomous work must target the integration lane, never main',
            );
        }

        $owners = array_values(array_filter(
            array_map(static fn ($o): string => trim((string) $o), (array) ($input['allowed_owners'] ?? ['atlas_dev'])),
            static fn (string $o): bool => $o !== '',
        ));
        if ($owners === []) {
            $owners = ['atlas_dev'];
        }
        // Forge is plan-only/fixture today (AP-806): it cannot execute real code,
        // so it is never an allowed autonomous owner under an envelope.
        $owners = array_values(array_filter($owners, static fn (string $o): bool => $o !== 'forge'));
        if ($owners === []) {
            $owners = ['atlas_dev'];
        }

        $risk = strtolower(trim((string) ($input['risk_ceiling'] ?? 'high')));
        if (! isset(self::RISK_ORDER[$risk])) {
            $risk = 'high';
        }

        return new self(
            areaId: $area,
            focus: trim((string) ($input['focus'] ?? 'dev_forge')) ?: 'dev_forge',
            mergeTarget: $mergeTarget,
            admitCrossSystem: $admitCrossSystem,
            allowedOwners: $owners,
            riskCeiling: $risk,
            maxAutoMergeFiles: max(1, (int) ($input['max_auto_merge_files'] ?? 12)),
            durationDays: max(1, min(31, (int) ($input['duration_days'] ?? 7))),
            allowedProviders: array_values(array_filter(
                array_map(static fn ($p): string => trim((string) $p), (array) ($input['allowed_providers'] ?? ['cursor_cli'])),
                static fn (string $p): bool => $p !== '',
            )),
            forbiddenActions: array_values(array_filter(
                array_map(static fn ($a): string => trim((string) $a), (array) ($input['forbidden_actions'] ?? self::DEFAULT_FORBIDDEN_ACTIONS)),
                static fn (string $a): bool => $a !== '',
            )),
            qualityCriteria: array_values(array_filter(
                array_map(static fn ($q): string => trim((string) $q), (array) ($input['quality_criteria'] ?? self::DEFAULT_QUALITY_CRITERIA)),
                static fn (string $q): bool => $q !== '',
            )),
            maxCycles: max(1, min(500, (int) ($input['max_cycles'] ?? 12))),
            maxMerges: max(1, min(500, (int) ($input['max_merges'] ?? 10))),
            operatorActor: trim((string) ($input['operator_actor'] ?? '')),
        );
    }

    /**
     * Build an envelope from input, or null when none is configured. A malformed
     * envelope is a hard error (never silently dropped — that would be an unsafe
     * surprise), but absence is simply "no envelope, default behavior".
     *
     * @param  array<string,mixed>  $input
     */
    public static function fromInputOrNull(array $input): ?self
    {
        $raw = $input['autonomy_envelope'] ?? null;
        if (! is_array($raw) || $raw === []) {
            return null;
        }

        return self::fromArray($raw);
    }

    public function mergeTarget(): string
    {
        return $this->mergeTarget;
    }

    public function routesToIntegrationLane(): bool
    {
        return $this->mergeTarget === self::MERGE_TARGET_INTEGRATION_LANE;
    }

    /**
     * Does this envelope pre-authorize an admitted candidate that factory_max
     * would otherwise reject as cross-system work for the given owner?
     */
    public function admitsCrossSystem(string $owner, string $severity = 'high'): bool
    {
        if (! $this->admitCrossSystem) {
            return false;
        }
        if (! in_array($owner, $this->allowedOwners, true)) {
            return false;
        }

        return $this->withinRiskCeiling($severity);
    }

    public function withinRiskCeiling(string $severity): bool
    {
        $sev = self::RISK_ORDER[strtolower(trim($severity))] ?? self::RISK_ORDER['medium'];

        return $sev <= self::RISK_ORDER[$this->riskCeiling];
    }

    /**
     * Deterministic identity of the POLICY (excludes operator actor and any
     * volatile arming metadata) so the same policy hashes identically.
     */
    public function policyHash(): string
    {
        $policy = $this->toArray();
        unset($policy['operator_actor']);

        return 'sha256:'.MissionCanonicalHash::sha256($policy);
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'area_id' => $this->areaId,
            'focus' => $this->focus,
            'merge_target' => $this->mergeTarget,
            'admit_cross_system' => $this->admitCrossSystem,
            'allowed_owners' => $this->allowedOwners,
            'risk_ceiling' => $this->riskCeiling,
            'max_auto_merge_files' => $this->maxAutoMergeFiles,
            'duration_days' => $this->durationDays,
            'allowed_providers' => $this->allowedProviders,
            'forbidden_actions' => $this->forbiddenActions,
            'quality_criteria' => $this->qualityCriteria,
            'max_cycles' => $this->maxCycles,
            'max_merges' => $this->maxMerges,
            'operator_actor' => $this->operatorActor,
        ];
    }
}
