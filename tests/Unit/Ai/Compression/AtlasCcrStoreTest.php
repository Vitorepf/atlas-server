<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compression;

use App\Services\Ai\Compression\AtlasCcrStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class AtlasCcrStoreTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Boot ONLY the table under test (driver-agnostic), rather than the full
        // RefreshDatabase migration set — some core migrations are raw Postgres SQL
        // that does not run on the sqlite test connection. Mirrors the repo pattern.
        Schema::dropIfExists('atlas_ccr_originals');
        Schema::create('atlas_ccr_originals', function (Blueprint $table) {
            $table->id();
            $table->string('original_hash', 64)->unique();
            $table->string('content_type', 40)->default('text');
            $table->string('codec', 16)->default('gzip');
            $table->unsignedBigInteger('original_bytes')->default(0);
            $table->unsignedBigInteger('compressed_bytes')->default(0);
            $table->longText('compressed_blob');
            $table->string('privacy_class', 40)->default('internal');
            $table->string('ledger_event_id', 32)->nullable()->index();
            $table->string('scope_type', 40)->nullable();
            $table->string('scope_id', 80)->nullable();
            $table->string('recorded_by', 120)->default('atlas.compression');
            $table->unsignedInteger('retrieved_count')->default(0);
            $table->timestamp('last_retrieved_at')->nullable();
            $table->timestamps();
            $table->index(['content_type', 'created_at']);
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ccr_originals');
        parent::tearDown();
    }

    private function store(): AtlasCcrStore
    {
        // null ledger keeps the test focused on the durable blob store itself.
        return new AtlasCcrStore(null, 'gzip');
    }

    public function test_store_then_retrieve_roundtrips_the_exact_original(): void
    {
        $store = $this->store();
        $original = str_repeat('{"id":1,"name":"alpha"}, ', 200);

        $stored = $store->store($original, ['content_type' => 'json', 'privacy_class' => 'internal']);

        $this->assertTrue($stored['persisted']);
        $this->assertFalse($stored['deduped']);
        $this->assertSame(hash('sha256', $original), $stored['hash']);
        // Compression actually shrank the blob on disk.
        $this->assertLessThan($stored['original_bytes'], $stored['compressed_bytes']);

        $retrieved = $store->retrieve($stored['hash']);

        $this->assertTrue($retrieved['found']);
        $this->assertSame($original, $retrieved['original']);
        $this->assertSame('json', $retrieved['content_type']);
        $this->assertSame('internal', $retrieved['privacy_class']);
    }

    public function test_storing_identical_content_dedups(): void
    {
        $store = $this->store();
        $original = str_repeat('repeated payload ', 100);

        $first = $store->store($original, ['content_type' => 'text']);
        $second = $store->store($original, ['content_type' => 'text']);

        $this->assertFalse($first['deduped']);
        $this->assertTrue($second['deduped']);
        $this->assertSame($first['hash'], $second['hash']);
        $this->assertDatabaseCount('atlas_ccr_originals', 1);
    }

    public function test_retrieve_miss_returns_not_found(): void
    {
        $result = $this->store()->retrieve(str_repeat('0', 64));

        $this->assertFalse($result['found']);
        $this->assertNull($result['original']);
    }

    public function test_empty_hash_is_a_miss(): void
    {
        $result = $this->store()->retrieve('');
        $this->assertFalse($result['found']);
    }

    public function test_privacy_class_is_surfaced_on_retrieve(): void
    {
        $store = $this->store();
        $stored = $store->store(str_repeat('secret data ', 100), ['content_type' => 'text', 'privacy_class' => 'secret']);

        $retrieved = $store->retrieve($stored['hash']);

        $this->assertTrue($retrieved['found']);
        $this->assertSame('secret', $retrieved['privacy_class']);
    }

    public function test_retrieve_increments_the_counter(): void
    {
        $store = $this->store();
        $stored = $store->store(str_repeat('countable ', 100), ['content_type' => 'text']);

        $store->retrieve($stored['hash']);
        $store->retrieve($stored['hash']);

        $this->assertDatabaseHas('atlas_ccr_originals', [
            'original_hash' => $stored['hash'],
            'retrieved_count' => 2,
        ]);
    }
}
