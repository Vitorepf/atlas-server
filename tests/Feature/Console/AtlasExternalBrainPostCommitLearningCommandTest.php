<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasExternalBrainPostCommitLearningCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $inputFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-post-commit-learning-cli-'.bin2hex(random_bytes(6));
        @mkdir($this->tempBase, 0o755, true);
        $this->inputFile = $this->tempBase.'/input.json';
    }

    protected function tearDown(): void
    {
        if ($this->tempBase !== '' && is_dir($this->tempBase)) {
            (new Process(['rm', '-rf', $this->tempBase]))->run();
        }
        parent::tearDown();
    }

    private function writeInput(array $data): void
    {
        file_put_contents($this->inputFile, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:external-brain:post-commit-learning', $args);

        return [$exit, $kernel->output()];
    }

    public function test_missing_input_option_fails(): void
    {
        [$exit] = $this->runCmd([]);

        $this->assertSame(1, $exit);
    }

    public function test_nonexistent_input_path_fails(): void
    {
        [$exit] = $this->runCmd(['--input' => $this->tempBase.'/does-not-exist.json']);

        $this->assertSame(1, $exit);
    }

    public function test_empty_sections_produce_empty_learning_report(): void
    {
        $this->writeInput([]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame([], $decoded['commit_evaluations']);
        $this->assertSame([], $decoded['next_batch_constraints']);
        $this->assertSame([], $decoded['transfer_recommendations']);
    }

    public function test_strong_commit_produces_promoted_lesson_and_real_value_lift(): void
    {
        $this->writeInput([
            'commits' => [
                [
                    'changed_capability' => 'cap-strong',
                    'implementation_evidence' => true,
                    'test_evidence' => true,
                    'compounding_value' => 8,
                    'test_strength' => 8,
                    'scope_size' => 1,
                    'capability_lift_evidence' => ['lift-1'],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertNotEmpty($decoded['feedback']['promoted_lessons']);
        $this->assertSame('real_value_lift', $decoded['commit_evaluations'][0]['verdict']);
    }

    public function test_weak_commit_produces_warning_and_low_lift_verdict(): void
    {
        $this->writeInput([
            'commits' => [
                [
                    'changed_capability' => 'cap-weak',
                    'implementation_evidence' => true,
                    'test_evidence' => true,
                    'compounding_value' => 1,
                    'test_strength' => 1,
                    'scope_size' => 5,
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertNotEmpty($decoded['feedback']['warnings']);
        $this->assertNotEmpty($decoded['next_batch_constraints']);
        $this->assertSame('low_lift_cosmetic', $decoded['commit_evaluations'][0]['verdict']);
    }

    public function test_roadmap_delta_matures_capability_with_full_evidence(): void
    {
        $this->writeInput([
            'commits' => [
                [
                    'capability_id' => 'cap-mature',
                    'touched_files' => ['app/Foo.php'],
                    'impact_class' => 'real_capability',
                    'has_behavior_evidence' => true,
                    'before_maturity' => 'partial',
                    'after_maturity' => 'integrated',
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertContains('cap-mature', $decoded['roadmap_delta']['matured_capabilities']);
    }

    public function test_capability_transfer_recommendation_is_included(): void
    {
        $this->writeInput([
            'source_capabilities' => [
                ['id' => 'src-1', 'name' => 'source cap', 'area' => 'area-a', 'evidence_refs' => ['behavior:proof']],
            ],
            'destination_gaps' => [
                ['id' => 'dst-1', 'name' => 'dest gap', 'area' => 'area-b', 'destination_fit_score' => 0.9],
            ],
            'evidence_strength' => ['src-1' => 0.9],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertNotEmpty($decoded['transfer_recommendations']);
        $this->assertSame('src-1', $decoded['transfer_recommendations'][0]['source_id']);
    }

    public function test_batch_lift_reflects_before_after_delta(): void
    {
        $this->writeInput([
            'green_lift' => [
                'before' => [
                    ['status' => 'give_back', 'impact_weight' => 0.5],
                ],
                'after' => [
                    ['status' => 'green_commit', 'impact_weight' => 0.9],
                ],
            ],
        ]);

        [$exit, $out] = $this->runCmd(['--input' => $this->inputFile]);

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertSame('improvement', $decoded['batch_lift']['verdict']);
    }
}
