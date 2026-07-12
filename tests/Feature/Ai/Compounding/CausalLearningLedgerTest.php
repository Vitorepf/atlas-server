<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compounding;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Compounding\CausalLearningCandidate;
use App\Services\Ai\Compounding\CausalLearningGate;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CausalLearningLedgerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('atlas_ledger_events');
        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        parent::tearDown();
    }

    public function test_adjudication_emits_one_idempotent_learning_event(): void
    {
        $candidate = CausalLearningCandidate::fromArray([
            'assignment_hash' => str_repeat('b', 64), 'experiment_hash' => str_repeat('c', 64), 'order_hash' => str_repeat('7', 64),
            'run_hash' => str_repeat('d', 64), 'release_hash' => str_repeat('e', 64), 'outcome_hash' => str_repeat('f', 64),
            'change_class' => 'routing', 'hypothesis' => 'route improves quality', 'baseline' => 'v1', 'metric' => 'quality',
            'window' => '7d', 'effect' => 0.18, 'ci_low' => 0.06, 'ci_high' => 0.3,
            'confounders' => ['provider' => 'controlled'], 'rollback' => 'v1', 'reversible' => true,
            'assignment_precedes_run' => true, 'real_outcome' => true, 'authority_hash' => str_repeat('1', 64),
            'scope' => 'atlas.route', 'expiry' => '2026-08-01T00:00:00Z',
            'assignment_at' => '2026-07-12T00:00:00Z', 'release_at' => '2026-07-12T00:10:00Z',
            'run_at' => '2026-07-12T00:20:00Z', 'outcome_at' => '2026-07-12T01:00:00Z',
            'binding_refs' => [
                'assignment' => ['hash' => str_repeat('b', 64), 'artifact_id' => 'assignment-1'],
                'experiment' => ['hash' => str_repeat('c', 64), 'artifact_id' => 'experiment-1'],
                'order' => ['hash' => str_repeat('7', 64), 'artifact_id' => 'order-1'],
                'run' => ['hash' => str_repeat('d', 64), 'artifact_id' => 'run-1'],
                'release' => ['hash' => str_repeat('e', 64), 'artifact_id' => 'release-1'],
                'outcome' => ['hash' => str_repeat('f', 64), 'artifact_id' => 'outcome-1'],
                'authority' => ['hash' => str_repeat('1', 64), 'artifact_id' => 'authority-1'],
            ],
        ]);
        $gate = new CausalLearningGate(app(AtlasEvidenceLedger::class));

        $first = $gate->adjudicate($candidate);
        $second = $gate->adjudicate($candidate);

        self::assertSame('promote_reversible', $first->verdict);
        self::assertSame($first->decisionHash, $second->decisionHash);
        self::assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::LearningProposed->value)->count());
        self::assertSame('learning.adjudicated', AtlasLedgerEvent::query()->firstOrFail()->payload['event_name']);
    }
}
