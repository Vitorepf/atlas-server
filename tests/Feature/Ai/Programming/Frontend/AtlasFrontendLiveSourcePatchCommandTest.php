<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasFrontendLiveSourcePatchCommandTest extends TestCase
{
    public function test_live_command_prepares_accepts_and_recovers_patch(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-live-command-'.bin2hex(random_bytes(4));
        mkdir($workspace);
        file_put_contents($workspace.'/Card.html', '<button>Save</button>');

        $this->artisan('atlas:frontend:live', [
            'action' => 'prepare',
            '--workspace' => $workspace,
            '--file' => 'Card.html',
            '--target' => '<button>Save</button>',
            '--variant' => ['v1=<button class="primary">Save</button>'],
            '--session' => 'session-cli',
            '--json' => true,
        ])->assertExitCode(0);

        $exitCode = Artisan::call('atlas:frontend:live', [
            'action' => 'accept',
            '--workspace' => $workspace,
            '--session' => 'session-cli',
            '--accept-variant' => 'v1',
            '--json' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('atlas.frontend.live_source_patch_decision_receipt.v1', $output);
        $this->assertStringContainsString('decision_receipt_hash', $output);
        $this->assertStringContainsString('visual_quality_gate', $output);
        $this->assertStringContainsString('live_patch_decision_is_not_delivery_evidence', $output);
        $this->assertSame('<button class="primary">Save</button>', file_get_contents($workspace.'/Card.html'));

        $this->artisan('atlas:frontend:live', [
            'action' => 'recover',
            '--workspace' => $workspace,
            '--session' => 'session-cli',
            '--json' => true,
        ])->assertExitCode(0);

        $this->assertSame('<button>Save</button>', file_get_contents($workspace.'/Card.html'));
    }
}
