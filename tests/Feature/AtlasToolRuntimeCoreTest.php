<?php

namespace Tests\Feature;

use App\Models\AtlasLedgerEvent;
use App\Models\AtlasToolFinding;
use App\Models\AtlasToolRun;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Tools\AtlasToolEvidenceStore;
use App\Services\Tools\AtlasToolGateService;
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
            'atlas_ledger_events',
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
        $this->assertSame('T1', data_get($gitleaks, 'execution_tier'));
        $this->assertSame('secret_scan', data_get($gitleaks, 'authority_group'));
        $this->assertSame('primary', data_get($gitleaks, 'authority_role'));
        $this->assertSame(['gitleaks', '--version'], data_get($gitleaks, 'safe_commands.0.command'));
        $this->assertTrue((bool) data_get($gitleaks, 'safe_commands.0.dry_run_default'));
        $this->assertSame('diagnostic', data_get($gitleaks, 'safe_commands.0.category'));
        $this->assertSame('manual_diagnostic', data_get($gitleaks, 'safe_commands.0.recommended_surface'));
        $this->assertFalse((bool) data_get($gitleaks, 'safe_commands.0.blocking_capable'));
        $this->assertDatabaseHas('atlas_tool_definitions', ['slug' => 'gitleaks']);
        $this->assertDatabaseHas('atlas_tool_definitions', ['slug' => 'atlas_visual_smoke']);
        $this->assertDatabaseHas('atlas_tool_definitions', ['slug' => 'atlas_code_intelligence']);
        $this->assertDatabaseHas('atlas_tool_installations', ['status' => 'ready']);
    }

    public function test_tool_evidence_and_gate_emit_kernel_ledger_events(): void
    {
        $run = app(AtlasToolEvidenceStore::class)->recordExternalToolResult('semgrep', $this->workspace, [
            'status' => 'passed',
            'required' => true,
            'duration_ms' => 42,
            'summary' => ['ok' => true],
            'findings' => [],
        ], [
            'run_context_type' => 'engineering_run',
            'run_context_id' => 'run-ledger-1',
            'envelope_id' => 'engineering_run:run-ledger-1',
            'tenant_id' => 'tenant_tools',
            'operator_id' => 'operator_tools',
        ]);

        $this->assertInstanceOf(AtlasToolRun::class, $run);
        $this->assertSame('semantic_sast', data_get($run->metadata_json, 'authority_group'));
        $this->assertSame('complementary', data_get($run->metadata_json, 'authority_role'));
        $this->assertSame('security', data_get($run->metadata_json, 'tool_category'));
        $this->assertSame('scanner', data_get($run->metadata_json, 'tool_type'));
        $this->assertSame('T2', data_get($run->metadata_json, 'execution_tier'));
        $this->assertSame('atlas.tool_evidence_receipt.v1', data_get($run->metadata_json, 'receipt_schema_version'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($run->metadata_json, 'summary_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($run->metadata_json, 'normalized_result_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($run->metadata_json, 'evidence_receipt_hash'));
        $gate = app(AtlasToolGateService::class)->evaluate([
            'workspace' => $this->workspace,
            'run_context_type' => 'engineering_run',
            'run_context_id' => 'run-ledger-1',
        ], [
            'require_evidence' => true,
            'required_tools' => ['semgrep'],
            'envelope_id' => 'engineering_run:run-ledger-1',
            'tenant_id' => 'tenant_tools',
            'operator_id' => 'operator_tools',
        ]);

        $this->assertSame('passed', $gate['status']);
        $events = AtlasLedgerEvent::query()
            ->where('envelope_id', 'engineering_run:run-ledger-1')
            ->orderBy('occurred_at')
            ->orderBy('event_id')
            ->get();

        $this->assertSame([
            LedgerEventType::ToolEvidenceRecorded->value,
            LedgerEventType::GatePassed->value,
            LedgerEventType::SloObserved->value,
        ], $events->pluck('event_type')->all());
        $this->assertSame('tenant_tools', $events->first()?->tenant_id);
        $this->assertSame($run->id, data_get($events->first()?->payload, 'tool_run_id'));
        $this->assertSame('semantic_sast', data_get($events->first()?->payload, 'authority_group'));
        $this->assertSame('complementary', data_get($events->first()?->payload, 'authority_role'));
        $this->assertSame(data_get($run->metadata_json, 'summary_hash'), data_get($events->first()?->payload, 'summary_hash'));
        $this->assertSame(data_get($run->metadata_json, 'normalized_result_hash'), data_get($events->first()?->payload, 'normalized_result_hash'));
        $this->assertSame(data_get($run->metadata_json, 'evidence_receipt_hash'), data_get($events->first()?->payload, 'evidence_receipt_hash'));
        $this->assertSame('atlas.tool_evidence_receipt.v1', data_get($events->first()?->payload, 'receipt_schema_version'));
        $this->assertSame([$run->id], data_get($events[1]?->payload, 'run_ids'));
        $this->assertSame('gate.evaluate', data_get($events->last()?->payload, 'stage'));
        $this->assertNull(data_get($events->first()?->payload, 'workspace'));
    }

    public function test_tool_evidence_store_fails_closed_when_auxiliary_tables_are_missing(): void
    {
        Schema::dropIfExists('atlas_tool_artifacts');

        $run = app(AtlasToolEvidenceStore::class)->recordExternalToolResult('semgrep', $this->workspace, [
            'status' => 'failed',
            'required' => true,
            'duration_ms' => 42,
            'summary' => ['ok' => false],
            'findings' => [
                [
                    'rule_id' => 'semgrep.test',
                    'title' => 'Should not persist without artifact table',
                    'severity' => 'high',
                    'blocks_resolved' => true,
                ],
            ],
        ], [
            'run_context_type' => 'engineering_run',
            'run_context_id' => 'run-missing-artifacts',
            'envelope_id' => 'engineering_run:run-missing-artifacts',
        ]);

        $this->assertNull($run);
        $this->assertSame(0, AtlasToolRun::query()->count());
        $this->assertSame(0, AtlasToolFinding::query()->count());
        $this->assertSame(0, AtlasLedgerEvent::query()->count());
    }

    public function test_tool_command_catalog_is_exposed_by_cli_and_api(): void
    {
        $this->installFakeBinary('rg', 'echo "ripgrep 99.0.0"');

        Artisan::call('atlas:tools', [
            'action' => 'commands',
            'tool' => 'ripgrep',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('ripgrep', data_get($payload, 'tool.slug'));
        $this->assertSame('version', data_get($payload, 'commands.0.name'));
        $this->assertSame(['rg', '--version'], data_get($payload, 'commands.0.command'));
        $this->assertSame('diagnostic', data_get($payload, 'commands.0.category'));
        $this->assertSame('manual_diagnostic', data_get($payload, 'commands.0.recommended_surface'));
        $this->assertTrue((bool) data_get($payload, 'commands.0.creates_evidence'));
        $this->assertFalse((bool) data_get($payload, 'commands.0.blocking_capable'));
        $this->assertSame('diagnostic', data_get($payload, 'commands.0.task_type'));
        $this->assertFalse((bool) data_get($payload, 'commands.0.network_allowed'));

        $this->getJson('/tools/ripgrep/commands?workspace='.urlencode($this->workspace), $this->headers)
            ->assertOk()
            ->assertJsonPath('tool.slug', 'ripgrep')
            ->assertJsonPath('commands.0.command.0', 'rg')
            ->assertJsonPath('commands.0.dry_run_default', true);
    }

    public function test_p0_programming_power_tools_expose_real_scan_recipes(): void
    {
        $this->installFakeBinary('gitleaks', 'echo "gitleaks 99.0.0"');
        $this->installFakeBinary('semgrep', 'echo "semgrep 99.0.0"');
        $this->installFakeBinary('osv-scanner', 'echo "osv-scanner 99.0.0"');
        $this->installFakeBinary('syft', 'echo "syft 99.0.0"');
        $this->installFakeBinary('trivy', 'echo "trivy 99.0.0"');
        $this->installFakeBinary('hadolint', 'echo "Haskell Dockerfile Linter 99.0.0"');
        $this->installFakeBinary('checkov', 'echo "99.0.0"');
        $this->installWorkspaceBinary('vendor/bin/phpstan', 'echo "PHPStan 99.0.0"');
        $this->installWorkspaceBinary('vendor/bin/pint', 'echo "Pint 99.0.0"');
        $this->installWorkspaceBinary('node_modules/.bin/tsc', 'echo "Version 99.0.0"');
        $this->installWorkspaceBinary('node_modules/.bin/biome', 'echo "Version 99.0.0"');
        $this->installWorkspaceBinary('node_modules/.bin/eslint', 'echo "v99.0.0"');

        $expectations = [
            'gitleaks' => ['detect-redacted', 'secret_scan', 'engineering_quality_scan', true],
            'semgrep' => ['scan-json', 'static_security_scan', 'engineering_quality_scan', true],
            'osv_scanner' => ['recursive-json', 'dependency_vulnerability_scan', 'engineering_quality_scan', true],
            'syft' => ['sbom-json', 'sbom', 'release_gate', true],
            'trivy' => ['fs-json', 'vulnerability_scan', 'release_gate', true],
            'phpstan' => ['analyse-json', 'static_analysis', 'engineering_quality_scan', true],
            'laravel_pint' => ['format-test', 'format_check', 'engineering_quality_scan', true],
            'typescript' => ['no-emit', 'typecheck', 'engineering_quality_scan', true],
            'biome' => ['ci-json', 'lint', 'engineering_quality_scan', true],
            'eslint' => ['lint-json', 'lint', 'engineering_quality_scan', true],
            'hadolint' => ['dockerfile-json', 'container_lint', 'engineering_quality_scan', true],
            'checkov' => ['directory-sarif', 'iac_security', 'engineering_quality_scan', true],
        ];

        foreach ($expectations as $tool => [$recipe, $category, $surface, $blocking]) {
            Artisan::call('atlas:tools', [
                'action' => 'commands',
                'tool' => $tool,
                '--workspace' => $this->workspace,
                '--json' => true,
            ]);
            $payload = json_decode(Artisan::output(), true);
            $scanRecipe = collect($payload['commands'] ?? [])->firstWhere('name', $recipe);

            $this->assertIsArray($scanRecipe, "Missing recipe [{$recipe}] for [{$tool}].");
            $this->assertSame($category, data_get($scanRecipe, 'category'));
            $this->assertSame($surface, data_get($scanRecipe, 'recommended_surface'));
            $this->assertFalse((bool) data_get($scanRecipe, 'dry_run_default'));
            $this->assertSame($blocking, (bool) data_get($scanRecipe, 'blocking_capable'));
            $this->assertNotEmpty(data_get($scanRecipe, 'command'));
        }
    }

    public function test_tool_recipe_can_be_executed_by_cli_and_api(): void
    {
        $this->installFakeBinary('rg', 'echo "ripgrep 99.0.0"');

        Artisan::call('atlas:tools', [
            'action' => 'run-recipe',
            'tool' => 'ripgrep',
            '--recipe' => 'version',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('skipped', data_get($payload, 'run.status'));
        $this->assertSame('version', data_get($payload, 'run.metadata_json.recipe'));
        $this->assertSame('diagnostic', data_get($payload, 'run.metadata_json.recipe_category'));
        $this->assertSame('manual_diagnostic', data_get($payload, 'run.metadata_json.recipe_recommended_surface'));
        $this->assertFalse((bool) data_get($payload, 'run.metadata_json.recipe_blocking_capable'));
        $this->assertSame('manual_diagnostic', data_get($payload, 'run.surface'));
        $this->assertSame('cli_recipe', data_get($payload, 'run.metadata_json.execution_origin'));

        $this->postJson('/tools/ripgrep/commands/version/run', [
            'workspace' => $this->workspace,
            'dry_run' => true,
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('data.status', 'skipped')
            ->assertJsonPath('data.metadata_json.recipe', 'version')
            ->assertJsonPath('data.surface', 'manual_diagnostic')
            ->assertJsonPath('data.metadata_json.execution_origin', 'api_recipe');
    }

    public function test_scan_recipe_executes_and_normalizes_semgrep_findings(): void
    {
        $this->installFakeBinary('semgrep', <<<'BASH'
cat <<'JSON'
{
  "results": [
    {
      "check_id": "php.security.recipe",
      "path": "app/Recipe.php",
      "start": {"line": 9},
      "end": {"line": 9},
      "extra": {
        "severity": "ERROR",
        "message": "Recipe blocking finding",
        "fingerprint": "recipe-fp"
      }
    }
  ]
}
JSON
exit 1
BASH);

        Artisan::call('atlas:tools', [
            'action' => 'run-recipe',
            'tool' => 'semgrep',
            '--recipe' => 'scan-json',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('failed', data_get($payload, 'run.status'));
        $this->assertSame('engineering_quality_scan', data_get($payload, 'run.surface'));
        $this->assertSame('scan-json', data_get($payload, 'run.metadata_json.recipe'));
        $this->assertSame('static_security_scan', data_get($payload, 'run.metadata_json.recipe_category'));
        $this->assertSame('engineering_quality_scan', data_get($payload, 'run.metadata_json.recipe_recommended_surface'));
        $this->assertSame('cli_recipe', data_get($payload, 'run.metadata_json.execution_origin'));
        $this->assertTrue((bool) data_get($payload, 'run.metadata_json.recipe_blocking_capable'));
        $this->assertSame('php.security.recipe', data_get($payload, 'run.normalized_result_json.findings.0.rule_id'));
        $this->assertSame('high', data_get($payload, 'run.normalized_result_json.findings.0.severity'));
        $this->assertTrue((bool) data_get($payload, 'run.normalized_result_json.findings.0.blocks_resolved'));
    }

    public function test_typescript_recipe_parses_no_emit_text_diagnostics(): void
    {
        $this->installWorkspaceBinary('node_modules/.bin/tsc', <<<'BASH'
echo 'src/app.ts(12,7): error TS2322: Type string is not assignable to type number.' >&2
exit 2
BASH);

        Artisan::call('atlas:tools', [
            'action' => 'run-recipe',
            'tool' => 'typescript',
            '--recipe' => 'no-emit',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('failed', data_get($payload, 'run.status'));
        $this->assertSame('no-emit', data_get($payload, 'run.metadata_json.recipe'));
        $this->assertSame('typecheck', data_get($payload, 'run.metadata_json.recipe_category'));
        $this->assertSame('TS2322', data_get($payload, 'run.normalized_result_json.findings.0.rule_id'));
        $this->assertSame('src/app.ts', data_get($payload, 'run.normalized_result_json.findings.0.file_path'));
        $this->assertSame(12, data_get($payload, 'run.normalized_result_json.findings.0.line'));
        $this->assertTrue((bool) data_get($payload, 'run.normalized_result_json.findings.0.blocks_resolved'));
    }

    public function test_pint_biome_hadolint_and_sarif_outputs_are_normalized(): void
    {
        $this->installWorkspaceBinary('vendor/bin/pint', <<<'BASH'
cat <<'JSON'
{
  "files": [
    {"name": "app/NeedsFormat.php", "appliedFixers": ["braces"]}
  ]
}
JSON
exit 1
BASH);
        $this->installWorkspaceBinary('node_modules/.bin/biome', <<<'BASH'
cat <<'JSON'
{
  "diagnostics": [
    {
      "category": "lint/suspicious/noConsole",
      "severity": "error",
      "description": "Avoid console.",
      "location": {
        "path": {"file": "src/App.tsx"},
        "span": {"start": {"line": 4, "column": 3}, "end": {"line": 4, "column": 10}}
      }
    }
  ]
}
JSON
exit 1
BASH);
        $this->installFakeBinary('hadolint', <<<'BASH'
cat <<'JSON'
[
  {"code": "DL3008", "level": "error", "message": "Pin versions in apt-get install.", "line": 3, "column": 1, "file": "Dockerfile"}
]
JSON
exit 1
BASH);
        $this->installFakeBinary('checkov', <<<'BASH'
cat <<'JSON'
{
  "version": "2.1.0",
  "runs": [
    {
      "tool": {
        "driver": {
          "name": "Checkov",
          "rules": [
            {
              "id": "CKV_DOCKER_2",
              "shortDescription": {"text": "Ensure HEALTHCHECK instructions have been added"},
              "properties": {"problem.severity": "error"}
            }
          ]
        }
      },
      "results": [
        {
          "ruleId": "CKV_DOCKER_2",
          "level": "error",
          "message": {"text": "Dockerfile is missing HEALTHCHECK."},
          "locations": [
            {
              "physicalLocation": {
                "artifactLocation": {"uri": "Dockerfile"},
                "region": {"startLine": 1}
              }
            }
          ]
        }
      ]
    }
  ]
}
JSON
exit 1
BASH);

        $cases = [
            ['laravel_pint', 'format-test', [], 'pint.format', 'app/NeedsFormat.php'],
            ['biome', 'ci-json', [], 'lint/suspicious/noConsole', 'src/App.tsx'],
            ['hadolint', 'dockerfile-json', [], 'DL3008', 'Dockerfile'],
            ['checkov', 'directory-sarif', ['--approved' => true], 'CKV_DOCKER_2', 'Dockerfile'],
        ];

        foreach ($cases as [$tool, $recipe, $extraOptions, $ruleId, $filePath]) {
            Artisan::call('atlas:tools', [
                'action' => 'run-recipe',
                'tool' => $tool,
                '--recipe' => $recipe,
                '--workspace' => $this->workspace,
                '--json' => true,
                ...$extraOptions,
            ]);
            $payload = json_decode(Artisan::output(), true);

            $this->assertSame('failed', data_get($payload, 'run.status'), "Expected [{$tool}] to fail for fixture output.");
            $this->assertSame($recipe, data_get($payload, 'run.metadata_json.recipe'));
            $this->assertSame($ruleId, data_get($payload, 'run.normalized_result_json.findings.0.rule_id'));
            $this->assertSame($filePath, data_get($payload, 'run.normalized_result_json.findings.0.file_path'));
            $this->assertTrue((bool) data_get($payload, 'run.normalized_result_json.findings.0.blocks_resolved'));
        }
    }

    public function test_evidence_and_gate_can_be_filtered_by_recipe_metadata(): void
    {
        $this->installFakeBinary('rg', 'echo "ripgrep 99.0.0"');

        Artisan::call('atlas:tools', [
            'action' => 'run-recipe',
            'tool' => 'ripgrep',
            '--recipe' => 'version',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);

        Artisan::call('atlas:tools', [
            'action' => 'evidence',
            'tool' => 'ripgrep',
            '--workspace' => $this->workspace,
            '--recipe' => 'version',
            '--recipe-category' => 'diagnostic',
            '--recipe-blocking-capable' => 'false',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertCount(1, $payload['runs'] ?? []);
        $this->assertSame('version', data_get($payload, 'runs.0.metadata_json.recipe'));

        $this->getJson('/tools/evidence?workspace='.urlencode($this->workspace).'&tool_slug=ripgrep&recipe=version&recipe_category=diagnostic&recipe_blocking_capable=false', $this->headers)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.metadata_json.recipe', 'version');

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=ripgrep&recipe=version&recipe_category=diagnostic&recipe_blocking_capable=false', $this->headers)
            ->assertOk()
            ->assertJsonPath('summary.run_count', 1)
            ->assertJsonPath('status', 'warning')
            ->assertJsonPath('runs.0.recipe', 'version')
            ->assertJsonPath('runs.0.recipe_category', 'diagnostic')
            ->assertJsonPath('runs.0.execution_origin', 'cli_recipe');
    }

    public function test_registry_seeds_programming_power_tools_with_execution_policy_metadata(): void
    {
        Artisan::call('atlas:tools', [
            'action' => 'list',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $serena = collect($payload['tools'] ?? [])->firstWhere('slug', 'serena');
        $infection = collect($payload['tools'] ?? [])->firstWhere('slug', 'infection');
        $codeql = collect($payload['tools'] ?? [])->firstWhere('slug', 'codeql');

        $this->assertSame('semantic_code_intelligence', data_get($serena, 'category'));
        $this->assertSame('T0', data_get($serena, 'execution_tier'));
        $this->assertSame('complementary', data_get($serena, 'authority_role'));
        $this->assertSame('T3', data_get($infection, 'execution_tier'));
        $this->assertSame('mutation_testing', data_get($infection, 'authority_group'));
        $this->assertSame('T2', data_get($codeql, 'execution_tier'));
        $this->assertSame('semantic_sast', data_get($codeql, 'authority_group'));
        $this->assertDatabaseHas('atlas_tool_definitions', [
            'slug' => 'serena',
            'execution_tier' => 'T0',
            'authority_group' => 'semantic_code_intelligence',
        ]);
    }

    public function test_cli_authority_matrix_exposes_tiers_roles_and_recommendations(): void
    {
        $exit = Artisan::call('atlas:tools', [
            'action' => 'authority',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $semanticSast = collect($payload['authority_groups'] ?? [])->firstWhere('authority_group', 'semantic_sast');
        $semanticCode = collect($payload['authority_groups'] ?? [])->firstWhere('authority_group', 'semantic_code_intelligence');
        $vulnerabilityScan = collect($payload['authority_groups'] ?? [])->firstWhere('authority_group', 'vulnerability_scan');
        $externalAgents = collect($payload['authority_groups'] ?? [])->firstWhere('authority_group', 'external_coding_agent');
        $recommendationCodes = collect($payload['recommendations'] ?? [])->pluck('code')->all();

        $this->assertSame(0, $exit);
        $this->assertSame('ok', data_get($payload, 'status'));
        $this->assertGreaterThanOrEqual(60, data_get($payload, 'summary.tool_count'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.t0_count'));
        $this->assertGreaterThan(0, data_get($payload, 'summary.t3_count'));
        $this->assertSame('codeql', data_get($semanticSast, 'primary_tools.0.slug'));
        $this->assertSame('semgrep', data_get($semanticSast, 'complementary_tools.0.slug'));
        $this->assertContains('T2', data_get($semanticSast, 'tier_span', []));
        $this->assertSame('atlas_code_intelligence', data_get($semanticCode, 'primary_tools.0.slug'));
        $this->assertContains('serena', collect(data_get($semanticCode, 'complementary_tools', []))->pluck('slug')->all());
        $this->assertSame('trivy', data_get($vulnerabilityScan, 'primary_tools.0.slug'));
        $this->assertSame('grype', data_get($vulnerabilityScan, 'complementary_tools.0.slug'));
        $this->assertSame('aider', data_get($externalAgents, 'executor_tools.0.slug'));
        $this->assertContains('external_agents_require_policy_boundary', $recommendationCodes);
        $this->assertNotContains('authority_group_missing_primary', collect($payload['recommendations'] ?? [])->where('authority_group', 'semantic_code_intelligence')->pluck('code')->all());
    }

    public function test_api_authority_route_is_static_and_not_treated_as_tool_slug(): void
    {
        $this->getJson('/tools/authority', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('authority_groups.0.authority_group', 'accessibility')
            ->assertJsonPath('tiers.T0.tools.0.execution_tier', 'T0');

        $this->getJson('/tools/authority/policies', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('schema', 'atlas.tool_authority_policies.v1')
            ->assertJsonPath('policies.0.authority_group', 'secret_scan');
    }

    public function test_cli_and_api_expose_authority_gate_policies(): void
    {
        $exit = Artisan::call('atlas:tools', [
            'action' => 'authority-policies',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $semanticSast = collect($payload['policies'] ?? [])->firstWhere('authority_group', 'semantic_sast');

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.tool_authority_policies.v1', data_get($payload, 'schema'));
        $this->assertSame('semantic_sast_critical_high_blocks_medium_warns', data_get($semanticSast, 'policy'));
        $this->assertContains('high', data_get($semanticSast, 'block_severities', []));
        $this->assertContains('medium', data_get($semanticSast, 'warn_severities', []));

        $this->getJson('/tools/authority/policies', $this->headers)
            ->assertOk()
            ->assertJsonPath('schema', 'atlas.tool_authority_policies.v1')
            ->assertJsonPath('summary.policy_count', count($payload['policies'] ?? []))
            ->assertJsonPath('policies.1.authority_group', 'semantic_sast')
            ->assertJsonPath('policies.1.block_reason', 'authority_sast_high_finding');
    }

    public function test_authority_gate_policy_can_be_overridden_and_revoked_per_workspace(): void
    {
        Artisan::call('atlas:tools', [
            'action' => 'set-authority-policy',
            'tool' => 'semantic_sast',
            '--workspace' => $this->workspace,
            '--block-severity' => ['critical', 'high', 'medium'],
            '--warn-severity' => ['low'],
            '--block-reason' => 'workspace_sast_medium_blocks',
            '--warn-reason' => 'workspace_sast_low_warns',
            '--json' => true,
        ]);
        $cliPayload = json_decode(Artisan::output(), true);
        $semanticSast = collect(data_get($cliPayload, 'catalog.policies', []))->firstWhere('authority_group', 'semantic_sast');

        $this->assertSame('configured', data_get($cliPayload, 'status'));
        $this->assertSame('workspace', data_get($semanticSast, 'source'));
        $this->assertContains('medium', data_get($semanticSast, 'block_severities', []));

        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('semgrep', $this->workspace, [
            'status' => 'passed',
            'findings' => [[
                'rule_id' => 'semgrep.medium.override',
                'title' => 'Medium SAST finding now blocks',
                'severity' => 'medium',
                'file' => 'app/Medium.php',
                'line' => 10,
                'blocks_resolved' => false,
            ]],
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=semgrep', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('blocking_failures.0.reason', 'workspace_sast_medium_blocks')
            ->assertJsonPath('blocking_failures.0.authority_policy', 'semantic_sast_critical_high_blocks_medium_warns_override');

        $this->deleteJson('/tools/authority/policies/semantic_sast?workspace='.urlencode($this->workspace), [], $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'revoked')
            ->assertJsonPath('catalog.policies.1.source', 'default');

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=semgrep', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'warning')
            ->assertJsonPath('warnings.0.reason', 'authority_sast_medium_finding');

        $this->putJson('/tools/authority/policies/semantic_sast', [
            'workspace' => $this->workspace,
            'block_severities' => ['critical', 'high'],
            'warn_severities' => ['medium', 'low'],
            'block_reason' => 'api_sast_high_blocks',
            'warn_reason' => 'api_sast_warns',
        ], $this->headers)
            ->assertCreated()
            ->assertJsonPath('status', 'configured')
            ->assertJsonPath('catalog.policies.1.warn_reason', 'api_sast_warns');
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

    public function test_policy_skips_tool_above_allowed_execution_tier_budget(): void
    {
        $this->installFakeBinary('codeql', 'echo "codeql ok"');

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'codeql',
            '--workspace' => $this->workspace,
            '--command' => ['codeql', '--version'],
            '--approved' => true,
            '--network-allowed' => true,
            '--max-execution-tier' => 'T1',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('skipped', data_get($payload, 'run.status'));
        $this->assertSame('skipped', data_get($payload, 'run.policy_decision'));
        $this->assertSame('T2', data_get($payload, 'run.policy_decision_json.execution_tier'));
        $this->assertSame('T1', data_get($payload, 'run.policy_decision_json.max_execution_tier'));
        $this->assertContains('execution_tier_above_policy_budget', data_get($payload, 'run.policy_decision_json.reasons', []));
    }

    public function test_policy_requires_sandbox_or_approval_for_workspace_writing_tools(): void
    {
        $this->installFakeBinary('ast-grep', 'echo "ast-grep ok"');

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'ast_grep',
            '--workspace' => $this->workspace,
            '--command' => ['ast-grep', '--version'],
            '--json' => true,
        ]);
        $blocked = json_decode(Artisan::output(), true);

        $this->assertSame('requires_approval', data_get($blocked, 'run.status'));
        $this->assertSame('workspace', data_get($blocked, 'run.policy_decision_json.sandbox_mode'));
        $this->assertContains('workspace_write_requires_sandbox_or_approval', data_get($blocked, 'run.policy_decision_json.reasons', []));

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'ast_grep',
            '--workspace' => $this->workspace,
            '--command' => ['ast-grep', '--version'],
            '--sandbox-mode' => 'worktree',
            '--json' => true,
        ]);
        $allowed = json_decode(Artisan::output(), true);

        $this->assertSame('passed', data_get($allowed, 'run.status'));
        $this->assertSame('worktree', data_get($allowed, 'run.policy_decision_json.sandbox_mode'));
    }

    public function test_policy_blocks_provider_unsafe_outputs_when_required(): void
    {
        $this->installFakeBinary('gitleaks', 'echo "gitleaks ok"');

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'gitleaks',
            '--workspace' => $this->workspace,
            '--command' => ['gitleaks', '--version'],
            '--requires-provider-safe' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('skipped', data_get($payload, 'run.status'));
        $this->assertFalse((bool) data_get($payload, 'run.policy_decision_json.provider_safe'));
        $this->assertTrue((bool) data_get($payload, 'run.policy_decision_json.requires_provider_safe'));
        $this->assertContains('provider_unsafe_output', data_get($payload, 'run.policy_decision_json.reasons', []));
    }

    public function test_approval_policy_preserves_execution_tier_budget_guardrail(): void
    {
        $this->installFakeBinary('codeql', 'echo "codeql ok"');

        Artisan::call('atlas:tools', [
            'action' => 'approve',
            'tool' => 'codeql',
            '--workspace' => $this->workspace,
            '--network-allowed' => true,
            '--max-execution-tier' => 'T1',
            '--json' => true,
        ]);
        $approval = json_decode(Artisan::output(), true);

        $this->assertSame('T1', data_get($approval, 'policy.metadata.max_execution_tier'));

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'codeql',
            '--workspace' => $this->workspace,
            '--command' => ['codeql', '--version'],
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('skipped', data_get($payload, 'run.status'));
        $this->assertSame('approved', data_get($payload, 'run.policy_decision_json.approval_status'));
        $this->assertSame('T1', data_get($payload, 'run.policy_decision_json.max_execution_tier'));
        $this->assertContains('execution_tier_above_policy_budget', data_get($payload, 'run.policy_decision_json.reasons', []));
    }

    public function test_approval_policy_can_require_sandbox_and_provider_safe_guardrails(): void
    {
        $this->installFakeBinary('ast-grep', 'echo "ast-grep ok"');
        $this->installFakeBinary('gitleaks', 'echo "gitleaks ok"');

        Artisan::call('atlas:tools', [
            'action' => 'approve',
            'tool' => 'ast_grep',
            '--workspace' => $this->workspace,
            '--sandbox-mode' => 'worktree',
            '--task-type' => 'refactor',
            '--json' => true,
        ]);

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'ast_grep',
            '--workspace' => $this->workspace,
            '--command' => ['ast-grep', '--version'],
            '--json' => true,
        ]);
        $allowed = json_decode(Artisan::output(), true);

        $this->assertSame('passed', data_get($allowed, 'run.status'));
        $this->assertSame('worktree', data_get($allowed, 'run.policy_decision_json.sandbox_mode'));
        $this->assertSame('refactor', data_get($allowed, 'run.policy_decision_json.task_type'));

        Artisan::call('atlas:tools', [
            'action' => 'approve',
            'tool' => 'gitleaks',
            '--workspace' => $this->workspace,
            '--requires-provider-safe' => true,
            '--json' => true,
        ]);
        $approval = json_decode(Artisan::output(), true);

        $this->assertTrue((bool) data_get($approval, 'policy.metadata.requires_provider_safe'));

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'gitleaks',
            '--workspace' => $this->workspace,
            '--command' => ['gitleaks', '--version'],
            '--json' => true,
        ]);
        $blocked = json_decode(Artisan::output(), true);

        $this->assertSame('skipped', data_get($blocked, 'run.status'));
        $this->assertContains('provider_unsafe_output', data_get($blocked, 'run.policy_decision_json.reasons', []));
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

    public function test_executor_applies_safe_env_and_audits_output_truncation(): void
    {
        $this->installFakeBinary('rg', <<<'BASH'
printf 'mode=%s\n' "$ATLAS_TOOL_MODE"
printf '%*s' 1500 '' | tr ' ' A
BASH);

        Artisan::call('atlas:tools', [
            'action' => 'run',
            'tool' => 'ripgrep',
            '--workspace' => $this->workspace,
            '--command' => ['rg', '--version'],
            '--tool-env' => ['ATLAS_TOOL_MODE=fixture'],
            '--output-limit' => 1000,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);
        $runId = data_get($payload, 'run.id');
        $run = AtlasToolRun::query()->with('artifacts')->find($runId);
        $stdout = $run?->artifacts->firstWhere('type', 'stdout');

        $this->assertSame('passed', data_get($payload, 'run.status'));
        $this->assertSame(['ATLAS_TOOL_MODE'], data_get($payload, 'run.metadata_json.env_keys'));
        $this->assertSame(1000, data_get($payload, 'run.metadata_json.output_limit'));
        $this->assertTrue((bool) data_get($payload, 'run.metadata_json.stdout_truncated'));
        $this->assertNotNull($stdout);
        $this->assertStringContainsString('mode=fixture', (string) data_get($stdout?->preview_json, 'excerpt'));
        $this->assertLessThanOrEqual(1000, mb_strlen((string) data_get($stdout?->preview_json, 'excerpt')));
    }

    public function test_api_rejects_sensitive_tool_env_keys(): void
    {
        $this->postJson('/tools/ripgrep/run', [
            'workspace' => $this->workspace,
            'command' => ['rg', '--version'],
            'dry_run' => true,
            'env' => [
                'GITHUB_TOKEN' => 'ghp_should_not_be_allowed',
            ],
        ], $this->headers)
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Sensitive env key rejected.');
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

    public function test_gate_reports_stale_evidence_as_warning_or_blocking_failure(): void
    {
        $run = app(AtlasToolEvidenceStore::class)->recordExternalToolResult('ripgrep', $this->workspace, [
            'status' => 'passed',
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);
        $run?->forceFill([
            'created_at' => now()->subHours(3),
            'finished_at' => now()->subHours(3),
        ])->save();

        $warningPayload = $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=ripgrep&max_age_minutes=60', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'warning')
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('summary.stale_evidence_count', 1)
            ->assertJsonPath('warnings.0.reason', 'stale_evidence')
            ->assertJsonPath('freshness.max_age_minutes', 60)
            ->json();
        $this->assertGreaterThanOrEqual(179, data_get($warningPayload, 'runs.0.evidence_age_minutes'));

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=ripgrep&max_age_minutes=60&stale_blocks=1', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('allowed', false)
            ->assertJsonPath('blocking_failures.0.reason', 'stale_evidence_blocks');

        Artisan::call('atlas:tools', [
            'action' => 'gate',
            'tool' => 'ripgrep',
            '--workspace' => $this->workspace,
            '--max-age-minutes' => 60,
            '--stale-blocks' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('blocked', $payload['status'] ?? null);
        $this->assertSame('stale_evidence_blocks', data_get($payload, 'blocking_failures.0.reason'));
    }

    public function test_gate_can_evaluate_only_latest_evidence_per_tool(): void
    {
        $oldRun = app(AtlasToolEvidenceStore::class)->recordExternalToolResult('semgrep', $this->workspace, [
            'status' => 'failed',
            'exit_code' => 1,
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);
        $oldRun?->forceFill([
            'created_at' => now()->subHours(2),
            'finished_at' => now()->subHours(2),
        ])->save();

        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('semgrep', $this->workspace, [
            'status' => 'passed',
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=semgrep', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('summary.input_run_count', 2)
            ->assertJsonPath('summary.run_count', 2)
            ->assertJsonPath('blocking_failures.0.reason', 'tool_status_failed');

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=semgrep&latest_per_tool=1', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('summary.input_run_count', 2)
            ->assertJsonPath('summary.run_count', 1)
            ->assertJsonPath('selection.latest_per_tool', true);
    }

    public function test_gate_applies_authority_group_thresholds_to_findings(): void
    {
        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('semgrep', $this->workspace, [
            'status' => 'passed',
            'findings' => [[
                'rule_id' => 'semgrep.medium',
                'title' => 'Medium SAST finding',
                'severity' => 'medium',
                'file' => 'app/Medium.php',
                'line' => 10,
                'blocks_resolved' => false,
            ]],
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=semgrep', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'warning')
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('summary.blocking_failure_count', 0)
            ->assertJsonPath('warnings.0.reason', 'authority_sast_medium_finding')
            ->assertJsonPath('warnings.0.authority_group', 'semantic_sast')
            ->assertJsonPath('warnings.0.authority_policy', 'semantic_sast_critical_high_blocks_medium_warns');

        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('checkov', $this->workspace, [
            'status' => 'passed',
            'findings' => [[
                'rule_id' => 'CKV_ATLAS_1',
                'title' => 'High IaC finding',
                'severity' => 'high',
                'file' => 'infra/main.tf',
                'line' => 3,
                'blocks_resolved' => false,
            ]],
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=checkov', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('allowed', false)
            ->assertJsonPath('blocking_failures.0.reason', 'authority_iac_security')
            ->assertJsonPath('blocking_failures.0.authority_group', 'iac_security')
            ->assertJsonPath('blocking_failures.0.authority_policy', 'iac_security_critical_high_blocks_medium_warns');
    }

    public function test_gate_warns_instead_of_blocking_for_failed_non_blocking_recipe(): void
    {
        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('ripgrep', $this->workspace, [
            'status' => 'failed',
            'command' => ['rg', '--version'],
            'exit_code' => 1,
            'duration_ms' => 10,
        ], [
            'surface' => 'cli_recipe',
            'source' => 'test',
            'metadata' => [
                'recipe' => 'version',
                'recipe_category' => 'diagnostic',
                'recipe_recommended_surface' => 'manual_diagnostic',
                'recipe_creates_evidence' => true,
                'recipe_blocking_capable' => false,
            ],
        ]);

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace).'&tool_slug=ripgrep', $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'warning')
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('summary.run_count', 1)
            ->assertJsonPath('summary.blocking_failure_count', 0)
            ->assertJsonPath('summary.warning_count', 1)
            ->assertJsonPath('warnings.0.reason', 'non_blocking_recipe_failed');
    }

    public function test_gate_correlates_duplicate_blocking_findings_across_authority_group(): void
    {
        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('codeql', $this->workspace, [
            'status' => 'passed',
            'findings' => [[
                'rule_id' => 'codeql.sql-injection',
                'title' => 'SQL injection risk',
                'message' => 'User input reaches SQL query.',
                'severity' => 'high',
                'file' => 'app/Http/Controllers/SearchController.php',
                'line' => 42,
                'blocks_resolved' => true,
            ]],
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);

        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('semgrep', $this->workspace, [
            'status' => 'passed',
            'findings' => [[
                'rule_id' => 'php.lang.security.sql-injection',
                'title' => 'SQL injection risk',
                'message' => 'Potential SQL injection.',
                'severity' => 'high',
                'file' => 'app/Http/Controllers/SearchController.php',
                'line' => 42,
                'blocks_resolved' => true,
            ]],
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);

        $this->getJson('/tools/gate?workspace='.urlencode($this->workspace), $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('summary.run_count', 2)
            ->assertJsonPath('summary.blocking_failure_count', 1)
            ->assertJsonPath('summary.correlated_finding_group_count', 1)
            ->assertJsonPath('summary.suppressed_duplicate_finding_count', 1)
            ->assertJsonPath('finding_correlations.0.authority_group', 'semantic_sast')
            ->assertJsonPath('finding_correlations.0.authoritative_tool_slug', 'codeql');
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

        $passedGatePayload = $this->getJson('/tools/release-gate?workspace='.urlencode($this->workspace), $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('summary.release_requirement_failure_count', 0)
            ->json();

        $waivedSemgrepRun = collect($passedGatePayload['runs'] ?? [])
            ->firstWhere('tool_slug', 'semgrep');
        $this->assertSame(1, data_get($waivedSemgrepRun, 'waived_finding_count'));

        Artisan::call('atlas:tools', [
            'action' => 'release-gate',
            '--workspace' => $this->workspace,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame('passed', $payload['status'] ?? null);
        $this->assertTrue((bool) ($payload['allowed'] ?? false));
    }

    public function test_release_gate_consumes_direct_recipe_runs_from_recommended_surfaces(): void
    {
        $this->installFakeBinary('gitleaks', <<<'BASH'
echo '[]'
exit 0
BASH);
        $this->installFakeBinary('semgrep', <<<'BASH'
echo '{"results":[]}'
exit 0
BASH);
        $this->installFakeBinary('osv-scanner', <<<'BASH'
echo '{"results":[]}'
exit 0
BASH);
        $this->installFakeBinary('trivy', <<<'BASH'
echo '{"Results":[]}'
exit 0
BASH);
        $this->installFakeBinary('syft', <<<'BASH'
cat <<'JSON'
{
  "source": {"type": "directory", "name": "."},
  "artifacts": [
    {"name": "vendor/package", "version": "1.0.0", "type": "php-composer-package"}
  ]
}
JSON
exit 0
BASH);

        foreach ([
            ['gitleaks', 'detect-redacted', ['--approved' => true]],
            ['semgrep', 'scan-json', []],
            ['osv_scanner', 'recursive-json', ['--approved' => true]],
            ['trivy', 'fs-json', ['--approved' => true]],
            ['syft', 'sbom-json', []],
        ] as [$tool, $recipe, $extraOptions]) {
            Artisan::call('atlas:tools', [
                'action' => 'run-recipe',
                'tool' => $tool,
                '--recipe' => $recipe,
                '--workspace' => $this->workspace,
                '--json' => true,
                ...$extraOptions,
            ]);
            $payload = json_decode(Artisan::output(), true);

            $this->assertSame('passed', data_get($payload, 'run.status'), "Expected [{$tool}:{$recipe}] to pass.");
            $this->assertSame($recipe, data_get($payload, 'run.metadata_json.recipe'));
            $this->assertSame('cli_recipe', data_get($payload, 'run.metadata_json.execution_origin'));
        }

        $this->assertSame('engineering_quality_scan', AtlasToolRun::query()->where('tool_slug', 'gitleaks')->value('surface'));
        $this->assertSame('release_gate', AtlasToolRun::query()->where('tool_slug', 'syft')->value('surface'));
        $this->assertSame('release_gate', AtlasToolRun::query()->where('tool_slug', 'trivy')->value('surface'));

        $this->getJson('/tools/release-gate?workspace='.urlencode($this->workspace), $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('allowed', true)
            ->assertJsonPath('summary.release_requirement_failure_count', 0)
            ->assertJsonPath('release_requirements.3.satisfied', true);
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

    public function test_release_gate_blocks_stale_security_evidence_by_default(): void
    {
        foreach (['gitleaks', 'semgrep', 'trivy'] as $tool) {
            app(AtlasToolEvidenceStore::class)->recordExternalToolResult($tool, $this->workspace, [
                'status' => 'passed',
            ], [
                'surface' => 'engineering_quality_scan',
                'source' => 'test',
            ]);
        }
        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('syft', $this->workspace, [
            'status' => 'passed',
            'stdout' => json_encode([
                'source' => ['type' => 'directory', 'name' => '.'],
                'artifacts' => [
                    ['name' => 'vendor/package', 'version' => '1.0.0', 'type' => 'php-composer-package'],
                ],
            ]),
        ], [
            'surface' => 'release_gate',
            'source' => 'test',
        ]);
        AtlasToolRun::query()->update([
            'created_at' => now()->subDays(2),
            'finished_at' => now()->subDays(2),
        ]);

        $this->getJson('/tools/release-gate?workspace='.urlencode($this->workspace), $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'blocked')
            ->assertJsonPath('summary.stale_evidence_count', 4)
            ->assertJsonPath('blocking_failures.0.reason', 'stale_evidence_blocks')
            ->assertJsonPath('freshness.max_age_minutes', 1440);
    }

    public function test_release_gate_uses_latest_evidence_per_tool(): void
    {
        $oldSemgrepRun = app(AtlasToolEvidenceStore::class)->recordExternalToolResult('semgrep', $this->workspace, [
            'status' => 'failed',
            'exit_code' => 1,
        ], [
            'surface' => 'engineering_quality_scan',
            'source' => 'test',
        ]);
        $oldSemgrepRun?->forceFill([
            'created_at' => now()->subHours(3),
            'finished_at' => now()->subHours(3),
        ])->save();

        foreach (['gitleaks', 'semgrep', 'trivy'] as $tool) {
            app(AtlasToolEvidenceStore::class)->recordExternalToolResult($tool, $this->workspace, [
                'status' => 'passed',
            ], [
                'surface' => 'engineering_quality_scan',
                'source' => 'test',
            ]);
        }
        app(AtlasToolEvidenceStore::class)->recordExternalToolResult('syft', $this->workspace, [
            'status' => 'passed',
            'stdout' => json_encode([
                'source' => ['type' => 'directory', 'name' => '.'],
                'artifacts' => [
                    ['name' => 'vendor/package', 'version' => '1.0.0', 'type' => 'php-composer-package'],
                ],
            ]),
        ], [
            'surface' => 'release_gate',
            'source' => 'test',
        ]);

        $this->getJson('/tools/release-gate?workspace='.urlencode($this->workspace), $this->headers)
            ->assertOk()
            ->assertJsonPath('status', 'passed')
            ->assertJsonPath('summary.input_run_count', 5)
            ->assertJsonPath('summary.run_count', 4)
            ->assertJsonPath('selection.latest_per_tool', true);
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

    private function installWorkspaceBinary(string $name, string $scriptBody): void
    {
        $path = $this->workspace.'/'.$name;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, "#!/usr/bin/env bash\n{$scriptBody}\n");
        chmod($path, 0755);
    }

    private function createTables(): void
    {
        Schema::create('atlas_ledger_events', function (Blueprint $table): void {
            $table->string('event_id', 32)->primary();
            $table->string('schema_version', 40)->default('atlas.ledger_event.v1');
            $table->string('tenant_id', 120)->index();
            $table->string('operator_id', 120)->index();
            $table->string('envelope_id', 80)->index();
            $table->string('receipt_id', 80)->nullable()->index();
            $table->uuid('trace_id')->nullable()->index();
            $table->string('correlation_id', 120)->index();
            $table->string('causation_id', 80)->nullable()->index();
            $table->string('event_type', 80)->index();
            $table->string('emitter_stage', 120)->index();
            $table->string('emitter_version', 80);
            $table->json('payload');
            $table->string('payload_hash', 64)->index();
            $table->timestampTz('occurred_at')->index();
            $table->timestampsTz();
        });

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
            $table->string('execution_tier')->default('T1');
            $table->string('expected_cost')->default('local_fast');
            $table->string('default_trigger')->default('manual_or_policy');
            $table->string('authority_role')->default('primary');
            $table->string('authority_group')->nullable();
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
