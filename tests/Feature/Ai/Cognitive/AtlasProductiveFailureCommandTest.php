<?php

namespace Tests\Feature\Ai\Cognitive;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasProductiveFailureCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_120000_create_dreyfus_overlays_table.php'))->up();
        (require database_path('migrations/2026_05_07_140000_create_worked_examples_table.php'))->up();
        (require database_path('migrations/2026_05_07_180000_create_productive_failure_sessions_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('productive_failure_sessions');
        Schema::dropIfExists('worked_examples');
        Schema::dropIfExists('dreyfus_overlays');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_command_runs_full_productive_failure_session_with_ledger_events(): void
    {
        Artisan::call('atlas:worked-example', [
            'actionOrTopic' => 'author',
            'subject' => 'queue-batching',
            '--domain' => 'programming',
            '--title' => 'Queue batching latency',
            '--json' => true,
        ]);

        Artisan::call('atlas:productive-failure', [
            'actionOrTopic' => 'queue-batching',
            '--domain' => 'programming',
            '--dreyfus-stage' => 3,
            '--json' => true,
        ]);
        $started = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $sessionId = (int) data_get($started, 'session.id');

        $this->assertSame('started', $started['status']);
        $this->assertGreaterThan(0, $sessionId);

        Artisan::call('atlas:productive-failure', [
            'actionOrTopic' => 'attempt',
            'subject' => (string) $sessionId,
            '--prediction' => 'Redis memory is saturated and queue depth is rising.',
            '--attempt' => 'Check Redis memory, queue depth and worker count first.',
            '--time-spent' => 7,
            '--confidence' => 3,
            '--json' => true,
        ]);
        $attempt = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('attempt_recorded', $attempt['status']);

        Artisan::call('atlas:productive-failure', [
            'actionOrTopic' => 'compare',
            'subject' => (string) $sessionId,
            '--reality' => 'The canonical diagnosis starts with lock contention, pg_locks, Redis SLOWLOG and batching boundaries.',
            '--delta' => 'I predicted memory saturation but the validated reality was lock contention and batching contention.',
            '--json' => true,
        ]);
        $comparison = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('comparison_recorded', $comparison['status']);

        Artisan::call('atlas:productive-failure', [
            'actionOrTopic' => 'articulate',
            'subject' => (string) $sessionId,
            '--insight' => 'Latency can be contention-bound even when memory and CPU look healthy.',
            '--why-failed' => 'I over-indexed on Redis memory because it is the most visible metric.',
            '--principle' => 'When latency rises without CPU or memory pressure, inspect contention before capacity.',
            '--json' => true,
        ]);
        $articulation = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('articulation_recorded', $articulation['status']);
        $this->assertSame('proposal_only', data_get($articulation, 'transfer_test.status'));

        Artisan::call('atlas:productive-failure', [
            'actionOrTopic' => 'complete',
            'subject' => (string) $sessionId,
            '--json' => true,
        ]);
        $completed = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('completed', $completed['status']);
        $this->assertSame('complete', data_get($completed, 'session.completion_status'));
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::ProductiveFailureCompleted->value)->count());
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::ProductiveFailurePhase2ComparisonRecorded->value)->count());
    }

    public function test_completion_is_blocked_without_prediction_error_delta(): void
    {
        Artisan::call('atlas:productive-failure', [
            'actionOrTopic' => 'queue-batching',
            '--domain' => 'programming',
            '--json' => true,
        ]);
        $started = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $sessionId = (int) data_get($started, 'session.id');

        Artisan::call('atlas:productive-failure', [
            'actionOrTopic' => 'complete',
            'subject' => (string) $sessionId,
            '--json' => true,
        ]);
        $blocked = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('blocked', $blocked['status']);
        $this->assertSame('productive_failure_phase_2_comparison_missing', data_get($blocked, 'gate.reason'));
    }
}
