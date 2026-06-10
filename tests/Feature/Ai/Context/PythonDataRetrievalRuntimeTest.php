<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasPythonDataRetrievalRuntimeService;
use App\Services\Ai\Programming\ProgrammingPythonRuntimePolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class PythonDataRetrievalRuntimeTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-apdr-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace.'/app');
        File::put($this->workspace.'/app/Service.php', "<?php\nclass Service { public function run(): void {} }\n");
        File::put($this->workspace.'/tool.py', "import json\nclass Worker:\n    def run(self):\n        return json.dumps({})\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_manifest_only_request_is_ready_and_provider_safe(): void
    {
        $payload = app(AtlasPythonDataRetrievalRuntimeService::class)->run([
            'workspace' => $this->workspace,
            'files' => ['app/Service.php', 'tool.py'],
        ]);

        $this->assertSame('atlas.aucri.python_data_runtime.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(AtlasPythonDataRetrievalRuntimeService::REQUEST_SCHEMA, data_get($payload, 'request.schema_version'));
        $this->assertSame('atlas.runtime_invocation_contract.v1', data_get($payload, 'request.runtime_invocation_contract.schema_version'));
        $this->assertSame('python_ai_data', data_get($payload, 'request.runtime_invocation_contract.selected_runtime_family'));
        $this->assertFalse(data_get($payload, 'request.runtime_policy.provider_calls_allowed'));
        $this->assertFalse(data_get($payload, 'request.runtime_policy.network_calls_allowed'));
        $this->assertFalse(data_get($payload, 'claim_policy.providers_invoked'));
        $this->assertFalse(data_get($payload, 'claim_policy.network_invoked'));
        $this->assertFalse(data_get($payload, 'claim_policy.memory_writes'));
        $this->assertNull($payload['execution_receipt']);
        $this->assertNull($payload['graph_fragment']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['runtime_hash']);

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($this->workspace, $json);
    }

    public function test_execution_blocks_without_approval_and_decision_receipt(): void
    {
        $payload = app(AtlasPythonDataRetrievalRuntimeService::class)->run([
            'workspace' => $this->workspace,
            'files' => ['app/Service.php'],
            'execute' => true,
            'approved' => false,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(AtlasPythonDataRetrievalRuntimeService::EXECUTION_RECEIPT_SCHEMA, data_get($payload, 'execution_receipt.schema_version'));
        $this->assertSame('blocked', data_get($payload, 'execution_receipt.gate.status'));
        $this->assertContains('approval_required', data_get($payload, 'execution_receipt.gate.reasons'));
        $this->assertContains('decision_receipt_hash_required', data_get($payload, 'execution_receipt.gate.reasons'));
        $this->assertNull($payload['graph_fragment']);
    }

    public function test_runtime_policy_helper_blocks_any_external_capability(): void
    {
        $policy = app(ProgrammingPythonRuntimePolicy::class);
        $safePolicy = $policy->manifestPolicy();

        $this->assertTrue($policy->isSafe($safePolicy));

        foreach (['provider_calls_allowed', 'shell_calls_allowed', 'network_calls_allowed', 'memory_writes_allowed'] as $flag) {
            $unsafePolicy = $safePolicy;
            $unsafePolicy[$flag] = true;

            $this->assertFalse($policy->isSafe($unsafePolicy), $flag);
        }
    }

    public function test_approved_execution_returns_graph_fragment_without_raw_source(): void
    {
        $payload = app(AtlasPythonDataRetrievalRuntimeService::class)->run([
            'workspace' => $this->workspace,
            'files' => ['app/Service.php', 'tool.py'],
            'execute' => true,
            'approved' => true,
            'decision_receipt_hash' => str_repeat('a', 64),
            'runtime_boundary_green' => true,
        ]);

        $this->assertSame('ready', $payload['status']);
        $this->assertSame(AtlasPythonDataRetrievalRuntimeService::EXECUTION_RECEIPT_SCHEMA, data_get($payload, 'execution_receipt.schema_version'));
        $this->assertSame('passed', data_get($payload, 'execution_receipt.status'));
        $this->assertSame(2, data_get($payload, 'execution_receipt.file_count'));
        $this->assertSame(AtlasPythonDataRetrievalRuntimeService::GRAPH_FRAGMENT_SCHEMA, data_get($payload, 'graph_fragment.schema_version'));
        $this->assertGreaterThanOrEqual(2, data_get($payload, 'graph_fragment.node_count'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'graph_fragment.edge_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', data_get($payload, 'graph_fragment.fragment_hash'));

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('public function run', $json);
        $this->assertStringNotContainsString('return json.dumps', $json);
    }

    public function test_command_emits_canonical_json(): void
    {
        $exitCode = Artisan::call('atlas:context:python-data', [
            '--workspace' => $this->workspace,
            '--file' => ['app/Service.php'],
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('atlas.aucri.python_data_runtime.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(['app/Service.php'], data_get($payload, 'request.files'));
    }
}
