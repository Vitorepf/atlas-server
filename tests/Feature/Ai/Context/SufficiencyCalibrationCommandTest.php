<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Models\AiRagFeedbackEvent;
use App\Services\Ai\Context\AtlasDeliveredPackLedger;
use App\Services\Ai\Context\SufficiencyCalibrationService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

final class SufficiencyCalibrationCommandTest extends TestCase
{
    use BootsCompoundingSchema;

    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = tempnam(sys_get_temp_dir(), 'atlas-suff-calib-').'.jsonl';
        @unlink($this->ledgerPath);
        config(['atlas.aobg.delivered_pack_ledger.path' => $this->ledgerPath]);
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    public function test_insufficient_signal_when_no_ledger_rows(): void
    {
        $this->bootCompoundingSchema();
        try {
            Artisan::call('atlas:context:sufficiency-calibration', ['--json' => true, '--days' => 14]);
            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(SufficiencyCalibrationService::SCHEMA, $payload['schema']);
            $this->assertSame('insufficient_signal', $payload['status']);
            $this->assertSame(0, $payload['measured_count']);
            $this->assertSame(0, $payload['declared_insufficient']['n']);
            $this->assertSame(0, $payload['declared_covered']['n']);
            $this->assertStringContainsString('death_criterion', json_encode($payload));
        } finally {
            $this->dropCompoundingSchema();
        }
    }

    public function test_join_reports_bucket_counts_with_denominators_when_ledger_carries_sufficiency_block(): void
    {
        $this->bootCompoundingSchema();

        try {
            $ledger = new AtlasDeliveredPackLedger($this->ledgerPath);
            $rows = [];
            for ($i = 0; $i < 12; $i++) {
                $hash = sha1('pack-cov-'.$i);
                $rows[] = [
                    'context_pack_hash' => $hash,
                    'sufficiency_block' => ['not_enough_context' => false],
                ];
            }
            $rows[] = [
                'context_pack_hash' => sha1('pack-insuff-1'),
                'sufficiency_block' => ['not_enough_context' => true],
            ];
            foreach ($rows as $row) {
                file_put_contents(
                    $this->ledgerPath,
                    json_encode($row).PHP_EOL,
                    FILE_APPEND,
                );
            }

            foreach ($rows as $i => $row) {
                AiRagFeedbackEvent::create([
                    'schema_version' => 'atlas.aucri.feedback_event.v1',
                    'retrieval_receipt_id' => 'test-'.$i,
                    'flow_id' => 'test.flow.'.$i,
                    'query_plan_hash' => sha1('q'.$i),
                    'included_sources' => 5,
                    'used_sources' => 3,
                    'noise_sources' => 0,
                    'missed_required_sources' => [],
                    'context_sufficiency' => 0,
                    'post_execution_utility' => 70,
                    'source_utility' => [],
                    'outcome_status' => 'passed',
                    'failure_reason' => null,
                    'next_retrieval_hint' => [],
                    'memory_candidate_id' => null,
                    'learning_proposal_id' => null,
                    'run_outcome_id' => null,
                    'payload' => [
                        'measured' => true,
                        'context_pack_hash' => $row['context_pack_hash'],
                    ],
                    'feedback_hash' => sha1('fb'.$i),
                ]);
            }

            Artisan::call('atlas:context:sufficiency-calibration', ['--json' => true, '--days' => 30]);
            $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame('ready', $payload['status']);
            $this->assertSame(13, $payload['measured_count']);
            $this->assertSame(12, $payload['declared_covered']['n']);
            $this->assertSame(1, $payload['declared_insufficient']['n']);
            $this->assertSame(0.6, $payload['declared_covered']['used_rate']);
        } finally {
            $this->dropCompoundingSchema();
        }
    }
}
