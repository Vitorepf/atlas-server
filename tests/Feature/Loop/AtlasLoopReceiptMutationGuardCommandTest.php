<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the human-completion receipt mutation guard is live at the operator surface and emits deterministic
 * facts: identical receipts pass; a tampered protected field is blocked and named. A missing --before/--after
 * is a usage error.
 */
final class AtlasLoopReceiptMutationGuardCommandTest extends TestCase
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

    public function test_requires_both_receipts(): void
    {
        $exit = Artisan::call('atlas:loop:receipt-mutation-guard', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_identical_receipts_pass(): void
    {
        $receipt = $this->receipt();
        $decoded = $this->compare($receipt, $receipt);

        $this->assertSame('atlas.self_construction.human_completion_receipt_mutation_guard.v1', $decoded['schema_version']);
        $this->assertSame('passed', $decoded['status']);
        $this->assertTrue($decoded['guard_passed']);
        $this->assertSame(0, $decoded['mutation_count']);
    }

    public function test_tampered_protected_field_is_blocked(): void
    {
        $before = $this->receipt();
        $after = $before;
        $after['signed_by'] = 'attacker';

        $decoded = $this->compare($before, $after);

        $this->assertSame('blocked', $decoded['status']);
        $this->assertFalse($decoded['guard_passed']);
        $this->assertGreaterThanOrEqual(1, $decoded['mutation_count']);
        $this->assertContains('signed_by', array_column($decoded['mutations'], 'field'));
    }

    /**
     * @return array<string,mixed>
     */
    private function receipt(): array
    {
        return [
            'receipt_id' => 'rcpt_001',
            'signed_by' => 'operator',
            'reason' => 'os_complete',
            'receipt_hash' => 'abc123',
            'os_complete_approved' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $before
     * @param  array<string,mixed>  $after
     * @return array<string,mixed>
     */
    private function compare(array $before, array $after): array
    {
        $beforePath = tempnam(sys_get_temp_dir(), 'rcpt_before_').'.json';
        $afterPath = tempnam(sys_get_temp_dir(), 'rcpt_after_').'.json';
        $this->files[] = $beforePath;
        $this->files[] = $afterPath;
        file_put_contents($beforePath, json_encode($before));
        file_put_contents($afterPath, json_encode($after));

        $exit = Artisan::call('atlas:loop:receipt-mutation-guard', [
            '--before' => $beforePath,
            '--after' => $afterPath,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
