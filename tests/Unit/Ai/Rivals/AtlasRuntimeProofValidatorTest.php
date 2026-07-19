<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Support\AtlasRuntimeProofValidator;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasRuntimeProofValidatorTest extends TestCase
{
    private string $runDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->runDir = sys_get_temp_dir().'/rivals_proof_validator_'.uniqid();
        File::ensureDirectoryExists($this->runDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->runDir);
        parent::tearDown();
    }

    public function test_v2_proof_requires_readable_stdout_and_stderr_for_every_call(): void
    {
        $proof = $this->proof();
        $validator = new AtlasRuntimeProofValidator;

        $this->assertFalse($validator->valid($proof, 'kimi-k2.7', $this->runDir));

        foreach (['stdout_path', 'stderr_path'] as $field) {
            $path = $this->runDir.'/'.$proof['calls'][0][$field];
            File::ensureDirectoryExists(dirname($path));
            file_put_contents($path, "atlas.rivals2.captured_stream.v1\n");
        }

        $this->assertTrue($validator->valid($proof, 'kimi-k2.7', $this->runDir));
    }

    /** @return array<string,mixed> */
    private function proof(): array
    {
        return [
            'schema_version' => AtlasRuntimeProofValidator::SCHEMA_V2,
            'status' => 'passed',
            'failure_reason' => null,
            'real_provider' => true,
            'provider' => 'hermes_cli',
            'model' => 'kimi-k2.7',
            'execution_id' => 'ne_validator',
            'runtime_contract' => 'atlas.hermes_cli_provider.v1',
            'fair_mode' => [
                'single_provider' => true,
                'decide_disabled' => true,
                'fallback_disabled' => true,
            ],
            'governance' => [
                'atlas_is_sovereign' => true,
                'executive_mission_schema' => 'atlas.hermes.executive_mission.v1',
                'result_packet_schema' => 'atlas.hermes.result_packet.v1',
                'safe_mode' => true,
            ],
            'provider_call' => [
                'provider_calls' => 1,
                'successful_responses' => 1,
                'error_codes' => [],
            ],
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 20,
                'cost_usd' => 0.0,
                'present' => true,
            ],
            'calls' => [[
                'sequence' => 1,
                'status' => 'passed',
                'failure_reason' => null,
                'input_tokens' => 100,
                'output_tokens' => 20,
                'stdout_path' => 'native_scratch/ne_validator/call-0001.stdout.log',
                'stderr_path' => 'native_scratch/ne_validator/call-0001.stderr.log',
                'executive_mission_hash' => hash('sha256', 'mission'),
                'result_packet_hash' => hash('sha256', 'result'),
            ]],
        ];
    }
}
