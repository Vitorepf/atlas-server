<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compounding;

use App\Models\AiLearningCandidate;
use App\Models\AiRunOutcome;
use App\Services\Ai\Compounding\AtlasLearningDistiller;
use App\Services\Ai\Compounding\DistillerAuthorAdapter;
use App\Services\Ai\Compounding\NullDistillerAuthorAdapter;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ASI-09 — author≠judge seam of the distiller.
 *
 * The model AUTHOR proposes the claim; the JUDGES (CaptureQualityGate +
 * false_learning_gate + ASI-02 admission + confidence floor) evaluate it. The
 * feature flag `atlas.ai.distiller.model_author_enabled` is default-OFF so the
 * shipped behavior is byte-identical to the template author.
 */
class DistillerAuthorAdapterSeamTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ai_run_outcomes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version')->nullable();
            $table->string('run_id')->nullable();
            $table->string('flow_id')->nullable();
            $table->string('outcome_status')->nullable();
            $table->integer('evidence_quality')->nullable();
            $table->boolean('learning_required')->default(false);
            $table->json('evidence_refs')->nullable();
            $table->string('outcome_hash')->nullable();
            $table->timestamps();
        });
        Schema::create('ai_learning_candidates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('schema_version')->nullable();
            $table->uuid('run_outcome_id')->nullable();
            $table->string('status')->nullable();
            $table->string('decision')->nullable();
            $table->string('memory_type')->nullable();
            $table->string('scope')->nullable();
            $table->text('claim')->nullable();
            $table->integer('confidence')->nullable();
            $table->boolean('promotion_allowed')->default(false);
            $table->json('evidence_refs')->nullable();
            $table->json('payload')->nullable();
            $table->string('candidate_hash')->nullable()->unique();
            $table->string('receipt_hash')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_learning_candidates');
        Schema::dropIfExists('ai_run_outcomes');

        parent::tearDown();
    }

    public function test_flag_off_is_byte_identical_to_template_author(): void
    {
        config(['atlas.ai.distiller.model_author_enabled' => false]);
        config(['atlas.ai.capture_quality_gate.mode' => 'observe']);

        $outcome = $this->outcome('atlas_conversation', 'delegated');

        $adapter = new class implements DistillerAuthorAdapter
        {
            public function authorClaim(AiRunOutcome $outcome, array $signals): ?array
            {
                return ['claim' => 'AUTHORED CLAIM (should not appear)', 'evidence_refs' => []];
            }
        };

        $distiller = new AtlasLearningDistiller($adapter);
        $candidate = $distiller->distill($outcome, ['evidence_refs' => ['trace:test']]);

        $this->assertStringContainsString('Flow atlas_conversation produced delegated outcome', (string) $candidate->claim);
        $this->assertArrayNotHasKey(
            'author',
            (array) $candidate->payload,
            'When the flag is OFF the payload must NOT record any model-author metadata (byte-identical to template).'
        );
    }

    public function test_flag_on_with_null_adapter_falls_back_to_template(): void
    {
        config(['atlas.ai.distiller.model_author_enabled' => true]);
        config(['atlas.ai.capture_quality_gate.mode' => 'observe']);

        $outcome = $this->outcome('atlas_debug', 'completed');

        $distiller = new AtlasLearningDistiller(new NullDistillerAuthorAdapter);
        $candidate = $distiller->distill($outcome, ['evidence_refs' => ['trace:t']]);

        $this->assertStringContainsString('Flow atlas_debug produced completed outcome', (string) $candidate->claim);
        $this->assertArrayNotHasKey('author', (array) $candidate->payload);
    }

    public function test_flag_on_with_authoring_adapter_uses_model_claim_and_records_provenance(): void
    {
        config(['atlas.ai.distiller.model_author_enabled' => true]);
        config(['atlas.ai.capture_quality_gate.mode' => 'observe']);

        $outcome = AiRunOutcome::query()->create([
            'id' => (string) Str::uuid(),
            'run_id' => 'asi09-refs:'.Str::ulid(),
            'flow_id' => 'atlas_forge',
            'outcome_status' => 'delegated',
            'evidence_quality' => 78,
            'learning_required' => true,
            'evidence_refs' => [], // author owns the refs when the caller has none
            'outcome_hash' => 'hash:'.Str::ulid(),
        ]);

        $adapter = new class implements DistillerAuthorAdapter
        {
            public function authorClaim(AiRunOutcome $outcome, array $signals): ?array
            {
                return [
                    'claim' => 'The Forge handoff to Hermes lost the run identity when the commissioning step retried with a new UUID; anchor the identity in the commissioning ledger before enqueue.',
                    'evidence_refs' => ['trace:forge:identity', 'commit:7ed929041e'],
                ];
            }
        };

        $distiller = new AtlasLearningDistiller($adapter);
        $candidate = $distiller->distill($outcome, []);

        $this->assertStringContainsString('anchor the identity in the commissioning ledger', (string) $candidate->claim);
        $author = data_get($candidate->payload, 'author');
        $this->assertIsArray($author);
        $this->assertSame('model_author', $author['source'] ?? null);
        $this->assertSame(
            $adapter::class,
            $author['adapter'] ?? null,
            'The adapter class MUST be recorded so dual-read (MED-01) can partition candidates by author engine.'
        );
        // Author-supplied refs are adopted only when the caller didn't provide any.
        $this->assertContains('trace:forge:identity', (array) $candidate->evidence_refs);
    }

    public function test_signals_claim_preempts_author(): void
    {
        // The caller can inject a pre-authored claim (e.g. a real Hermes run). It
        // stays authoritative and the adapter is never asked.
        config(['atlas.ai.distiller.model_author_enabled' => true]);
        config(['atlas.ai.capture_quality_gate.mode' => 'observe']);

        $outcome = $this->outcome('atlas_review', 'completed');

        $probe = new class implements DistillerAuthorAdapter
        {
            public bool $called = false;

            public function authorClaim(AiRunOutcome $outcome, array $signals): ?array
            {
                $this->called = true;

                return ['claim' => 'DO NOT USE', 'evidence_refs' => []];
            }
        };

        $distiller = new AtlasLearningDistiller($probe);
        $candidate = $distiller->distill($outcome, [
            'claim' => 'Caller-supplied claim wins',
            'evidence_refs' => ['trace:x'],
        ]);

        $this->assertSame('Caller-supplied claim wins', (string) $candidate->claim);
        $this->assertFalse($probe->called, 'Author must not be invoked when the caller already supplied a claim.');
    }

    public function test_judges_stay_deterministic_over_authored_claim_author_never_bypasses_gate(): void
    {
        // The JUDGES are unchanged. Boilerplate authored by the model is still
        // rejected in enforce mode — author≠judge.
        config(['atlas.ai.distiller.model_author_enabled' => true]);
        config(['atlas.ai.capture_quality_gate.mode' => 'enforce']);

        $outcome = $this->outcome('atlas_conversation', 'delegated');

        $boilerplateAuthor = new class implements DistillerAuthorAdapter
        {
            public function authorClaim(AiRunOutcome $outcome, array $signals): ?array
            {
                return [
                    'claim' => 'Specialist flow atlas_conversation emitted a learning signal contract for future routing, retrieval and execution evaluation.',
                    'evidence_refs' => ['trace:x'],
                ];
            }
        };

        $distiller = new AtlasLearningDistiller($boilerplateAuthor);
        $candidate = $distiller->distill($outcome, []);

        $this->assertSame('hold', $candidate->decision);
        $this->assertSame('held_low_quality', $candidate->status);
        $this->assertSame('meta_stub', data_get($candidate->payload, 'capture_quality.reason'));
        // Provenance still captured — audit knows this hold came from the model.
        $this->assertSame('model_author', data_get($candidate->payload, 'author.source'));
    }

    private function outcome(string $flow, string $status): AiRunOutcome
    {
        return AiRunOutcome::query()->create([
            'id' => (string) Str::uuid(),
            'run_id' => 'asi09:'.Str::ulid(),
            'flow_id' => $flow,
            'outcome_status' => $status,
            'evidence_quality' => 78,
            'learning_required' => true,
            'evidence_refs' => ['trace:seam'],
            'outcome_hash' => 'hash:'.Str::ulid(),
        ]);
    }
}
