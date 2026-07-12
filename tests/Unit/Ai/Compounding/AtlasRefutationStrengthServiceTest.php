<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compounding;

use App\Models\AiLearningCandidate;
use App\Services\Ai\Compounding\AtlasRefutationStrengthService;
use Tests\TestCase;

final class AtlasRefutationStrengthServiceTest extends TestCase
{
    public function test_repeated_give_back_strength_is_monotonic_and_bounded_by_denominator(): void
    {
        $service = new AtlasRefutationStrengthService;

        $one = $service->forCandidate($this->candidate(['outcome' => 'give_back', 'repeated_failure_count' => 1]));
        $three = $service->forCandidate($this->candidate(['outcome' => 'give_back', 'repeated_failure_count' => 3]));

        $this->assertGreaterThan($one['strength'], $three['strength']);
        $this->assertSame(1, $one['denominator']);
        $this->assertSame(3, $three['denominator']);
        $this->assertLessThanOrEqual(1.0, $three['strength']);
    }

    public function test_quarantine_has_more_refutation_strength_than_give_back_with_same_denominator(): void
    {
        $service = new AtlasRefutationStrengthService;

        $giveBack = $service->forCandidate($this->candidate(['outcome' => 'give_back', 'repeated_failure_count' => 3]));
        $quarantine = $service->forCandidate($this->candidate(['outcome' => 'quarantine', 'repeated_failure_count' => 3]));

        $this->assertGreaterThan($giveBack['strength'], $quarantine['strength']);
        $this->assertSame('atlas.refutation_strength.v1', $quarantine['schema']);
        $this->assertSame(3, data_get($quarantine, 'components.severity'));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function candidate(array $payload): AiLearningCandidate
    {
        $candidate = new AiLearningCandidate;
        $candidate->forceFill([
            'id' => '00000000-0000-4000-8000-000000000001',
            'candidate_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
            'payload' => $payload,
            'evidence_refs' => ['ev:one'],
        ]);

        return $candidate;
    }
}
