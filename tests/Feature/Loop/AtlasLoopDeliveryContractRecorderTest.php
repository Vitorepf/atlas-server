<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopDeliveryContract;
use App\Services\Ai\AutonomousEvolution\AtlasLoopDeliveryContractRecorder;
use Closure;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ACDE B4b — proves the brain-feedback recorder: provider-safe (no code), deterministic anti-Goodhart
 * confidence, OFF no-op (byte-identical), fail-open, candidate dedupe, and the de-orphaning history() reader.
 */
final class AtlasLoopDeliveryContractRecorderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_delivery_contracts')) {
            (require base_path('database/migrations/2026_06_16_000200_create_atlas_loop_delivery_contracts_table.php'))->up();
        }
        AtlasLoopDeliveryContract::query()->delete();
    }

    /** A blast resolver returning a fixed radius so the test is independent of B4a's own flag/DB. */
    private function stubReader(array $consumers, string $risk = 'high'): Closure
    {
        return static fn (string $target): array => $consumers === []
            ? []
            : ['consumer_count' => count($consumers), 'risk' => $risk, 'consumers' => $consumers, 'truncated' => false];
    }

    public function test_off_is_a_byte_identical_no_op(): void
    {
        config(['atlas.loop.delivery_brain_feedback_enabled' => false]);
        (new AtlasLoopDeliveryContractRecorder($this->stubReader(['app/Foo/A.php'])))->record([
            'target_path' => 'app/Foo/Target.php',
            'changed_symbols' => ['foo'],
            'canary' => 'green',
            'quality' => [],
            'commit_sha' => 'abc',
        ]);
        $this->assertSame(0, AtlasLoopDeliveryContract::query()->count(), 'flag OFF => zero rows => byte-identical');
    }

    public function test_records_a_provider_safe_contract_when_armed(): void
    {
        config(['atlas.loop.delivery_brain_feedback_enabled' => true]);
        (new AtlasLoopDeliveryContractRecorder($this->stubReader(['app/Foo/A.php', 'app/Bar/B.php'])))->record([
            'target_path' => 'app/Foo/Target.php',
            'changed_symbols' => ['compute', 'compute', ' '],
            'canary' => 'green',
            'quality' => ['completeness' => 1.0],
            'commit_sha' => 'deadbeef',
        ]);

        $row = AtlasLoopDeliveryContract::query()->firstOrFail();
        $this->assertSame('app/Foo/Target.php', $row->target_path);
        $this->assertSame(['compute'], $row->changed_symbols, 'symbols deduped + blanks dropped');
        $this->assertSame(2, $row->consumer_count);
        $this->assertSame(['app/Foo/A.php', 'app/Bar/B.php'], $row->consumers);
        $this->assertGreaterThan(0.5, $row->confidence, 'clean + wired => above the clean-orphan floor');
    }

    public function test_transform_is_pure_and_provider_safe_no_code_leaks(): void
    {
        $candidate = (new AtlasLoopDeliveryContractRecorder($this->stubReader(['app/Foo/A.php'])))->toMemoryCandidates([
            'target_path' => 'app/Foo/Target.php',
            'changed_symbols' => ['render'],
            'canary' => 'green',
            'quality' => ['mutation_kill_ratio' => 0.8],
            'commit_sha' => 'c0ffee',
            'diff_text' => '<?php class Secret { function leak() {} }', // must NEVER appear in the record
        ]);

        $allowed = ['target_path', 'changed_symbols', 'consumer_count', 'consumers', 'risk_band', 'canary', 'mutation_kill_ratio', 'completeness', 'confidence', 'commit_sha', 'candidate_hash'];
        $this->assertSame([], array_diff(array_keys($candidate), $allowed), 'only provider-safe keys are emitted');
        $this->assertStringNotContainsString('Secret', json_encode($candidate), 'no raw diff/code ever enters the record');
        $this->assertStringNotContainsString('leak', json_encode($candidate));
    }

    public function test_confidence_is_deterministic_and_anti_goodhart(): void
    {
        $r = new AtlasLoopDeliveryContractRecorder;
        $clean = ['clean' => true, 'canary' => 'green'];
        $red = ['clean' => false, 'canary' => 'red'];

        // canary RED / non-clean proves nothing => 0
        $this->assertSame(0.0, $r->confidence($red, 50));
        $this->assertSame(0.0, $r->confidence(['clean' => false, 'canary' => 'not_run'], 10));
        // clean+wired strictly outranks clean+orphan; monotonic non-decreasing in consumers; saturates <= 1.
        $orphan = $r->confidence($clean, 0);
        $few = $r->confidence($clean, 3);
        $many = $r->confidence($clean, 100);
        $this->assertSame(0.5, $orphan);
        $this->assertGreaterThan($orphan, $few);
        $this->assertGreaterThanOrEqual($few, $many);
        $this->assertLessThanOrEqual(1.0, $many);
        // deterministic: same inputs => same output
        $this->assertSame($few, $r->confidence($clean, 3));
    }

    public function test_records_are_deduped_by_candidate_hash(): void
    {
        config(['atlas.loop.delivery_brain_feedback_enabled' => true]);
        $recorder = new AtlasLoopDeliveryContractRecorder($this->stubReader(['app/Foo/A.php']));
        $delivery = [
            'target_path' => 'app/Foo/Target.php',
            'changed_symbols' => ['x'],
            'canary' => 'green',
            'quality' => [],
            'commit_sha' => 'samesha',
        ];
        $recorder->record($delivery);
        $recorder->record($delivery); // same target+symbols+commit => one row
        $this->assertSame(1, AtlasLoopDeliveryContract::query()->count());
    }

    public function test_fail_open_when_the_reader_throws(): void
    {
        config(['atlas.loop.delivery_brain_feedback_enabled' => true]);
        $throwing = static function (string $target): array {
            throw new \RuntimeException('graph exploded');
        };
        // must swallow (best-effort) and write nothing — never propagate.
        (new AtlasLoopDeliveryContractRecorder($throwing))->record([
            'target_path' => 'app/Foo/Target.php',
            'changed_symbols' => ['x'],
            'canary' => 'green',
            'quality' => [],
            'commit_sha' => 'abc',
        ]);
        $this->assertSame(0, AtlasLoopDeliveryContract::query()->count(), 'a throwing reader leaves no row and no exception');
    }

    public function test_history_returns_the_newest_proven_contract(): void
    {
        config(['atlas.loop.delivery_brain_feedback_enabled' => true]);
        $recorder = new AtlasLoopDeliveryContractRecorder($this->stubReader(['app/Foo/A.php']));
        $recorder->record(['target_path' => 'app/Foo/Target.php', 'changed_symbols' => ['x'], 'canary' => 'green', 'quality' => [], 'commit_sha' => 's1']);
        // a red (confidence 0) contract for the same target must NOT be returned by history()
        $recorder->record(['target_path' => 'app/Foo/Target.php', 'changed_symbols' => ['y'], 'canary' => 'red', 'quality' => [], 'commit_sha' => 's2']);

        $history = $recorder->history('app/Foo/Target.php');
        $this->assertNotSame([], $history);
        $this->assertSame('app/Foo/Target.php', $history['target_path']);
        $this->assertGreaterThan(0.0, $history['confidence'], 'history only returns a PROVEN (confidence>0) contract');
        $this->assertSame([], $recorder->history('app/Nope/Missing.php'));
    }
}
