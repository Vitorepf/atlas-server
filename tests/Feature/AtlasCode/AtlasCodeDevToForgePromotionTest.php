<?php

declare(strict_types=1);

namespace Tests\Feature\AtlasCode;

use App\Services\AtlasCode\DevToForgePromotionService;
use App\Services\AtlasCode\PromotionSignalDetector;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Meta 8 · Dev-to-Forge Promotion · feature tests.
 *
 *   - small thread → recommends nothing / quick_intervention only on operator request
 *   - thread with architecture + risk + multi-file → obra_candidate or forge_obra
 *   - preview preserves workspace_slug
 *   - payload carries context/risks/criteria
 *   - candidate creation does NOT confuse Project with Obra
 *   - Blackink thread → candidate carries workspace_slug=blackink
 *   - operator promote with target=forge_obra → real Obra (AtlasProject) created
 *   - Attention picks up pending obra_candidate items (when integration is wired)
 */
class AtlasCodeDevToForgePromotionTest extends TestCase
{
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
        if (! Schema::hasTable('atlas_projects')) {
            Schema::create('atlas_projects', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('description')->nullable();
                $t->string('status')->default('active');
                $t->string('domain')->default('atlas');
                $t->uuid('source_capture_id')->nullable();
                $t->text('goal')->nullable();
                $t->text('next_action')->nullable();
                $t->string('project_type')->nullable();
                $t->text('desired_outcome')->nullable();
                $t->text('minimum_viable_outcome')->nullable();
                $t->text('definition_of_done')->nullable();
                $t->text('why_now')->nullable();
                $t->timestamp('deadline_at')->nullable();
                $t->string('deadline_kind')->nullable();
                $t->string('priority')->default('medium');
                $t->string('energy_profile')->nullable();
                $t->text('avoidance_reason')->nullable();
                $t->uuid('active_next_task_id')->nullable();
                $t->uuid('current_step_id')->nullable();
                $t->timestamp('last_touched_at')->nullable();
                $t->timestamp('next_review_at')->nullable();
                $t->timestamp('completed_at')->nullable();
                $t->timestamp('paused_until')->nullable();
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
                $t->string('surface')->nullable();
                $t->string('workspace')->nullable();
                $t->string('source_type')->nullable();
                $t->uuid('source_id')->nullable();
                $t->uuid('last_trace_id')->nullable();
                $t->string('last_provider')->nullable();
                $t->integer('message_count')->default(0);
                $t->timestamp('last_message_at')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }
        if (! Schema::hasTable('ai_messages')) {
            Schema::create('ai_messages', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->uuid('thread_id');
                $t->uuid('trace_id')->nullable();
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
                $t->string('source_type')->default('app');
                $t->uuid('source_id')->nullable();
                $t->string('status')->default('completed');
                $t->text('operator_input')->nullable();
                $t->text('response_text')->nullable();
                $t->json('metadata')->nullable();
                $t->timestamps();
            });
        }

        // Wipe storage between tests so candidate filesystem doesn't bleed.
        $dir = storage_path('app/atlas-code/promotion-candidates');
        if (is_dir($dir)) {
            File::deleteDirectory($dir);
        }
        DB::table('ai_messages')->delete();
        DB::table('ai_threads')->delete();
        DB::table('ai_traces')->delete();
    }

    private function seedThread(string $workspace, array $messageContents): string
    {
        $threadId = (string) Str::uuid();
        DB::table('ai_threads')->insert([
            'id' => $threadId,
            'title' => 'Conversa de teste',
            'summary' => '',
            'status' => 'active',
            'surface' => 'atlas_ai',
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

    public function test_small_thread_does_not_recommend_obra(): void
    {
        $threadId = $this->seedThread('atlas', [
            ['role' => 'user', 'content' => 'Olá, pode renomear a variável foo para bar em util.ts?'],
            ['role' => 'assistant', 'content' => 'Pronto, renomeado.'],
        ]);
        $detector = new PromotionSignalDetector();
        $thread = ['id' => $threadId, 'workspace' => 'atlas'];
        $messages = DB::table('ai_messages')->where('thread_id', $threadId)->orderBy('position')->get()
            ->map(fn ($r) => ['role' => $r->role, 'content' => $r->content])->all();
        $report = $detector->analyse($thread, $messages);

        $this->assertSame(PromotionSignalDetector::TARGET_NONE, $report['recommended_target']);
        $this->assertLessThan(2, $report['score']);
    }

    public function test_operator_explicit_request_promotes_to_obra_candidate(): void
    {
        $threadId = $this->seedThread('atlas', [
            ['role' => 'user', 'content' => 'Acho que isso virou obra. Pode criar obra para esse refactor de auth?'],
            ['role' => 'assistant', 'content' => 'Posso preparar; vamos definir o escopo.'],
        ]);
        $messages = DB::table('ai_messages')->where('thread_id', $threadId)->orderBy('position')->get()
            ->map(fn ($r) => ['role' => $r->role, 'content' => $r->content])->all();
        $report = (new PromotionSignalDetector())->analyse(
            ['id' => $threadId, 'workspace' => 'atlas'],
            $messages
        );
        $this->assertContains('operator_promotion_request', $report['reasons']);
        $this->assertContains(
            $report['recommended_target'],
            [PromotionSignalDetector::TARGET_OBRA_CANDIDATE, PromotionSignalDetector::TARGET_FORGE_OBRA],
        );
    }

    public function test_architecture_plus_risk_plus_multifile_recommends_forge_obra(): void
    {
        $contents = [
            ['role' => 'user', 'content' => 'Precisamos redesenhar a arquitetura de auth em produção. Migration de tokens.'],
            ['role' => 'assistant', 'content' => 'Mexer em `app/Services/Auth/Login.php` e `app/Http/Controllers/AuthController.php`.'],
            ['role' => 'user', 'content' => 'Também `app/Models/User.php`, `database/migrations/2026_x.php` e `app/Services/Tokens/Refresh.php`.'],
            ['role' => 'assistant', 'content' => 'Risco de breaking change em production. Necessário rollback.'],
            ['role' => 'user', 'content' => 'Falhou rodar test, erro de migration. Spec precisa cobrir o caso edge.'],
            ['role' => 'assistant', 'content' => 'Vou propor um RFC. Refactor de larga escala em `src/auth/index.ts` também.'],
        ];
        $threadId = $this->seedThread('atlas', $contents);
        $messages = DB::table('ai_messages')->where('thread_id', $threadId)->orderBy('position')->get()
            ->map(fn ($r) => ['role' => $r->role, 'content' => $r->content])->all();
        $report = (new PromotionSignalDetector())->analyse(
            ['id' => $threadId, 'workspace' => 'atlas'],
            $messages
        );

        $this->assertGreaterThanOrEqual(4, $report['score'], 'Strong signals should score ≥4 (obra_candidate threshold)');
        $this->assertContains(
            $report['recommended_target'],
            [PromotionSignalDetector::TARGET_OBRA_CANDIDATE, PromotionSignalDetector::TARGET_FORGE_OBRA],
        );
        $this->assertGreaterThan(0, (int) ($report['signals']['architecture']['hits'] ?? 0));
        $this->assertGreaterThan(0, (int) ($report['signals']['risk']['hits'] ?? 0));
    }

    public function test_preview_preserves_workspace_slug_and_payload_shape(): void
    {
        $threadId = $this->seedThread('blackink', [
            ['role' => 'user', 'content' => 'Promover: precisamos refactor de checkout em production. `src/Checkout/Cart.tsx`.'],
            ['role' => 'assistant', 'content' => 'OK.'],
        ]);
        $response = $this->withHeaders($this->headers())
            ->getJson("/atlas-code/dev-to-forge/threads/{$threadId}/promotion-preview");

        $response
            ->assertOk()
            ->assertJsonStructure([
                'preview' => [
                    'schema_version',
                    'source_thread_id',
                    'workspace_slug',
                    'workspace_name',
                    'title',
                    'objective',
                    'context_summary',
                    'known_files',
                    'risks',
                    'open_questions',
                    'suggested_success_criteria',
                    'suggested_next_step',
                    'promotion_target',
                    'signal_report',
                ],
            ])
            ->assertJsonPath('preview.schema_version', DevToForgePromotionService::SCHEMA_VERSION)
            ->assertJsonPath('preview.source_thread_id', $threadId)
            ->assertJsonPath('preview.workspace_slug', 'blackink')
            ->assertJsonPath('preview.workspace_name', 'Blackink')
            ->assertJsonPath('preview.workspace_production_status', 'production');

        $risks = (array) $response->json('preview.risks');
        $this->assertNotEmpty($risks, 'Preview should surface at least one risk for Blackink (production).');
    }

    public function test_promotion_creates_filesystem_candidate_with_pending_status(): void
    {
        $threadId = $this->seedThread('atlas', [
            ['role' => 'user', 'content' => 'Por favor, vamos virar isso em obra. `src/auth/Login.tsx` e `src/auth/index.ts`.'],
            ['role' => 'assistant', 'content' => 'Combinado.'],
        ]);

        $response = $this->withHeaders($this->headers())->postJson(
            "/atlas-code/dev-to-forge/threads/{$threadId}/promote",
            ['promotion_target' => 'obra_candidate'],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('candidate.promotion_target', 'obra_candidate')
            ->assertJsonPath('candidate.candidate_status', 'pending_decision')
            ->assertJsonPath('candidate.workspace_slug', 'atlas')
            ->assertJsonPath('candidate.promoted_obra_id', null);

        // The filesystem write occurred under the workspace slug folder.
        $expectedDir = storage_path('app/atlas-code/promotion-candidates/atlas');
        $this->assertDirectoryExists($expectedDir);
        $this->assertNotEmpty(glob($expectedDir.'/*.json'));
    }

    public function test_promotion_to_forge_obra_creates_real_obra_bound_to_workspace(): void
    {
        $threadId = $this->seedThread('blackink', [
            ['role' => 'user', 'content' => 'Promove forge: refactor de auth em production. `src/auth/Login.tsx`.'],
            ['role' => 'assistant', 'content' => 'Combinado.'],
        ]);

        $response = $this->withHeaders($this->headers())->postJson(
            "/atlas-code/dev-to-forge/threads/{$threadId}/promote",
            [
                'promotion_target' => 'forge_obra',
                'overrides' => ['title' => 'Refactor de auth Blackink'],
            ],
        );

        $response
            ->assertCreated()
            ->assertJsonPath('candidate.promotion_target', 'forge_obra')
            ->assertJsonPath('candidate.candidate_status', 'promoted')
            ->assertJsonPath('candidate.workspace_slug', 'blackink');

        $obraId = $response->json('candidate.promoted_obra_id');
        $this->assertIsString($obraId);
        $row = DB::table('atlas_projects')->where('id', $obraId)->first();
        $this->assertNotNull($row);
        $metadata = json_decode((string) $row->metadata, true);
        // Project boundary must hold: Obra carries workspace_slug, NOT itself a Project.
        $this->assertSame('blackink', $metadata['workspace_slug'] ?? null);
        $this->assertSame('atlas-dev-promotion', $metadata['origin'] ?? null);
        $this->assertSame($threadId, $metadata['promoted_from_thread_id'] ?? null);
    }

    public function test_quick_intervention_does_not_show_up_in_attention(): void
    {
        $threadId = $this->seedThread('atlas', [
            ['role' => 'user', 'content' => 'Promove isso como intervenção rápida em util.ts'],
        ]);
        $this->withHeaders($this->headers())->postJson(
            "/atlas-code/dev-to-forge/threads/{$threadId}/promote",
            ['promotion_target' => 'quick_intervention'],
        )->assertCreated();

        $attention = $this->withHeaders($this->headers())->getJson('/atlas-code/attention?workspace=atlas');
        $items = collect($attention->json('queue_items'));
        $matching = $items->first(fn (array $it): bool => ($it['blocker_translation']['source'] ?? null) === 'dev_to_forge_candidate');
        $this->assertNull($matching, 'quick_intervention should NOT route through Attention');
    }

    public function test_pending_obra_candidate_shows_in_attention(): void
    {
        $threadId = $this->seedThread('atlas', [
            ['role' => 'user', 'content' => 'Vamos criar obra para esse refactor de auth, é arquitetura.'],
        ]);
        $promoteResponse = $this->withHeaders($this->headers())->postJson(
            "/atlas-code/dev-to-forge/threads/{$threadId}/promote",
            ['promotion_target' => 'obra_candidate'],
        );
        $promoteResponse->assertCreated();
        $candidateId = $promoteResponse->json('candidate.id');

        $attention = $this->withHeaders($this->headers())->getJson('/atlas-code/attention?workspace=atlas');
        $items = collect($attention->json('queue_items'));
        $matching = $items->first(function (array $it) use ($candidateId): bool {
            $tr = (array) ($it['blocker_translation'] ?? []);
            return ($tr['source'] ?? null) === 'dev_to_forge_candidate'
                && ($tr['candidate_id'] ?? null) === $candidateId;
        });
        $this->assertNotNull($matching, 'obra_candidate should appear in Attention queue');
        $this->assertSame('intake_needed', $matching['kind']);
        $this->assertContains('open_obra', $matching['allowed_actions']);
    }

    public function test_dismiss_endpoint_marks_candidate_as_dismissed(): void
    {
        $threadId = $this->seedThread('atlas', [
            ['role' => 'user', 'content' => 'Promove isso por favor.'],
        ]);
        $promote = $this->withHeaders($this->headers())->postJson(
            "/atlas-code/dev-to-forge/threads/{$threadId}/promote",
            ['promotion_target' => 'obra_candidate'],
        );
        $candidateId = $promote->json('candidate.id');

        $dismiss = $this->withHeaders($this->headers())->postJson(
            "/atlas-code/dev-to-forge/candidates/{$candidateId}/dismiss",
            ['reason' => 'Pequeno demais para virar obra']
        );
        $dismiss
            ->assertOk()
            ->assertJsonPath('candidate.candidate_status', 'dismissed')
            ->assertJsonPath('candidate.decision_reason', 'Pequeno demais para virar obra');

        // After dismissal it should NOT appear in Attention.
        $attention = $this->withHeaders($this->headers())->getJson('/atlas-code/attention?workspace=atlas');
        $items = collect($attention->json('queue_items'));
        $found = $items->first(function (array $it) use ($candidateId): bool {
            $tr = (array) ($it['blocker_translation'] ?? []);
            return ($tr['candidate_id'] ?? null) === $candidateId;
        });
        $this->assertNull($found);
    }

    public function test_unknown_thread_returns_404_honestly(): void
    {
        $bogus = (string) Str::uuid();
        $this->withHeaders($this->headers())
            ->getJson("/atlas-code/dev-to-forge/threads/{$bogus}/promotion-preview")
            ->assertNotFound();
    }
}
