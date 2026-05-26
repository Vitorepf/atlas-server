<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AiTrace;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasObraReplayControllerTest extends TestCase
{
    /** @var array<string,string> */
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'test-token-with-enough-length-123');
    }

    public function test_returns_503_when_stream_events_table_missing(): void
    {
        // Drop the table specifically for this test if it exists.
        if (Schema::hasTable('ai_stream_events')) {
            Schema::drop('ai_stream_events');
        }
        // We still need ai_traces to exist for route model binding to load.
        if (! Schema::hasTable('ai_traces')) {
            Schema::create('ai_traces', function ($table): void {
                $table->uuid('id')->primary();
                $table->timestamps();
            });
        }
        $trace = AiTrace::query()->create(['id' => (string) \Illuminate\Support\Str::uuid()]);

        $response = $this->getJson("/atlas-code/obras/{$trace->id}/replay", $this->headers);

        $response->assertStatus(503)
            ->assertJsonPath('code', 'stream_events_unavailable');
    }
}
