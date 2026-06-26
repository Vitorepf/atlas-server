<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasMaestroDialogueCommand;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroDialogueDrivenPacketLedger;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroIntentToPacketShapeProposer;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroPacketShapeOperatorReviewGate;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Proves the dialogue command's enqueue verb writes the operator-approved packet to the OPERATOR
 * SERVING disk — the disk the worker reads with `atlas:task next`. With the disk gotcha (the
 * version that wrote through the container-injected, default-disk repository), the packet would
 * land on 'local' and stay invisible to the serving worker.
 */
final class AtlasMaestroDialogueEnqueueServingDiskTest extends TestCase
{
    private const TEST_DISK = 'atlas_dialogue_enqueue_test_disk';

    private string $tempBase = '';

    private string $intentFile = '';

    private string $ledgerSqlite = '';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.task_serving.queue_disk', self::TEST_DISK);
        Storage::fake(self::TEST_DISK);
        Storage::fake('local');

        $this->tempBase = sys_get_temp_dir().'/atlas-dialogue-enqueue-serving-'.bin2hex(random_bytes(6));
        @mkdir($this->tempBase, 0o755, true);
        $this->intentFile = $this->tempBase.'/intent.json';
        $this->ledgerSqlite = $this->tempBase.'/ledger.sqlite';

        $this->app->instance(
            AtlasMaestroDialogueDrivenPacketLedger::class,
            new AtlasMaestroDialogueDrivenPacketLedger($this->ledgerSqlite),
        );
        $this->app->instance(
            AtlasMaestroPacketShapeOperatorReviewGate::class,
            new AtlasMaestroPacketShapeOperatorReviewGate($this->tempBase, fn (): int => 1700000000),
        );
        $base = $this->tempBase;
        $this->app->instance(
            AtlasMaestroIntentToPacketShapeProposer::class,
            new AtlasMaestroIntentToPacketShapeProposer(function (string $path, string $canonicalJson) use ($base): void {
                $dir = $base.'/atlas/maestro/dialogue/proposed';
                @mkdir($dir, 0o755, true);
                file_put_contents($dir.'/'.basename($path), $canonicalJson);
            }),
        );
    }

    protected function tearDown(): void
    {
        if ($this->tempBase !== '' && is_dir($this->tempBase)) {
            (new Process(['rm', '-rf', $this->tempBase]))->run();
        }
        parent::tearDown();
    }

    public function test_enqueue_lands_packet_on_serving_disk_not_default(): void
    {
        file_put_contents($this->intentFile, json_encode([
            'phrases' => ['remove keepalive from the loop'],
            'timestamps' => [1700000000],
            'scope_tags' => ['loop'],
            'verbs' => ['remove'],
            'cortex_symbols' => ['AtlasLoopComprehensionOriginator'],
            'cortex_files' => ['app/Services/Ai/AutonomousEvolution/AtlasLoopComprehensionOriginator.php'],
            'cortex_id' => 'cortex-run-serving',
            'wave' => 'w99',
        ], JSON_UNESCAPED_SLASHES));

        [$proposeExit, $proposeOut] = $this->runCmd(['action' => 'propose', '--intent-file' => $this->intentFile]);
        $this->assertSame(AtlasMaestroDialogueCommand::EXIT_OK, $proposeExit, $proposeOut);
        $this->assertSame(1, preg_match('/SHAPE_ID=([0-9A-Za-z_\-]+)/', $proposeOut, $m));
        $shapeId = $m[1];

        // Approve operator-signed enqueue.
        [$enqueueExit, $enqueueOut] = $this->runCmd([
            'action' => 'enqueue',
            '--shape-id' => $shapeId,
            '--operator-signature' => 'tester-signature-ok',
            '--reason' => 'serving-disk-test',
        ]);
        $this->assertSame(AtlasMaestroDialogueCommand::EXIT_OK, $enqueueExit, $enqueueOut);

        // Packet must be visible on the SERVING disk.
        $servingRecord = AtlasTaskServingStack::queueRepo()->list();
        $servingIds = array_map(static fn (array $r): string => (string) ($r['task_packet_id'] ?? ''), $servingRecord);
        $this->assertNotEmpty($servingIds, 'serving-disk worker must see the dialogue-driven packet');

        // And NOT on the container-default repo (the buggy write target).
        $defaultRepo = new AgentControlPlaneTaskPacketQueueRepository;
        $this->assertSame([], $defaultRepo->list(), 'default-disk leakage means the gotcha is back');
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array{0:int,1:string}
     */
    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:maestro:dialogue', $args);

        return [$exit, $kernel->output()];
    }
}
