<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\CognitiveMemory\AtlasCognitiveWorkingSetMemoryService;
use Tests\TestCase;

/**
 * Atlas Cognition Operating System — ACMF Phase 1 tests.
 *
 * Cobre logica pura (in-memory): budget, working set, delta, pressure.
 */
class AtlasCognitiveMemoryFabricServiceTest extends TestCase
{
    public function test_budget_returns_canonical_envelope_per_mode(): void
    {
        $service = new AtlasCognitiveWorkingSetMemoryService;

        $balanced = $service->budget('balanced');
        $this->assertSame('atlas.cognitive_memory.budget.v1', $balanced['schema_version']);
        $this->assertSame('balanced', $balanced['mode']);
        $this->assertSame(60, $balanced['max_items']);

        $emergency = $service->budget('emergency_trim');
        $this->assertSame(5, $emergency['max_items']);

        $deep = $service->budget('deep_work');
        $this->assertSame(300, $deep['max_items']);
    }

    public function test_budget_falls_back_to_balanced_for_invalid_mode(): void
    {
        $service = new AtlasCognitiveWorkingSetMemoryService;
        $b = $service->budget('invalid_mode');
        $this->assertSame('balanced', $b['mode']);
    }

    public function test_track_adds_item_to_working_set(): void
    {
        $service = new AtlasCognitiveWorkingSetMemoryService;

        $service->track('test_scope', [
            'content_hash' => hash('sha256', 'item-1'),
            'content' => 'item one',
            'must_keep' => false,
        ]);

        $ws = $service->workingSet('test_scope');
        $this->assertSame(1, $ws['total_tracked']);
        $this->assertSame(1, $ws['kept']);
    }

    public function test_track_updates_existing_item_increments_hit_count(): void
    {
        $service = new AtlasCognitiveWorkingSetMemoryService;

        $hash = hash('sha256', 'duplicate-item');
        $service->track('test_scope', ['content_hash' => $hash, 'content' => 'x']);
        $service->track('test_scope', ['content_hash' => $hash, 'content' => 'x']);
        $service->track('test_scope', ['content_hash' => $hash, 'content' => 'x']);

        $ws = $service->workingSet('test_scope');
        $this->assertSame(1, $ws['total_tracked']);
        $this->assertSame(3, $ws['items'][0]['hit_count']);
    }

    public function test_working_set_trims_to_budget_preserving_must_keep(): void
    {
        $service = new AtlasCognitiveWorkingSetMemoryService;

        // Track 10 items normais.
        for ($i = 0; $i < 10; $i++) {
            $service->track('s', [
                'content_hash' => hash('sha256', "n-$i"),
                'content' => "normal $i",
                'must_keep' => false,
            ]);
        }
        // Track 2 must_keep.
        for ($i = 0; $i < 2; $i++) {
            $service->track('s', [
                'content_hash' => hash('sha256', "k-$i"),
                'content' => "keep $i",
                'must_keep' => true,
            ]);
        }

        $ws = $service->workingSet('s', 'emergency_trim'); // max_items=5
        $this->assertSame(12, $ws['total_tracked']);
        // Must keep nao trimmed; alguns normais sao.
        $this->assertGreaterThanOrEqual(2, $ws['kept']);
        $mustKeepCount = 0;
        foreach ($ws['items'] as $it) {
            if ($it['must_keep']) {
                $mustKeepCount++;
            }
        }
        $this->assertSame(2, $mustKeepCount, 'must_keep nao pode ser trimmed.');
    }

    public function test_working_set_sorts_by_heat_score_descending(): void
    {
        $service = new AtlasCognitiveWorkingSetMemoryService;

        // Item with 1 hit.
        $service->track('s', ['content_hash' => hash('sha256', 'a'), 'content' => 'a']);
        // Item with 5 hits.
        $b = hash('sha256', 'b');
        for ($i = 0; $i < 5; $i++) {
            $service->track('s', ['content_hash' => $b, 'content' => 'b']);
        }

        $ws = $service->workingSet('s');
        $this->assertSame($b, $ws['items'][0]['content_hash'], 'item com mais hits deve aparecer primeiro.');
    }

    public function test_delta_excludes_already_seen(): void
    {
        $service = new AtlasCognitiveWorkingSetMemoryService;

        $h1 = hash('sha256', '1');
        $h2 = hash('sha256', '2');
        $h3 = hash('sha256', '3');

        // First delta call: all 3 are new.
        $delta1 = $service->delta('s', [
            ['content_hash' => $h1, 'content' => 'a'],
            ['content_hash' => $h2, 'content' => 'b'],
            ['content_hash' => $h3, 'content' => 'c'],
        ]);

        $this->assertSame(3, $delta1['delta_size']);
        $this->assertSame(0, $delta1['already_seen']);

        // Second delta call with same items: all already seen.
        $delta2 = $service->delta('s', [
            ['content_hash' => $h1, 'content' => 'a'],
            ['content_hash' => $h2, 'content' => 'b'],
        ]);

        $this->assertSame(0, $delta2['delta_size']);
        $this->assertSame(2, $delta2['already_seen']);

        // Third delta with one new + one old.
        $h4 = hash('sha256', '4');
        $delta3 = $service->delta('s', [
            ['content_hash' => $h1, 'content' => 'a'], // seen
            ['content_hash' => $h4, 'content' => 'd'], // new
        ]);

        $this->assertSame(1, $delta3['delta_size']);
        $this->assertSame(1, $delta3['already_seen']);
    }

    public function test_delta_always_includes_must_keep(): void
    {
        $service = new AtlasCognitiveWorkingSetMemoryService;

        $hk = hash('sha256', 'k');
        $hn = hash('sha256', 'n');

        // First delta - both new.
        $delta1 = $service->delta('s', [
            ['content_hash' => $hk, 'content' => 'k', 'must_keep' => true],
            ['content_hash' => $hn, 'content' => 'n', 'must_keep' => false],
        ]);
        $this->assertSame(2, $delta1['delta_size']);

        // Second delta - must_keep ainda incluido, normal nao.
        $delta2 = $service->delta('s', [
            ['content_hash' => $hk, 'content' => 'k', 'must_keep' => true],
            ['content_hash' => $hn, 'content' => 'n', 'must_keep' => false],
        ]);
        $this->assertSame(1, $delta2['delta_size'], 'must_keep deve aparecer.');
        $this->assertSame(1, $delta2['must_keep_included']);
        $this->assertSame(1, $delta2['already_seen']);
    }

    public function test_reset_delta_clears_seen(): void
    {
        $service = new AtlasCognitiveWorkingSetMemoryService;
        $h = hash('sha256', 'r');

        $service->delta('s', [['content_hash' => $h, 'content' => 'x']]);
        $service->resetDelta('s');
        $second = $service->delta('s', [['content_hash' => $h, 'content' => 'x']]);

        $this->assertSame(1, $second['delta_size'], 'apos reset, item antigo volta a aparecer.');
    }

    public function test_pressure_event_classifies_severity(): void
    {
        $service = new AtlasCognitiveWorkingSetMemoryService;

        // Empty - no pressure.
        $p0 = $service->pressureEvent('p', 'emergency_trim');
        $this->assertSame('none', $p0['severity']);
        $this->assertFalse($p0['pressure']);

        // Fill at 100% of emergency_trim budget (5 items).
        for ($i = 0; $i < 5; $i++) {
            $service->track('p', ['content_hash' => hash('sha256', "x-$i")]);
        }
        $p1 = $service->pressureEvent('p', 'emergency_trim');
        $this->assertSame('high', $p1['severity']);
        $this->assertTrue($p1['pressure']);

        // Push to 200%.
        for ($i = 5; $i < 10; $i++) {
            $service->track('p', ['content_hash' => hash('sha256', "x-$i")]);
        }
        $p2 = $service->pressureEvent('p', 'emergency_trim');
        $this->assertSame('critical', $p2['severity']);
        $this->assertTrue($p2['pressure']);
    }

    public function test_static_validator(): void
    {
        $this->assertTrue(AtlasCognitiveWorkingSetMemoryService::isValidMode('balanced'));
        $this->assertFalse(AtlasCognitiveWorkingSetMemoryService::isValidMode('frenetic'));
    }
}
