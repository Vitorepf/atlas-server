<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Attribution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Attribution\ProviderProofAttributionScorer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ProviderAndModelAttributionPerLaneContract;
use Tests\TestCase;

final class ProviderProofAttributionScorerTest extends TestCase
{
    private ProviderProofAttributionScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new ProviderProofAttributionScorer();
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, array{provider: ?string, model: ?string}>
     */
    private function lanes(array $roles): array
    {
        $lanes = [];
        foreach (ProviderAndModelAttributionPerLaneContract::LANE_ROLES as $role) {
            $lanes[$role] = in_array($role, $roles, true)
                ? ['provider' => 'codex_cli', 'model' => 'fast']
                : ['provider' => null, 'model' => null];
        }

        return $lanes;
    }

    private function allRoles(): array
    {
        return ProviderAndModelAttributionPerLaneContract::LANE_ROLES;
    }

    public function test_forge_changed_files_without_provider_calls_is_unattributed(): void
    {
        $result = $this->scorer->score('forge', 0, true, $this->lanes($this->allRoles()));

        $this->assertSame('unattributed_forge_diff', $result['verdict']);
        $this->assertFalse($result['attributed']);
        $this->assertSame(0.0, $result['attribution_score']);
    }

    public function test_forge_weak_coverage_is_blocked(): void
    {
        $result = $this->scorer->score('forge', 3, true, $this->lanes(['context_scout', 'architect']));

        $this->assertSame(0.4, $result['attribution_score']);
        $this->assertSame('weak_attribution_coverage', $result['verdict']);
        $this->assertFalse($result['attributed']);
    }

    public function test_partial_attribution_at_threshold_is_attributed(): void
    {
        $result = $this->scorer->score('forge', 3, true, $this->lanes(['context_scout', 'architect', 'implementer']));

        $this->assertSame(0.6, $result['attribution_score']);
        $this->assertSame('partial_attribution', $result['verdict']);
        $this->assertTrue($result['attributed']);
        $this->assertCount(2, $result['unattributed_lanes']);
    }

    public function test_full_attribution_for_non_forge_owner(): void
    {
        $result = $this->scorer->score('dev', 3, true, $this->lanes($this->allRoles()));

        $this->assertSame(1.0, $result['attribution_score']);
        $this->assertSame('fully_attributed', $result['verdict']);
        $this->assertSame([], $result['unattributed_lanes']);
    }

    public function test_no_changed_files_requires_no_proof_and_exposes_schema_literal(): void
    {
        $result = $this->scorer->score('forge', 3, false, $this->lanes([]));

        $this->assertSame('no_diff_no_proof_required', $result['verdict']);
        $this->assertTrue($result['attributed']);
        $this->assertSame('atlas.stewardship.provider_proof_attribution.v1', $result['schema_version']);
    }

    public function test_unattributed_lanes_are_sorted_ascending_and_typed_as_strings(): void
    {
        $result = $this->scorer->score('dev', 2, true, $this->lanes(['judge']));

        $expected = ['architect', 'context_scout', 'implementer', 'reviewer'];
        $this->assertSame($expected, $result['unattributed_lanes']);
        $this->assertSame(array_map('strval', $result['unattributed_lanes']), $result['unattributed_lanes']);
        $this->assertSame(array_values($result['unattributed_lanes']), $result['unattributed_lanes']);
    }

    public function test_non_forge_weak_coverage_falls_through_to_review(): void
    {
        $result = $this->scorer->score('dev', 4, true, $this->lanes(['implementer']));

        $this->assertSame(0.2, $result['attribution_score']);
        $this->assertSame('under_attribution_review', $result['verdict']);
        $this->assertFalse($result['attributed']);
        $this->assertSame(1, $result['attributed_lane_count']);
    }

    public function test_changed_files_with_no_attribution_blocks(): void
    {
        $result = $this->scorer->score('dev', 5, true, $this->lanes([]));

        $this->assertSame('no_lane_attribution', $result['verdict']);
        $this->assertFalse($result['attributed']);
        $this->assertSame(0.0, $result['attribution_score']);
        $this->assertSame(0, $result['attributed_lane_count']);
    }

    public function test_owner_is_normalised_and_extra_lane_keys_are_ignored(): void
    {
        $lanes = $this->lanes($this->allRoles());
        $lanes['rogue_lane'] = ['provider' => 'should_be_ignored', 'model' => 'x'];
        $lanes['implementer'] = ['provider' => '   ', 'model' => 'whitespace_only'];

        $result = $this->scorer->score('  FORGE  ', 3, true, $lanes);

        $this->assertSame(5, $result['total_lane_count']);
        $this->assertSame(4, $result['attributed_lane_count']);
        $this->assertSame(0.8, $result['attribution_score']);
        $this->assertSame('partial_attribution', $result['verdict']);
        $this->assertSame(['implementer'], $result['unattributed_lanes']);
    }
}
