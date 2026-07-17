<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition;

use App\Services\Ai\Cognition\Watchdog\AtlasWatchdogCheckRegistry;
use Tests\TestCase;

final class HealthReportWatchdogRegistrationTest extends TestCase
{
    public function test_registry_includes_collapsed_health_report_check_ids(): void
    {
        /** @var AtlasWatchdogCheckRegistry $registry */
        $registry = app(AtlasWatchdogCheckRegistry::class);
        $ids = array_map(static fn ($check): string => $check->id(), $registry->all());

        foreach ([
            'mem-09.memory_quality',
            'fee-13.learning_cadence',
            'rag-10.aurg_coverage',
            'rag-12.rag_dimension',
            'com-10.context_feedback_health',
            'cpt-09.compaction_soak',
            'pip-08.scorecard_stability',
            'ope-08.lift_cycle_closure',
            'ope-10.scorecard_receipts_diagnosis',
            'eng-11.enforce_readiness',
        ] as $id) {
            $this->assertContains($id, $ids, "missing collapsed watchdog id {$id}");
        }
    }
}
