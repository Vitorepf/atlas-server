<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBacklogIntentSource;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopIntentIntakeService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * LOOP-OS · Fase 5 · Slice 14.5 — a free-text want lands in the manifest the loop reads, with a real path
 * validated (never invented), append-safe, and in the exact shape AtlasLoopBacklogIntentSource consumes.
 */
final class AtlasLoopIntentIntakeServiceTest extends TestCase
{
    private string $manifest;

    private AtlasLoopIntentIntakeService $intake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manifest = sys_get_temp_dir().'/atlas-want-'.bin2hex(random_bytes(5)).'/backlog.json';
        $this->intake = new AtlasLoopIntentIntakeService($this->manifest);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->manifest));
        parent::tearDown();
    }

    public function test_a_want_naming_a_real_file_records_a_validated_path(): void
    {
        // Name an existing repo file in the want — it must be validated + recorded as the path.
        $realRel = 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopIntentIntakeService.php';
        $res = $this->intake->want("Refatora o $realRel pra ficar mais limpo", base_path(), 0.8);

        $this->assertTrue($res['ok']);
        $this->assertTrue($res['resolved_path']);
        $this->assertSame($realRel, $res['item']['path']);
        $this->assertSame(0.8, $res['item']['priority']);
        $this->assertStringContainsString('Refatora', $res['item']['objective']);
    }

    public function test_a_want_with_no_real_path_is_still_recorded_as_a_general_objective(): void
    {
        $res = $this->intake->want('Deixa o sistema de memória mais rápido em geral', base_path());
        $this->assertTrue($res['ok']);
        $this->assertFalse($res['resolved_path']);
        $this->assertSame('', $res['item']['path']);
    }

    public function test_an_invented_path_is_never_recorded(): void
    {
        // A .php that does NOT exist must not be smuggled in as a resolved path.
        $res = $this->intake->want('conserta o app/Totally/Fake/Nonexistent.php', base_path());
        $this->assertFalse($res['resolved_path'], 'a non-existent path is not validated/recorded as the target');
        $this->assertSame('', $res['item']['path']);
    }

    public function test_empty_want_is_rejected(): void
    {
        $res = $this->intake->want('   ', base_path());
        $this->assertFalse($res['ok']);
        $this->assertStringContainsString('empty_want', (string) $res['reason']);
    }

    public function test_wants_are_append_safe_and_in_the_backlog_source_shape(): void
    {
        // Two wants naming distinct REAL files (the read-side validates path existence, so pathless general
        // wants are intentionally not surfaced as targets — proven separately).
        $realA = 'app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopIntentIntakeService.php';
        $realB = 'app/Console/Commands/AtlasLoopWantCommand.php';
        $this->intake->want("primeiro: melhora $realA", base_path());
        $this->intake->want("segundo: melhora $realB", base_path(), 0.9);

        $items = json_decode((string) File::get($this->manifest), true)['items'];
        $this->assertCount(2, $items);
        foreach ($items as $item) {
            $this->assertArrayHasKey('path', $item);
            $this->assertArrayHasKey('objective', $item);
            $this->assertArrayHasKey('priority', $item);
        }

        // The live read-side actually picks them up (write→read wire) via the default storage manifest.
        $store = storage_path('app/atlas/loop/backlog-intents.json');
        File::ensureDirectoryExists(dirname($store));
        File::put($store, (string) File::get($this->manifest));
        try {
            $paths = array_column((new AtlasLoopBacklogIntentSource())->candidates(base_path(), 12), 'path');
            $this->assertContains($realB, $paths, 'the operator want reached the loop discovery list');
        } finally {
            @unlink($store);
        }
    }
}
