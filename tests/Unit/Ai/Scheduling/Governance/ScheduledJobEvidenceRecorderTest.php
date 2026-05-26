<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Scheduling\Governance;

use App\Services\Ai\Scheduling\Governance\ScheduledJobEvidenceRecorder;
use Tests\TestCase;

final class ScheduledJobEvidenceRecorderTest extends TestCase
{
    private string $sandbox;

    private ScheduledJobEvidenceRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/atlas-job-evidence-'.bin2hex(random_bytes(4));
        mkdir($this->sandbox, 0775, true);
        $this->recorder = new ScheduledJobEvidenceRecorder($this->sandbox);
    }

    protected function tearDown(): void
    {
        $this->purge($this->sandbox);
        parent::tearDown();
    }

    public function test_emits_canonical_envelope(): void
    {
        $env = $this->recorder->record([
            'job_id' => 'self_improvement_review',
            'outcome' => 'success',
            'duration_ms' => 1200,
            'stop_gate_decision' => [
                'schema_version' => 'atlas.scheduling.stop_condition_gate.v1',
                'may_run' => true,
                'stop_reasons' => [],
            ],
        ]);

        $this->assertSame('atlas.scheduling.job_evidence.v1', $env['schema_version']);
        $this->assertSame('self_improvement_review', $env['job_id']);
        $this->assertSame('success', $env['outcome']);
        $this->assertTrue($env['provider_safe']);
    }

    public function test_writes_dated_file_under_storage(): void
    {
        $this->recorder->record(['job_id' => 'j1', 'outcome' => 'success']);

        $files = glob($this->sandbox.'/*/*.json');
        $this->assertNotEmpty($files);
        $this->assertStringContainsString('j1', basename((string) $files[0]));
    }

    public function test_failure_signature_persisted_when_supplied(): void
    {
        $env = $this->recorder->record([
            'job_id' => 'j2',
            'outcome' => 'failed',
            'failure_signature' => 'phpunit:Foo::test_bar',
        ]);
        $this->assertSame('phpunit:Foo::test_bar', $env['failure_signature']);
    }

    public function test_stop_gate_decision_carried_into_envelope(): void
    {
        $env = $this->recorder->record([
            'job_id' => 'j3',
            'outcome' => 'skipped',
            'stop_gate_decision' => [
                'schema_version' => 'atlas.scheduling.stop_condition_gate.v1',
                'may_run' => false,
                'stop_reasons' => ['operator_paused_via_flag'],
            ],
        ]);
        $this->assertFalse($env['stop_gate_decision']['may_run']);
        $this->assertContains('operator_paused_via_flag', $env['stop_gate_decision']['stop_reasons']);
    }

    public function test_job_id_sanitised_in_filename(): void
    {
        $this->recorder->record(['job_id' => 'has/slash and space', 'outcome' => 'success']);
        $files = glob($this->sandbox.'/*/*.json');
        $this->assertNotEmpty($files);
        $this->assertStringContainsString('has_slash_and_space', basename((string) $files[0]));
    }

    private function purge(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $items = @scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $full = $path.'/'.$item;
            if (is_dir($full)) {
                $this->purge($full);
                @rmdir($full);
            } else {
                @unlink($full);
            }
        }
        @rmdir($path);
    }
}
