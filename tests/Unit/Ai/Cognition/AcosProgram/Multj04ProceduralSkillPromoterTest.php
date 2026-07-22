<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Models\AiLearningCandidate;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxLote2MeasureService;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxMeasureSeriesRegistry;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxProceduralSkillPromoterService;
use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookApplier;
use App\Services\Ai\Kernel\Procedural\AtlasProceduralPlaybookLedger;
use App\Services\Ai\Kernel\Procedural\ProceduralPlaybook;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

final class Multj04ProceduralSkillPromoterTest extends TestCase
{
    use BootsCompoundingSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompoundingSchema();
        config(['atlas.ai.procedural_skill_promoter.enqueue_enabled' => false]);
    }

    protected function tearDown(): void
    {
        $this->dropCompoundingSchema();
        parent::tearDown();
    }

    public function test_default_off_reports_mechanism_landed_and_case_count_soak_pending(): void
    {
        $ledger = $this->ledger();
        $this->seedPlaybookCases($ledger, attempts: 1);

        $report = (new AcosMaxProceduralSkillPromoterService($ledger))->report(floor: 2, enqueue: true);

        $this->assertSame('pending_window', $report['status']);
        $this->assertSame(['mechanism'], $report['scoreboard']['landed']);
        $this->assertSame(['procedural_case_count_soak'], $report['scoreboard']['pending_window']);
        $this->assertFalse($report['promotion_allowed']);
        $this->assertFalse($report['claim_policy']['enqueue_enabled']);
        $this->assertFalse($report['claim_policy']['enqueue_effective']);
        $this->assertSame(0, AiLearningCandidate::query()->count());

        $candidate = $report['candidates'][0];
        $this->assertSame(self::class, $candidate['task_category']);
        $this->assertSame('skill.v1', $candidate['skill_schema_version']);
        $this->assertSame(1, $candidate['case_count']);
        $this->assertFalse($candidate['case_count_floor_met']);
        $this->assertFalse($candidate['promotion_allowed']);
        $this->assertSame('ASI-02', $candidate['gate']['admission_door']);
        $this->assertSame('pending_window', $candidate['gate']['status']);
    }

    public function test_flag_on_enqueues_floor_met_skill_v1_candidate_held_for_asi02(): void
    {
        config(['atlas.ai.procedural_skill_promoter.enqueue_enabled' => true]);
        $ledger = $this->ledger();
        $this->seedPlaybookCases($ledger, attempts: 2);

        $report = (new AcosMaxProceduralSkillPromoterService($ledger))->report(floor: 2, enqueue: true);

        $this->assertSame('ok', $report['status']);
        $this->assertSame([], $report['scoreboard']['pending_window']);
        $this->assertSame(1, $report['totals']['enqueued']);
        $this->assertFalse($report['promotion_allowed']);

        $row = AiLearningCandidate::query()->first();
        $this->assertNotNull($row);
        $this->assertSame('held_for_evidence', $row->status);
        $this->assertSame('hold', $row->decision);
        $this->assertSame('skill.v1', $row->memory_type);
        $this->assertFalse((bool) $row->promotion_allowed);
        $this->assertSame('ASI-02', data_get($row->payload, 'source.admission_door'));
        $this->assertSame('skill.v1', data_get($row->payload, 'skill_v1.schema_version'));
        $this->assertSame(2, data_get($row->payload, 'case_count'));
    }

    public function test_command_emits_json_report_from_bound_promoter(): void
    {
        $ledger = $this->ledger();
        $this->seedPlaybookCases($ledger, attempts: 1);
        $this->app->instance(AcosMaxProceduralSkillPromoterService::class, new AcosMaxProceduralSkillPromoterService($ledger));

        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:ai:procedural-skill-promoter', [
            '--floor' => 2,
            '--json' => true,
        ], $output);
        $payload = json_decode($output->fetch(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('MULTJ-04', $payload['slice']);
        $this->assertSame('pending_window', $payload['status']);
        $this->assertSame('procedural_case_count_soak', $payload['reason']);
    }

    public function test_freeze_and_measure_registry_include_multj04(): void
    {
        $freeze = AcosMaxLote2MeasureService::freezePayload('MULTJ-04');
        $registry = collect((new AcosMaxMeasureSeriesRegistry)->entries())->keyBy('slice');

        $this->assertSame(AcosMaxLote2MeasureService::MULTJ04_MEASURE_ID, $freeze['measure_id']);
        $this->assertSame('multj.procedural_skill_promoter.v1', $freeze['formula_version']);
        $this->assertSame(8, data_get($freeze, 'thresholds.procedural_case_count_floor'));
        $this->assertTrue(data_get($freeze, 'thresholds.default_off'));
        $this->assertSame('ASI-02', data_get($freeze, 'thresholds.admission_door'));
        $this->assertSame(AcosMaxLote2MeasureService::MULTJ04_MEASURE_ID, data_get($registry->get('MULTJ-04'), 'series'));
        $this->assertSame('atlas:ai:procedural-skill-promoter --json', data_get($registry->get('MULTJ-04'), 'path'));
    }

    private function ledger(): AtlasProceduralPlaybookLedger
    {
        $path = tempnam(sys_get_temp_dir(), 'multj04_procedural_').'.jsonl';
        @unlink($path);

        return new AtlasProceduralPlaybookLedger($path);
    }

    private function seedPlaybookCases(AtlasProceduralPlaybookLedger $ledger, int $attempts): void
    {
        $ledger->define(new ProceduralPlaybook(
            self::class,
            'Convert a proven procedural playbook into a held skill.v1 proposal.',
            ['Follow the proven procedure.'],
            ['Focused verification passes.'],
            ['Do not auto-promote.'],
        ));

        $applier = new AtlasProceduralPlaybookApplier($ledger);
        for ($i = 0; $i < $attempts; $i++) {
            $application = $applier->apply(self::class);
            $this->assertIsArray($application);
            $ledger->recordOutcome(
                (string) $application['application_id'],
                'success',
                ['tests_run' => 1, 'assertions_executed' => 1],
            );
        }
    }
}
