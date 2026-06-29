<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBacklogIntentSource;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the backlog-intent source is live at the operator surface: addressable manifest items (whose target
 * files exist in the repo) become named intent candidates ordered by priority; phantom targets and a missing
 * manifest fail open to nothing.
 */
final class AtlasLoopBacklogIntentCommandTest extends TestCase
{
    private string $repo = '';

    private string $storage = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-backlog-repo-'.bin2hex(random_bytes(5));
        $this->storage = sys_get_temp_dir().'/atlas-backlog-storage-'.bin2hex(random_bytes(5));
        @mkdir($this->repo, 0o755, true);
        @mkdir($this->storage.'/app/atlas/loop', 0o755, true);

        // an addressable target (exists) and a phantom (does not) — only the addressable one survives
        file_put_contents($this->repo.'/Real.php', "<?php\n");

        // The manifest path is storage_path(...): redirect storage to an isolated temp dir.
        $this->app->useStoragePath($this->storage);

        // Pin the source's optional legs OFF so only the curated manifest leg runs (deterministic).
        $this->app->instance(AtlasLoopBacklogIntentSource::class, new AtlasLoopBacklogIntentSource());
    }

    protected function tearDown(): void
    {
        @unlink($this->storage.'/app/atlas/loop/backlog-intents.json');
        @unlink($this->repo.'/Real.php');
        @rmdir($this->storage.'/app/atlas/loop');
        @rmdir($this->storage.'/app/atlas');
        @rmdir($this->storage.'/app');
        @rmdir($this->storage);
        @rmdir($this->repo);
        parent::tearDown();
    }

    private function writeManifest(array $items): void
    {
        file_put_contents(
            $this->storage.'/app/atlas/loop/backlog-intents.json',
            (string) json_encode(['items' => $items]),
        );
    }

    public function test_addressable_manifest_items_become_candidates(): void
    {
        $this->writeManifest([
            ['path' => 'Real.php', 'objective' => 'Harden Real.php input validation', 'priority' => 0.9],
            ['path' => 'Ghost.php', 'objective' => 'Fix a file that does not exist', 'priority' => 0.95],
        ]);

        $exit = Artisan::call('atlas:loop:backlog-intent', ['--repo-root' => $this->repo, '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopBacklogIntentSource::SCHEMA, $decoded['schema']);
        $this->assertSame(1, $decoded['candidate_count'], (string) json_encode($decoded));
        $this->assertSame('Real.php', $decoded['candidates'][0]['path']);
        $this->assertSame('Harden Real.php input validation', $decoded['candidates'][0]['objective']);
        $this->assertSame('manifest', $decoded['candidates'][0]['source']);
    }

    public function test_missing_manifest_fails_open_empty(): void
    {
        // no manifest written
        $exit = Artisan::call('atlas:loop:backlog-intent', ['--repo-root' => $this->repo, '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $decoded['candidate_count']);
        $this->assertSame([], $decoded['candidates']);
    }
}
