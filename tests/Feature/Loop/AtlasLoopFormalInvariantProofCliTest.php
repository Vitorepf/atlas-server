<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopFormalInvariantProofCli;
use App\Services\Ai\AutonomousEvolution\SelfMod\FormalProofs\AtlasLoopFormalInvariantProofReceiptLedger;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Tests\TestCase;

final class AtlasLoopFormalInvariantProofCliTest extends TestCase
{
    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/atlas-formal-proof-cli-'.bin2hex(random_bytes(6));
        @mkdir($this->sandbox, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->sandbox);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) glob($dir.'/*') as $entry) {
            is_dir($entry) ? $this->rrmdir($entry) : @unlink($entry);
        }
        @rmdir($dir);
    }

    /** Arm the CLI: flip the config flag and force-register the concrete runner on the live kernel. */
    private function armCli(): void
    {
        config(['atlas.loop.formal_proofs_cli_enabled' => true]);
        $this->app->instance(
            AtlasLoopFormalInvariantProofCli::LEDGER_BINDING,
            new AtlasLoopFormalInvariantProofReceiptLedger($this->sandbox),
        );
        $this->app->make(Kernel::class)->addCommands([AtlasLoopFormalInvariantProofCli::RUNNER_CLASS]);
    }

    public function test_prove_survives_returns_exit_0_and_history_finds_receipt(): void
    {
        $this->armCli();
        $prePath = $this->sandbox.'/pre.php';
        $postPath = $this->sandbox.'/post.php';
        file_put_contents($prePath, "<?php\n\$x = 1;\n");
        file_put_contents($postPath, "<?php\n\$x = 2;\n");

        $exit = Artisan::call('atlas:loop:formal-proof', [
            'action' => 'prove',
            '--invariant' => 'always($x)',
            '--pre' => $prePath,
            '--post' => $postPath,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame('survives', $payload['verdict']);
        $this->assertNotEmpty($payload['receipt_id']);
        $receiptId = (string) $payload['receipt_id'];

        $exit = Artisan::call('atlas:loop:formal-proof', [
            'action' => 'history',
            '--invariant' => 'always($x)',
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);
        $history = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($history);
        $ids = array_map(static fn (array $r): string => (string) $r['receipt_id'], $history['receipts']);
        $this->assertContains($receiptId, $ids);
    }

    public function test_prove_broken_returns_exit_3_and_indeterminate_returns_exit_2(): void
    {
        $this->armCli();

        $prePath = $this->sandbox.'/pre_broken.php';
        $postPath = $this->sandbox.'/post_broken.php';
        file_put_contents($prePath, "<?php\n\$x = 1;\n");
        file_put_contents($postPath, "<?php\n\$y = 1;\n");
        $exitBroken = Artisan::call('atlas:loop:formal-proof', [
            'action' => 'prove',
            '--invariant' => 'always($x)',
            '--pre' => $prePath,
            '--post' => $postPath,
            '--json' => true,
        ]);
        $this->assertSame(3, $exitBroken);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame('broken', $payload['verdict']);

        $prePath2 = $this->sandbox.'/pre_indet.php';
        $postPath2 = $this->sandbox.'/post_indet.php';
        file_put_contents($prePath2, "<?php\n\$other = 1;\n");
        file_put_contents($postPath2, "<?php\n\$x = 1;\n");
        $exitIndet = Artisan::call('atlas:loop:formal-proof', [
            'action' => 'prove',
            '--invariant' => 'always($x)',
            '--pre' => $prePath2,
            '--post' => $postPath2,
            '--json' => true,
        ]);
        $this->assertSame(2, $exitIndet);
        $payload2 = json_decode(trim(Artisan::output()), true);
        $this->assertSame('indeterminate', $payload2['verdict']);
    }

    public function test_command_dormant_unless_config_flag_armed(): void
    {
        // Dormant path: do NOT arm. Auto-discovery skips (abstract base) and the
        // AppServiceProvider gate only registers the runner when config is true.
        config(['atlas.loop.formal_proofs_cli_enabled' => false]);

        $threw = false;
        try {
            Artisan::call('atlas:loop:formal-proof', ['action' => 'history', '--invariant' => 'always($x)', '--json' => true]);
        } catch (CommandNotFoundException) {
            $threw = true;
        }
        $this->assertTrue($threw, 'command must NOT be registered when config flag is false');

        // Armed path: fresh kernel + register the runner directly on it.
        $this->refreshApplication();
        $this->armCli();
        $exit = Artisan::call('atlas:loop:formal-proof', ['action' => 'history', '--invariant' => 'always($x)', '--json' => true]);
        $this->assertContains($exit, [0, 2], 'command must be registered when flag is true (returned exit '.$exit.')');
    }
}
