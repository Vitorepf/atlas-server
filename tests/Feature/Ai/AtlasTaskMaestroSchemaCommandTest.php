<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaMigrator;
use App\Services\Ai\SelfConstruction\Maestro\PacketEvolution\AtlasMaestroPacketSchemaVersioning;
use Illuminate\Support\Facades\Config;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

class AtlasTaskMaestroSchemaCommandTest extends TestCase
{
    private string $statePath = '';

    private string $tmpInput = '';

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(4));
        $this->statePath = sys_get_temp_dir().'/atlas-maestro-schema-state-'.$tag.'.json';
        $this->tmpInput = sys_get_temp_dir().'/atlas-maestro-schema-input-'.$tag.'.json';
        Config::set('atlas.maestro.packet_schema.state_path', $this->statePath);
        Config::set('atlas.maestro.packet_schema.deprecate_token', 'operator-confirm');

        $migrator = new AtlasMaestroPacketSchemaMigrator(new AtlasMaestroPacketSchemaVersioning([
            [
                'id' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
                'version' => 1,
                'status' => AtlasMaestroPacketSchemaVersioning::STATUS_ACTIVE,
                'required_fields' => ['task_packet_id'],
                'additive_fields' => [],
                'removed_fields' => [],
                'introduced_at' => '2025-12-01',
                'successor' => 'atlas.self_construction.agent_control_plane_task_packet.v2',
            ],
            [
                'id' => 'atlas.self_construction.agent_control_plane_task_packet.v2',
                'version' => 2,
                'status' => AtlasMaestroPacketSchemaVersioning::STATUS_ACTIVE,
                'required_fields' => ['task_packet_id', 'evolution_hints'],
                'additive_fields' => ['evolution_hints'],
                'removed_fields' => [],
                'introduced_at' => '2026-06-25',
                'successor' => null,
            ],
        ]), static fn (): string => '2026-06-25T00:00:00Z');
        $migrator->registerTransform(
            AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            'atlas.self_construction.agent_control_plane_task_packet.v2',
            'add_evolution_hints',
            static function (array $packet): array {
                $packet['evolution_hints'] = ['default_strategy' => 'forward_only'];

                return $packet;
            },
        );
        app()->instance(AtlasMaestroPacketSchemaMigrator::class, $migrator);
    }

    protected function tearDown(): void
    {
        @unlink($this->statePath);
        @unlink($this->tmpInput);
        parent::tearDown();
    }

    private function runCmd(array $params): array
    {
        $buf = new BufferedOutput();
        /** @var \Illuminate\Contracts\Console\Kernel $kernel */
        $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
        $exit = $kernel->call('atlas:task:maestro:schema', $params, $buf);

        return ['exit' => $exit, 'output' => trim($buf->fetch())];
    }

    public function test_versions_action_lists_v1_id_as_json(): void
    {
        $r = $this->runCmd(['action' => 'versions', '--json' => true]);

        self::assertSame(0, $r['exit']);
        $payload = json_decode($r['output'], true);
        self::assertIsArray($payload);
        $ids = array_column($payload['versions'], 'id');
        self::assertContains(AtlasMaestroPacketSchemaVersioning::CANONICAL_V1, $ids);
    }

    public function test_migrate_action_emits_v2_packet_with_one_hop(): void
    {
        $v1 = [
            'schema_version' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            'task_packet_id' => 'pk-1',
            'objective' => 'noop',
        ];
        file_put_contents($this->tmpInput, json_encode($v1));

        $r = $this->runCmd([
            'action' => 'migrate',
            '--to' => 'atlas.self_construction.agent_control_plane_task_packet.v2',
            '--input' => $this->tmpInput,
        ]);

        self::assertSame(0, $r['exit'], 'migrate must succeed: '.$r['output']);
        $payload = json_decode($r['output'], true);
        self::assertIsArray($payload);
        self::assertSame('atlas.self_construction.agent_control_plane_task_packet.v2', $payload['schema_version']);
        self::assertCount(1, $payload['migration_trail']);
    }

    public function test_migrate_with_unknown_target_exits_non_zero_with_exception_message(): void
    {
        $v1 = ['schema_version' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1, 'task_packet_id' => 'pk-x'];
        file_put_contents($this->tmpInput, json_encode($v1));

        $r = $this->runCmd([
            'action' => 'migrate',
            '--to' => 'never.heard.v99',
            '--input' => $this->tmpInput,
        ]);

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('UnknownSchemaVersionException', $r['output']);
    }

    public function test_deprecate_without_token_is_refused_and_state_is_not_mutated(): void
    {
        $r = $this->runCmd([
            'action' => 'deprecate',
            '--from' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            '--reason' => 'test',
        ]);

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('deprecate_refused', $r['output']);
        self::assertFileDoesNotExist($this->statePath);
    }

    public function test_deprecate_with_valid_token_then_versions_reports_deprecated(): void
    {
        $r = $this->runCmd([
            'action' => 'deprecate',
            '--from' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            '--reason' => 'rolled-forward-to-v2',
            '--token' => 'operator-confirm',
            '--json' => true,
        ]);

        self::assertSame(0, $r['exit'], 'deprecate with valid token must succeed: '.$r['output']);
        self::assertFileExists($this->statePath);

        // Drop the test-bound versioning so the next command rebuilds with the persisted overrides.
        if (app()->bound(AtlasMaestroPacketSchemaVersioning::class)) {
            app()->forgetInstance(AtlasMaestroPacketSchemaVersioning::class);
        }

        $r2 = $this->runCmd(['action' => 'versions', '--json' => true]);
        self::assertSame(0, $r2['exit']);
        $payload = json_decode($r2['output'], true);
        $byId = [];
        foreach ($payload['versions'] as $row) {
            $byId[$row['id']] = $row;
        }
        self::assertSame('deprecated', $byId[AtlasMaestroPacketSchemaVersioning::CANONICAL_V1]['status']);
    }

    public function test_unknown_action_exits_non_zero_with_usage_message(): void
    {
        $r = $this->runCmd(['action' => 'bogus']);

        self::assertNotSame(0, $r['exit']);
        self::assertStringContainsString('unknown_action', $r['output']);
    }

    public function test_migrate_json_without_to_emits_refused_json(): void
    {
        $r = $this->runCmd(['action' => 'migrate', '--input' => $this->tmpInput, '--json' => true]);

        self::assertSame(2, $r['exit']);
        $p = json_decode($r['output'], true);
        self::assertSame('refused', $p['status']);
        self::assertSame('to_option_missing', $p['reason']);
    }

    public function test_migrate_json_with_missing_input_emits_refused_json(): void
    {
        $r = $this->runCmd(['action' => 'migrate', '--to' => 'v2', '--json' => true]);

        self::assertSame(2, $r['exit']);
        $p = json_decode($r['output'], true);
        self::assertSame('refused', $p['status']);
        self::assertSame('input_option_missing', $p['reason']);
    }

    public function test_migrate_json_with_invalid_json_input_emits_refused_json(): void
    {
        file_put_contents($this->tmpInput, 'not-json');
        $r = $this->runCmd(['action' => 'migrate', '--to' => 'atlas.self_construction.agent_control_plane_task_packet.v2', '--input' => $this->tmpInput, '--json' => true]);

        self::assertSame(2, $r['exit']);
        $p = json_decode($r['output'], true);
        self::assertSame('refused', $p['status']);
        self::assertSame('input_not_valid_json', $p['reason']);
    }

    public function test_migrate_json_with_unknown_target_emits_refused_json(): void
    {
        file_put_contents($this->tmpInput, json_encode(['schema_version' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1, 'task_packet_id' => 'pk-z']));
        $r = $this->runCmd(['action' => 'migrate', '--to' => 'never.heard.v99', '--input' => $this->tmpInput, '--json' => true]);

        self::assertSame(2, $r['exit']);
        $p = json_decode($r['output'], true);
        self::assertSame('refused', $p['status']);
        self::assertStringContainsString('UnknownSchemaVersionException', $p['reason']);
    }

    public function test_deprecate_json_refusal_emits_refused_json(): void
    {
        $r = $this->runCmd([
            'action' => 'deprecate',
            '--from' => AtlasMaestroPacketSchemaVersioning::CANONICAL_V1,
            '--reason' => 'test',
            '--json' => true,
        ]);

        self::assertSame(2, $r['exit']);
        $p = json_decode($r['output'], true);
        self::assertSame('refused', $p['status']);
        self::assertStringContainsString('deprecate_refused', $p['reason']);
    }
}
