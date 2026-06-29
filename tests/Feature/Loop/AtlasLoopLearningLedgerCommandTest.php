<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningLedger;
use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasSelfConstructionLearningLedgerQuery;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the learning-ledger query is live at the operator surface: an injected ledger holding two entries is
 * dumped in full by atlas:loop:learning-ledger.
 */
final class AtlasLoopLearningLedgerCommandTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = sys_get_temp_dir().'/atlas-learning-ledger-'.bin2hex(random_bytes(5)).'.jsonl';
        // Seed the durable JSONL with two entries (all() decodes lines as-is).
        file_put_contents($this->path, implode("\n", [
            (string) json_encode(['schema_version' => 'v1', 'lesson' => ['lesson_id' => 'l1', 'class' => 'refactor', 'decision' => 'give_back']]),
            (string) json_encode(['schema_version' => 'v1', 'lesson' => ['lesson_id' => 'l2', 'class' => 'feature', 'decision' => 'proof']]),
        ])."\n");

        $this->app->instance(
            AtlasSelfConstructionLearningLedgerQuery::class,
            new AtlasSelfConstructionLearningLedgerQuery(new AtlasSelfConstructionLearningLedger($this->path)),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    public function test_emits_all_ledger_entries(): void
    {
        $exit = Artisan::call('atlas:loop:learning-ledger', ['--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.learning_ledger.v1', $d['schema']);
        $this->assertSame(2, $d['entry_count'], (string) json_encode($d));
        $lessonIds = array_map(static fn (array $e): string => (string) ($e['lesson']['lesson_id'] ?? ''), $d['entries']);
        $this->assertContains('l1', $lessonIds);
        $this->assertContains('l2', $lessonIds);
    }

    public function test_empty_ledger_emits_no_entries(): void
    {
        @unlink($this->path); // no file ⇒ all() returns []

        $exit = Artisan::call('atlas:loop:learning-ledger', ['--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $d['entry_count']);
        $this->assertSame([], $d['entries']);
    }
}
