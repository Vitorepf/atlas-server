<?php

declare(strict_types=1);

namespace Tests\Feature\AtlasCode;

use App\Services\AtlasCode\VerificationCommandRunner;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class VerificationCommandRunnerTest extends TestCase
{
    private string $workspace = '';

    private function headers(): array
    {
        return [
            'Accept' => 'application/json',
            'X-Atlas-Token' => 'testing-atlas-token-with-enough-length',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/atlas-test-verify-'.Str::random(8);
        File::ensureDirectoryExists($this->workspace);
        config()->set('atlas_code_verification.evidence_dir', sys_get_temp_dir().'/atlas-test-evidence-'.Str::random(8));
        config()->set('atlas_code_verification.execute_enabled', false);
        if (! Schema::hasTable('atlas_projects')) {
            Schema::create('atlas_projects', function (Blueprint $t) {
                $t->uuid('id')->primary();
                $t->string('title')->nullable();
                $t->text('description')->nullable();
                $t->string('status')->default('active');
                $t->string('domain')->default('atlas');
                $t->text('goal')->nullable();
                $t->string('priority')->default('medium');
                $t->json('metadata')->nullable();
                $t->timestamps();
                $t->softDeletes();
            });
        }
    }

    protected function tearDown(): void
    {
        if ($this->workspace !== '' && is_dir($this->workspace)) {
            File::deleteDirectory($this->workspace);
        }
        $dir = (string) config('atlas_code_verification.evidence_dir');
        if ($dir !== '' && is_dir($dir)) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    public function test_dry_run_returns_canonical_packet_without_executing(): void
    {
        $runner = new VerificationCommandRunner();
        $result = $runner->run('obra-x', 'os-y', 'pnpm test', $this->workspace, 'dry_run');

        $this->assertSame('atlas.code.verification_run.v1', $result['schema_version']);
        $this->assertSame('dry_run', $result['status']);
        $this->assertNotNull($result['allowlist_match']);
        $this->assertNull($result['exit_code']);
    }

    public function test_execute_blocked_when_command_not_in_allowlist(): void
    {
        config()->set('atlas_code_verification.execute_enabled', true);
        $runner = new VerificationCommandRunner();
        $result = $runner->run('obra-x', 'os-y', 'rm -rf /', $this->workspace, 'execute', 'fake-token');

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('command_not_in_allowlist', $result['reason']);
    }

    public function test_execute_blocked_when_execute_flag_off(): void
    {
        config()->set('atlas_code_verification.execute_enabled', false);
        $runner = new VerificationCommandRunner();
        $result = $runner->run('obra-x', 'os-y', 'pnpm test', $this->workspace, 'execute', 'token');

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('execute_disabled_by_config', $result['reason']);
    }

    public function test_execute_blocked_when_workspace_missing(): void
    {
        $runner = new VerificationCommandRunner();
        $result = $runner->run('obra-x', 'os-y', 'pnpm test', '/tmp/atlas-nonexistent-'.bin2hex(random_bytes(4)), 'dry_run');

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('workspace_path_missing_or_unreadable', $result['reason']);
    }

    public function test_endpoint_returns_dry_run_by_default(): void
    {
        // Set up minimal Obra + Observed session via service (filesystem mode).
        $obraId = (string) Str::uuid();
        DB::table('atlas_projects')->insert([
            'id' => $obraId,
            'title' => 'Obra verify',
            'description' => 'intent',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => 'goal',
            'priority' => 'medium',
            'metadata' => json_encode([
                'origin' => 'atlas-code',
                'workspace_slug' => 'atlas',
                'workspace_path' => $this->workspace,
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $packetId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/work-packets", [
            'objective' => 'verify',
            'acceptance_criteria' => ['ok'],
        ])->json('packet.id');
        $sessionId = $this->withHeaders($this->headers())->postJson("/atlas-code/works/{$obraId}/observed-sessions", [
            'work_packet_id' => $packetId,
            'provider_id' => 'claude_code',
        ])->json('session.id');

        $response = $this->withHeaders($this->headers())->postJson(
            "/atlas-code/works/{$obraId}/observed-sessions/{$sessionId}/verification-runs",
            ['command' => 'pnpm test']
        );
        $response->assertOk()
            ->assertJsonPath('verification_run.status', 'dry_run')
            ->assertJsonPath('verification_run.schema_version', 'atlas.code.verification_run.v1');
    }
}
