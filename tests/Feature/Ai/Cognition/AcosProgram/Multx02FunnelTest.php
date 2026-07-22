<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class Multx02FunnelTest extends TestCase
{
    private string $outcomesPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->outcomesPath = storage_path('framework/testing/multx02-'.bin2hex(random_bytes(4)).'.jsonl');
        @mkdir(dirname($this->outcomesPath), 0775, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->outcomesPath);
        parent::tearDown();
    }

    public function test_empty_window_reports_no_signal_never_healthy(): void
    {
        $payload = $this->callFunnel();

        $this->assertSame('atlas.m.funnel.v1', $payload['schema_version']);
        $this->assertSame('no_signal', $payload['status']);
        $this->assertSame([], $payload['by_executor']);
        $this->assertFalse(data_get($payload, 'claim_policy.single_scalar_score_emitted'));
        $this->assertFalse(data_get($payload, 'claim_policy.used_as_producer_target'));
    }

    public function test_funnel_reports_five_raw_stages_per_executor(): void
    {
        $this->appendOutcome('dev', ['learning_status' => 'candidate', 'lesson_promoted' => false]);
        $this->appendOutcome('dev', []);
        $this->appendOutcome('forge', ['learning_status' => 'candidate', 'lesson_promoted' => true, 'promoted_lesson_recalled' => false]);
        $this->appendOutcome('autonomos', [
            'learning_status' => 'candidate',
            'lesson_promoted' => true,
            'promoted_lesson_recalled' => true,
            'promoted_lesson_cited' => true,
            'subsequent_outcome_improved' => true,
        ]);

        $payload = $this->callFunnel();

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(['autonomos', 'dev', 'forge'], array_keys($payload['by_executor']));

        $dev = $payload['by_executor']['dev'];
        $this->assertSame(['outcomes_without_lesson', 'lessons_without_promotion', 'promoted_without_recall', 'recalls_without_citation', 'citations_without_better_outcome'], array_keys($dev['stages']));
        $this->assertSame(['num' => 1, 'den' => 2, 'status' => 'ok'], $dev['stages']['outcomes_without_lesson']);
        $this->assertSame(['num' => 1, 'den' => 1, 'status' => 'ok'], $dev['stages']['lessons_without_promotion']);
        $this->assertSame('insufficient', $dev['stages']['promoted_without_recall']['status']);

        $forge = $payload['by_executor']['forge'];
        $this->assertSame(['num' => 1, 'den' => 1, 'status' => 'ok'], $forge['stages']['promoted_without_recall']);

        $autonomos = $payload['by_executor']['autonomos'];
        $this->assertSame(['num' => 0, 'den' => 1, 'status' => 'ok'], $autonomos['stages']['citations_without_better_outcome']);
    }

    /**
     * @param  array<string,mixed>  $extra
     */
    private function appendOutcome(string $executor, array $extra): void
    {
        $entry = [
            'schema_version' => 'atlas.atlas_decide.live_outcome.v1',
            'recorded_at' => '2026-07-12T12:00:00+00:00',
            'task_category' => 'programming',
            'role' => $executor,
            'provider' => 'local',
            'result' => 'success',
            'actor' => 'engineering_outcome_spine:'.$executor,
        ] + $extra;

        file_put_contents($this->outcomesPath, json_encode($entry, JSON_UNESCAPED_SLASHES)."\n", FILE_APPEND);
    }

    /**
     * @return array<string,mixed>
     */
    private function callFunnel(): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:flywheel:funnel', [
            '--outcomes' => $this->outcomesPath,
            '--json' => true,
        ], $output);

        $this->assertSame(0, $exit);

        return json_decode(trim($output->fetch()), true, flags: JSON_THROW_ON_ERROR);
    }
}
