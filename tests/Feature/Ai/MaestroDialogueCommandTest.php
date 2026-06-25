<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Console\Commands\AtlasMaestroDialogueCommand;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroDialogueDrivenPacketLedger;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroIntentToPacketShapeProposer;
use App\Services\Ai\SelfConstruction\Quaternity\DialogueToPackets\AtlasMaestroPacketShapeOperatorReviewGate;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class MaestroDialogueCommandTest extends TestCase
{
    private string $tempBase = '';

    private string $intentFile = '';

    private string $ledgerSqlite = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-dialogue-cli-'.bin2hex(random_bytes(6));
        @mkdir($this->tempBase, 0o755, true);
        $this->intentFile = $this->tempBase.'/intent.json';
        $this->ledgerSqlite = $this->tempBase.'/ledger.sqlite';

        // In-memory P03 ledger pinned to a temp sqlite path.
        $this->app->instance(
            AtlasMaestroDialogueDrivenPacketLedger::class,
            new AtlasMaestroDialogueDrivenPacketLedger($this->ledgerSqlite),
        );
        // Gate rooted at the temp base so proposer + gate share a directory tree.
        $this->app->instance(
            AtlasMaestroPacketShapeOperatorReviewGate::class,
            new AtlasMaestroPacketShapeOperatorReviewGate($this->tempBase, fn (): int => 1700000000),
        );
        // Proposer with a writer callback that lands shapes under temp/atlas/maestro/dialogue/proposed/.
        $base = $this->tempBase;
        $this->app->instance(
            AtlasMaestroIntentToPacketShapeProposer::class,
            new AtlasMaestroIntentToPacketShapeProposer(function (string $path, string $canonicalJson) use ($base): void {
                // Redirect the proposer's computed path into the gate's tempBase so both share one tree.
                $name = basename($path);
                $dir = $base.'/atlas/maestro/dialogue/proposed';
                @mkdir($dir, 0o755, true);
                file_put_contents($dir.'/'.$name, $canonicalJson);
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

    private function seedIntentFixture(): void
    {
        file_put_contents($this->intentFile, json_encode([
            'phrases' => ['tira keepalive do loop'],
            'timestamps' => [1700000000],
            'scope_tags' => ['loop'],
            'verbs' => ['remove'],
            'cortex_symbols' => ['AtlasLoopComprehensionOriginator'],
            'cortex_files' => ['app/Services/Ai/AutonomousEvolution/AtlasLoopComprehensionOriginator.php'],
            'cortex_id' => 'cortex-run-1',
            'wave' => 'w42',
        ], JSON_UNESCAPED_SLASHES));
    }

    private function runCmd(array $args): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:maestro:dialogue', $args);

        return [$exit, $kernel->output()];
    }

    public function test_propose_prints_shape_id_and_emits_proposed_event(): void
    {
        $this->seedIntentFixture();

        [$exit, $out] = $this->runCmd(['action' => 'propose', '--intent-file' => $this->intentFile]);

        $this->assertSame(AtlasMaestroDialogueCommand::EXIT_OK, $exit, $out);
        $this->assertMatchesRegularExpression('/^SHAPE_ID=[A-Za-z0-9_\-]+$/m', $out);

        $events = $this->app->make(AtlasMaestroDialogueDrivenPacketLedger::class)->list();
        $this->assertGreaterThanOrEqual(1, count($events));
        $this->assertSame('Proposed', $events[0]->eventType);
    }

    public function test_enqueue_without_operator_signature_exits_two(): void
    {
        [$exit] = $this->runCmd(['action' => 'enqueue', '--shape-id' => 'shape-x']);

        $this->assertSame(AtlasMaestroDialogueCommand::EXIT_OPERATOR_SIGNATURE, $exit);
        // No queue insertion happens — verified by the ledger having no Enqueued event.
        $events = $this->app->make(AtlasMaestroDialogueDrivenPacketLedger::class)->list();
        foreach ($events as $event) {
            $this->assertNotSame('Enqueued', $event->eventType);
        }
    }

    public function test_auto_enqueue_with_flag_off_exits_three(): void
    {
        config()->set('atlas.maestro.dialogue.auto_enqueue_enabled', false);

        [$exit] = $this->runCmd(['action' => 'enqueue', '--shape-id' => 'shape-x', '--auto' => true]);

        $this->assertSame(AtlasMaestroDialogueCommand::EXIT_POLICY_BLOCKED, $exit);
        $events = $this->app->make(AtlasMaestroDialogueDrivenPacketLedger::class)->list();
        foreach ($events as $event) {
            $this->assertNotSame('Enqueued', $event->eventType);
        }
    }

    public function test_review_is_byte_identical_across_two_calls_and_does_not_emit_ledger_events(): void
    {
        $this->seedIntentFixture();
        [, $proposeOut] = $this->runCmd(['action' => 'propose', '--intent-file' => $this->intentFile]);
        $this->assertSame(1, preg_match('/SHAPE_ID=([0-9A-Za-z_\-]+)/', $proposeOut, $m));
        $shapeId = $m[1];

        $ledger = $this->app->make(AtlasMaestroDialogueDrivenPacketLedger::class);
        $countBefore = count($ledger->list());

        [$exit1, $out1] = $this->runCmd(['action' => 'review', '--shape-id' => $shapeId]);
        [$exit2, $out2] = $this->runCmd(['action' => 'review', '--shape-id' => $shapeId]);

        $this->assertSame(AtlasMaestroDialogueCommand::EXIT_OK, $exit1, 'shape_id='.$shapeId.' out1='.$out1);
        $this->assertSame(AtlasMaestroDialogueCommand::EXIT_OK, $exit2);
        $this->assertSame($out1, $out2, 'review output must be byte-identical across two calls');
        $this->assertSame($countBefore, count($ledger->list()), 'review must not add P03 ledger events');
    }
}
