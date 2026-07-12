<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compaction;

use App\Services\Ai\LongHorizon\CompactionRecoveryExecutor;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CompactionTurnRecoveryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('ai_messages');
        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('thread_id');
            $table->integer('position');
            $table->string('role', 20);
            $table->string('status', 20)->default('stored');
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestampTz('occurred_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_messages');

        parent::tearDown();
    }

    public function test_recovery_queries_rehydrate_conversation_turns_from_ai_messages_but_not_redacted_turns(): void
    {
        $threadId = (string) Str::uuid();
        $turnA = (string) Str::uuid();
        $turnB = (string) Str::uuid();
        $redactedTurn = (string) Str::uuid();

        $this->insertMessage($turnA, $threadId, 1, 'user', 'final', 'Full user turn content survives compaction.');
        $this->insertMessage($turnB, $threadId, 2, 'assistant', 'final', 'Assistant turn is recoverable through turn prefix.');
        $this->insertMessage($redactedTurn, $threadId, 3, 'user', 'redacted', 'This text must not be recovered.');

        $recovery = app(CompactionRecoveryExecutor::class)->recover([
            'recovery_queries' => [
                'rehydrate conversation_turn:'.$turnA.' from canonical sources',
                'rehydrate turn:'.$turnB.' from canonical sources',
                'rehydrate conversation_turn:'.$redactedTurn.' from canonical sources',
            ],
        ]);

        $this->assertSame('partial', $recovery['status']);
        $this->assertSame(2, $recovery['recovered_count']);
        $this->assertSame(1, $recovery['missing_count']);
        $this->assertSame('ai_messages', data_get($recovery, 'items.conversation_turn:'.$turnA.'.source'));
        $this->assertSame('Full user turn content survives compaction.', data_get($recovery, 'items.conversation_turn:'.$turnA.'.payload.content'));
        $this->assertSame('Assistant turn is recoverable through turn prefix.', data_get($recovery, 'items.turn:'.$turnB.'.payload.content'));
        $this->assertSame('redacted_turn_not_recoverable', data_get($recovery, 'missing.0.reason'));
    }

    private function insertMessage(string $id, string $threadId, int $position, string $role, string $status, string $content): void
    {
        DB::table('ai_messages')->insert([
            'id' => $id,
            'thread_id' => $threadId,
            'position' => $position,
            'role' => $role,
            'status' => $status,
            'content' => $content,
            'metadata' => json_encode([]),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
