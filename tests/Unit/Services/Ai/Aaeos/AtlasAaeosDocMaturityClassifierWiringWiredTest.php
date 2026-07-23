<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasAaeosDocMaturityClassifierWiringWiredTest extends TestCase
{
    private string $tempBase = '';

    private string $sectionsFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempBase = sys_get_temp_dir().'/atlas-department-status-doc-maturity-'.bin2hex(random_bytes(6));
        @mkdir($this->tempBase, 0o755, true);
        $this->sectionsFile = $this->tempBase.'/sections.json';
    }

    protected function tearDown(): void
    {
        if ($this->tempBase !== '' && is_dir($this->tempBase)) {
            (new Process(['rm', '-rf', $this->tempBase]))->run();
        }
        parent::tearDown();
    }

    public function test_department_status_command_classifies_doc_maturity_level(): void
    {
        file_put_contents($this->sectionsFile, json_encode([
            'mother_doc' => true,
            'contracts' => true,
            'runbook' => 'strong',
            'matrix' => 'strong',
            'quality_bar' => 'strong',
            'evidence' => 'strong',
            'gates' => 'strong',
        ]));

        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:aeos:department-status', [
            '--doc-maturity' => $this->sectionsFile,
            '--json' => true,
        ]);
        $out = $kernel->output();

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);

        $this->assertSame('DOC L4', $decoded['doc_maturity']['level']);
        $this->assertFalse($decoded['doc_maturity']['runtime_ready']);
    }

    public function test_department_status_command_omits_doc_maturity_without_option(): void
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:aeos:department-status', ['--json' => true]);
        $out = $kernel->output();

        $this->assertSame(0, $exit, $out);
        $decoded = json_decode($out, true);
        $this->assertArrayNotHasKey('doc_maturity', $decoded);
    }
}
