<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Maestro\Personalization\AtlasMaestroWorkerPreferenceRegistry;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasTaskMaestroPrefsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        app()->instance(AtlasMaestroWorkerPreferenceRegistry::class, new AtlasMaestroWorkerPreferenceRegistry([
            'codex' => ['max_files' => 10, 'max_loc' => 2000, 'tier' => 'multi_file_large'],
            'sonnet' => ['max_files' => 2, 'max_loc' => 200, 'tier' => 'small_scope'],
        ]));
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:task:maestro:prefs', $params, $buf);

        return ['exit' => $exit, 'output' => trim($buf->fetch())];
    }

    public function test_inspect_emits_envelope_with_expected_schema_and_preferences(): void
    {
        $r = $this->runCmd(['sub' => 'inspect', '--client' => 'codex', '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertSame('atlas.maestro.personalization.v1', $payload['schema']);
        self::assertSame(10, $payload['preferences']['max_files']);
        self::assertSame('multi_file_large', $payload['preferences']['tier']);
    }

    public function test_policy_returns_advisory_and_shape_match_float(): void
    {
        $packet = json_encode(['task_packet_id' => 'X', 'allowed_files' => ['a.php', 'b.php'], 'loc_estimate' => 150, 'tier_hint' => 'small_scope']);
        $r = $this->runCmd(['sub' => 'policy', '--client' => 'sonnet', '--packet' => $packet, '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertSame('atlas.maestro.personalization.v1', $payload['schema']);
        self::assertTrue($payload['verdict']['advisory']);
        self::assertIsNumeric($payload['verdict']['shape_match']);
        self::assertGreaterThanOrEqual(0.0, (float) $payload['verdict']['shape_match']);
        self::assertLessThanOrEqual(1.0, (float) $payload['verdict']['shape_match']);
    }

    public function test_register_mutates_in_process_registry(): void
    {
        $r = $this->runCmd(['sub' => 'register', '--client' => 'newbie', '--max-files' => 4, '--max-loc' => 400, '--tier' => 'midline', '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertSame('atlas.maestro.personalization.v1', $payload['schema']);
        self::assertSame('newbie', $payload['client_id']);
        self::assertSame(4, $payload['preferences']['max_files']);
        self::assertSame('midline', $payload['preferences']['tier']);
    }

    public function test_unknown_sub_returns_status_with_failure_exit(): void
    {
        $r = $this->runCmd(['sub' => 'reset']);

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_sub', $r['output']);
    }

    public function test_missing_client_returns_missing_client_without_throwing(): void
    {
        $r = $this->runCmd(['sub' => 'register']);

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('missing_client', $r['output']);
    }
}
