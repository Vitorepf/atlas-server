<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\AtlasAaelExecutionSafeStateRecoverer;
use RuntimeException;
use Tests\TestCase;

final class AtlasAaelExecutionSafeStateRecovererTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-aael-ss-'.bin2hex(random_bytes(6));
        @mkdir($this->dir, 0o755, true);
    }

    protected function tearDown(): void
    {
        foreach ((array) glob($this->dir.'/*') as $f) {
            @unlink((string) $f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_snapshot_returns_sha1_id_matching_file_sha1(): void
    {
        $rec = new AtlasAaelExecutionSafeStateRecoverer($this->dir);
        $id = $rec->snapshot(['step' => 3, 'objective' => 'x']);

        $this->assertSame(40, strlen($id));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $id);
        $this->assertTrue(is_file($this->dir.'/'.$id.'.json'));
        $this->assertSame($id, sha1_file($this->dir.'/'.$id.'.json'));
    }

    public function test_recover_round_trips_the_state(): void
    {
        $rec = new AtlasAaelExecutionSafeStateRecoverer($this->dir);
        $state = ['step' => 3, 'objective' => 'x', 'meta' => ['phase' => 'plan', 'depth' => 4]];
        $id = $rec->snapshot($state);

        $verdict = $rec->recover($id);

        $this->assertEquals($state, $verdict['recovered_state']);
        $this->assertSame($id, $verdict['checkpoint_id']);
        $this->assertSame(AtlasAaelExecutionSafeStateRecoverer::SCHEMA, $verdict['schema_version']);
    }

    public function test_recover_with_tampered_file_throws_integrity_error_carrying_id(): void
    {
        $rec = new AtlasAaelExecutionSafeStateRecoverer($this->dir);
        $id = $rec->snapshot(['step' => 1]);
        // Tamper with the on-disk bytes.
        file_put_contents($this->dir.'/'.$id.'.json', file_get_contents($this->dir.'/'.$id.'.json').' ');

        try {
            $rec->recover($id);
            $this->fail('expected RuntimeException from tampered file');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('integrity', $e->getMessage());
            $this->assertStringContainsString($id, $e->getMessage());
        }
    }

    public function test_snapshot_is_idempotent_for_byte_identical_input(): void
    {
        $rec = new AtlasAaelExecutionSafeStateRecoverer($this->dir);
        $a = $rec->snapshot(['step' => 7, 'data' => ['a' => 1, 'b' => 2]]);
        $b = $rec->snapshot(['data' => ['b' => 2, 'a' => 1], 'step' => 7]); // same content, different key order

        $this->assertSame($a, $b, 'canonical-sort makes both inputs produce the same id');
        // Only one file on disk.
        $files = glob($this->dir.'/*.json');
        $this->assertNotFalse($files);
        $this->assertCount(1, $files);
    }

    public function test_recover_without_id_returns_most_recent_checkpoint(): void
    {
        $rec = new AtlasAaelExecutionSafeStateRecoverer($this->dir);
        $rec->snapshot(['step' => 1]);
        sleep(1);
        $newer = $rec->snapshot(['step' => 2]);

        $verdict = $rec->recover();

        $this->assertSame($newer, $verdict['checkpoint_id']);
        $this->assertSame(2, $verdict['recovered_state']['step']);
    }
}
