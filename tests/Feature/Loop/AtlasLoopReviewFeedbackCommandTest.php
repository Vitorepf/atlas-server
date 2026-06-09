<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopReviewFeedbackCommandTest extends TestCase
{
    private string $runId;

    private string $runDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runId = 'run-test-feedback-'.bin2hex(random_bytes(4));
        $this->runDir = storage_path('atlas/loop/unified/'.$this->runId);
        mkdir($this->runDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->runDir]))->run();
        parent::tearDown();
    }

    public function test_records_review_feedback_without_applying_any_change(): void
    {
        $this->artisan('atlas:loop:review-feedback', [
            '--run' => $this->runId,
            '--path' => 'app/Subject.php',
            '--mode' => 'coverage_gap',
            '--action' => 'rejected',
            '--reason' => 'not valuable now',
            '--json' => true,
        ])->assertExitCode(0);

        $path = $this->runDir.'/review_feedback.jsonl';
        $this->assertFileExists($path);
        $record = json_decode((string) file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)[0], true);

        $this->assertSame('atlas.loop.review_feedback.v1', $record['schema_version']);
        $this->assertSame('app/Subject.php', $record['path']);
        $this->assertSame('coverage_gap', $record['mode']);
        $this->assertSame('rejected', $record['action']);
        $this->assertSame('operator_review', $record['source']);
    }
}
