<?php

namespace Tests\Feature\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidenceKitService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendEvidenceKitCommandTest extends TestCase
{
    public function test_evidence_kit_command_prepares_collection_artifacts(): void
    {
        $output = sys_get_temp_dir().'/atlas-frontend-evidence-kit-command-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:evidence-kit', [
            'action' => 'prepare',
            '--task' => 'Criar dashboard SaaS premium',
            '--output' => $output,
            '--acceptance' => true,
            '--json' => true,
            '--strict' => true,
        ]);
        $outputText = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(AtlasFrontendEvidenceKitService::SCHEMA_VERSION, $outputText);
        $this->assertStringContainsString('evidence_kit_hash', $outputText);
        $this->assertStringContainsString('prepared_templates_are_not_completion_evidence', $outputText);
        $this->assertTrue(File::isFile($output.'/evidence-kit-manifest.json'));
    }

    public function test_evidence_kit_command_fails_strict_when_acceptance_context_missing(): void
    {
        $output = sys_get_temp_dir().'/atlas-frontend-evidence-kit-command-blocked-'.bin2hex(random_bytes(4));

        $exitCode = Artisan::call('atlas:frontend:evidence-kit', [
            'action' => 'prepare',
            '--task' => 'Melhorar tela',
            '--output' => $output,
            '--json' => true,
            '--strict' => true,
        ]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('scenario_matrix_not_ready', Artisan::output());
    }
}
