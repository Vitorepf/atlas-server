<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compounding;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Compounding\CausalLearningCandidate;
use App\Services\Ai\Compounding\CausalLearningGate;
use App\Services\Ai\Compounding\CausalLearningEvidenceBindingVerifier;
use App\Services\Ai\Compounding\CausalLearningPromotionService;
use App\Services\Ai\Compounding\CausalLearningRoutingPromotionOwner;
use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
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
            'observation_schedule' => ['0h' => 'pending', '24h' => 'pending', '7d' => 'pending', '30d' => 'pending', '90d' => 'pending', '150d' => 'pending'],
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

    public function test_promotion_resolves_binding_artifacts_from_the_canonical_ledger(): void
    {
        $ledger = app(AtlasEvidenceLedger::class);
        $hashes = [];
        $bindingRefs = [];
        foreach (['assignment', 'experiment', 'order', 'run', 'release', 'outcome', 'authority'] as $binding) {
            $event = $ledger->record(
                LedgerEventType::LearningProposed,
                ['artifact_binding' => $binding, 'artifact_nonce' => str_repeat($binding[0], 8)],
                ['event_id' => 'artifact-'.$binding, 'emitter_stage' => 'test.artifact'],
            );
            self::assertNotNull($event);
            $hashes[$binding] = (string) $event->payload_hash;
            $bindingRefs[$binding] = ['hash' => $hashes[$binding], 'artifact_id' => 'artifact-'.$binding];
        }

        $candidate = CausalLearningCandidate::fromArray([
            'assignment_hash' => $hashes['assignment'], 'experiment_hash' => $hashes['experiment'],
            'order_hash' => $hashes['order'], 'run_hash' => $hashes['run'], 'release_hash' => $hashes['release'],
            'outcome_hash' => $hashes['outcome'], 'change_class' => 'routing',
            'hypothesis' => 'ledger-backed route improves quality', 'baseline' => 'route-v1', 'metric' => 'quality',
            'window' => '7d', 'effect' => 0.18, 'ci_low' => 0.06, 'ci_high' => 0.30,
            'confounders' => ['provider_drift' => 'controlled'], 'rollback' => 'route-v1', 'reversible' => true,
            'assignment_precedes_run' => true, 'real_outcome' => true, 'authority_hash' => $hashes['authority'],
            'scope' => 'atlas.route', 'expiry' => '2026-08-01T00:00:00Z',
            'assignment_at' => '2026-07-12T00:00:00Z', 'release_at' => '2026-07-12T00:10:00Z',
            'run_at' => '2026-07-12T00:20:00Z', 'outcome_at' => '2026-07-12T01:00:00Z',
            'observation_schedule' => ['0h' => 'pending', '24h' => 'pending', '7d' => 'pending', '30d' => 'pending', '90d' => 'pending', '150d' => 'pending'],
            'binding_refs' => $bindingRefs,
        ]);

        $verdict = (new CausalLearningGate($ledger))->adjudicate($candidate);
        $binding = (new CausalLearningEvidenceBindingVerifier($ledger))->verify($candidate);
        self::assertTrue($binding['admitted'], json_encode($binding, JSON_THROW_ON_ERROR));
        $promotion = (new CausalLearningPromotionService)->promote($candidate, $verdict, 'route-v2');

        self::assertSame('promote_reversible', $verdict->verdict);
        self::assertSame('promoted', $promotion->status);
    }

    public function test_owner_effect_receipt_is_persisted_with_canonical_promotion_event(): void
    {
        $ledger = app(AtlasEvidenceLedger::class);
        $hashes = [];
        $bindingRefs = [];
        foreach (['assignment', 'experiment', 'order', 'run', 'release', 'outcome', 'authority'] as $binding) {
            $event = $ledger->record(LedgerEventType::LearningProposed, ['artifact_binding' => $binding], ['event_id' => 'owner-artifact-'.$binding, 'emitter_stage' => 'test.artifact']);
            $hashes[$binding] = (string) $event->payload_hash;
            $bindingRefs[$binding] = ['hash' => $hashes[$binding], 'artifact_id' => 'owner-artifact-'.$binding];
        }
        $candidate = CausalLearningCandidate::fromArray([
            'assignment_hash' => $hashes['assignment'], 'experiment_hash' => $hashes['experiment'], 'order_hash' => $hashes['order'],
            'run_hash' => $hashes['run'], 'release_hash' => $hashes['release'], 'outcome_hash' => $hashes['outcome'],
            'change_class' => 'routing', 'hypothesis' => 'owner route improves quality', 'baseline' => 'route-v1', 'metric' => 'quality',
            'window' => '7d', 'effect' => 0.18, 'ci_low' => 0.06, 'ci_high' => 0.30, 'confounders' => ['provider_drift' => 'controlled'],
            'rollback' => 'route-v1', 'reversible' => true, 'assignment_precedes_run' => true, 'real_outcome' => true,
            'authority_hash' => $hashes['authority'], 'scope' => 'atlas.route', 'expiry' => '2026-08-01T00:00:00Z',
            'assignment_at' => '2026-07-12T00:00:00Z', 'release_at' => '2026-07-12T00:10:00Z', 'run_at' => '2026-07-12T00:20:00Z',
            'outcome_at' => '2026-07-12T01:00:00Z', 'task_category' => 'engineering', 'role' => 'worker', 'provider' => 'provider-a', 'model' => 'model-a',
            'observation_schedule' => ['0h' => 'pending', '24h' => 'pending', '7d' => 'pending', '30d' => 'pending', '90d' => 'pending', '150d' => 'pending'],
            'binding_refs' => $bindingRefs,
        ]);
        $verdict = (new CausalLearningGate($ledger))->adjudicate($candidate);
        $path = sys_get_temp_dir().'/atlas-causal-ledger-routing-'.bin2hex(random_bytes(5)).'.jsonl';
        $routing = new AtlasConductorRoutingMemory;
        $routing->setLogPathForTesting($path);

        try {
            (new CausalLearningPromotionService($ledger, new CausalLearningRoutingPromotionOwner($routing)))->promote($candidate, $verdict, 'route-v2');
            $event = AtlasLedgerEvent::query()->where('payload->event_name', 'causal.learning.promoted')->firstOrFail();
            self::assertSame(CausalLearningRoutingPromotionOwner::OWNER, $event->payload['owner']);
            self::assertSame('route-v1', $event->payload['before_state']['version']);
            self::assertSame('route-v2', $event->payload['after_state']['version']);
            self::assertSame(64, strlen((string) $event->payload['effect_receipt_hash']));
        } finally {
            if (is_file($path)) @unlink($path);
            if (is_file($path.'.preferred.jsonl')) @unlink($path.'.preferred.jsonl');
        }
    }
}
