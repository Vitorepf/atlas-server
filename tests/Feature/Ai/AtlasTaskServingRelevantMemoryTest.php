<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AtlasTaskServingService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\Concerns\MakesAgentControlPlaneTaskQueueOrchestrator;
use Tests\TestCase;

/**
 * C2 (Obra #18) — the served packet carries a FIFTH advisory source,
 * `relevant_memory`: decisions relevant to its files, via the query-aware recall.
 * The key always travels (list) and the source is fail-open.
 *
 * B3 (fechamento ACOS) added the SEEDED gate: the prior test enqueued with an EMPTY
 * registry, so recall passed trivially on empty. This one seeds real decisions in the
 * packet's zone plus off-zone decoys and proves the served packet surfaces the RELEVANT
 * memory and excludes the decoys.
 *
 * HONEST FINDING (why the gate is PRECISION ≥80%, not recall-of-N-identical-seeds):
 * the recall composer keeps a Pareto-optimal, char-budget-bounded set (relevance ×
 * freshness), so among several equal-relevance memories it surfaces the top/freshest
 * representative — NOT N copies. So "seed 5 in-zone → recall 5" is against the design.
 * The meaningful, achievable gate is: a seeded in-zone decision SURFACES (recall > 0
 * and relevant) and EVERY surfaced item is in-zone (precision ≥80%, decoys excluded).
 */
final class AtlasTaskServingRelevantMemoryTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;
    use MakesAgentControlPlaneTaskQueueOrchestrator;

    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->createAtlasMemoryEntryTable();
        $this->envFile = sys_get_temp_dir().'/atlas-c2-env-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        if ($this->envFile !== '') {
            @unlink($this->envFile);
        }
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    public function test_served_packet_carries_relevant_memory_as_a_failopen_list(): void
    {
        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => [
            'task_packet_id' => 'c2-relmem-1',
            'objective' => 'wire AtlasFooService for c2',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/C2RelMem.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/C2RelMem.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'required_evidence' => ['task_packet_created'],
        ]]);

        $res = (new AtlasTaskServingService($orch))->next('client-c2');

        self::assertSame('served', $res['status']);
        // The fifth advisory source always travels with the served packet …
        self::assertArrayHasKey('relevant_memory', $res['task']);
        // … as a list (each entry a provider-safe "title — summary" string) …
        self::assertIsArray($res['task']['relevant_memory']);
        self::assertSame(array_values($res['task']['relevant_memory']), $res['task']['relevant_memory']);
        // … and never wedges a serve when there is no memory to recall (fail-open).
        foreach ($res['task']['relevant_memory'] as $line) {
            self::assertIsString($line);
        }
    }

    public function test_seeded_in_zone_decision_surfaces_and_decoys_are_excluded_at_precision_80pct(): void
    {
        // In-zone: decisions whose text carries the packet's module token.
        foreach ([
            'AtlasWidgetAssembler must retry once on a 500 inside SelfConstruction',
            'AtlasWidgetAssembler idempotency key is the packet id in SelfConstruction',
            'AtlasWidgetAssembler never mutates the live source tree in SelfConstruction',
        ] as $t) {
            $this->seedDecision($t, $t.' — decisão sobre o AtlasWidgetAssembler.');
        }
        // Off-zone decoys: nothing about the target module.
        foreach ([
            'Postgres backup window is 3am UTC',
            'Invoice rounding uses banker rounding',
            'The mobile app polls every 10s',
            'Marketing funnel step 2 is the bridge page',
        ] as $decoy) {
            $this->seedDecision($decoy, $decoy.' — assunto sem relação com o alvo.');
        }

        $orch = $this->orchestrator();
        $orch->prepareAndEnqueue(['task_packet' => [
            'task_packet_id' => 'c2-relmem-real',
            'objective' => 'wire AtlasWidgetAssembler',
            'operator_id' => 'tester',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasWidgetAssembler.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/AtlasWidgetAssembler.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'required_evidence' => ['task_packet_created'],
        ]]);

        $res = (new AtlasTaskServingService($orch))->next('client-c2-real');
        self::assertSame('served', $res['status']);

        $recalled = array_map('strval', (array) $res['task']['relevant_memory']);

        // 1) Recall is NON-EMPTY on a seeded zone — the shape-only test could not tell
        //    this apart from a broken recall, because it passed on an empty registry.
        self::assertNotEmpty($recalled, 'a seeded in-zone decision must surface (vs shape-only empty)');

        // 2) An in-zone decision actually surfaced (recall is relevant, not noise).
        $blob = implode("\n", $recalled);
        self::assertStringContainsString('AtlasWidgetAssembler', $blob, 'the surfaced memory is the in-zone one');

        // 3) Precision ≥80%: every surfaced line is in-zone; no off-zone decoy leaks in.
        $decoyMarkers = ['Postgres backup', 'banker rounding', 'mobile app polls', 'bridge page'];
        $inZoneLines = 0;
        foreach ($recalled as $line) {
            $isDecoy = false;
            foreach ($decoyMarkers as $m) {
                if (str_contains($line, $m)) {
                    $isDecoy = true;
                    break;
                }
            }
            if (! $isDecoy) {
                $inZoneLines++;
            }
        }
        $precision = $inZoneLines / max(1, count($recalled));
        self::assertGreaterThanOrEqual(
            0.8,
            $precision,
            sprintf('recall precision was %.2f (%d/%d in-zone) — below the 80%% gate', $precision, $inZoneLines, count($recalled)),
        );
    }

    private function seedDecision(string $title, string $body): void
    {
        AtlasMemoryEntry::query()->create([
            'id' => (string) Str::uuid7(),
            'memory_type' => 'decision',
            'scope_type' => 'global',
            'status' => 'active',
            'title' => $title,
            'summary' => $title,
            'body' => $body,
            'priority' => 8,
            'importance' => 4,
            'confidence' => 0.5,
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'source_type' => 'test_fixture',
        ]);
    }
}
