<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutputContractNormalizer;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOutputContractNormalizerTest extends TestCase
{
    private AtlasExternalBrainOutputContractNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new AtlasExternalBrainOutputContractNormalizer();
    }

    // AC 2: valid task specs normalized with objective, allowed_files, acceptance, evidence
    public function test_valid_task_spec_normalized(): void
    {
        $result = $this->normalizer->normalize([
            'type' => 'task_spec',
            'objective' => 'Implement X',
            'allowed_files' => ['app/X.php'],
            'acceptance_criteria' => ['test passes'],
            'required_evidence' => ['test_result'],
        ]);

        $this->assertSame('task_spec', $result['type']);
        $this->assertSame('Implement X', $result['payload']['objective']);
        $this->assertEmpty($result['repair_hints']);
    }

    // AC 3: prose-only outputs become critique or no_proposal
    public function test_prose_only_with_suggestions_becomes_critique(): void
    {
        $result = $this->normalizer->normalize([
            'prose' => 'This design should be improved by splitting the class.',
        ]);

        $this->assertSame('critique', $result['type']);
    }

    public function test_prose_only_without_suggestions_becomes_no_proposal(): void
    {
        $result = $this->normalizer->normalize([
            'prose' => 'The system is operating normally today.',
        ]);

        $this->assertSame('no_proposal', $result['type']);
    }

    // AC 4: malformed task envelopes include repair hints
    public function test_malformed_task_envelope_includes_repair_hints(): void
    {
        $result = $this->normalizer->normalize([
            'type' => 'task_spec',
            'objective' => 'Do something',
            // missing allowed_files, acceptance_criteria, required_evidence
        ]);

        $this->assertSame('task_spec', $result['type']);
        $this->assertNotEmpty($result['repair_hints']);
        $this->assertTrue(
            count(array_filter($result['repair_hints'], fn ($h) => str_contains($h, 'allowed_files'))) > 0
        );
    }

    public function test_empty_input_is_no_proposal(): void
    {
        $result = $this->normalizer->normalize([]);

        $this->assertSame('no_proposal', $result['type']);
    }

    public function test_explicit_research_note_normalized(): void
    {
        $result = $this->normalizer->normalize([
            'type' => 'research_note',
            'research_note' => 'Found interesting pattern in Brain family',
        ]);

        $this->assertSame('research_note', $result['type']);
    }
}
