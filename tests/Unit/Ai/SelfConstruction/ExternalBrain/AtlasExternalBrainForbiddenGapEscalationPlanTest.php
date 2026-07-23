<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainForbiddenGapEscalationPlan;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainForbiddenGapEscalationPlanTest extends TestCase
{
    private function plan(): AtlasExternalBrainForbiddenGapEscalationPlan
    {
        return new AtlasExternalBrainForbiddenGapEscalationPlan;
    }

    // ── AC: forbidden targets emitted as operator_only_interventions ──────────

    public function test_forbidden_target_emitted_with_all_required_fields(): void
    {
        $r = $this->plan()->plan(['gaps' => [
            [
                'target_file' => 'app/Console/Commands/AtlasBrainNextCommand.php',
                'intended_change' => 'add a guard for X',
                'required_receipt' => 'operator:receipt-1',
            ],
        ]]);

        $this->assertCount(1, $r['operator_only_interventions']);
        $intervention = $r['operator_only_interventions'][0];
        foreach (['file', 'intended_change', 'required_receipt', 'why_muscle_cannot_do_it'] as $field) {
            $this->assertArrayHasKey($field, $intervention, "Missing field: {$field}");
        }
        $this->assertSame('app/Console/Commands/AtlasBrainNextCommand.php', $intervention['file']);
        $this->assertSame('operator:receipt-1', $intervention['required_receipt']);
        $this->assertStringContainsString('AtlasBrainNextCommand', $intervention['why_muscle_cannot_do_it']);
    }

    public function test_all_five_forbidden_classes_escalate(): void
    {
        $classes = [
            'AtlasBrainSeedQualityGate',
            'AtlasBrainWorkerPromptCommand',
            'AtlasBrainNextCommand',
            'AtlasBrainAuditCommand',
            'AtlasBrainSummaryCommand',
        ];

        $gaps = [];
        foreach ($classes as $i => $class) {
            $gaps[] = ['target_file' => "app/Foo{$i}.php", 'target_class' => $class];
        }

        $r = $this->plan()->plan(['gaps' => $gaps]);

        $this->assertCount(5, $r['operator_only_interventions']);
    }

    public function test_default_required_receipt_used_when_absent(): void
    {
        $r = $this->plan()->plan(['gaps' => [
            ['target_file' => 'app/AtlasBrainAuditCommand.php'],
        ]]);

        $this->assertSame('operator_signed_change_receipt', $r['operator_only_interventions'][0]['required_receipt']);
    }

    // ── AC: allowed no-gap targets are not escalated ──────────────────────────

    public function test_allowed_target_is_not_escalated(): void
    {
        $r = $this->plan()->plan(['gaps' => [
            ['target_file' => 'app/Services/Foo/OrdinaryService.php', 'intended_change' => 'add a test'],
        ]]);

        $this->assertSame([], $r['operator_only_interventions']);
        $this->assertContains('app/Services/Foo/OrdinaryService.php', $r['allowed_no_gap_targets']);
    }

    public function test_mixed_gaps_split_into_escalated_and_allowed(): void
    {
        $r = $this->plan()->plan(['gaps' => [
            ['target_file' => 'app/AtlasBrainSummaryCommand.php'],
            ['target_file' => 'app/Services/Foo/OrdinaryService.php'],
        ]]);

        $this->assertCount(1, $r['operator_only_interventions']);
        $this->assertSame('app/AtlasBrainSummaryCommand.php', $r['operator_only_interventions'][0]['file']);
        $this->assertSame(['app/Services/Foo/OrdinaryService.php'], $r['allowed_no_gap_targets']);
    }

    // ── AC: dedupes repeated forbidden targets into one intervention per file ──

    public function test_repeated_forbidden_target_deduplicates_into_one_intervention(): void
    {
        $r = $this->plan()->plan(['gaps' => [
            ['target_file' => 'app/AtlasBrainNextCommand.php', 'intended_change' => 'attempt 1'],
            ['target_file' => 'app/AtlasBrainNextCommand.php', 'intended_change' => 'attempt 2'],
            ['target_file' => 'app/AtlasBrainNextCommand.php', 'intended_change' => 'attempt 3'],
        ]]);

        $this->assertCount(1, $r['operator_only_interventions']);
        $this->assertSame('attempt 1', $r['operator_only_interventions'][0]['intended_change']);
    }

    // ── Determinism ─────────────────────────────────────────────────────────

    public function test_plan_is_deterministic(): void
    {
        $facts = ['gaps' => [['target_file' => 'app/AtlasBrainNextCommand.php']]];
        $a = $this->plan()->plan($facts);
        $b = $this->plan()->plan($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_empty_input_yields_no_interventions(): void
    {
        $r = $this->plan()->plan([]);
        $this->assertSame([], $r['operator_only_interventions']);
        $this->assertSame([], $r['allowed_no_gap_targets']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->plan()->plan([]);
        $this->assertSame(AtlasExternalBrainForbiddenGapEscalationPlan::SCHEMA, $r['schema']);
    }
}
