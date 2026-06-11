<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier;

use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FindingSlicePlannerService;

/**
 * Foundry AP-C · Frontier · Proposal Packet Decomposer (Armor invariant I7 runtime).
 *
 * Composes the canonical {@see FindingSlicePlannerService} — it does NOT
 * re-implement decomposition. Given the `proposed_packets[]` of a single
 * evolution proposal, it maps each packet to a synthetic finding (mirroring the
 * AP-794 / BuildPlanDecomposer syntheticFinding shape) and delegates to
 * {@see FindingSlicePlannerService::plan()} EXACTLY ONCE per packet.
 *
 * Hard invariants:
 *   - Pure planner. NEVER calls a provider, NEVER writes canon/docs/code, NEVER
 *     opens a branch/worktree, NEVER merges, NEVER mutates anything outside the
 *     returned plan.
 *   - The planner is `final`, so a test cannot subclass it; composition is
 *     asserted through {@see self::setSlicePlannerCallableForTesting()} (the same
 *     callable-override seam BuildPlanDecomposerService uses).
 */
final class FrontierProposalPacketDecomposerService
{
    private FindingSlicePlannerService $slicePlanner;

    /** @var (callable(array<string,mixed>):array<string,mixed>)|null */
    private $slicePlannerCallable = null;

    public function __construct(?FindingSlicePlannerService $slicePlanner = null)
    {
        $this->slicePlanner = $slicePlanner ?? new FindingSlicePlannerService;
    }

    /**
     * Test seam: route every per-packet plan() call through the given callable
     * so composition (one call per packet) can be asserted without subclassing
     * the `final` planner.
     *
     * @param  (callable(array<string,mixed>):array<string,mixed>)|null  $callable
     */
    public function setSlicePlannerCallableForTesting(?callable $callable): void
    {
        $this->slicePlannerCallable = $callable;
    }

    /**
     * Plan ONE packet via the canonical planner. Returns the planner plan
     * verbatim (decomposition_status + slices + blockers).
     *
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    public function planPacket(array $packet): array
    {
        $synthetic = $this->syntheticFinding($packet);

        return $this->runSlicePlanner([
            'finding' => $synthetic,
            'mode' => FindingSlicePlannerService::MODE_DRY_RUN,
            'scope_profile' => FindingSlicePlannerService::SCOPE_BALANCED,
        ]);
    }

    /**
     * Map a proposal packet to the synthetic finding shape the planner expects,
     * mirroring BuildPlanDecomposerService::syntheticFinding().
     *
     * @param  array<string,mixed>  $packet
     * @return array<string,mixed>
     */
    private function syntheticFinding(array $packet): array
    {
        $packetId = trim((string) ($packet['packet_id'] ?? ''));
        $label = trim((string) ($packet['label'] ?? ''));
        $delivery = trim((string) ($packet['delivery'] ?? ''));
        $objective = trim((string) ($packet['objective'] ?? ''));
        $acceptance = $this->stringList($packet['acceptance_criteria'] ?? []);
        $tests = $this->stringList($packet['tests_required'] ?? []);
        $ownerCandidate = strtolower(trim((string) ($packet['owner_candidate'] ?? '')));

        $affectedFiles = array_values(array_filter(
            $tests,
            static fn (string $file): bool => ! str_ends_with($file, 'Test.php') && ! str_starts_with($file, 'tests/'),
        ));

        return [
            'title' => $label !== '' ? $label : ($objective !== '' ? $objective : $packetId),
            'detail' => trim($delivery.($acceptance !== [] ? ' Acceptance: '.implode('; ', $acceptance) : '')),
            'why_it_matters' => $objective,
            'proposed_next_action' => $delivery,
            'affected_files' => $affectedFiles,
            'owner_candidate' => $ownerCandidate,
            'finding_id' => $packetId !== '' ? $packetId : ('packet_'.substr(md5($delivery.$label), 0, 12)),
            'finding_hash' => $packetId,
            'severity' => 'high',
            'kind' => trim((string) ($packet['kind'] ?? 'frontier_proposal_packet')),
            'origin_type' => 'frontier_proposal_packet',
            'spec_seed' => [
                'tests_required' => $tests,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function runSlicePlanner(array $input): array
    {
        if ($this->slicePlannerCallable !== null) {
            return ($this->slicePlannerCallable)($input);
        }

        return $this->slicePlanner->plan($input);
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        return AiStringListNormalizer::trimmedStrings($value);
    }
}
