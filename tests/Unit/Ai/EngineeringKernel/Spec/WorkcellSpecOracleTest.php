<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel\Spec;

use App\Services\Ai\EngineeringKernel\Spec\AtlasSpecGateAdapter;
use App\Services\Ai\EngineeringKernel\Spec\SpecDraft;
use App\Services\Ai\EngineeringKernel\Spec\SpecProvenance;
use App\Services\Ai\EngineeringKernel\Spec\SpecVerdict;
use App\Services\Ai\EngineeringKernel\Spec\WorkcellSpecOracle;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\EngineeringKernel\WorkcellExecutor;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Slice 3 — the real no-op-impl oracle via WorkcellExecutor + the AtlasSpecGateAdapter regression.
 * Wiper-safe: the executor is faked, no real execution, no DB.
 */
final class WorkcellSpecOracleTest extends TestCase
{
    /** A fake executor that returns a canned probe result (or throws / reports not-ran). */
    private function executor(array $result, bool $throw = false): WorkcellExecutor
    {
        return new class($result, $throw) implements WorkcellExecutor
        {
            public function __construct(private array $result, private bool $throw) {}

            public function execute(array $workcell): array
            {
                if ($this->throw) {
                    throw new RuntimeException('runner unavailable');
                }

                return $this->result;
            }
        };
    }

    private const GOOD_AC = [
        ['id' => 'ac_behavior_add', 'description' => 'rejects an invalid email address', 'verification' => 'test', 'verification_ref' => 'php artisan test t', 'case_class' => 'happy'],
    ];

    private function draft(array $criteria): SpecDraft
    {
        return SpecDraft::fromArray(['intent_text' => 'adicionar validação em EmailValidator.php', 'acceptance_criteria' => $criteria]);
    }

    // --- the oracle interpretation ---

    public function test_oracle_reports_red_criteria_as_executional(): void
    {
        $oracle = new WorkcellSpecOracle($this->executor(['ran' => true, 'red_ids' => ['ac_behavior_add']]));

        $report = $oracle->probe($this->draft(self::GOOD_AC));

        self::assertSame(SpecProvenance::ORACLE_EXECUTIONAL, $report->mode);
        self::assertSame(['ac_behavior_add'], $report->redCriteriaIds);
    }

    public function test_oracle_reports_unmeasured_when_runner_cannot_run(): void
    {
        $oracle = new WorkcellSpecOracle($this->executor(['ran' => false]));
        self::assertSame(SpecProvenance::ORACLE_UNMEASURED, $oracle->probe($this->draft(self::GOOD_AC))->mode);
    }

    public function test_oracle_reports_unmeasured_when_runner_throws(): void
    {
        $oracle = new WorkcellSpecOracle($this->executor([], throw: true));
        self::assertSame(SpecProvenance::ORACLE_UNMEASURED, $oracle->probe($this->draft(self::GOOD_AC))->mode);
    }

    public function test_oracle_reports_unmeasured_when_no_runnable_refs(): void
    {
        // a criterion with no verification_ref cannot be probed
        $oracle = new WorkcellSpecOracle($this->executor(['ran' => true, 'red_ids' => []]));
        $noRef = [['id' => 'ac_x', 'description' => 'does a thing well enough', 'verification' => 'test', 'verification_ref' => '', 'case_class' => 'happy']];
        self::assertSame(SpecProvenance::ORACLE_UNMEASURED, $oracle->probe($this->draft($noRef))->mode);
    }

    // --- the adapter regression ---

    public function test_a_discriminating_dev_spec_freezes_via_the_adapter(): void
    {
        $adapter = new AtlasSpecGateAdapter(new WorkcellSpecOracle($this->executor(['ran' => true, 'red_ids' => ['ac_behavior_add']])));

        $verdict = $adapter->contestDevSpec([
            'spec' => ['intent_text' => 'adicionar validação em EmailValidator.php', 'acceptance_criteria' => self::GOOD_AC],
            'intent' => ['raw_goal' => 'adicionar validação em EmailValidator.php', 'recognized_verbs' => ['adicionar']],
        ], TrustLevel::Dev);

        self::assertSame(SpecVerdict::FREEZE, $verdict->status, 'gaps: '.implode(',', $verdict->gaps));
    }

    public function test_a_green_on_noop_dev_spec_is_refused_via_the_adapter(): void
    {
        $adapter = new AtlasSpecGateAdapter(new WorkcellSpecOracle($this->executor(['ran' => true, 'red_ids' => []])));

        $verdict = $adapter->contestDevSpec([
            'spec' => ['intent_text' => 'adicionar validação em EmailValidator.php', 'acceptance_criteria' => self::GOOD_AC],
            'intent' => ['raw_goal' => 'adicionar validação em EmailValidator.php', 'recognized_verbs' => ['adicionar']],
        ], TrustLevel::Dev);

        self::assertSame(SpecVerdict::REFUSE, $verdict->status);
        self::assertContains('oracle_adequacy', $verdict->gaps);
    }

    public function test_self_composed_spec_holds_in_autonomous_via_the_adapter(): void
    {
        $adapter = new AtlasSpecGateAdapter(new WorkcellSpecOracle($this->executor(['ran' => true, 'red_ids' => ['ac_behavior_add']])));

        $verdict = $adapter->contestDevSpec([
            'spec' => ['intent_text' => 'adicionar validação em EmailValidator.php', 'acceptance_criteria' => self::GOOD_AC],
            'intent' => ['raw_goal' => 'adicionar validação em EmailValidator.php', 'recognized_verbs' => ['adicionar']],
        ], TrustLevel::Autonomos);

        self::assertSame(SpecVerdict::HOLD, $verdict->status, 'no independent witness in the autonomous lane yet');
    }
}
