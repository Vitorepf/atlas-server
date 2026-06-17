<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * AP-774 · Stewardship Merge Autonomy Policy.
 *
 * Central, deterministic policy for deciding when the stewardship loop may
 * auto-merge. It does not inspect git or perform merges; AP-769 owns that. This
 * service only turns branch evidence into an auditable autonomy decision.
 *
 * The per-class decision logic and the output payload assembly live in
 * {@see StewardshipMergeAutonomyPolicyServiceSupport} so {@see decide()} stays
 * a thin orchestrator and the audit surface is one shape per public method.
 */
final class StewardshipMergeAutonomyPolicyService
{
    public const DECISION_SCHEMA = 'atlas.software_company_stewardship.merge_autonomy_policy.v1';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    private StewardshipMergeAutonomyPolicyServiceSupport $support;

    public function __construct(?StewardshipMergeAutonomyPolicyServiceSupport $support = null)
    {
        $this->support = $support ?? new StewardshipMergeAutonomyPolicyServiceSupport();
    }

    /**
     * @param  array<string,mixed>  $classification
     * @param  array<string,mixed>  $validation
     * @param  list<string>  $changedFiles
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function decide(array $classification, array $validation, array $changedFiles, int $branchOnly, array $blockers, array $input): array
    {
        $kind = (string) ($classification['kind'] ?? '');
        $maxFiles = max(1, (int) ($input['max_auto_merge_files'] ?? 5));
        $areaId = AreaFocusSlugNormalizer::lowerFileToken((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID), self::DEFAULT_AREA_ID);

        $classes = $this->support->classifyAutoMergeAuthorizations($kind, $classification, $validation, $changedFiles, $branchOnly, $input);
        $riskClass = $this->support->riskClass($kind, $changedFiles, $branchOnly, $blockers);
        $reasons = $this->support->collectReasons($blockers, $branchOnly, $changedFiles, $maxFiles, $classes, $validation, $riskClass);

        return $this->support->buildDecisionPayload(
            $areaId,
            $kind,
            $reasons === [],
            $maxFiles,
            count($changedFiles),
            $branchOnly,
            $classes,
            $riskClass,
            $reasons,
            $classification,
        );
    }

    /**
     * Step 2 of 3: entry point for the per-class changed-file ceiling signal.
     * Empty input returns the step-1 default contract; non-empty transformation
     * wiring lands in step 3.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluatePerClassChangedFileCeilingSignal(array $input = []): array
    {
        if ($input === []) {
            return APerClassChangedFileCeilingSignalContract::defaults()->toArray();
        }

        if (
            (isset($input['changed_files']) && ! is_array($input['changed_files']))
            || (isset($input['bounded_packet_allowed_files']) && ! is_array($input['bounded_packet_allowed_files']))
        ) {
            return APerClassChangedFileCeilingSignalContract::defaults()->toArray();
        }

        return APerClassChangedFileCeilingSignalContract::fromArray($input)->toArray();
    }
}
