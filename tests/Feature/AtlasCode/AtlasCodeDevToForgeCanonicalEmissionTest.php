<?php

declare(strict_types=1);

namespace Tests\Feature\AtlasCode;

use App\Models\AiDualCoreRouteDecision;
use App\Services\Ai\DualCore\DualCoreRouteDecisionCanon;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use App\Services\AtlasCode\PromotionSignalDetector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesDualCoreRouteDecisionTables;
use Tests\TestCase;

/**
 * Phase 1 of the consolidation plan: the AtlasCode HTTP path
 * (Mechanism 2 in the audit) must dual-emit:
 *
 *   - `atlas.dev_to_forge.escalation_packet.v1` under `escalation_packet_v1`
 *      on the persisted candidate when target ∈ {forge_obra, obra_candidate}.
 *   - `atlas.dual_core.route_decision.v1` row persisted via
 *      DualCoreRouteDecisionService.
 *
 * `quick_intervention` is NOT a Dev->Forge handoff — the canonical packet
 * MUST NOT be emitted for that target.
 */
class AtlasCodeDevToForgeCanonicalEmissionTest extends TestCase
{
    use CreatesDualCoreRouteDecisionTables;

    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->createDualCoreRouteDecisionTables();

        if (! Schema::hasTable('atlas_projects')) {
            Schema::create('atlas_projects', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('description')->nullable();
                $t->string('status')->default('active');
                $t->string('domain')->default('atlas');
                $t->text('goal')->nullable();
                $t->text('desired_outcome')->nullable();
                $t->string('priority')->default('medium');
                $t->timestamp('last_touched_at')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
        if (! Schema::hasTable('ai_threads')) {
            Schema::create('ai_threads', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('summary')->nullable();
                $t->string('status')->default('active');
                $t->string('workspace')->nullable();
                $t->integer('message_count')->default(0);
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('thread_id');
                $t->integer('position')->default(1);
                $t->string('role');
                $t->string('status')->default('completed');
                $t->text('content')->nullable();
                $t->timestamp('occurred_at')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('ai_traces')) {
            Schema::create('ai_traces', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('thread_id')->nullable();
                $t->string('status')->default('completed');
                $t->timestamps();
            });
        }
        $dir = storage_path('app/atlas-code/promotion-candidates');
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
        DB::table('ai_messages')->delete();
        DB::table('ai_threads')->delete();
    }

    protected function tearDown(): void
    {
        $this->dropDualCoreRouteDecisionTables();
        parent::tearDown();
    }

    private function seedThread(array $messageContents, string $workspace = 'atlas'): string
    {
        $threadId = (string) Str::uuid();
        DB::table('ai_threads')->insert([
            'id' => $threadId,
            'title' => 'Refatorar billing engine multi-modulo Blackink',
            'summary' => 'Necessario SDD: auth, billing, payment flows.',
            'status' => 'active',
            'workspace' => $workspace,
            'message_count' => count($messageContents),
            'metadata' => json_encode([]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $pos = 1;
        foreach ($messageContents as $entry) {
            DB::table('ai_messages')->insert([
                'id' => (string) Str::uuid(),
                'thread_id' => $threadId,
                'position' => $pos++,
                'role' => $entry['role'],
                'status' => 'completed',
                'content' => $entry['content'],
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $threadId;
    }

    public function test_forge_obra_promotion_attaches_canonical_packet_v1_to_candidate(): void
    {
        $threadId = $this->seedThread([
            ['role' => 'user', 'content' => 'Refactor billing + auth de Blackink, multi-modulo, em produção. src/auth/Login.tsx, src/billing/Engine.ts.'],
            ['role' => 'assistant', 'content' => 'Vou tratar como obra (forge).'],
        ], 'blackink');

        $response = $this->withHeaders($this->headers())->postJson(
            "/atlas-code/dev-to-forge/threads/{$threadId}/promote",
            [
                'promotion_target' => PromotionSignalDetector::TARGET_FORGE_OBRA,
                'overrides' => ['title' => 'Refatorar billing engine Blackink'],
            ],
        );

        $response->assertCreated();
        $packet = $response->json('candidate.escalation_packet_v1');
        $this->assertIsArray($packet);
        $this->assertSame(EscalationPacket::SCHEMA_VERSION, $packet['schema_version']);
        $this->assertSame(EscalationPacket::SOURCE_CORE, $packet['source_core']);
        $this->assertSame(EscalationPacket::TARGET_CORE, $packet['target_core']);
        $this->assertSame(
            EscalationPacket::RECOMMENDED_FORGE_MODE_OBRA_INTAKE,
            $packet['recommended_forge_mode'],
        );
        $this->assertNotEmpty($packet['promotion_triggers']);
        $this->assertSame(64, strlen((string) $packet['packet_hash']));

        $routeMeta = $response->json('candidate.route_decision_v1');
        $this->assertTrue($routeMeta['recorded'] ?? false, 'route_decision.v1 must be recorded for forge_obra');
        $this->assertSame(DualCoreRouteDecisionCanon::ROUTE_DEV_TO_FORGE, $routeMeta['route']);

        $row = AiDualCoreRouteDecision::query()
            ->where('uuid', $routeMeta['uuid'])
            ->first();
        $this->assertNotNull($row);
        $this->assertSame(DualCoreRouteDecisionCanon::SCHEMA_VERSION, (string) $row->schema_version);
        $this->assertSame(DualCoreRouteDecisionCanon::ROUTE_DEV_TO_FORGE, (string) $row->route);
        $this->assertSame('atlas_code_dev_to_forge', (string) $row->actor_type);
        $signals = (array) $row->routing_signals;
        $this->assertSame($threadId, $signals['thread_id'] ?? null);
        $this->assertSame(PromotionSignalDetector::TARGET_FORGE_OBRA, $signals['promotion_target'] ?? null);
    }

    public function test_quick_intervention_does_not_emit_canonical_packet(): void
    {
        $threadId = $this->seedThread([
            ['role' => 'user', 'content' => 'Promove isso como intervenção rápida em util.ts'],
        ], 'atlas');

        $response = $this->withHeaders($this->headers())->postJson(
            "/atlas-code/dev-to-forge/threads/{$threadId}/promote",
            ['promotion_target' => PromotionSignalDetector::TARGET_QUICK_INTERVENTION],
        );

        $response->assertCreated();
        $this->assertNull(
            $response->json('candidate.escalation_packet_v1'),
            'quick_intervention is NOT a dev_to_forge handoff and must not carry the canonical packet.',
        );
        $routeMeta = $response->json('candidate.route_decision_v1');
        $this->assertFalse(
            $routeMeta['recorded'] ?? true,
            'quick_intervention must NOT persist a route_decision.v1.',
        );
        $this->assertSame(0, AiDualCoreRouteDecision::query()->count());
    }

    public function test_obra_candidate_promotion_also_emits_canonical_packet(): void
    {
        $threadId = $this->seedThread([
            ['role' => 'user', 'content' => 'Discovery: planeja refactor mais amplo do billing.'],
        ], 'atlas');

        $response = $this->withHeaders($this->headers())->postJson(
            "/atlas-code/dev-to-forge/threads/{$threadId}/promote",
            ['promotion_target' => PromotionSignalDetector::TARGET_OBRA_CANDIDATE],
        );

        $response->assertCreated();
        $packet = $response->json('candidate.escalation_packet_v1');
        $this->assertIsArray($packet);
        $this->assertSame(
            EscalationPacket::RECOMMENDED_FORGE_MODE_SDD_INTAKE,
            $packet['recommended_forge_mode'],
        );
        $this->assertSame(DualCoreRouteDecisionCanon::ROUTE_DEV_TO_FORGE, $response->json('candidate.route_decision_v1.route'));
    }
}
