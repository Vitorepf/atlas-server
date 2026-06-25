<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Console\Commands\AtlasLoopConflictCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\ConflictResolution\AtlasLoopCycleConflictDetector;
use App\Services\Ai\AutonomousEvolution\ConflictResolution\AtlasLoopCycleConflictResolver;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopConflictCommandTest extends TestCase
{
    private string $envPath = '';

    /** @var array<string,array<string,mixed>> */
    private array $cycles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->envPath = sys_get_temp_dir().'/atlas-conflict-env-'.bin2hex(random_bytes(6));
        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;
        $this->masterOn();

        $self = $this;
        $this->app->instance(
            AtlasLoopConflictCommand::CYCLE_SOURCE_BINDING,
            static fn (string $id): ?array => $self->cycles[$id] ?? null,
        );
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->envPath);
        parent::tearDown();
    }

    private function masterOn(): void
    {
        file_put_contents($this->envPath, 'ATLAS_LOOP_MASTER_ENABLED=true'.PHP_EOL);
    }

    private function masterOff(): void
    {
        file_put_contents($this->envPath, 'ATLAS_LOOP_MASTER_ENABLED=false'.PHP_EOL);
    }

    private function cycle(string $id, array $files): array
    {
        return ['cycle_id' => $id, 'files' => $files];
    }

    public function test_detect_emits_conflict_report_with_overlap_modes_and_no_score_or_winner_keys(): void
    {
        $this->cycles = [
            'A' => $this->cycle('A', [
                ['path' => 'app/Shared.php', 'content_hash' => 'aaa', 'byte_range' => [10, 50]],
            ]),
            'B' => $this->cycle('B', [
                ['path' => 'app/Shared.php', 'content_hash' => 'aaa', 'byte_range' => [10, 50]],
            ]),
        ];

        $exit = Artisan::call('atlas:loop:conflict', [
            'action' => 'detect',
            '--cycle-a' => 'A',
            '--cycle-b' => 'B',
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);

        // Overlap report carries per-file mode FACTS.
        $this->assertArrayHasKey('overlapping_paths', $payload);
        $this->assertArrayHasKey('per_file', $payload);
        foreach (['score', 'winner', 'verdict', 'rank'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload, 'forbidden FACT-violation key: '.$forbidden);
        }
    }

    public function test_resolve_on_divergent_bytes_exits_nonzero_with_clause_r_div(): void
    {
        $this->cycles = [
            'A' => $this->cycle('A', [
                ['path' => 'app/Shared.php', 'content_hash' => 'aaa', 'byte_range' => [10, 50]],
            ]),
            'B' => $this->cycle('B', [
                ['path' => 'app/Shared.php', 'content_hash' => 'bbb', 'byte_range' => [20, 40]],
            ]),
        ];

        $exit = Artisan::call('atlas:loop:conflict', [
            'action' => 'resolve',
            '--cycle-a' => 'A',
            '--cycle-b' => 'B',
            '--json' => true,
        ]);
        $this->assertNotSame(0, $exit, 'divergent-bytes must REFUSE with non-zero exit');
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame('R-DIV', $payload['clause']);
        $this->assertNotEmpty($payload['receipt_ids']);
    }

    public function test_resolve_on_identical_bytes_exits_zero_with_clause_r_ident(): void
    {
        $this->cycles = [
            'A' => $this->cycle('A', [
                ['path' => 'app/Shared.php', 'content_hash' => 'aaa', 'byte_range' => [10, 50]],
            ]),
            'B' => $this->cycle('B', [
                ['path' => 'app/Shared.php', 'content_hash' => 'aaa', 'byte_range' => [10, 50]],
            ]),
        ];

        $exit = Artisan::call('atlas:loop:conflict', [
            'action' => 'resolve',
            '--cycle-a' => 'A',
            '--cycle-b' => 'B',
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);
        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertSame('R-IDENT', $payload['clause']);
    }

    public function test_master_switch_off_short_circuits_for_every_subcommand(): void
    {
        $this->masterOff();

        // Seed a divergent-bytes pair so a NON-no-op detect/resolve would clearly produce a
        // ConflictReport / R-DIV refusal. The master-off short-circuit MUST suppress those.
        $this->cycles = [
            'A' => $this->cycle('A', [['path' => 'app/X.php', 'content_hash' => 'aaa', 'byte_range' => [10, 50]]]),
            'B' => $this->cycle('B', [['path' => 'app/X.php', 'content_hash' => 'bbb', 'byte_range' => [10, 50]]]),
        ];

        foreach (['detect', 'resolve', 'history'] as $action) {
            $exit = Artisan::call('atlas:loop:conflict', [
                'action' => $action,
                '--cycle-a' => 'A',
                '--cycle-b' => 'B',
                '--json' => true,
            ]);
            $this->assertSame(0, $exit, 'master-off must exit 0 for '.$action);
            $payload = json_decode(trim(Artisan::output()), true);
            $this->assertSame('no_op', $payload['status']);
            $this->assertSame('master_switch_off', $payload['reason']);
            // Detector/Resolver outputs would carry these keys; their absence proves the short-circuit.
            $this->assertArrayNotHasKey('overlapping_paths', $payload);
            $this->assertArrayNotHasKey('clause', $payload);
        }
    }

    public function test_history_filters_by_clause(): void
    {
        $path = sys_get_temp_dir().'/atlas-conflict-history-'.bin2hex(random_bytes(4)).'.jsonl';
        $this->app->instance(AtlasLoopConflictCommand::RECEIPT_STORE_PATH_BINDING, $path);
        try {
            $rows = [
                ['policy_clause' => 'R-DIV', 'file_path' => 'a.php'],
                ['policy_clause' => 'R-IDENT', 'file_path' => 'b.php'],
                ['policy_clause' => 'R-STITCH', 'file_path' => 'c.php'],
                ['policy_clause' => 'OTHER', 'file_path' => 'd.php'],
            ];
            foreach ($rows as $r) {
                file_put_contents($path, json_encode($r).PHP_EOL, FILE_APPEND);
            }

            $exit = Artisan::call('atlas:loop:conflict', [
                'action' => 'history',
                '--clause' => 'R-DIV',
                '--json' => true,
            ]);
            $this->assertSame(0, $exit);
            $payload = json_decode(trim(Artisan::output()), true);
            $this->assertCount(1, $payload['rows']);
            $this->assertSame('R-DIV', $payload['rows'][0]['policy_clause']);
        } finally {
            @unlink($path);
        }
    }
}
