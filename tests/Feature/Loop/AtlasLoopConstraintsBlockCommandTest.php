<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Proves the constraints-block assembler is live at the operator surface and emits deterministic facts: the
 * machine-validated findings supplied for a campaign appear in the assembled block under VALIDATED FINDINGS,
 * and an operator note is kept provenance-distinct. A missing --campaign is a usage error.
 *
 * build() reads the campaign-tree table; we provide a minimal table (just the queried columns) so its query
 * succeeds with no rows for our fresh campaigns, letting the caller-supplied findings flow through assemble().
 */
final class AtlasLoopConstraintsBlockCommandTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    private bool $createdTable = false;

    protected function setUp(): void
    {
        parent::setUp();
        // Provide the minimal table build() queries ONLY if it isn't already present (don't shadow a real
        // migrated schema another test created in this shared in-memory DB).
        if (! Schema::hasTable('atlas_loop_targets')) {
            Schema::create('atlas_loop_targets', function (Blueprint $table): void {
                $table->id();
                $table->string('campaign_id')->nullable();
                $table->string('tree_status')->nullable();
                $table->json('node_insight')->nullable();
            });
            $this->createdTable = true;
        }
    }

    protected function tearDown(): void
    {
        // Drop ONLY the table we created, so we never leave a minimal table shadowing the real schema.
        if ($this->createdTable) {
            Schema::dropIfExists('atlas_loop_targets');
        }
        foreach ($this->files as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        parent::tearDown();
    }

    public function test_requires_campaign(): void
    {
        $exit = Artisan::call('atlas:loop:constraints-block-assemble', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_assembles_block_from_validated_findings(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'constraints_').'.json';
        $this->files[] = $path;
        file_put_contents($path, json_encode(['Caching layer is proven', 'Idempotent retries certified']));

        $exit = Artisan::call('atlas:loop:constraints-block-assemble', [
            '--campaign' => 'campaign-no-rows-'.bin2hex(random_bytes(4)),
            '--input' => $path,
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.constraints_block.v1', $decoded['schema']);
        $this->assertSame(2, $decoded['finding_count']);
        $this->assertStringContainsString('## VALIDATED FINDINGS (2', $decoded['block']);
        $this->assertStringContainsString('- Caching layer is proven', $decoded['block']);
        $this->assertStringContainsString('- Idempotent retries certified', $decoded['block']);
    }

    public function test_operator_note_is_kept_distinct(): void
    {
        $exit = Artisan::call('atlas:loop:constraints-block-assemble', [
            '--campaign' => 'campaign-note-'.bin2hex(random_bytes(4)),
            '--note' => 'prefer the smallest diff',
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('## OPERATOR NOTE', $decoded['block']);
        $this->assertStringContainsString('prefer the smallest diff', $decoded['block']);
    }
}
