<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopExternalResearchService;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopResearchOriginator;
use Tests\TestCase;

/**
 * P27 slice-1 invariants — the research-to-RED chain must be honest in CODE, not by trust:
 *   1. FAIL-CLOSED: no search backend wired ⇒ NO objective is ever minted (the bare topic is not laundered).
 *   2. SOURCE-QUARANTINE: when an objective IS produced, the research note is stamped advisory and the
 *      objective text says so — the cert never treats it as proof.
 *   3. RED-REQUIRED: every minted objective demands the loop's own failing-test-first proof.
 */
final class AtlasLoopResearchOriginatorTest extends TestCase
{
    private function originator(bool $backend): AtlasLoopResearchOriginator
    {
        // "backend present" now means a real (here faked) pull is wired — a flag alone no longer researches
        // (the stub note was removed). "no backend" stays fail-closed.
        $service = $backend
            ? new AtlasLoopExternalResearchService(
                searchToolAvailable: true,
                backend: static fn (string $topic): string => "advisory state-of-the-art note for: {$topic}",
            )
            : new AtlasLoopExternalResearchService(searchToolAvailable: false);

        return new AtlasLoopResearchOriginator($service);
    }

    public function test_fail_closed_no_backend_mints_nothing(): void
    {
        // default state: no search tool wired ⇒ research() returns researched=false ⇒ ZERO origination.
        $this->assertNull(
            $this->originator(false)->originate('reduce coupling in the planner', base_path(), ['app/Svc/X.php']),
            'an unresearched topic must never be laundered into an objective',
        );
    }

    public function test_backend_present_mints_a_red_required_source_quarantined_objective(): void
    {
        $obj = $this->originator(true)->originate('idempotency for queue refill', base_path(), ['app/Svc/X.php']);

        $this->assertIsArray($obj);
        $this->assertTrue($obj['acceptance']['red_required'], 'research work must carry its OWN red requirement');
        $this->assertTrue($obj['source_quarantined'], 'the research note must be quarantined as advisory');
        $this->assertSame('research', $obj['shape']);
        $this->assertSame('idempotency for queue refill', $obj['research_source']['topic']);
        $this->assertSame(['app/Svc/X.php'], $obj['acceptance']['allowed_globs']);
        $this->assertStringContainsStringIgnoringCase('advisory', $obj['objective']);
        $this->assertStringContainsStringIgnoringCase('red', $obj['objective']);
    }

    public function test_empty_topic_or_empty_scope_mints_nothing(): void
    {
        $this->assertNull($this->originator(true)->originate('   ', base_path(), ['app/Svc/X.php']));
        $this->assertNull($this->originator(true)->originate('a clean topic', base_path(), []));
    }

    public function test_task_packet_contract_has_required_fields_and_php_artisan_criteria(): void
    {
        $obj = $this->originator(true)->originate('retry budget for provider calls', base_path(), ['app/Svc/X.php', 'tests/Unit/Svc/XTest.php']);

        $this->assertIsArray($obj);
        $this->assertArrayHasKey('task_packet_contract', $obj);
        $contract = $obj['task_packet_contract'];

        $this->assertSame(['app/Svc/X.php', 'tests/Unit/Svc/XTest.php'], $contract['allowed_files']);
        $this->assertSame(['tests_or_gates_result', 'implementation_notes'], $contract['required_evidence']);
        $this->assertSame('research_to_task', $contract['objective_kind']);
        $this->assertStringStartsWith('sha256:', $contract['research_provenance_hash']);

        // acceptance_criteria must mention php artisan and RED/failing-test-first proof
        $criteria = implode(' ', $contract['acceptance_criteria']);
        $this->assertStringContainsString('/opt/homebrew/bin/php artisan test', $criteria);
        $this->assertStringContainsStringIgnoringCase('red', $criteria);
        $this->assertStringContainsString('source_quarantined=true', $criteria, 'anti-hype warning must reference quarantine');

        // Source quarantine still intact on parent result.
        $this->assertTrue($obj['source_quarantined']);
        $this->assertTrue($obj['acceptance']['red_required']);
    }

    public function test_task_packet_contract_provenance_hash_is_deterministic(): void
    {
        $o = $this->originator(true);
        $r1 = $o->originate('idempotency for queue refill', base_path(), ['app/Svc/Y.php']);
        $r2 = $o->originate('idempotency for queue refill', base_path(), ['app/Svc/Y.php']);

        $this->assertIsArray($r1);
        $this->assertIsArray($r2);
        $this->assertSame(
            $r1['task_packet_contract']['research_provenance_hash'],
            $r2['task_packet_contract']['research_provenance_hash'],
            'same topic and note must produce the same provenance hash',
        );
    }

    public function test_egress_blocked_topic_mints_nothing_even_with_a_backend(): void
    {
        // a topic carrying a REAL repo file path is egress-blocked by the research service (it only blocks
        // fragments that resolve to an actual file — never shipping real repo internals to a research tool),
        // so no objective is produced even with a backend present.
        $real = 'app/Services/Ai/AutonomousEvolution/AtlasLoopResearchContract.php';
        $this->assertFileExists(base_path($real)); // guard: the egress block is only meaningful on a real path
        $blocked = $this->originator(true)->originate("leak {$real} internals", base_path(), ['app/Svc/X.php']);
        $this->assertNull($blocked, 'egress-blocked research must not produce an objective');
    }
}
