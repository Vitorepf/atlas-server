<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopKeepaliveCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 24h+ sem intervenção: a STARVATION de fila é a única parada PERMANENTE do loop (o
 * supervisor sai com queue_starved → status=completed → o respawn-de-morte nunca o pega).
 * Mas o loop mergeia código em main → novos alvos surgem → reviver periodicamente acha
 * trabalho. Estes testes congelam a SELEÇÃO segura do revival: revive só soaks REAIS,
 * RECENTES, com budget, fora do cooldown — nunca acorda campanha-teste, antiga ou viva.
 */
final class AtlasLoopKeepaliveReviveStarvedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        config(['atlas.loop.keepalive_revive_starved' => true, 'atlas.loop.keepalive_starved_revive_minutes' => 20]);
    }

    private function seedCampaign(array $over): string
    {
        $id = (string) Str::uuid();
        DB::table('atlas_loop_campaigns')->insert(array_merge([
            'id' => $id,
            'schema_version' => 'atlas.loop.campaign.v1',
            'goal' => 'starve-test',
            'status' => 'completed',
            'stop_reason' => 'queue_starved_no_refill',
            'max_seconds' => 604800,
            'elapsed_seconds' => 1000,
            'kill_switch' => false,
            'config' => '{}',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subMinutes(40),
        ], $over));

        return $id;
    }

    /** Keepalive com respawn/alive stubados (não shella nada; processo nunca "vivo"). */
    private function runKeepalive(): array
    {
        $cmd = new class extends AtlasLoopKeepaliveCommand
        {
            public array $respawned = [];

            protected function respawn(string $campaignId): void
            {
                $this->respawned[] = $campaignId;
            }

            protected function supervisorAlive(string $campaignId): bool
            {
                return false;
            }
        };
        $cmd->setLaravel(app());
        $captured = [];
        $cmd->run(new \Symfony\Component\Console\Input\ArrayInput(['--json' => true]), new class($captured) extends \Symfony\Component\Console\Output\Output {
            public function __construct(private array &$cap) { parent::__construct(); }
            protected function doWrite(string $message, bool $newline): void { $this->cap[] = $message; }
        });

        return [$cmd, implode("\n", $captured)];
    }

    public function test_real_recent_starved_campaign_is_revived(): void
    {
        $id = $this->seedCampaign(['updated_at' => now()->subMinutes(40)]); // fora do cooldown de 20min

        [$cmd, $json] = $this->runKeepalive();

        $this->assertContains($id, $cmd->respawned, 'soak real starved é revivido');
        $this->assertSame('running', DB::table('atlas_loop_campaigns')->where('id', $id)->value('status'), 'status volta a running');
    }

    public function test_test_sized_campaign_is_NOT_revived(): void
    {
        // Campanha-teste (budget pequeno) jamais é acordada pelo keepalive.
        $id = $this->seedCampaign(['max_seconds' => 60, 'elapsed_seconds' => 10, 'updated_at' => now()->subMinutes(40)]);

        [$cmd] = $this->runKeepalive();

        $this->assertNotContains($id, $cmd->respawned, 'campanha-teste (max<1h) não revive');
    }

    public function test_ancient_campaign_is_NOT_revived(): void
    {
        $id = $this->seedCampaign(['updated_at' => now()->subDays(5)]); // > 48h = artefato antigo

        [$cmd] = $this->runKeepalive();

        $this->assertNotContains($id, $cmd->respawned, 'campanha antiga (>48h) não é acordada');
    }

    public function test_campaign_within_cooldown_is_NOT_revived(): void
    {
        $id = $this->seedCampaign(['updated_at' => now()->subMinutes(5)]); // dentro do cooldown de 20min

        [$cmd] = $this->runKeepalive();

        $this->assertNotContains($id, $cmd->respawned, 'dentro do cooldown não revive (anti-thrash)');
    }

    public function test_over_budget_campaign_is_NOT_revived(): void
    {
        $id = $this->seedCampaign(['elapsed_seconds' => 604800, 'updated_at' => now()->subMinutes(40)]);

        [$cmd] = $this->runKeepalive();

        $this->assertNotContains($id, $cmd->respawned, 'sem budget não revive');
    }

    public function test_respawn_contract_uses_configured_php_and_literal_campaign_pattern(): void
    {
        config([
            'atlas.cli.php_binary' => '/opt/homebrew/bin/php',
            'atlas.cli.php_binary_candidates' => [PHP_BINARY],
        ]);

        $cmd = new class extends AtlasLoopKeepaliveCommand
        {
            public function exposedPhpBinary(): string
            {
                return $this->phpBinary();
            }

            public function exposedSupervisorPattern(string $campaignId): string
            {
                return $this->supervisorPattern($campaignId);
            }
        };
        $cmd->setLaravel(app());

        $pattern = $cmd->exposedSupervisorPattern('abc.def[123]');
        $this->assertSame('/opt/homebrew/bin/php', $cmd->exposedPhpBinary());
        $this->assertSame('atlas:loop:campaign.*abc\\.def\\[123\\]', $pattern);
        $this->assertMatchesRegularExpression('/'.$pattern.'/', 'php artisan atlas:loop:campaign --campaign-id=abc.def[123]');
        $this->assertDoesNotMatchRegularExpression('/'.$pattern.'/', 'php artisan atlas:loop:campaign --campaign-id=abcXdef3');
    }
}
