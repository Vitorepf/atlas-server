<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Rsi\EarnedAutonomy;

use App\Services\Ai\Foundry\Rsi\EarnedAutonomy\RiskClassifierService;
use App\Services\Ai\Foundry\Rsi\ImmutableInvariantRegistryService;
use Tests\TestCase;

/**
 * Earned Autonomy · Risk Classifier proof.
 *
 * Proves the ordered risk ladder and the SACRED SAFETY PROPERTY relevant to this
 * leaf: conservative-by-default — an ambiguous / unreadable change can NEVER be
 * under-classified; it always lands at the highest plausible class
 * (gate_or_invariant_touch, rank 3, the ceiling that never auto-applies).
 *
 *   C1 — cosmetic: only comments / whitespace added on a .php file => rank 0.
 *   C2 — non_sacred_logic: behavioural .php change in a non-sacred file => rank 1.
 *   C3 — ledger_or_schema: a Ledger path or schema-declaring line => rank 2.
 *   C4 — gate_or_invariant_touch: a Gate / Policy authority file => rank 3.
 *   C5 — sacred-signature in any added line forces rank 3 even on a benign path.
 *   C6 — touching a registry-owned sacred path => rank 3 (sacred_touch=true).
 *   C7 — SAFETY: empty / unreadable changed_paths fails CLOSED to rank 3.
 *   C8 — rankOf() fails closed: an unknown class maps to the highest rank (3).
 *   C9 — the ladder ranks are strictly ascending and frozen; purity (no provider).
 */
final class RiskClassifierServiceTest extends TestCase
{
    private function classifier(): RiskClassifierService
    {
        // Real registry (read-only) is fine — these diffs do not touch registry
        // files except the explicit sacred-path case, which uses a known path.
        return new RiskClassifierService(app(ImmutableInvariantRegistryService::class));
    }

    public function test_cosmetic_only_comment_change_is_rank_0(): void
    {
        $result = $this->classifier()->classify([
            'changed_paths' => ['app/Services/Ai/Foundry/SomeRepairLoopHeuristic.php'],
            'added_lines' => [
                '// clarify the intent of this branch',
                '   ',
                '* @return void',
            ],
        ]);

        $this->assertSame(RiskClassifierService::RISK_CLASS_COSMETIC, $result['risk_class']);
        $this->assertSame(0, $result['risk_rank']);
        $this->assertFalse($result['sacred_touch']);
        $this->assertFalse($result['provider_invoked']);
    }

    public function test_behavioural_php_change_is_non_sacred_logic_rank_1(): void
    {
        $result = $this->classifier()->classify([
            'changed_paths' => ['app/Services/Ai/Foundry/SomeRepairLoopHeuristic.php'],
            'added_lines' => [
                '// tune the retry budget',
                'if ($attempts > $this->maxRetries) {',
            ],
        ]);

        $this->assertSame(RiskClassifierService::RISK_CLASS_NON_SACRED_LOGIC, $result['risk_class']);
        $this->assertSame(1, $result['risk_rank']);
        $this->assertFalse($result['sacred_touch']);
    }

    public function test_ledger_writer_path_is_ledger_or_schema_rank_2(): void
    {
        $result = $this->classifier()->classify([
            'changed_paths' => ['app/Services/Ai/Foundry/Frontier/SomeValueLedgerService.php'],
            'added_lines' => [
                '$this->records[] = $event;',
            ],
        ]);

        $this->assertSame(RiskClassifierService::RISK_CLASS_LEDGER_OR_SCHEMA, $result['risk_class']);
        $this->assertSame(2, $result['risk_rank']);
        $this->assertFalse($result['sacred_touch']);
    }

    public function test_schema_declaring_line_is_ledger_or_schema_rank_2(): void
    {
        $result = $this->classifier()->classify([
            'changed_paths' => ['app/Services/Ai/Foundry/SomeWriterService.php'],
            'added_lines' => [
                "public const SCHEMA = 'atlas.foundry.something.v1';",
            ],
        ]);

        $this->assertSame(RiskClassifierService::RISK_CLASS_LEDGER_OR_SCHEMA, $result['risk_class']);
        $this->assertSame(2, $result['risk_rank']);
    }

    public function test_gate_class_authority_file_is_rank_3(): void
    {
        $result = $this->classifier()->classify([
            'changed_paths' => ['app/Services/Ai/Foundry/SomeNewAdmissionGate.php'],
            'added_lines' => [
                'return true;',
            ],
        ]);

        $this->assertSame(RiskClassifierService::RISK_CLASS_GATE_OR_INVARIANT_TOUCH, $result['risk_class']);
        $this->assertSame(3, $result['risk_rank']);
        $this->assertTrue($result['sacred_touch']);
    }

    public function test_policy_class_authority_file_is_rank_3(): void
    {
        $result = $this->classifier()->classify([
            'changed_paths' => ['app/Services/Ai/Foundry/SomeRiskPolicy.php'],
            'added_lines' => ['$x = 1;'],
        ]);

        $this->assertSame(RiskClassifierService::RISK_CLASS_GATE_OR_INVARIANT_TOUCH, $result['risk_class']);
        $this->assertSame(3, $result['risk_rank']);
        $this->assertTrue($result['sacred_touch']);
    }

    public function test_sacred_signature_in_added_line_forces_rank_3_on_benign_path(): void
    {
        // A perfectly benign .php product path, but the diff smuggles an
        // auto-canonize / eligibility-override signature => MUST classify rank 3.
        $result = $this->classifier()->classify([
            'changed_paths' => ['app/Services/Ai/Foundry/SomeHarmlessHelper.php'],
            'added_lines' => [
                "\$config['auto_apply'] = true;",
            ],
        ]);

        $this->assertSame(RiskClassifierService::RISK_CLASS_GATE_OR_INVARIANT_TOUCH, $result['risk_class']);
        $this->assertSame(3, $result['risk_rank']);
        $this->assertTrue($result['sacred_touch']);
    }

    public function test_eligibility_override_signature_in_removed_line_forces_rank_3(): void
    {
        $result = $this->classifier()->classify([
            'changed_paths' => ['app/Services/Ai/Foundry/SomeHarmlessHelper.php'],
            'removed_lines' => [
                'app/Services/Ai/Foundry/SomeHarmlessHelper.php' => [
                    'if ($this->forceEligible) { /* override_invariant */ }',
                ],
            ],
        ]);

        $this->assertSame(RiskClassifierService::RISK_CLASS_GATE_OR_INVARIANT_TOUCH, $result['risk_class']);
        $this->assertSame(3, $result['risk_rank']);
        $this->assertTrue($result['sacred_touch']);
    }

    public function test_touching_a_registry_owned_sacred_path_is_rank_3(): void
    {
        // The registry deliberately lists its OWN file as sacred.
        $result = $this->classifier()->classify([
            'changed_paths' => ['app/Services/Ai/Foundry/Rsi/ImmutableInvariantRegistryService.php'],
            'added_lines' => ['// harmless looking comment'],
        ]);

        $this->assertSame(RiskClassifierService::RISK_CLASS_GATE_OR_INVARIANT_TOUCH, $result['risk_class']);
        $this->assertSame(3, $result['risk_rank']);
        $this->assertTrue($result['sacred_touch']);
    }

    public function test_empty_changed_paths_fails_closed_to_rank_3(): void
    {
        // SAFETY PROPERTY: an unreadable / empty diff is NEVER classified low.
        $result = $this->classifier()->classify([
            'changed_paths' => [],
            'added_lines' => ['$x = 1;'],
        ]);

        $this->assertSame(RiskClassifierService::RISK_CLASS_GATE_OR_INVARIANT_TOUCH, $result['risk_class']);
        $this->assertSame(3, $result['risk_rank']);
        $this->assertTrue($result['sacred_touch']);
    }

    public function test_missing_changed_paths_key_fails_closed_to_rank_3(): void
    {
        $result = $this->classifier()->classify([]);

        $this->assertSame(RiskClassifierService::RISK_CLASS_GATE_OR_INVARIANT_TOUCH, $result['risk_class']);
        $this->assertSame(3, $result['risk_rank']);
        $this->assertTrue($result['sacred_touch']);
    }

    public function test_rank_of_fails_closed_for_unknown_class(): void
    {
        $c = $this->classifier();

        $this->assertSame(0, $c->rankOf(RiskClassifierService::RISK_CLASS_COSMETIC));
        $this->assertSame(1, $c->rankOf(RiskClassifierService::RISK_CLASS_NON_SACRED_LOGIC));
        $this->assertSame(2, $c->rankOf(RiskClassifierService::RISK_CLASS_LEDGER_OR_SCHEMA));
        $this->assertSame(3, $c->rankOf(RiskClassifierService::RISK_CLASS_GATE_OR_INVARIANT_TOUCH));
        // Unknown / forged class => highest rank, never a low one.
        $this->assertSame(3, $c->rankOf('totally_made_up_class'));
        $this->assertSame(3, $c->rankOf(''));
    }

    public function test_ladder_is_strictly_ascending_and_deterministic(): void
    {
        $c = $this->classifier();

        $cosmetic = $c->rankOf(RiskClassifierService::RISK_CLASS_COSMETIC);
        $logic = $c->rankOf(RiskClassifierService::RISK_CLASS_NON_SACRED_LOGIC);
        $ledger = $c->rankOf(RiskClassifierService::RISK_CLASS_LEDGER_OR_SCHEMA);
        $gate = $c->rankOf(RiskClassifierService::RISK_CLASS_GATE_OR_INVARIANT_TOUCH);

        $this->assertTrue($cosmetic < $logic && $logic < $ledger && $ledger < $gate);

        // Deterministic: same input => identical hash.
        $diff = [
            'changed_paths' => ['app/Services/Ai/Foundry/SomeRepairLoopHeuristic.php'],
            'added_lines' => ['if ($x) { return; }'],
        ];
        $a = $c->classify($diff);
        $b = $c->classify($diff);
        $this->assertSame($a['classification_hash'], $b['classification_hash']);
        $this->assertSame(RiskClassifierService::SCHEMA, $a['schema_version']);
        $this->assertFalse($a['provider_invoked']);
    }

    public function test_set_registry_for_testing_seam_swaps_registry(): void
    {
        $c = $this->classifier();
        $c->setRegistryForTesting(new ImmutableInvariantRegistryService());

        $result = $c->classify([
            'changed_paths' => ['app/Services/Ai/Foundry/SomeRepairLoopHeuristic.php'],
            'added_lines' => ['$x = compute();'],
        ]);

        $this->assertSame(RiskClassifierService::RISK_CLASS_NON_SACRED_LOGIC, $result['risk_class']);
    }
}
