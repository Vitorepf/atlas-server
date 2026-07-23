<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Models\AiMemoryDelta;
use App\Models\Capture;
use App\Services\Ai\Memory\AiMemoryDeltaProposer;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G3 / MEM-DELTA — AiMemoryDeltaProposer contract.
 *
 * Exercita o caminho REAL de proposta de memory delta a partir de capture
 * (quarentena imune) e o degrade-safe do caminho workspace:
 *  - proposeForCapture cria delta `pending` com requires_confirmation e
 *    evidencia capture_quarantine (fallback `legacy_missing` para captures
 *    antigas sem immune audit)
 *  - idempotente por scope capture:<id> (re-propor nao duplica)
 *  - capture privada (external_ai_allowed=false) vira claim provider-unsafe
 *    com confidence menor
 *  - capture sem texto nao gera delta
 *  - proposeForWorkspace degrada para [] quando ai_traces/ai_session_states
 *    nao existem (nunca crash)
 */
class AiMemoryDeltaProposerTest extends TestCase
{
    private AiMemoryDeltaProposer $proposer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['ai_memory_deltas', 'captures'] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('ai_memory_deltas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_trace_id')->nullable()->index();
            $table->uuid('source_session_id')->nullable()->index();
            $table->string('source_workspace')->nullable();
            $table->string('type', 32)->default('process');
            $table->text('claim');
            $table->json('evidence');
            $table->string('scope', 255)->default('global');
            $table->float('confidence')->default(0.5);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('use_when')->nullable();
            $table->json('do_not_use_when')->nullable();
            $table->boolean('requires_confirmation')->default(true);
            $table->string('status', 16)->default('pending');
            $table->uuid('superseded_by')->nullable();
            $table->uuid('promoted_memory_entry_id')->nullable();
            $table->timestamp('promoted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('captures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('client_id')->unique();
            $table->string('kind');
            $table->string('domain');
            $table->text('content_text')->nullable();
            $table->string('transcription_status')->default('na');
            $table->timestamp('captured_at');
            $table->string('captured_timezone');
            $table->json('pre_capture_digital_context')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamps();
            $table->softDeletes();
        });

        $this->proposer = new AiMemoryDeltaProposer;
    }

    private function capture(string $text, array $metadata = []): Capture
    {
        return Capture::create([
            'client_id' => (string) Str::uuid(),
            'kind' => 'text',
            'domain' => 'atlas',
            'content_text' => $text,
            'captured_at' => now(),
            'captured_timezone' => 'UTC',
            'pre_capture_digital_context' => [],
            'metadata' => $metadata,
        ]);
    }

    public function test_propose_for_capture_creates_pending_delta_with_legacy_missing_immune_fallback(): void
    {
        $capture = $this->capture('Decidimos padronizar receipts sha256 em todos os envelopes.');

        $delta = $this->proposer->proposeForCapture($capture);

        $this->assertInstanceOf(AiMemoryDelta::class, $delta);
        $this->assertSame('pending', $delta->status);
        $this->assertSame('capture:'.$capture->id, $delta->scope);
        $this->assertTrue((bool) $delta->requires_confirmation);
        $this->assertStringStartsWith('Capture candidate:', $delta->claim);

        $evidence = $delta->evidence[0];
        $this->assertSame('capture_quarantine', $evidence['kind']);
        $this->assertSame($capture->id, $evidence['ref']);
        $this->assertTrue($evidence['provider_safe']);
        // Capture antiga sem cognitive_quarantine -> fallback honesto, nunca promovivel agora.
        $this->assertSame('legacy_missing', $evidence['immune_audit_status']);
        $this->assertFalse($evidence['immune_memory_promotion_allowed_now']);
        $this->assertSame(hash('sha256', (string) $capture->content_text), $evidence['content_hash']);
    }

    public function test_propose_for_capture_is_idempotent_per_capture_scope(): void
    {
        $capture = $this->capture('Aprendizado repetido nao deve duplicar delta.');

        $first = $this->proposer->proposeForCapture($capture);
        $second = $this->proposer->proposeForCapture($capture);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AiMemoryDelta::query()->count());
    }

    public function test_private_capture_yields_provider_unsafe_claim_with_lower_confidence(): void
    {
        $capture = $this->capture('Conteudo privado do operador.', [
            'privacy' => ['external_ai_allowed' => false, 'sensitivity' => 'private'],
        ]);

        $delta = $this->proposer->proposeForCapture($capture);

        $this->assertSame(
            'Private capture candidate requires manual review before memory promotion.',
            $delta->claim,
        );
        $this->assertEqualsWithDelta(0.4, $delta->confidence, 0.001);
        $this->assertFalse($delta->evidence[0]['provider_safe']);
        $this->assertSame('private', $delta->evidence[0]['privacy_class']);
    }

    public function test_capture_without_text_produces_no_delta(): void
    {
        $capture = $this->capture('   ');

        $this->assertNull($this->proposer->proposeForCapture($capture));
        $this->assertSame(0, AiMemoryDelta::query()->count());
    }

    public function test_propose_for_workspace_degrades_to_empty_when_trace_tables_are_absent(): void
    {
        // ai_traces / ai_session_states nao existem neste schema minimo:
        // o proposer deve degradar honestamente para [], nunca lancar.
        $this->assertSame([], $this->proposer->proposeForWorkspace('/tmp/workspace-inexistente'));
    }
}
