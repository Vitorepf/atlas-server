<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the completion-reality projector is live at the operator surface and emits deterministic facts:
 * passed-verification + admitted-merge + conformant knowledge-sync projects 'completed' with no missing
 * proofs; a failed verification projects 'rejected'. A missing --receipts is a usage error.
 */
final class AtlasLoopCompletionRealityCommandTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_requires_receipts(): void
    {
        $exit = Artisan::call('atlas:loop:completion-reality', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_full_clean_receipts_project_completed(): void
    {
        $decoded = $this->invoke([
            'verification_receipt' => ['verdict' => 'passed'],
            'merge_receipt' => ['decision' => 'admitted'],
            'rollback_receipt' => ['performed' => false],
            'knowledge_sync_receipt' => ['conformant' => true],
        ]);

        $this->assertSame('atlas.selfconstruction.completion_reality.v1', $decoded['schema']);
        $this->assertSame('completed', $decoded['reality']);
        $this->assertSame([], $decoded['missing_proofs']);
        $this->assertSame([], $decoded['residual_risks']);
    }

    public function test_failed_verification_projects_rejected(): void
    {
        $decoded = $this->invoke([
            'verification_receipt' => ['verdict' => 'failed'],
            'merge_receipt' => ['decision' => 'rejected'],
        ]);

        $this->assertSame('rejected', $decoded['reality']);
        $this->assertContains('missing_proof:knowledge_sync', $decoded['missing_proofs']);
    }

    /**
     * @param  array<string,mixed>  $receipts
     * @return array<string,mixed>
     */
    private function invoke(array $receipts): array
    {
        $path = tempnam(sys_get_temp_dir(), 'reality_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode($receipts));

        $exit = Artisan::call('atlas:loop:completion-reality', ['--receipts' => $path, '--json' => true]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
