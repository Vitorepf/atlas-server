<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopExecutionContract;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternCompiler;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternDecisionDriver;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternRegistry;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSelector;
use App\Services\Ai\AutonomousEvolution\Pattern\AtlasLoopPatternSpec;
use RuntimeException;

/**
 * LOOP-PATTERN-REGISTRY · A0 — the DECISION DRIVER contract. Proves the driver DRIVES the pre-origination
 * work choice: it walks the candidates in order and selects the FIRST the selector accepts, rejecting
 * cosmetic / negligible / non-finite-impact ones with receipts; returns a governed terminal when none is
 * acceptable; is FAIL-CLOSED on a throwing selector and on a compile failure; and compiles the contract
 * with the DRIVER-selected pattern. Pure + provider-free (real Selector/Registry/Compiler).
 */
final class AtlasLoopPatternDecisionDriverTest extends \Tests\TestCase
{
    private function driver(): AtlasLoopPatternDecisionDriver
    {
        return new AtlasLoopPatternDecisionDriver;
    }

    private function selector(): AtlasLoopPatternSelector
    {
        return new AtlasLoopPatternSelector;
    }

    private function registry(): AtlasLoopPatternRegistry
    {
        return new AtlasLoopPatternRegistry;
    }

    /**
     * A descriptor builder reading the candidate's OWN signals — the same shape the producer feeds the
     * driver (kind / leverage→impact / cosmetic). expected_impact 0 when leverage is absent/non-finite, so
     * the selector's negligible-impact gate refuses it.
     *
     * @return callable(array<string,mixed>):array<string,mixed>
     */
    private function descriptorFor(): callable
    {
        return static function (array $c): array {
            $leverage = (float) ($c['_score']['leverage'] ?? 0.0);
            $impact = is_finite($leverage) ? min(1.0, max(0.0, $leverage / 28.0)) : 0.0;

            return [
                'objective_kind' => (string) ($c['kind'] ?? 'refactor'),
                'expected_impact' => $impact,
                'evidence' => ((bool) ($c['verifiable'] ?? false)) ? 0.8 : 0.3,
                'risk' => (float) ($c['risk'] ?? 0.4),
                'cost' => (float) ($c['cost'] ?? 0.3),
                'cosmetic' => (bool) ($c['cosmetic'] ?? false),
                'touches_loop' => true,
            ];
        };
    }

    /** THE load-bearing proof: candidate #1 would win on order, but the driver REJECTS it (cosmetic) and #2 wins. */
    public function test_rejects_the_cosmetic_top_candidate_and_selects_the_runner_up(): void
    {
        $candidates = [
            // #1 — the top-of-order candidate, but COSMETIC: the selector must refuse it.
            ['path' => 'app/Svc/Cosmetic.php', 'kind' => 'refactor', 'cosmetic' => true, '_score' => ['leverage' => 30.0]],
            // #2 — a real, high-leverage refactor: the runner-up the driver must pick instead.
            ['path' => 'app/Svc/Real.php', 'kind' => 'refactor', 'verifiable' => true, '_score' => ['leverage' => 20.0]],
        ];

        $decision = $this->driver()->decide($candidates, $this->descriptorFor(), $this->selector(), $this->registry());

        $this->assertSame(AtlasLoopPatternDecisionDriver::STATE_SELECTED, $decision['state']);
        $this->assertSame(1, $decision['selected_index'], 'the cosmetic #1 is skipped; the real #2 is chosen');
        $this->assertSame('app/Svc/Real.php', $decision['selected']['path']);
        $this->assertInstanceOf(AtlasLoopPatternSpec::class, $decision['pattern']);
        $this->assertSame('ticket_to_pr_ready', $decision['pattern']->id);

        // The rejection of #1 is receipted (honest provenance of the changed decision).
        $this->assertCount(1, $decision['rejections']);
        $this->assertSame(0, $decision['rejections'][0]['index']);
        $this->assertSame('app/Svc/Cosmetic.php', $decision['rejections'][0]['path']);
        $this->assertStringContainsStringIgnoringCase('cosmetic', $decision['rejections'][0]['reason']);
    }

    /** When EVERY candidate is cosmetic/negligible, the driver refuses all — no objective is invented. */
    public function test_rejects_all_when_no_candidate_is_acceptable(): void
    {
        $candidates = [
            ['path' => 'a.php', 'kind' => 'refactor', 'cosmetic' => true, '_score' => ['leverage' => 30.0]],
            ['path' => 'b.php', 'kind' => 'refactor', '_score' => ['leverage' => 0.0]],          // negligible impact
            ['path' => 'c.php', 'kind' => 'marketing_blast', '_score' => ['leverage' => 30.0]],   // unknown kind
        ];

        $decision = $this->driver()->decide($candidates, $this->descriptorFor(), $this->selector(), $this->registry());

        $this->assertSame(AtlasLoopPatternDecisionDriver::STATE_REJECTED_ALL, $decision['state']);
        $this->assertNull($decision['pattern']);
        $this->assertNull($decision['selected']);
        $this->assertCount(3, $decision['rejections'], 'every candidate carries a rejection receipt');
    }

    public function test_empty_candidate_set_is_a_governed_no_op(): void
    {
        $decision = $this->driver()->decide([], $this->descriptorFor(), $this->selector(), $this->registry());

        $this->assertSame(AtlasLoopPatternDecisionDriver::STATE_NO_CANDIDATES, $decision['state']);
        $this->assertNull($decision['pattern']);
        $this->assertSame([], $decision['rejections']);
    }

    /** A non-finite (NaN/INF) or absent leverage maps to ZERO impact and is refused — never healthy work. */
    public function test_non_finite_or_absent_impact_is_refused(): void
    {
        foreach (['NaN' => NAN, '+INF' => INF, '-INF' => -INF] as $label => $leverage) {
            $candidates = [['path' => "bad-{$label}.php", 'kind' => 'refactor', '_score' => ['leverage' => $leverage]]];

            $decision = $this->driver()->decide($candidates, $this->descriptorFor(), $this->selector(), $this->registry());

            $this->assertSame(AtlasLoopPatternDecisionDriver::STATE_REJECTED_ALL, $decision['state'], "{$label} must be refused");
            $this->assertNull($decision['pattern']);
        }

        // absent leverage altogether → impact 0 → refused.
        $decision = $this->driver()->decide(
            [['path' => 'no-leverage.php', 'kind' => 'refactor']],
            $this->descriptorFor(),
            $this->selector(),
            $this->registry(),
        );
        $this->assertSame(AtlasLoopPatternDecisionDriver::STATE_REJECTED_ALL, $decision['state']);
    }

    /** FAIL-CLOSED: a throwing descriptor/selector stops the cycle with a null pattern, not a silent skip. */
    public function test_selector_failure_is_fail_closed(): void
    {
        $throwing = static function (array $c): array {
            throw new RuntimeException('descriptor/selector layer down');
        };

        $candidates = [['path' => 'x.php', 'kind' => 'refactor', '_score' => ['leverage' => 20.0]]];

        $decision = $this->driver()->decide($candidates, $throwing, $this->selector(), $this->registry());

        $this->assertSame(AtlasLoopPatternDecisionDriver::STATE_SELECTOR_FAILED, $decision['state']);
        $this->assertNull($decision['pattern'], 'a failed selector yields NO pattern (caller must abort)');
        $this->assertNotEmpty($decision['rejections']);
        $this->assertStringContainsStringIgnoringCase('selector_error', $decision['rejections'][0]['reason']);
    }

    /** The DRIVER-selected pattern compiles into a complete, gated contract bound to the same pattern. */
    public function test_compiles_the_selected_pattern_into_a_complete_contract(): void
    {
        $candidates = [['path' => 'app/Svc/Real.php', 'kind' => 'refactor', 'verifiable' => true, '_score' => ['leverage' => 20.0]]];

        $decision = $this->driver()->decide($candidates, $this->descriptorFor(), $this->selector(), $this->registry());
        $this->assertSame(AtlasLoopPatternDecisionDriver::STATE_SELECTED, $decision['state']);

        [$contract, $error] = $this->driver()->compileContract($decision['pattern'], [
            'objective' => 'reduce coupling in Real with a RED-proven refactor',
            'allowed_scope' => ['app/Svc/Real.php'],
            'required_inputs' => ['target_path'],
            'expected_outputs' => $decision['pattern']->outputSchema,
            'budget' => [],
        ], new AtlasLoopPatternCompiler);

        $this->assertNull($error);
        $this->assertInstanceOf(AtlasLoopExecutionContract::class, $contract);
        $this->assertSame([], AtlasLoopExecutionContract::missingFields($contract->toArray()));
        $this->assertSame($decision['pattern']->id, $contract->patternId, 'contract is bound to the driver-selected pattern');
    }

    /** FAIL-CLOSED: an un-compilable objective (empty text) returns [null, error], never a half contract. */
    public function test_compile_failure_is_fail_closed(): void
    {
        $candidates = [['path' => 'app/Svc/Real.php', 'kind' => 'refactor', 'verifiable' => true, '_score' => ['leverage' => 20.0]]];
        $decision = $this->driver()->decide($candidates, $this->descriptorFor(), $this->selector(), $this->registry());

        [$contract, $error] = $this->driver()->compileContract($decision['pattern'], [
            'objective' => '   ', // blank objective → the compiler's up-front guard throws
            'allowed_scope' => ['app/Svc/Real.php'],
            'required_inputs' => ['target_path'],
            'expected_outputs' => $decision['pattern']->outputSchema,
            'budget' => [],
        ], new AtlasLoopPatternCompiler);

        $this->assertNull($contract, 'an un-compilable objective yields NO contract');
        $this->assertIsString($error);
        $this->assertNotSame('', $error, 'the compile failure is surfaced, not swallowed');
    }
}
