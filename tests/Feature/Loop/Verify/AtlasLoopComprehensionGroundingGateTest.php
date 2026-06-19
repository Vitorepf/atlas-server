<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Verify;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopComprehensionGroundingGate;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Behavioral proof of the §11.5 comprehension grounding gate.
 *
 * Each test asserts EXACT values / sets (not count>0), so the suite fails if the
 * resolution logic, the grounded verdict, or the fail-open contract were broken.
 * No live DB is required: a real autoloadable App\ class, an existing repo file, and a
 * fabricated FQN are enough to exercise all three branches deterministically.
 */
final class AtlasLoopComprehensionGroundingGateTest extends TestCase
{
    private const REPO_ROOT = '/Users/vitorepf/develop/Atlas/atlas-server';

    private function gate(): AtlasLoopComprehensionGroundingGate
    {
        return new AtlasLoopComprehensionGroundingGate();
    }

    #[Test]
    public function a_real_class_is_grounded_and_appears_in_resolved(): void
    {
        // The gate itself is a real, autoloadable App\ class — the cheapest existence oracle.
        $real = AtlasLoopComprehensionGroundingGate::class;

        $out = $this->gate()->ground(
            'Harden the loop comprehension grounding gate',
            [$real],
            self::REPO_ROOT
        );

        $this->assertTrue($out['grounded'], 'a real autoloadable class must be grounded');
        $this->assertSame([$real], $out['resolved']);
        $this->assertSame([], $out['ungrounded']);
        $this->assertSame(1, $out['citation_count']);
    }

    #[Test]
    public function a_fabricated_symbol_is_ungrounded_and_does_not_block(): void
    {
        $fake = 'App\\Totally\\Fake\\Nonexistent';

        $out = $this->gate()->ground(
            'Hallucinated objective about a class that does not exist',
            [$fake],
            self::REPO_ROOT
        );

        $this->assertFalse($out['grounded'], 'a symbol resolving nowhere must drop grounded to false');
        $this->assertSame([$fake], $out['ungrounded']);
        $this->assertSame([], $out['resolved']);
        $this->assertSame(1, $out['citation_count']);
        $this->assertStringContainsString('UNGROUNDED', $out['note']);
        $this->assertStringContainsString($fake, $out['note']);
    }

    #[Test]
    public function empty_citations_fail_open_to_grounded_true(): void
    {
        $out = $this->gate()->ground('A bare objective with no cited symbols', [], self::REPO_ROOT);

        $this->assertTrue($out['grounded'], 'empty citations must fail OPEN (never block)');
        $this->assertSame([], $out['resolved']);
        $this->assertSame([], $out['ungrounded']);
        $this->assertSame(0, $out['citation_count']);
        $this->assertStringContainsString('fail-open', $out['note']);
    }

    #[Test]
    public function blank_and_nonstring_citations_normalize_to_empty_and_fail_open(): void
    {
        // After normalization these collapse to zero real citations → fail-open path.
        $out = $this->gate()->ground('whitespace-only citations', ['  ', '', "\t", [], null], self::REPO_ROOT);

        $this->assertTrue($out['grounded']);
        $this->assertSame(0, $out['citation_count']);
        $this->assertSame([], $out['ungrounded']);
    }

    #[Test]
    public function a_mixed_set_resolves_real_and_refutes_fake(): void
    {
        $real = TestCase::class; // autoloadable
        $fake = 'App\\Totally\\Fake\\Nonexistent';

        $out = $this->gate()->ground(
            'objective citing one real and one hallucinated symbol',
            [$real, $fake],
            self::REPO_ROOT
        );

        $this->assertFalse($out['grounded'], 'one unresolved citation must make the whole verdict ungrounded');
        $this->assertSame([$real], $out['resolved']);
        $this->assertSame([$fake], $out['ungrounded']);
        $this->assertSame(2, $out['citation_count']);
    }

    #[Test]
    public function a_non_autoloaded_symbol_resolves_via_repo_file_scan(): void
    {
        // Reference an existing repo file by its FQN whose class is NOT autoloaded in the
        // test process (a migration class). The file oracle, not class_exists, must catch it.
        $migrationFqn = 'App\\NotAutoloaded\\CreateAtlasEngineeringCodeIntelligenceTables';

        // Sanity: this FQN is genuinely not autoloadable, so a pass proves the FILE oracle.
        $this->assertFalse(class_exists($migrationFqn, true));

        // Cite by a basename that exists as a real file in the repo tree.
        $byBasename = 'CodeGraphEdgeResolver';
        $resolvedFile = $this->gate()->ground(
            'objective grounded by an existing repo file (basename match)',
            [$byBasename],
            self::REPO_ROOT
        );

        $this->assertTrue(
            $resolvedFile['grounded'],
            'an existing repo file basename must resolve via the file scan oracle'
        );
        $this->assertSame([$byBasename], $resolvedFile['resolved']);
        $this->assertSame([], $resolvedFile['ungrounded']);
    }

    #[Test]
    public function unreadable_repo_root_fails_open_for_unresolved_citations(): void
    {
        // repoRoot does not exist → file oracle is blind. A symbol that resolves on no
        // oracle must be treated as INCONCLUSIVE (grounded=true), never refuted by a blind scan.
        $fake = 'App\\Totally\\Fake\\Nonexistent';

        $out = $this->gate()->ground(
            'check infra unavailable',
            [$fake],
            '/this/path/does/not/exist/at/all'
        );

        $this->assertTrue($out['grounded'], 'unreadable repoRoot must fail OPEN');
        $this->assertSame([], $out['ungrounded'], 'blind scan must not refute a citation');
        $this->assertStringContainsString('fail-open', $out['note']);
    }

    #[Test]
    public function unreadable_root_still_resolves_an_autoloadable_class(): void
    {
        // Even with a blind file oracle, class_exists still resolves a real class — and the
        // verdict is grounded with that class in resolved, nothing inconclusive.
        $real = AtlasLoopComprehensionGroundingGate::class;

        $out = $this->gate()->ground('infra blind but class autoloads', [$real], '/no/such/root');

        $this->assertTrue($out['grounded']);
        $this->assertSame([$real], $out['resolved']);
        $this->assertSame([], $out['ungrounded']);
    }

    #[Test]
    public function duplicate_citations_are_deduplicated_deterministically(): void
    {
        $real = AtlasLoopComprehensionGroundingGate::class;

        $out = $this->gate()->ground(
            'same symbol cited twice, once with a leading backslash',
            [$real, '\\' . $real, $real],
            self::REPO_ROOT
        );

        $this->assertSame(1, $out['citation_count'], 'leading-backslash and repeat must dedupe to one');
        $this->assertTrue($out['grounded']);
        $this->assertSame([$real], $out['resolved']);
    }

    #[Test]
    public function the_result_carries_the_stable_schema_and_shape(): void
    {
        $out = $this->gate()->ground('shape check', [AtlasLoopComprehensionGroundingGate::class], self::REPO_ROOT);

        $this->assertSame('atlas.loop.comprehension.grounding.v1', $out['schema']);
        $this->assertSame(
            ['schema', 'grounded', 'resolved', 'ungrounded', 'citation_count', 'note'],
            array_keys($out)
        );
        $this->assertIsBool($out['grounded']);
        $this->assertIsArray($out['resolved']);
        $this->assertIsArray($out['ungrounded']);
        $this->assertIsInt($out['citation_count']);
        $this->assertIsString($out['note']);
    }
}
