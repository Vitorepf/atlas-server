<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusAdmissionCandidateNormalizer;
use Tests\TestCase;

final class AreaFocusAdmissionCandidateNormalizerTest extends TestCase
{
    public function test_level_phase_and_candidate_id_match_admission_gate_contract(): void
    {
        $candidate = ['id' => ' l8-p5 '];

        $level = AreaFocusAdmissionCandidateNormalizer::level($candidate);
        $phase = AreaFocusAdmissionCandidateNormalizer::phase($candidate);

        $this->assertSame('L8', $level);
        $this->assertSame('P5', $phase);
        $this->assertSame('L8-P5', AreaFocusAdmissionCandidateNormalizer::candidateId($level, $phase, $candidate));
    }

    public function test_explicit_tokens_override_combined_id(): void
    {
        $candidate = [
            'id' => 'L8-P5',
            'level' => ' l9 ',
            'phase' => ' q2 ',
        ];

        $this->assertSame('L9', AreaFocusAdmissionCandidateNormalizer::level($candidate));
        $this->assertSame('Q2', AreaFocusAdmissionCandidateNormalizer::phase($candidate));
    }

    public function test_kind_normalizes_explicit_or_split_id_tail(): void
    {
        $this->assertSame(
            'runtime_execution',
            AreaFocusAdmissionCandidateNormalizer::kind(['level' => 'L10', 'kind' => 'Runtime Execution']),
        );

        $this->assertSame(
            'precondition',
            AreaFocusAdmissionCandidateNormalizer::kind(['id' => 'L10-precondition']),
        );
    }

    public function test_completed_phase_and_dependency_helpers_are_deterministic(): void
    {
        $phasePrerequisites = [
            'SOVEREIGNTY' => [],
            'Q2' => ['SOVEREIGNTY'],
            'Q1' => ['Q2'],
        ];

        $completed = [' l9-sovereignty ', 'L9-Q2', 'L9-Q2', 42, ''];

        $this->assertSame(
            ['SOVEREIGNTY', 'Q2'],
            AreaFocusAdmissionCandidateNormalizer::completedPhases($completed, $phasePrerequisites),
        );
        $this->assertSame(
            ['L9-SOVEREIGNTY', 'L9-Q2', '42'],
            AreaFocusAdmissionCandidateNormalizer::uniqueUpperTokens($completed),
        );
        $this->assertSame(
            ['S103'],
            AreaFocusAdmissionCandidateNormalizer::missingDependencies(
                ['depends_on' => ['s102', 'S103', '', false]],
                ['S102'],
            ),
        );
    }

    public function test_empty_or_non_string_identifier_falls_back_to_unknown_candidate_id(): void
    {
        $this->assertSame(['', ''], AreaFocusAdmissionCandidateNormalizer::splitId(['id' => 123]));
        $this->assertSame('unknown', AreaFocusAdmissionCandidateNormalizer::candidateId('', '', ['id' => '   ']));
    }
}
