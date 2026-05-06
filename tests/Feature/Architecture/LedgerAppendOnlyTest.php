<?php

namespace Tests\Feature\Architecture;

use App\Models\AtlasLedgerEvent;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Tests\TestCase;

class LedgerAppendOnlyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('atlas_ledger_events');
        $this->createLedgerTable();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_ledger_model_rejects_update_after_insert(): void
    {
        $event = AtlasLedgerEvent::query()->create($this->eventPayload());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');

        $event->emitter_version = 'mutated';
        $event->save();
    }

    public function test_ledger_model_rejects_delete(): void
    {
        $event = AtlasLedgerEvent::query()->create($this->eventPayload());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('append-only');

        $event->delete();
    }

    public function test_application_code_does_not_mutate_ledger_events_with_query_builder(): void
    {
        $violations = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = $file->getContents();
            if (! str_contains($contents, 'atlas_ledger_events')) {
                continue;
            }

            if (preg_match('/DB::table\(\s*[\'"]atlas_ledger_events[\'"]\s*\)(?:(?!;).)*(?:update|delete|truncate)\s*\(/s', $contents)) {
                $violations[] = $file->getRelativePathname().':query_builder_mutation';
            }

            if (preg_match('/AtlasLedgerEvent::(?:(?!;).)*(?:update|delete|destroy|truncate)\s*\(/s', $contents)) {
                $violations[] = $file->getRelativePathname().':model_static_mutation';
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * @return array<string,mixed>
     */
    private function eventPayload(): array
    {
        return [
            'event_id' => 'evt00000000000000000000000000001',
            'schema_version' => 'atlas.ledger_event.v1',
            'tenant_id' => 'default',
            'operator_id' => 'test',
            'envelope_id' => 'env-test',
            'receipt_id' => null,
            'trace_id' => 'trace-test',
            'correlation_id' => 'trace-test',
            'causation_id' => null,
            'event_type' => 'ENVELOPE_CREATED',
            'emitter_stage' => 'test',
            'emitter_version' => 'test',
            'payload' => ['ok' => true],
            'payload_hash' => str_repeat('a', 64),
            'occurred_at' => '2026-05-06 00:00:00',
        ];
    }

    private function createLedgerTable(): void
    {
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 32)->primary();
            $table->string('schema_version', 40)->default('atlas.ledger_event.v1');
            $table->string('tenant_id', 120)->index();
            $table->string('operator_id', 120)->index();
            $table->string('envelope_id', 80)->index();
            $table->string('receipt_id', 80)->nullable()->index();
            $table->string('trace_id', 80)->nullable()->index();
            $table->string('correlation_id', 120)->index();
            $table->string('causation_id', 80)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('emitter_stage', 120)->index();
            $table->string('emitter_version', 80);
            $table->json('payload');
            $table->string('payload_hash', 64)->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });
    }
}
