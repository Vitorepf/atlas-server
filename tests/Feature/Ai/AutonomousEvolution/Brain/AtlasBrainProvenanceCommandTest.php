<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainProvenanceLedger;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasBrainProvenanceCommandTest extends TestCase
{
    public function test_provenance_tails_ledger_with_source_filter(): void
    {
        $root = sys_get_temp_dir().'/atlas-brain-prov-cmd-'.bin2hex(random_bytes(4));
        config()->set('atlas.brain.provenance_root', $root);
        config()->set('atlas.brain.scopes.loop', ['label' => 't', 'roots' => [], 'docs_roots' => [], 'meta_harness' => true]);
        config()->set('atlas.brain.default_scope', 'loop');

        $ledger = new AtlasBrainProvenanceLedger($root);
        $ledger->append('loop', ['cycle_id' => 'c1', 'source_finding' => 'frontier_empty'], 1);
        $ledger->append('loop', ['cycle_id' => 'c2', 'source_finding' => 'gate_regression'], 2);
        $ledger->append('loop', ['cycle_id' => 'c3', 'source_finding' => 'frontier_empty'], 3);

        $buf = new BufferedOutput;
        Artisan::call('atlas:brain:provenance', ['--source-finding' => 'frontier_empty'], $buf);
        $payload = json_decode(trim($buf->fetch()), true);

        self::assertSame(2, $payload['count']);
        self::assertSame('frontier_empty', $payload['source_finding_filter']);
        foreach ($payload['rows'] as $row) {
            self::assertSame('frontier_empty', $row['source_finding']);
        }
    }

    public function test_provenance_command_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit('app/Console/Commands/AtlasBrainProvenanceCommand.php', true);
        self::assertSame('forbidden', $verdict);
    }
}
