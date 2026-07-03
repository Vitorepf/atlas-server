<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskServing;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use App\Services\Ai\SelfConstruction\TaskServing\AtlasRefactorOpportunityScout;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Proves brain-side refactor ORIGINATION: from a directory with measured
 * cross-file duplication the scout derives a COMPLETE design spec (accepted by
 * the builder's strict normalizer — the same gate that judges specs) and a
 * staged chain that enqueues and serves. No duplication ⇒ honest abstention.
 */
final class AtlasRefactorOpportunityScoutTest extends TestCase
{
    private string $envFile = '';

    private string $root = '';

    private const DUP = <<<'PHP'
        $a = load($id);
        if ($a === null) { throw new RuntimeException('missing'); }
        $a->normalize();
        $a->validate();
        $a->stamp();
        save($a);
    PHP;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->envFile = sys_get_temp_dir().'/atlas-scout-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        AtlasTaskServingSwitch::on();

        $this->root = sys_get_temp_dir().'/atlas-scout-'.bin2hex(random_bytes(5));
        @mkdir($this->root.'/app/Services/Reports', 0775, true);
        file_put_contents($this->root.'/app/Services/Reports/Daily.php',
            "<?php\nclass Daily {\nfunction run(\$id) {\n".self::DUP."\nreturn 'daily';\n}\n}\n");
        file_put_contents($this->root.'/app/Services/Reports/Weekly.php',
            "<?php\nclass Weekly {\nfunction run(\$id) {\n".self::DUP."\nreturn 'weekly';\n}\n}\n");
        file_put_contents($this->root.'/app/Services/Reports/Unrelated.php',
            "<?php\nclass Unrelated {\nfunction ping() { return 'pong'; }\n}\n");
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        AtlasTaskServingSwitch::$envPathOverride = null;
        @unlink($this->envFile);
        foreach (['Daily', 'Weekly', 'Unrelated'] as $f) {
            @unlink($this->root.'/app/Services/Reports/'.$f.'.php');
        }
        @rmdir($this->root.'/app/Services/Reports');
        @rmdir($this->root.'/app/Services');
        @rmdir($this->root.'/app');
        @rmdir($this->root);
        parent::tearDown();
    }

    public function test_originates_a_complete_spec_and_chain_from_measured_duplication(): void
    {
        $out = (new AtlasRefactorOpportunityScout)->scout($this->root, 'app/Services/Reports');

        $this->assertSame('opportunity_found', $out['status'], json_encode($out));
        $this->assertEqualsCanonicalizing(
            ['app/Services/Reports/Daily.php', 'app/Services/Reports/Weekly.php'],
            $out['cluster']['files'],
        );

        // The derived spec passes the SAME strict normalizer the builder gate uses.
        $this->assertNotNull(AgentControlPlaneTaskPacketBuilder::normalizeRefactorDesignSpec($out['design']));
        // Evidence, not invention: the problem names the measured files and count.
        $this->assertStringContainsString('Daily.php', $out['design']['problem']);
        $this->assertStringContainsString('2 files', $out['design']['problem']);

        // The chain enqueues and stage 1 serves through the real fabric.
        $batch = sys_get_temp_dir().'/atlas-scout-batch-'.bin2hex(random_bytes(4)).'.json';
        file_put_contents($batch, json_encode($out['chain']));
        $this->assertSame(0, Artisan::call('atlas:task:enqueue', ['--file' => $batch, '--json' => true]));

        $serving = new AtlasTaskServingService(AtlasTaskServingStack::orchestrator());
        $first = $serving->next('client-scout');
        $this->assertSame('served', $first['status'], json_encode($first));
        $this->assertStringContainsString('s1-extract', (string) data_get($first, 'task.task_packet_id'));
        $this->assertSame($out['design']['problem'], data_get($first, 'task.refactor_design_spec.problem'));
        @unlink($batch);
    }

    public function test_abstains_honestly_when_there_is_no_cross_file_duplication(): void
    {
        @unlink($this->root.'/app/Services/Reports/Weekly.php');
        file_put_contents($this->root.'/app/Services/Reports/Weekly.php',
            "<?php\nclass Weekly {\nfunction other(\$x) { return \$x + 1; }\n}\n");

        $out = (new AtlasRefactorOpportunityScout)->scout($this->root, 'app/Services/Reports');

        $this->assertSame('no_cross_file_duplication', $out['status'], json_encode($out));
        $this->assertArrayNotHasKey('chain', $out);
    }
}
