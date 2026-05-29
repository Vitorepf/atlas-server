<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Anti-inertia, the SAFE shape (operator mandate, 2026-05-29).
 *
 * The wiring gap is closed by a FINDING SOURCE — the deep engine detects a
 * contract accessor with zero callers (inert / progress theater) and EMITS a
 * "consume contract X in its decision" finding. A finding source can never
 * false-block legitimate TDD slices (unlike a per-cycle gate). These pin that
 * behavior with injected candidates (no filesystem).
 */
final class InertWiringDebtFindingTest extends TestCase
{
    private function engine(): AreaFocusDeepFindingEngineService
    {
        return app(AreaFocusDeepFindingEngineService::class);
    }

    /**
     * @param  list<array<string,mixed>>  $candidates
     * @return list<array<string,mixed>>
     */
    private function emit(array $candidates): array
    {
        $engine = $this->engine();
        $m = new ReflectionMethod($engine, 'inertWiringFindings');
        $m->setAccessible(true);

        return $m->invoke($engine, $candidates, 'agentic_engineering_os', 'dev_forge', []);
    }

    public function test_inert_accessor_with_zero_callers_emits_a_wiring_finding(): void
    {
        // The exact proven defect.
        $findings = $this->emit([[
            'consumer_class' => 'LoopPreflightCycleFirewallService',
            'consumer_file' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopPreflightCycleFirewallService.php',
            'accessor' => 'theAdmissionDeficitReason',
            'contract' => 'TheAdmissionDeficitReasonContract',
            'contract_file' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/TheAdmissionDeficitReasonContract.php',
            'caller_count' => 0,
        ]]);

        $this->assertCount(1, $findings);
        $f = $findings[0];
        $this->assertStringContainsString('Consume inert contract TheAdmissionDeficitReasonContract', $f['title']);
        $this->assertStringContainsString('LoopPreflightCycleFirewallService', $f['title']);
        $this->assertSame('atlas_dev', $f['owner_candidate']);
        $this->assertSame('inert_contract_no_caller', $f['origin_type']);
        // Both the consumer and the contract are in scope for the wiring change.
        $this->assertContains('app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopPreflightCycleFirewallService.php', $f['affected_files']);
        $this->assertContains('app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/TheAdmissionDeficitReasonContract.php', $f['affected_files']);
        // It proposes a real wiring (gain a caller), not more shape.
        $this->assertStringContainsString('gains a real caller', $f['proposed_next_action']);
    }

    public function test_wired_accessor_with_a_caller_emits_nothing(): void
    {
        $findings = $this->emit([[
            'consumer_class' => 'SomeService',
            'consumer_file' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/SomeService.php',
            'accessor' => 'alreadyWired',
            'contract' => 'SomeContract',
            'contract_file' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/SomeContract.php',
            'caller_count' => 3,
        ]]);

        $this->assertSame([], $findings, 'a contract with real callers is NOT inert => no finding');
    }

    public function test_incomplete_candidate_is_skipped(): void
    {
        $findings = $this->emit([
            ['consumer_class' => '', 'accessor' => 'x', 'contract' => 'C', 'caller_count' => 0],
            ['consumer_class' => 'S', 'accessor' => '', 'contract' => 'C', 'caller_count' => 0],
            ['consumer_class' => 'S', 'accessor' => 'x', 'contract' => '', 'caller_count' => 0],
        ]);
        $this->assertSame([], $findings);
    }

    public function test_check_routes_injected_candidates_and_reports_source(): void
    {
        $engine = $this->engine();
        $m = new ReflectionMethod($engine, 'checkInertWiringDebt');
        $m->setAccessible(true);

        [$findings, $source] = $m->invoke($engine, 'agentic_engineering_os', 'dev_forge', [], [
            'inert_wiring_candidates' => [[
                'consumer_class' => 'LoopPostCycleAuditorService',
                'consumer_file' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopPostCycleAuditorService.php',
                'accessor' => 'providerSpentWithoutMerge',
                'contract' => 'ProviderSpentWithoutMergeContract',
                'contract_file' => 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ProviderSpentWithoutMergeContract.php',
                'caller_count' => 0,
            ]],
        ]);

        $this->assertCount(1, $findings);
        $this->assertTrue($source['available']);
        $this->assertSame(1, $source['candidate_count']);
        $this->assertSame(1, $source['emitted_count']);
    }

    public function test_default_off_so_direct_scan_callers_stay_byte_identical(): void
    {
        $engine = $this->engine();
        $m = new ReflectionMethod($engine, 'checkInertWiringDebt');
        $m->setAccessible(true);

        // No enable flag => disabled => no findings (keeps the existing scan() suite green).
        [$findings, $source] = $m->invoke($engine, 'agentic_engineering_os', 'dev_forge', [], []);
        $this->assertSame([], $findings);
        $this->assertFalse($source['enabled']);

        // Explicit enable + skip => skipped honestly.
        [$f2, $s2] = $m->invoke($engine, 'agentic_engineering_os', 'dev_forge', [], [
            'scan_inert_wiring_debt' => true,
            'skip_inert_wiring_debt' => true,
        ]);
        $this->assertSame([], $f2);
        $this->assertTrue($s2['skipped']);
    }
}
