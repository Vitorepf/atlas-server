<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPWriterImplementationPreflightService;
use Tests\TestCase;

/**
 * Pins the documented Codex Merge PEAP Writer IMPLEMENTATION Preflight: the
 * eight-key boundary (including writer_file_creation_allowed), the two future
 * files declared as names-only (never created), the eight required tests, the
 * six release conditions, the blocked/ready statuses and the "consideration is
 * never authorization" invariant.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-implementation-preflight.md
 */
class AtlasCodexMergePEAPWriterImplementationPreflightTest extends TestCase
{
    private function service(): AtlasCodexMergePEAPWriterImplementationPreflightService
    {
        return new AtlasCodexMergePEAPWriterImplementationPreflightService();
    }

    /** @return array<string,true> */
    private function allTestsPresent(): array
    {
        $out = [];
        foreach (AtlasCodexMergePEAPWriterImplementationPreflightService::REQUIRED_TESTS as $t) {
            $out[$t] = true;
        }

        return $out;
    }

    /** @return array<string,true> */
    private function allConditionsMet(): array
    {
        $out = [];
        foreach (AtlasCodexMergePEAPWriterImplementationPreflightService::RELEASE_CONDITIONS as $c) {
            $out[$c] = true;
        }

        return $out;
    }

    /**
     * Doc "Boundary": exactly the eight keys, every one false, and the set
     * includes writer_file_creation_allowed (the hazard unique to this preflight)
     * while excluding the sibling-preflight-only receipt_signed.
     */
    public function test_boundary_is_the_eight_documented_keys_all_false(): void
    {
        $boundary = $this->service()->boundary();

        $this->assertSame([
            'execution_allowed',
            'writer_file_creation_allowed',
            'ledger_write_allowed',
            'dispatch_allowed',
            'approval_granted',
            'merge_allowed',
            'signature_valid',
            'receipt_persisted',
        ], array_keys($boundary));

        foreach ($boundary as $value) {
            $this->assertFalse($value);
        }

        $this->assertArrayHasKey('writer_file_creation_allowed', $boundary);
        $this->assertArrayNotHasKey('receipt_signed', $boundary);
    }

    /**
     * Doc "Future Files": the two expected paths are DECLARED by name, but each is
     * creation_allowed=false and created=false. Naming never creates.
     */
    public function test_future_files_are_named_but_never_created(): void
    {
        $ff = $this->service()->futureFiles();

        $this->assertSame(2, $ff['count']);
        $this->assertFalse($ff['any_created']);
        $this->assertFalse($ff['writer_file_creation_allowed']);

        $paths = array_column($ff['future_files'], 'path');
        $this->assertContains(
            'app/Services/Ai/SelfConstruction/CodexReviewMergePostExecutionActionSignedReceiptPersistenceWriter.php',
            $paths,
        );
        $this->assertContains(
            'tests/Unit/Ai/SelfConstruction/CodexReviewMergePostExecutionActionSignedReceiptPersistenceWriterTest.php',
            $paths,
        );

        foreach ($ff['future_files'] as $file) {
            $this->assertFalse($file['creation_allowed']);
            $this->assertFalse($file['created']);
        }
    }

    /**
     * Doc "Required Tests" + "Release Conditions": with empty input the preflight
     * is BLOCKED, reports all 8 missing tests + all 6 unmet conditions as the 14
     * standing blockers, and cannot be considered or authorized — yet the boundary
     * still holds.
     */
    public function test_empty_input_is_blocked_with_all_fourteen_blockers(): void
    {
        $result = $this->service()->preflight([]);

        $this->assertSame(
            AtlasCodexMergePEAPWriterImplementationPreflightService::STATUS_BLOCKED,
            $result['status'],
        );
        $this->assertFalse($result['ready']);
        $this->assertFalse($result['implementation_may_be_considered']);
        $this->assertFalse($result['implementation_authorized']);

        // 8 required tests missing + 6 release conditions unmet, no boundary breach.
        $this->assertCount(8, $result['required_tests']['missing_tests']);
        $this->assertCount(6, $result['release_conditions']['unmet_conditions']);
        $this->assertSame(14, $result['blocker_count']);

        $this->assertTrue($result['boundary_held']);
        $this->assertSame([], $result['boundary_violations']);
        $this->assertFalse($result['writer_file_creation_allowed']);

        // Blocker labels are namespaced by kind.
        $this->assertContains('required_test_missing:writer_recomputes_payload_hash', $result['standing_blockers']);
        $this->assertContains('release_condition_unmet:implementation_files_exist', $result['standing_blockers']);
    }

    /**
     * Partial input: providing 7 of 8 tests and all conditions still leaves the
     * one missing test as the sole blocker and keeps the preflight blocked — the
     * cap is the full set, not "most of it".
     */
    public function test_one_missing_test_keeps_it_blocked(): void
    {
        $tests = $this->allTestsPresent();
        unset($tests['writer_is_append_only_write_only']);

        $result = $this->service()->preflight([
            'tests_present' => $tests,
            'release_conditions' => $this->allConditionsMet(),
        ]);

        $this->assertSame(
            AtlasCodexMergePEAPWriterImplementationPreflightService::STATUS_BLOCKED,
            $result['status'],
        );
        $this->assertSame(['writer_is_append_only_write_only'], $result['required_tests']['missing_tests']);
        $this->assertSame(1, $result['blocker_count']);
        $this->assertFalse($result['implementation_may_be_considered']);
        $this->assertFalse($result['implementation_authorized']);
    }

    /**
     * Doc "Human Meaning": even with every test present and every condition met,
     * the preflight reaches READY/may-be-considered but NEVER authorizes. The
     * boundary (incl. writer_file_creation_allowed) stays false regardless.
     */
    public function test_fully_clean_is_considered_but_never_authorized(): void
    {
        $result = $this->service()->preflight([
            'tests_present' => $this->allTestsPresent(),
            'release_conditions' => $this->allConditionsMet(),
        ]);

        $this->assertSame(
            AtlasCodexMergePEAPWriterImplementationPreflightService::STATUS_READY,
            $result['status'],
        );
        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['standing_blockers']);
        $this->assertSame(0, $result['blocker_count']);
        $this->assertTrue($result['implementation_may_be_considered']);

        // The whole point: consideration is never authorization.
        $this->assertFalse($result['implementation_authorized']);
        $this->assertFalse($result['writer_file_creation_allowed']);
        $this->assertFalse($result['boundary']['writer_file_creation_allowed']);

        $this->assertSame(
            'What would block implementing the writer safely?',
            $result['human_question'],
        );
        $this->assertSame(
            'Should the writer be implemented now?',
            $result['does_not_answer'],
        );
    }

    /**
     * assertBoundaryHeld is fail-closed: a sub-surface missing a boundary key, or
     * flipping one truthy, is reported as a violation.
     */
    public function test_boundary_guard_is_fail_closed(): void
    {
        $svc = $this->service();

        // Missing key entirely => breach.
        $this->assertContains('bad.execution_allowed', $svc->assertBoundaryHeld([
            ['surface' => 'bad', 'boundary' => []],
        ]));

        // Truthy flip => breach.
        $this->assertContains('bad.writer_file_creation_allowed', $svc->assertBoundaryHeld([
            ['surface' => 'bad', 'boundary' => ['writer_file_creation_allowed' => true] + $svc->boundary()],
        ]));

        // The real surfaces never breach.
        $this->assertSame([], $svc->assertBoundaryHeld([
            $svc->futureFiles(),
            $svc->requiredTests(),
            $svc->releaseConditions(),
        ]));
    }
}
