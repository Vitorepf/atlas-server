<?php

namespace Tests\Feature;

use App\Models\AtlasToolFinding;
use App\Models\AtlasToolRun;
use App\Services\Tools\AtlasToolEvidenceStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasToolRuntimeCoreTest extends TestCase
{
    private array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    private string $workspace;

    private string $binDir;

    private string|false $originalPath;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->workspace = sys_get_temp_dir().'/atlas-tool-runtime-'.bin2hex(random_bytes(4));
        $this->binDir = sys_get_temp_dir().'/atlas-tool-runtime-bin-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        File::ensureDirectoryExists($this->binDir);
        $this->originalPath = getenv('PATH');
        putenv('PATH='.$this->binDir.':'.($this->originalPath !== false ? $this->originalPath : ''));
        $this->createTables();
    }

    protected function tearDown(): void
    {
        foreach ([
            'atlas_tool_findings',
            'atlas_tool_artifacts',
            'atlas_tool_runs',
            'atlas_tool_policies',
            'atlas_tool_installations',
            'atlas_tool_definitions',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        File::deleteDirectory($this->workspace);
        File::deleteDirectory($this->binDir);
        if ($this->originalPath !== false) {
            putenv('PATH='.$this->originalPath);
        }

        parent::tearDown();
    }

    public function test_registry_doctor_seeds_and_detects_fake_tool(): void
    {
        $this->installFakeBinary('gitleaks', 'echo "gitleaks version test"');

        $exit = Artisan::call('atlas:tools', [
            'action' => 'doctor',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $gitleaks = collect($payload['tools'] ?? [])->firstWhere('slug', 'gitleaks');
        $visualSmoke = collect($payload['tools'] ?? [])->firstWhere('slug', 'atlas_visual_smoke');
        $codeIntelligence = collect($payload['tools'] ?? [])->firstWhere('slug', 'atlas_code_intelligence');

        $this->assertSame(0, $exit);
        $this->assertGreaterThanOrEqual(10, $payload['tool_count'] ?? 0);
        $this->assertSame('ready', data_get($gitleaks, 'status'));
        $this->assertSame('host', data_get($gitleaks, 'execution_layer'));
        $this->assertSame('ready', data_get($visualSmoke, 'status'));
        $this->assertSame('atlas_internal', data_get($visualSmoke, 'execution_layer'));
        $this->assertSame('ready', data_get($codeIntelligence, 'status'));
        $this->assertDatabaseHas('atlas_tool_definitions', ['slug' => 'gitleaks']);
        $this->assertDatabaseHas('atlas_tool_definitions', ['slug' => 'atlas_visual_smoke']);
        $this->assertDatabaseHas('atlas_tool_definitions', ['slug' => 'atlas_code_intelligence']);
        $this->assertDatabaseHas('atlas_tool_installations', ['status' => 'ready']);
    }

    public function test_policy_requires_approval_for_high_risk_tool_and_records_auditable_run(): void
    {
        $this->installFakeBinary('gitleaks', 'echo "would scan"');

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'gitleaks',
            '--workspace' => $this->workspace,
            '--command' => ['gitleaks', '--version'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('requires_approval', data_get($payload, 'run.status'));
        $this->assertSame('requires_approval', data_get($payload, 'run.policy_decision'));
        $this->assertDatabaseHas('atlas_tool_runs', [
            'tool_slug' => 'gitleaks',
            'policy_decision' => 'requires_approval',
        ]);
    }

    public function test_tool_approval_policy_allows_high_risk_tool_and_can_be_revoked(): void
    {
        $this->installFakeBinary('gitleaks', 'echo "gitleaks ok"');

        Artisan::call('atlas:tools', [
            'action' => 'approve',
            'tool' => 'gitleaks',
            '--workspace' => $this->workspace,
            '--reason' => 'Fixture approval',
            '--ttl-hours' => 2,
            '--json' => true,
        ]);
        $approval = json_decode(Artisan::output(), true);

        $this->assertSame('approved', $approval['status'] ?? null);
        $this->assertSame('approved', $approval['approval_status'] ?? null);
        $this->assertDatabaseHas('atlas_tool_policies', [
            'tool_slug' => 'gitleaks',
            'scope_type' => 'workspace',
            'enabled' => true,
        ]);

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'gitleaks',
            '--workspace' => $this->workspace,
            '--command' => ['gitleaks', '--version'],
            '--json' => true,
        ]);
        $run = json_decode(Artisan::output(), true);

        $this->assertSame('passed', data_get($run, 'run.status'));
        $this->assertSame('allowed', data_get($run, 'run.policy_decision'));
        $this->assertSame('approved', data_get($run, 'run.policy_decision_json.approval_status'));

        Artisan::call('atlas:tools', [
            'action' => 'revoke',
            'tool' => 'gitleaks',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $revoked = json_decode(Artisan::output(), true);
        $this->assertSame('revoked', $revoked['status'] ?? null);
        $this->assertSame('not_approved', $revoked['approval_status'] ?? null);

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'gitleaks',
            '--workspace' => $this->workspace,
            '--command' => ['gitleaks', '--version'],
            '--json' => true,
        ]);
        $blocked = json_decode(Artisan::output(), true);

        $this->assertSame('requires_approval', data_get($blocked, 'run.status'));
    }

    public function test_api_approves_lists_and_revokes_tool_policy(): void
    {
        $this->installFakeBinary('gitleaks', 'echo "gitleaks ok"');

        $this->postJson('/tools/gitleaks/approval', [
            'workspace' => $this->workspace,
            'reason' => 'API approval',
            'ttl_hours' => 1,
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('approval_status', 'approved')
            ->assertJsonPath('data.tool_slug', 'gitleaks');

        $this->getJson('/tools/policies?workspace='.urlencode($this->workspace), $this->headers)
            ->assertOk()
            ->assertJsonPath('data.0.tool_slug', 'gitleaks')
            ->assertJsonPath('data.0.approval_status', 'approved');

        $this->deleteJson('/tools/gitleaks/approval', [
            'workspace' => $this->workspace,
        ], $this->headers)
            ->assertOk()
            ->assertJsonPath('approval_status', 'not_approved');
    }

    public function test_executor_dry_run_and_approved_execution_store_artifacts(): void
    {
        $this->installFakeBinary('rg', 'echo "ripgrep 99.0.0"');

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'ripgrep',
            '--workspace' => $this->workspace,
            '--command' => ['rg', '--version'],
            '--dry-run' => true,
            '--json' => true,
        ]);
        $dryRun = json_decode(Artisan::output(), true);
        $this->assertSame('skipped', data_get($dryRun, 'run.status'));

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'ripgrep',
            '--workspace' => $this->workspace,
            '--command' => ['rg', '--version'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $runId = data_get($payload, 'run.id');

        $this->assertSame('passed', data_get($payload, 'run.status'));
        $this->assertNotEmpty($runId);
        $this->assertDatabaseHas('atlas_tool_artifacts', [
            'tool_run_id' => $runId,
            'type' => 'stdout',
        ]);
    }

    public function test_executor_normalizes_structured_tool_output_into_findings(): void
    {
        $this->installFakeBinary('semgrep', <<<'BASH'
cat <<'JSON'
{
  "results": [
    {
      "check_id": "php.security.example",
      "path": "app/Foo.php",
      "start": {"line": 12},
      "end": {"line": 12},
      "extra": {
        "severity": "ERROR",
        "message": "Unsafe example"
      }
    }
  ]
}
JSON
exit 1
BASH);

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'semgrep',
            '--workspace' => $this->workspace,
            '--command' => ['semgrep', '--json'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $runId = data_get($payload, 'run.id');

        $this->assertSame('failed', data_get($payload, 'run.status'));
        $this->assertNotEmpty($runId);
        $this->assertDatabaseHas('atlas_tool_findings', [
            'tool_run_id' => $runId,
            'rule_id' => 'php.security.example',
            'severity' => 'high',
            'file_path' => 'app/Foo.php',
            'line' => 12,
        ]);
    }

    public function test_api_exposes_registry_and_evidence(): void
    {
        $this->getJson('/tools/doctor?workspace='.urlencode($this->workspace), $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->postJson('/tools/ripgrep/run', [
            'workspace' => $this->workspace,
            'command' => ['rg', '--version'],
            'dry_run' => true,
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('data.tool_slug', 'ripgrep')
            ->assertJsonPath('data.status', 'skipped');

        $this->getJson('/tools/evidence?limit=5', $this->headers)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_run_errors_are_reported_as_api_and_cli_contracts(): void
    {
        $this->postJson('/tools/not-registered/run', [
            'workspace' => $this->workspace,
            'command' => ['not-registered', '--version'],
        ], $this->headers)
            ->assertNotFound()
            ->assertJsonPath('message', 'Tool [not-registered] is not registered.');

        $this->installFakeBinary('rg', 'echo "ripgrep 99.0.0"');

        $this->postJson('/tools/ripgrep/run', [
            'workspace' => $this->workspace,
            'command' => ['./../bin/rg'],
        ], $this->headers)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Unsafe command argument rejected.');

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'not-registered',
            '--workspace' => $this->workspace,
            '--command' => ['not-registered', '--version'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('error', $payload['status'] ?? null);
        $this->assertSame('invalid_tool_run_request', $payload['error'] ?? null);
    }

    public function test_evidence_can_be_filtered_by_workspace_tool_status_and_surface(): void
    {
        $this->installFakeBinary('rg', 'echo "ripgrep 99.0.0"');
        $otherWorkspace = sys_get_temp_dir().'/atlas-tool-runtime-other-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($otherWorkspace);

        try {
            Artisan::call('atlas:tools', [
                'action' => 'run',
                'tool' => 'ripgrep',
                '--workspace' => $this->workspace,
                '--command' => ['rg', '--version'],
                '--json' => true,
            ]);
            Artisan::call('atlas:tools', [
                'action' => 'run',
                'tool' => 'ripgrep',
                '--workspace' => $otherWorkspace,
                '--command' => ['rg', '--version'],
                '--json' => true,
            ]);

            $this->getJson('/tools/evidence?workspace='.urlencode($this->workspace).'&tool_slug=ripgrep&status=passed&surface=cli', $this->headers)
                ->assertOk()
                ->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.tool_slug', 'ripgrep')
                ->assertJsonPath('data.0.status', 'passed')
                ->assertJsonPath('data.0.surface', 'cli');

            Artisan::call('atlas:tools', [
                'action' => 'evidence',
                'tool' => 'ripgrep',
                '--workspace' => $this->workspace,
                '--status' => 'passed',
                '--surface' => 'cli',
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);

            $this->assertCount(1, $payload['runs'] ?? []);
            $this->assertSame('ripgrep', data_get($payload, 'runs.0.tool_slug'));
            $this->assertSame('passed', data_get($payload, 'runs.0.status'));
        } finally {
            File::deleteDirectory($otherWorkspace);
        }
    }

    public function test_evidence_run_can_be_shown_and_exported_by_cli_and_api(): void
    {
        $this->installFakeBinary('rg', 'echo "ripgrep 99.0.0"');

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'ripgrep',
            '--workspace' => $this->workspace,
            '--command' => ['rg', '--version'],
            '--json' => true,
        ]);
        $runPayload = json_decode(Artisan::output(), true);
        $runId = (string) data_get($runPayload, 'run.id');

        $this->assertNotSame('', $runId);

        Artisan::call('atlas:tools', [
            'action' => 'evidence-show',
            'tool' => $runId,
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $showPayload = json_decode(Artisan::output(), true);

        $this->assertSame($runId, data_get($showPayload, 'run.id'));
        $this->assertSame('ripgrep', data_get($showPayload, 'run.tool_slug'));
        $this->assertNotEmpty(data_get($showPayload, 'run.artifacts'));

        Artisan::call('atlas:tools', [
            'action' => 'evidence-export',
            '--run-id' => $runId,
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $exportPayload = json_decode(Artisan::output(), true);

        $this->assertSame('atlas.tool_evidence.v1', $exportPayload['schema'] ?? null);
        $this->assertSame($runId, data_get($exportPayload, 'integrity.run_id'));
        $this->assertSame('ripgrep', data_get($exportPayload, 'integrity.tool_slug'));
        $this->assertNotEmpty(data_get($exportPayload, 'integrity.artifact_hashes'));
        $this->assertSame($runId, data_get($exportPayload, 'run.id'));
        $this->assertArrayNotHasKey('workspace', $exportPayload['run']);
        $this->assertArrayNotHasKey('path', data_get($exportPayload, 'artifacts.0', []));
        $this->assertArrayNotHasKey('preview_json', data_get($exportPayload, 'artifacts.0', []));

        Artisan::call('atlas:tools', [
            'action' => 'evidence-export',
            '--run-id' => $runId,
            '--json' => true,
        ]);
        $unscopedExportPayload = json_decode(Artisan::output(), true);

        $this->assertSame($runId, data_get($unscopedExportPayload, 'integrity.run_id'));

        $this->getJson('/tools/evidence/'.$runId.'?workspace='.urlencode($this->workspace), $this->headers)
            ->assertOk()
            ->assertJsonPath('data.id', $runId)
            ->assertJsonPath('data.tool_slug', 'ripgrep');

        $this->getJson('/tools/evidence/'.$runId.'/export?workspace='.urlencode($this->workspace), $this->headers)
            ->assertOk()
            ->assertJsonPath('schema', 'atlas.tool_evidence.v1')
            ->assertJsonPath('integrity.run_id', $runId)
            ->assertJsonMissingPath('run.workspace')
            ->assertJsonMissingPath('artifacts.0.path')
            ->assertJsonMissingPath('artifacts.0.preview_json');
    }

    public function test_evidence_run_lookup_is_scoped_by_workspace_when_requested(): void
    {
        $this->installFakeBinary('rg', 'echo "ripgrep 99.0.0"');
        $otherWorkspace = sys_get_temp_dir().'/atlas-tool-runtime-other-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($otherWorkspace);

        try {
            Artisan::call('atlas:tools', [
                'action' => 'run',
                'tool' => 'ripgrep',
                '--workspace' => $this->workspace,
                '--command' => ['rg', '--version'],
                '--json' => true,
            ]);
            $runId = (string) data_get(json_decode(Artisan::output(), true), 'run.id');

            $this->getJson('/tools/evidence/'.$runId.'?workspace='.urlencode($otherWorkspace), $this->headers)
                ->assertNotFound();

            Artisan::call('atlas:tools', [
                'action' => 'evidence-export',
                '--run-id' => $runId,
                '--workspace' => $otherWorkspace,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);

            $this->assertSame('missing', $payload['status'] ?? null);
            $this->assertSame('tool_run_not_found', $payload['error'] ?? null);
        } finally {
            File::deleteDirectory($otherWorkspace);
        }
    }

    public function test_evidence_export_sanitizes_finding_paths_and_messages(): void
    {
        $absoluteFile = $this->workspace.'/app/Secret.php';
        File::ensureDirectoryExists(dirname($absoluteFile));
        File::put($absoluteFile, '<?php');
        $this->installFakeBinary('eslint', <<<BASH
cat <<'JSON'
[
  {
    "filePath": "{$absoluteFile}",
    "messages": [
      {
        "ruleId": "secret/path",
        "severity": 2,
        "message": "Leaked token sk-abcdefghijklmnop at {$absoluteFile}",
        "line": 7
      }
    ]
  }
]
JSON
exit 1
BASH);

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'eslint',
            '--workspace' => $this->workspace,
            '--command' => ['eslint', '--format', 'json'],
            '--json' => true,
        ]);
        $runId = (string) data_get(json_decode(Artisan::output(), true), 'run.id');

        Artisan::call('atlas:tools', [
            'action' => 'evidence-export',
            '--run-id' => $runId,
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('app/Secret.php', data_get($payload, 'findings.0.file_path'));
        $this->assertStringNotContainsString($this->workspace, (string) data_get($payload, 'findings.0.file_path'));
        $this->assertStringNotContainsString('sk-abcdefghijklmnop', (string) data_get($payload, 'findings.0.message'));
        $this->assertStringNotContainsString($this->workspace, (string) data_get($payload, 'findings.0.message'));
    }

    public function test_gate_blocks_on_failed_evidence_and_missing_required_tools(): void
    {
        $this->installFakeBinary('semgrep', <<<'BASH'
cat <<'JSON'
{
  "results": [
    {
      "check_id": "php.security.gate",
      "path": "app/Gate.php",
      "start": {"line": 5},
      "extra": {
        "severity": "ERROR",
        "message": "Gate blocking finding"
      }
    }
  ]
}
JSON
exit 1
BASH);

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'semgrep',
            '--workspace' => $this->workspace,
            '--command' => ['semgrep', '--json'],
            '--json' => true,
        ]);

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=semgrep&required_tool[]=semgrep', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('allowed', false)
            ->assertJsonPath('summary.run_count', 1)
            ->assertJsonPath('summary.blocking_failure_count', 2)
            ->assertJsonPath('blocking_failures.0.reason', 'tool_status_failed');

        Artisan::call('atlas:tools', [
            'action' => 'gate',
            '--workspace' => $this->workspace,
            '--required-tool' => ['ripgrep'],
            '--require-evidence' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertSame('required_tool_missing', data_get($payload, 'blocking_failures.0.reason'));
    }

    public function test_gate_passes_when_required_tool_has_passing_evidence(): void
    {
        $this->installFakeBinary('rg', 'echo "ripgrep 99.0.0"');

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'ripgrep',
            '--workspace' => $this->workspace,
            '--command' => ['rg', '--version'],
            '--json' => true,
        ]);

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=ripgrep&required_tool[]=ripgrep', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('summary.run_count', 1)
            ->assertJsonPath('summary.blocking_failure_count', 0);
    }

    public function test_finding_waivers_are_auditable_and_excluded_from_gates_until_revoked(): void
    {
        $run = app(AtlasToolEvidenceStore::class)->recordExternalToolResult('semgrep', $this->workspace, [
            'status' => 'passed',
            'findings' => [[
                'rule_id' => 'php.security.waiver',
                'title' => 'Waivable blocking finding',
                'message' => 'Fixture finding that blocks a release gate.',
                'severity' => 'high',
                'file' => 'app/Waiver.php',
                'line' => 9,
                'blocks_resolved' => true,
            ]],
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);
        $finding = AtlasToolFinding::query()->where('tool_run_id', $run?->id)->firstOrFail();

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=semgrep', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('summary.blocking_failure_count', 1);

        $this->postJson('/tools/findings/'.$finding->id.'/waiver', [
            'reason' => 'Accepted false positive in generated fixture.',
            'ttl_hours' => 2,
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('data.id', $finding->id)
            ->assertJsonPath('data.status', 'waived')
            ->assertJsonPath('data.metadata_json.waiver.reason', 'Accepted false positive in generated fixture.')
            ->assertJsonPath('data.metadata_json.waiver.waived_by', 'atlas_api');

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=semgrep', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('summary.blocking_failure_count', 0)
            ->assertJsonPath('runs.0.blocking_finding_count', 0)
            ->assertJsonPath('runs.0.waived_finding_count', 1);

        Artisan::call('atlas:tools', [
            'action' => 'evidence-export',
            '--run-id' => $run?->id,
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $exportPayload = json_decode(Artisan::output(), true);

        $this->assertSame('waived', data_get($exportPayload, 'findings.0.status'));
        $this->assertSame('Accepted false positive in generated fixture.', data_get($exportPayload, 'findings.0.waiver.reason'));
        $this->assertNotEmpty(data_get($exportPayload, 'findings.0.waiver.id'));

        Artisan::call('atlas:tools', [
            'action' => 'revoke-finding-waiver',
            '--finding-id' => $finding->id,
            '--reason' => 'Fixture waiver revoked.',
            '--json' => true,
        ]);
        $revoked = json_decode(Artisan::output(), true);

        $this->assertSame('open', $revoked['status'] ?? null);
        $this->assertSame('open', data_get($revoked, 'finding.status'));
        $this->assertNull(data_get($revoked, 'finding.waiver_id'));

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=semgrep', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('summary.blocking_failure_count', 1);
    }

    public function test_expired_finding_waiver_does_not_suppress_blocking_gate(): void
    {
        $run = app(AtlasToolEvidenceStore::class)->recordExternalToolResult('semgrep', $this->workspace, [
            'status' => 'passed',
            'findings' => [[
                'rule_id' => 'php.security.expired_waiver',
                'title' => 'Expired waiver finding',
                'severity' => 'high',
                'blocks_resolved' => true,
            ]],
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);
        $finding = AtlasToolFinding::query()->where('tool_run_id', $run?->id)->firstOrFail();

        Artisan::call('atlas:tools', [
            'action' => 'waive-finding',
            '--finding-id' => $finding->id,
            '--reason' => 'Temporary waiver.',
            '--ttl-hours' => 1,
            '--json' => true,
        ]);

        $finding->refresh();
        $metadata = (array) $finding->metadata_json;
        data_set($metadata, 'waiver.waived_until', now()->subHour()->toISOString());
        $finding->forceFill(['metadata_json' => $metadata])->save();

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=semgrep', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('summary.blocking_failure_count', 1)
            ->assertJsonPath('runs.0.waived_finding_count', 0);
    }

    public function test_release_gate_requires_security_sbom_evidence_and_honors_valid_waivers(): void
    {
        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('gitleaks', $this->workspace, [
            'status' => 'passed',
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);
        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('trivy', $this->workspace, [
            'status' => 'passed',
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);
        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('syft', $this->workspace, [
            'status' => 'passed',
            'stdout' => json_encode([
                'source' => ['type' => 'directory', 'name' => '.'],
                'artifacts' => [
                    ['name' => 'vendor/package', 'version' => '1.0.0', 'type' => 'php-composer-package'],
                ],
            ]),
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);
        $semgrepRun = app(AtlasToolEvidenceStore::class)->recordExternalToolResult('semgrep', $this->workspace, [
            'status' => 'failed',
            'exit_code' => 1,
            'findings' => [[
                'rule_id' => 'php.security.release_gate',
                'title' => 'Release gate security finding',
                'severity' => 'high',
                'blocks_resolved' => true,
            ]],
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);
        $finding = AtlasToolFinding::query()->where('tool_run_id', $semgrepRun?->id)->firstOrFail();

        $this->getJson('/tools/release-gate?workspace='.urlencode($this->workspace), $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('release_requirements.0.satisfied', true)
            ->assertJsonPath('release_requirements.1.satisfied', true)
            ->assertJsonPath('release_requirements.2.satisfied', true)
            ->assertJsonPath('release_requirements.3.satisfied', true);

        $this->postJson('/tools/findings/'.$finding->id.'/waiver', [
            'reason' => 'Accepted false positive for release fixture.',
            'ttl_hours' => 1,
        ], $this->headers)->assertCreated();

        $this->getJson('/tools/release-gate?workspace='.urlencode($this->workspace), $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('summary.release_requirement_failure_count', 0)
            ->assertJsonPath('runs.3.waived_finding_count', 1);

        Artisan::call('atlas:tools', [
            'action' => 'release-gate',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('passed', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['allowed'] ?? false));
    }

    public function test_release_gate_blocks_when_required_sbom_evidence_is_missing(): void
    {
        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('gitleaks', $this->workspace, [
            'status' => 'passed',
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);
        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('semgrep', $this->workspace, [
            'status' => 'passed',
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);
        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('trivy', $this->workspace, [
            'status' => 'passed',
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);

        $this->getJson('/tools/release-gate?workspace='.urlencode($this->workspace), $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('summary.release_requirement_failure_count', 1)
            ->assertJsonPath('blocking_failures.0.requirement', 'sbom_attached');
    }

    public function test_quality_scan_records_generic_tool_evidence_without_breaking_existing_payload(): void
    {
        File::put($this->workspace.'/eslint.config.js', 'export default [];');

        Artisan::call('atlas:engineering:quality-scan', [
            '--workspace' => $this->workspace,
            '--profile' => 'fast',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('passed', $payload['status'] ?? null);
        $this->assertFileExists($payload['artifact_root'].'/scan.json');
        $this->assertGreaterThan(0, AtlasToolRun::query()->where('surface', 'engineering_quality_scan')->count());
    }

    public function test_external_sbom_evidence_persists_normalized_metrics_and_artifact_summaries(): void
    {
        $run = app(AtlasToolEvidenceStore::class)->recordExternalToolResult('syft', $this->workspace, [
            'status' => 'passed',
            'exit_code' => 0,
            'duration_ms' => 12,
            'stdout' => json_encode([
                'source' => ['type' => 'directory', 'name' => '.'],
                'artifacts' => [
                    ['name' => 'vendor/package', 'version' => '1.0.0', 'type' => 'php-composer-package'],
                    ['name' => 'node/package', 'version' => '2.0.0', 'type' => 'npm-package'],
                ],
            ]),
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);

        $this->assertNotNull($run);
        $this->assertSame(2, data_get($run, 'normalized_result_json.metrics.package_count'));
        $this->assertSame(1, data_get($run, 'normalized_result_json.metrics.package_type_counts.php-composer-package'));
        $this->assertSame('sbom_summary', data_get($run, 'normalized_result_json.artifacts.0.type'));
        $this->assertSame(2, data_get($run, 'normalized_result_json.artifacts.0.package_count'));
        $this->assertSame(0, data_get($run, 'summary_json.finding_count'));
    }

    public function test_visual_smoke_records_generic_tool_evidence_for_internal_sensor(): void
    {
        $exit = Artisan::call('atlas:engineering:visual-smoke', [
            '--workspace' => $this->workspace,
            '--start-command' => '',
            '--artifact-dir' => 'atlas-visual-report',
            '--timeout' => 5,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertSame('failed', $payload['status'] ?? null);
        $this->assertSame('no_start_command_detected', $payload['failure'] ?? null);
        $this->assertFileExists($this->workspace.'/atlas-visual-report/manifest.json');
        $this->assertDatabaseHas('atlas_tool_runs', [
            'tool_slug' => 'atlas_visual_smoke',
            'surface' => 'engineering_visual_smoke',
            'status' => 'failed',
        ]);
        $this->assertDatabaseHas('atlas_tool_artifacts', [
            'type' => 'manifest',
            'filename' => 'manifest.json',
        ]);
        $this->assertDatabaseHas('atlas_tool_findings', [
            'rule_id' => 'atlas_visual_smoke.failure',
            'severity' => 'high',
        ]);
    }

    private function installFakeBinary(string $name, string $scriptBody): void
    {
        File::put($this->binDir.'/'.$name, "#!/usr/bin/env bash\n{$scriptBody}\n");
        chmod($this->binDir.'/'.$name, 0755);
    }

    private function createTables(): void
    {
        Schema::create('atlas_tool_definitions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('type')->default('validator');
            $table->string('category');
            $table->text('description')->nullable();
            $table->string('homepage')->nullable();
            $table->string('license_posture')->default('open_source');
            $table->string('cost_posture')->default('free_local');
            $table->boolean('default_enabled')->default(true);
            $table->unsignedSmallInteger('default_timeout_seconds')->default(120);
            $table->string('default_failure_policy')->default('advisory');
            $table->string('risk_level')->default('low');
            $table->string('status')->default('active');
            $table->string('detected_version')->nullable();
            $table->json('capabilities_json')->nullable();
            $table->json('runtime_json')->nullable();
            $table->json('detect_json')->nullable();
            $table->json('outputs_json')->nullable();
            $table->json('risks_json')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_tool_installations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_definition_id');
            $table->string('workspace_hash');
            $table->string('execution_layer');
            $table->string('status');
            $table->string('version')->nullable();
            $table->string('binary_path_hash')->nullable();
            $table->string('node_modules_path_hash')->nullable();
            $table->timestamp('detected_at')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_tool_policies', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope_type')->default('global');
            $table->string('scope_id')->nullable();
            $table->string('tool_slug');
            $table->boolean('enabled')->default(true);
            $table->json('required_when_json')->nullable();
            $table->string('failure_policy')->nullable();
            $table->unsignedSmallInteger('timeout_seconds')->nullable();
            $table->json('thresholds_json')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_tool_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_definition_id')->nullable();
            $table->string('tool_slug');
            $table->string('surface')->default('cli');
            $table->string('workspace_hash')->nullable();
            $table->text('workspace')->nullable();
            $table->string('run_context_type')->nullable();
            $table->string('run_context_id')->nullable();
            $table->string('status');
            $table->boolean('required')->default(false);
            $table->string('failure_policy')->default('advisory');
            $table->string('policy_decision')->default('allowed');
            $table->string('command_hash')->nullable();
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->uuid('stdout_artifact_id')->nullable();
            $table->uuid('stderr_artifact_id')->nullable();
            $table->json('summary_json')->nullable();
            $table->json('normalized_result_json')->nullable();
            $table->json('policy_decision_json')->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_tool_artifacts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_run_id');
            $table->string('type');
            $table->text('path');
            $table->string('filename');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('sha256');
            $table->boolean('is_redacted')->default(true);
            $table->json('preview_json')->nullable();
            $table->timestamps();
        });

        Schema::create('atlas_tool_findings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('tool_run_id');
            $table->string('rule_id')->nullable();
            $table->text('title');
            $table->text('message')->nullable();
            $table->string('severity')->default('medium');
            $table->decimal('confidence', 4, 3)->nullable();
            $table->text('file_path')->nullable();
            $table->unsignedInteger('line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->string('fingerprint')->nullable();
            $table->boolean('blocks_resolved')->default(false);
            $table->string('waiver_id')->nullable();
            $table->string('status')->default('open');
            $table->json('metadata_json')->nullable();
            $table->timestamps();
        });
    }
}
