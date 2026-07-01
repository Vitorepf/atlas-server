<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainResearchToTaskCommandTest extends TestCase
{
    private string $inputPath;

    protected function tearDown(): void
    {
        if (isset($this->inputPath) && is_file($this->inputPath)) {
            unlink($this->inputPath);
        }
        parent::tearDown();
    }

    private function writeInput(array $payload): string
    {
        $this->inputPath = tempnam(sys_get_temp_dir(), 'research_input_').'.json';
        file_put_contents($this->inputPath, (string) json_encode($payload));

        return $this->inputPath;
    }

    private function callCommand(array $payload): array
    {
        $path = $this->writeInput($payload);
        Artisan::call('atlas:external-brain:research-to-task', ['--input' => $path]);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Command output is not valid JSON:\n{$raw}");

        return $decoded;
    }

    private function promisingFrontierRow(string $id, array $overrides = []): array
    {
        return array_merge([
            'id' => $id,
            'title' => "Pattern {$id}",
            'evidence_strength' => 0.90,
            'has_code' => true,
            'has_benchmark' => true,
            'atlas_fit_score' => 0.90,
            'implementation_risk' => 0.10,
            'atlas_failure_mode' => "Atlas lacks {$id} handling",
            'target_path' => "app/Services/Foo{$id}.php",
            'adaptation_notes' => 'Map pattern to Atlas service boundary.',
            'allowed_files' => ["app/Services/Foo{$id}.php"],
            'test_path' => "tests/Unit/Foo{$id}Test.php",
            'anti_goodhart_risks' => ['could be gamed by inflating counters'],
            'runnable_acceptance' => "./vendor/bin/phpunit tests/Unit/Foo{$id}Test.php",
            'source_type' => 'external_dissection',
        ], $overrides);
    }

    public function test_missing_input_option_fails(): void
    {
        $exitCode = Artisan::call('atlas:external-brain:research-to-task');
        $this->assertNotSame(0, $exitCode);
    }

    public function test_promising_frontier_row_becomes_a_promoted_task_candidate(): void
    {
        $result = $this->callCommand(['frontier_rows' => [$this->promisingFrontierRow('r1')]]);

        $this->assertSame(1, $result['promoted_count']);
        $this->assertSame('app/Services/Foor1.php', $result['promoted_task_candidates'][0]['target_path']);
        $this->assertSame([], $result['rejected_by_digestor']);
    }

    public function test_hype_only_row_is_blocked_by_triage_before_digestor(): void
    {
        $result = $this->callCommand(['frontier_rows' => [
            $this->promisingFrontierRow('r1', [
                'evidence_strength' => 0.10,
                'hype_signals' => ['revolutionary', 'game-changer'],
            ]),
        ]]);

        $this->assertSame(0, $result['promoted_count']);
        $this->assertCount(1, $result['rejected_by_triage']['hype_rejected']);
    }

    public function test_provider_dependent_row_is_blocked_before_digestor(): void
    {
        $result = $this->callCommand(['frontier_rows' => [
            $this->promisingFrontierRow('r1', ['provider_steady_state_dependency' => true]),
        ]]);

        $this->assertSame(0, $result['promoted_count']);
        $this->assertCount(1, $result['rejected_by_triage']['provider_dependent_rejected']);
    }

    public function test_promising_row_missing_runnable_acceptance_is_rejected_by_digestor(): void
    {
        $result = $this->callCommand(['frontier_rows' => [
            $this->promisingFrontierRow('r1', ['runnable_acceptance' => 'looks good to me']),
        ]]);

        $this->assertSame(0, $result['promoted_count']);
        $this->assertCount(1, $result['rejected_by_digestor']);
        $this->assertSame('no_runnable_acceptance', $result['rejected_by_digestor'][0]['rejection_reason']);
    }

    public function test_direct_research_items_bypass_triage_and_go_straight_to_digestor(): void
    {
        $item = [
            'atlas_failure_mode' => 'Atlas lacks retry backoff',
            'target_path' => 'app/Services/Retry.php',
            'adaptation_notes' => 'Wrap provider calls.',
            'allowed_files' => ['app/Services/Retry.php'],
            'test_path' => 'tests/Unit/RetryTest.php',
            'anti_goodhart_risks' => ['could mask real failures'],
            'runnable_acceptance' => './vendor/bin/phpunit tests/Unit/RetryTest.php',
            'source_type' => 'manual_review',
        ];

        $result = $this->callCommand(['research_items' => [$item]]);

        $this->assertSame(1, $result['promoted_count']);
    }
}
