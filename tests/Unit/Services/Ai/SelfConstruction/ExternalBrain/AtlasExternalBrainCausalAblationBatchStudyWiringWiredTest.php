<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves AtlasExternalBrainCausalAblationBatchStudy is wired into
 * atlas:external-brain:originator-quality as two optional sections
 * (causal_ablation_batch_study, causal_ablation_compare), matching the
 * command's established optional-section convention.
 */
final class AtlasExternalBrainCausalAblationBatchStudyWiringWiredTest extends TestCase
{
    private string $inputPath;

    protected function tearDown(): void
    {
        if (isset($this->inputPath) && is_file($this->inputPath)) {
            unlink($this->inputPath);
        }
        parent::tearDown();
    }

    private function callCommand(array $payload): array
    {
        $this->inputPath = tempnam(sys_get_temp_dir(), 'causal_ablation_wiring_').'.json';
        file_put_contents($this->inputPath, (string) json_encode($payload));

        Artisan::call('atlas:external-brain:originator-quality', ['--input' => $this->inputPath]);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Command output is not valid JSON:\n{$raw}");

        return $decoded;
    }

    public function test_causal_ablation_batch_study_section_runs_when_supplied(): void
    {
        $decoded = $this->callCommand([
            'opportunities' => [],
            'causal_ablation_batch_study' => [
                'batches' => [
                    ['dimensions' => ['uses_evidence_gate' => true], 'outcome_dimensions' => ['value_proof_rate' => 0.9, 'give_back_rate' => 0.1]],
                    ['dimensions' => ['uses_evidence_gate' => true], 'outcome_dimensions' => ['value_proof_rate' => 0.8, 'give_back_rate' => 0.2]],
                    ['dimensions' => ['uses_evidence_gate' => false], 'outcome_dimensions' => ['value_proof_rate' => 0.2, 'give_back_rate' => 0.8]],
                    ['dimensions' => ['uses_evidence_gate' => false], 'outcome_dimensions' => ['value_proof_rate' => 0.1, 'give_back_rate' => 0.9]],
                ],
                'min_sample_size' => 2,
            ],
        ]);

        $this->assertArrayHasKey('causal_ablation_batch_study', $decoded);
        $this->assertSame('atlas.external_brain.causal_ablation_batch_study.v1', $decoded['causal_ablation_batch_study']['schema_version']);
        $this->assertSame(4, $decoded['causal_ablation_batch_study']['sample_size']);
    }

    public function test_causal_ablation_compare_section_runs_when_control_and_treatment_supplied(): void
    {
        $decoded = $this->callCommand([
            'opportunities' => [],
            'causal_ablation_compare' => [
                'control' => ['green_rate' => 0.5, 'sample_count' => 20],
                'treatment' => ['green_rate' => 0.7, 'sample_count' => 20],
            ],
        ]);

        $this->assertArrayHasKey('causal_ablation_compare', $decoded);
        $this->assertSame('keep_policy', $decoded['causal_ablation_compare']['decision']);
        $this->assertSame(0.2, $decoded['causal_ablation_compare']['causal_lift']);
    }

    public function test_causal_ablation_sections_absent_when_not_supplied(): void
    {
        $decoded = $this->callCommand(['opportunities' => []]);

        $this->assertArrayNotHasKey('causal_ablation_batch_study', $decoded);
        $this->assertArrayNotHasKey('causal_ablation_compare', $decoded);
    }
}
