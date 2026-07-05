<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedPacketFieldRecoveryMiner;
use Tests\TestCase;

final class AtlasTaskBlockedPacketFieldRecoveryMinerTest extends TestCase
{
    private function svc(): AtlasTaskBlockedPacketFieldRecoveryMiner
    {
        return new AtlasTaskBlockedPacketFieldRecoveryMiner;
    }

    // ── output structure ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->svc()->recover(['objective' => 'Implement AtlasFoo so it validates input.']);

        $this->assertArrayHasKey('recovered_fields', $r);
        $this->assertArrayHasKey('allowed_files', $r['recovered_fields']);
        $this->assertArrayHasKey('acceptance_criteria', $r['recovered_fields']);
        $this->assertArrayHasKey('required_evidence', $r['recovered_fields']);
        $this->assertArrayHasKey('confidence', $r);
        $this->assertArrayHasKey('evidence_sources', $r);
        $this->assertArrayHasKey('refusal_reasons', $r);
        $this->assertSame(AtlasTaskBlockedPacketFieldRecoveryMiner::SCHEMA, $r['schema']);
    }

    // ── inference from scope_in / objective path mentions ──────────────────────

    public function test_allowed_files_recovered_from_scope_in(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement AtlasFoo so it validates input.',
            'scope_in' => ['app/Services/Ai/Foo/AtlasFoo.php', 'tests/Unit/Ai/Foo/AtlasFooTest.php'],
        ]);

        $this->assertContains('app/Services/Ai/Foo/AtlasFoo.php', $r['recovered_fields']['allowed_files']);
        $this->assertContains('tests/Unit/Ai/Foo/AtlasFooTest.php', $r['recovered_fields']['allowed_files']);
        $this->assertContains('scope_in', $r['evidence_sources']);
    }

    public function test_allowed_files_recovered_from_objective_path_mention(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement app/Services/Ai/Foo/AtlasFoo.php and tests/Unit/Ai/Foo/AtlasFooTest.php so it validates input.',
        ]);

        $this->assertContains('app/Services/Ai/Foo/AtlasFoo.php', $r['recovered_fields']['allowed_files']);
        $this->assertContains('objective_path_mention', $r['evidence_sources']);
    }

    public function test_recovered_acceptance_criteria_runs_the_inferred_test_path(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement app/Services/Ai/Foo/AtlasFoo.php and tests/Unit/Ai/Foo/AtlasFooTest.php so it validates input.',
        ]);

        $hasTestCommand = false;
        foreach ($r['recovered_fields']['acceptance_criteria'] as $c) {
            if (str_contains($c, 'tests/Unit/Ai/Foo/AtlasFooTest.php')) {
                $hasTestCommand = true;
            }
        }
        $this->assertTrue($hasTestCommand);
        $this->assertContains('test_path_inferred_acceptance', $r['evidence_sources']);
    }

    public function test_recovered_required_evidence_is_minimal_standard_set(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement app/Services/Ai/Foo/AtlasFoo.php so it validates input thoroughly.',
        ]);

        $this->assertSame(['tests_or_gates_result', 'implementation_notes'], $r['recovered_fields']['required_evidence']);
        $this->assertContains('objective_concreteness_default_evidence', $r['evidence_sources']);
    }

    // ── refusal: ambiguous paths ─────────────────────────────────────────────────

    public function test_refuses_when_multiple_implementation_candidates_are_ambiguous(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Touch app/Services/Ai/Foo/AtlasFoo.php and app/Services/Ai/Bar/AtlasBar.php to fix something.',
        ]);

        $this->assertContains('ambiguous_implementation_path_candidates', $r['refusal_reasons']);
        $this->assertSame([], $r['recovered_fields']['allowed_files']);
    }

    // ── refusal: forbidden / property-gated target without evidence ────────────

    public function test_refuses_when_target_is_forbidden_without_governance_evidence(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php so it validates input.',
            'metadata' => [
                'forbidden_or_property_gated' => ['app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php'],
            ],
        ]);

        $this->assertContains('target_forbidden_or_property_gated_without_evidence', $r['refusal_reasons']);
        $this->assertSame([], $r['recovered_fields']['allowed_files']);
    }

    public function test_recovers_forbidden_looking_target_when_governance_evidence_present(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php so it validates input.',
            'metadata' => [
                'forbidden_or_property_gated' => ['app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php'],
                'governance_evidence_present' => true,
            ],
        ]);

        $this->assertNotContains('target_forbidden_or_property_gated_without_evidence', $r['refusal_reasons']);
        $this->assertContains('app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php', $r['recovered_fields']['allowed_files']);
    }

    // ── refusal: objective lacks concrete clue ──────────────────────────────────

    public function test_refuses_when_objective_lacks_concrete_class_path_or_test_clue(): void
    {
        $r = $this->svc()->recover(['objective' => 'make things better in general']);

        $this->assertContains('objective_lacks_concrete_class_path_or_test_clue', $r['refusal_reasons']);
        $this->assertSame([], $r['recovered_fields']['allowed_files']);
    }

    public function test_refuses_when_only_bare_class_name_mentioned_without_path(): void
    {
        $r = $this->svc()->recover(['objective' => 'Improve AtlasFoo so it is better.']);

        $this->assertContains('class_name_mentioned_without_concrete_path', $r['refusal_reasons']);
    }

    public function test_refuses_required_evidence_when_objective_too_short(): void
    {
        $r = $this->svc()->recover(['objective' => 'fix it']);

        $this->assertSame([], $r['recovered_fields']['required_evidence']);
        $this->assertContains('objective_not_concrete_enough_for_evidence_defaults', $r['refusal_reasons']);
    }

    // ── AC3: existing fields are never overwritten ──────────────────────────────

    public function test_existing_allowed_files_are_not_overwritten(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement app/Services/Ai/Other/Thing.php for something different.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php'],
        ]);

        $this->assertSame(['app/Services/Ai/Foo/AtlasFoo.php'], $r['recovered_fields']['allowed_files']);
    }

    public function test_existing_acceptance_criteria_are_not_overwritten(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement app/Services/Ai/Foo/AtlasFoo.php so it validates input.',
            'acceptance_criteria' => ['existing custom criterion'],
        ]);

        $this->assertSame(['existing custom criterion'], $r['recovered_fields']['acceptance_criteria']);
    }

    public function test_existing_required_evidence_is_not_overwritten(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement app/Services/Ai/Foo/AtlasFoo.php so it validates input.',
            'required_evidence' => ['custom_evidence_kind'],
        ]);

        $this->assertSame(['custom_evidence_kind'], $r['recovered_fields']['required_evidence']);
    }

    // ── confidence ────────────────────────────────────────────────────────────

    public function test_confidence_is_a_float_between_zero_and_one(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement app/Services/Ai/Foo/AtlasFoo.php and tests/Unit/Ai/Foo/AtlasFooTest.php so it validates input.',
        ]);

        $this->assertIsFloat($r['confidence']);
        $this->assertGreaterThanOrEqual(0.0, $r['confidence']);
        $this->assertLessThanOrEqual(1.0, $r['confidence']);
    }

    public function test_confidence_is_zero_when_everything_is_refused(): void
    {
        $r = $this->svc()->recover(['objective' => 'fix it']);

        $this->assertSame(0.0, $r['confidence']);
    }

    // ── AC2/AC3: recoveries list — recoverable scope gap ────────────────────────

    public function test_recoverable_scope_gap_produces_high_confidence_safe_recovery(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement AtlasFoo so it validates input.',
            'scope_in' => ['app/Services/Ai/Foo/AtlasFoo.php', 'tests/Unit/Ai/Foo/AtlasFooTest.php'],
        ]);

        $entry = $this->findRecovery($r['recoveries'], 'missing_implementation_file');
        $this->assertNotNull($entry);
        $this->assertGreaterThanOrEqual(0.5, $entry['confidence']);
        $this->assertTrue($entry['safe_to_respec']);
        $this->assertNotEmpty($entry['source_evidence']);
    }

    // ── unrecoverable forbidden target ──────────────────────────────────────────

    public function test_unrecoverable_forbidden_target_is_never_safe_to_respec(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php so it validates input.',
            'metadata' => [
                'forbidden_or_property_gated' => ['app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php'],
            ],
        ]);

        $entry = $this->findRecovery($r['recoveries'], 'forbidden_target');
        $this->assertNotNull($entry);
        $this->assertSame(0.0, $entry['confidence']);
        $this->assertFalse($entry['safe_to_respec']);
    }

    // ── missing runnable proof ───────────────────────────────────────────────────

    public function test_missing_runnable_proof_is_recovered_from_existing_test_path(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement AtlasFoo so it validates input thoroughly across edge cases.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php', 'tests/Unit/Ai/Foo/AtlasFooTest.php'],
            'acceptance_criteria' => ['Must validate thoroughly'],
        ]);

        $entry = $this->findRecovery($r['recoveries'], 'missing_runnable_proof');
        $this->assertNotNull($entry);
        $this->assertTrue($entry['safe_to_respec']);
        $this->assertContains('test_path_inferred_acceptance', $entry['source_evidence']);
    }

    // ── contradictory acceptance ─────────────────────────────────────────────────

    public function test_contradictory_acceptance_is_flagged_and_never_safe(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement AtlasFoo so it validates input.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php'],
            'acceptance_criteria' => [
                'result must be idempotent',
                'result must not be idempotent',
            ],
        ]);

        $entry = $this->findRecovery($r['recoveries'], 'contradictory_acceptance');
        $this->assertNotNull($entry);
        $this->assertSame(0.0, $entry['confidence']);
        $this->assertFalse($entry['safe_to_respec']);
    }

    // ── test-only scope ───────────────────────────────────────────────────────────

    public function test_test_only_scope_is_flagged_and_never_safe(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement AtlasFoo so it validates input.',
            'allowed_files' => ['tests/Unit/Ai/Foo/AtlasFooTest.php'],
        ]);

        $entry = $this->findRecovery($r['recoveries'], 'test_only_scope');
        $this->assertNotNull($entry);
        $this->assertFalse($entry['safe_to_respec']);
    }

    // ── dependency inversion ──────────────────────────────────────────────────────

    public function test_dependency_inversion_is_flagged_when_dependency_matches_own_scope(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement AtlasFoo so it validates input.',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFoo.php'],
            'dependencies' => ['app/Services/Ai/Foo/AtlasFoo.php'],
        ]);

        $entry = $this->findRecovery($r['recoveries'], 'dependency_inversion');
        $this->assertNotNull($entry);
        $this->assertTrue($entry['safe_to_respec']);
    }

    // ── low-confidence output ─────────────────────────────────────────────────────

    public function test_low_confidence_recovery_is_never_safe_to_respec(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Update app/Foo.php now.',
        ]);

        $entry = $this->findRecovery($r['recoveries'], 'missing_implementation_file');
        $this->assertNotNull($entry);
        $this->assertLessThan(0.5, $entry['confidence']);
        $this->assertFalse($entry['safe_to_respec']);
    }

    /**
     * @param  list<array<string,mixed>>  $recoveries
     * @return array<string,mixed>|null
     */
    private function findRecovery(array $recoveries, string $field): ?array
    {
        foreach ($recoveries as $entry) {
            if ($entry['recovered_field'] === $field) {
                return $entry;
            }
        }

        return null;
    }

    // ── determinism ───────────────────────────────────────────────────────────

    public function test_recover_is_deterministic(): void
    {
        $packet = [
            'objective' => 'Implement app/Services/Ai/Foo/AtlasFoo.php and tests/Unit/Ai/Foo/AtlasFooTest.php so it validates input.',
        ];

        $a = $this->svc()->recover($packet);
        $b = $this->svc()->recover($packet);

        $this->assertSame(json_encode($a, JSON_UNESCAPED_SLASHES), json_encode($b, JSON_UNESCAPED_SLASHES));
    }

    public function test_forbidden_directory_hint_catches_file_under_it_via_prefix_aware_match(): void
    {
        $r = $this->svc()->recover([
            'objective' => 'Implement app/Services/Ai/AutonomousEvolution/Constitution/Bylaw.php so it validates input.',
            'metadata' => [
                'forbidden_or_property_gated' => ['app/Services/Ai/AutonomousEvolution/'],
            ],
        ]);

        $this->assertContains('target_forbidden_or_property_gated_without_evidence', $r['refusal_reasons']);
        $this->assertSame([], $r['recovered_fields']['allowed_files']);
    }
}
