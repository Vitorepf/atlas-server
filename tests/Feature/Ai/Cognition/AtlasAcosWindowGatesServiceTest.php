<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition;

use App\Services\Ai\Cognition\AtlasAcosWindowGatesService;
use Tests\TestCase;

/**
 * D (Obra #18/#19) window-gate panel: every gate is either measured (with a
 * real value) or honestly `aguardando_janela` / `sem_dados` — NEVER a fabricated
 * pass. A dimension may only be `met` when its value actually clears the
 * threshold; degrade-safe in sqlite :memory: (no crash).
 */
class AtlasAcosWindowGatesServiceTest extends TestCase
{
    public function test_status_is_honest_and_degrade_safe(): void
    {
        $status = app(AtlasAcosWindowGatesService::class)->status();

        $this->assertSame(AtlasAcosWindowGatesService::SCHEMA_VERSION, $status['schema_version']);
        $this->assertArrayHasKey('live_dimensions', $status);
        $this->assertArrayHasKey('window_receipts', $status);

        $honestDim = ['met', 'aguardando_janela', 'reported', 'sem_dados'];
        foreach ($status['live_dimensions'] as $d) {
            $this->assertContains($d['status'], $honestDim, 'live dimension status must be an honest label');
            // A pass is NEVER fabricated: `met` requires the value to clear >=70.
            if (($d['status'] ?? null) === 'met') {
                $this->assertGreaterThanOrEqual(70, (float) $d['value']);
            }
        }

        $honestReceipt = ['certified', 'aguardando_janela', 'sem_dados'];
        foreach ($status['window_receipts'] as $r) {
            $this->assertContains($r['status'], $honestReceipt);
            // A receipt is only `certified` when it truly is (echoes its own flag).
            if (($r['status'] ?? null) === 'certified') {
                $this->assertTrue($r['certified'] ?? false);
            }
        }
    }
}
