<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\CognitiveMemory;

use App\Services\Ai\CognitiveMemory\AtlasCognitiveWorkingSetMemoryService;
use Tests\TestCase;

/**
 * SIS1 (Obra #20) — Working Memory UNA persistida. The core property: a fact
 * tracked in one process survives into the NEXT (a fresh instance hydrating the
 * same file sees it) — the survives-the-process primitive under mobile→desktop
 * continuity. Aditivo: in-process behaviour is unchanged; it just durably lands.
 */
class AtlasWorkingSetPersistenceTest extends TestCase
{
    private function path(): string
    {
        $p = tempnam(sys_get_temp_dir(), 'working_set_').'.json';
        @unlink($p);

        return $p;
    }

    public function test_fact_survives_the_process(): void
    {
        $path = $this->path();

        // "Process A" records a fact.
        $a = new AtlasCognitiveWorkingSetMemoryService($path);
        $a->track('operator', ['content' => 'operador prefere PT-BR', 'content_hash' => 'h1']);
        $a->track('operator', ['content' => 'projeto = atlas-server', 'content_hash' => 'h2', 'must_keep' => true]);

        // "Process B" — a brand-new instance on the same file — sees them.
        $b = new AtlasCognitiveWorkingSetMemoryService($path);
        $view = $b->workingSet('operator');

        $this->assertSame(2, $view['total_tracked'], 'o working set deve sobreviver ao processo');
        $hashes = collect($view['items'])->pluck('content_hash')->all();
        $this->assertContains('h1', $hashes);
        $this->assertContains('h2', $hashes);
    }

    public function test_reset_is_durable_too(): void
    {
        $path = $this->path();

        $a = new AtlasCognitiveWorkingSetMemoryService($path);
        $a->track('p', ['content' => 'x', 'content_hash' => 'hx']);
        $a->resetWorkingSet('p');

        $b = new AtlasCognitiveWorkingSetMemoryService($path);
        $this->assertSame(0, $b->workingSet('p')['total_tracked'], 'o reset também deve persistir');
    }

    public function test_absent_state_is_empty_not_crash(): void
    {
        $ws = new AtlasCognitiveWorkingSetMemoryService($this->path());

        $this->assertSame(0, $ws->workingSet('never')['total_tracked']);
    }
}
