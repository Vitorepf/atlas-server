<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\AtlasCortexDocIntentLens;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\CortexSubject;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\LensObservation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the doc-intent lens emits a class_summary fact for a documented class, a doc_absent fact when no
 * docblock is present, declared_doc_ref facts for docs/loop-*.md|cortex-*.md|maestro-*.md mentions, and a
 * 'doc_intent_drift' disagreement when a referenced doc file does not exist on disk. Confirms by reflection
 * that LensObservation carries no scoring fields.
 */
final class AtlasCortexDocIntentLensTest extends TestCase
{
    private string $tmpRepo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpRepo = sys_get_temp_dir().'/atlas_docintent_'.bin2hex(random_bytes(6));
        mkdir($this->tmpRepo.'/docs', 0775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpRepo)) {
            shell_exec('rm -rf '.escapeshellarg($this->tmpRepo));
        }
        parent::tearDown();
    }

    private function lens(): AtlasCortexDocIntentLens
    {
        return new AtlasCortexDocIntentLens;
    }

    private function subject(string $source, ?string $repoRoot = null): CortexSubject
    {
        $facts = ['source_code' => $source];
        if ($repoRoot !== null) {
            $facts['repo_root'] = $repoRoot;
        }

        return new CortexSubject('subj-1', 'php_source', $facts);
    }

    public function test_emits_class_summary_for_documented_class(): void
    {
        $source = <<<'PHP'
<?php
/**
 * Sample governance primitive that enforces the autopoiesis charter. Auxiliary lines follow.
 *
 * @purpose example
 */
final class Sample {}
PHP;
        $obs = $this->lens()->observe($this->subject($source));

        $facts = $obs->facts['facts'];
        $kinds = array_column($facts, 'kind');
        $this->assertContains('class_summary', $kinds, 'documented class emits a class_summary fact');
        $summary = null;
        foreach ($facts as $f) {
            if ($f['kind'] === 'class_summary') {
                $summary = $f['summary'];
                break;
            }
        }
        $this->assertStringContainsString('Sample governance primitive', (string) $summary);
    }

    public function test_emits_doc_absent_for_class_without_docblock(): void
    {
        $source = "<?php\nclass Naked {}\n";
        $obs = $this->lens()->observe($this->subject($source));

        $kinds = array_column($obs->facts['facts'], 'kind');
        $this->assertContains('doc_absent', $kinds, 'class with no docblock emits doc_absent');
    }

    public function test_parses_declared_doc_refs_for_loop_cortex_maestro(): void
    {
        $source = <<<'PHP'
<?php
/**
 * Anchored at docs/loop-canonical-definition.md and docs/cortex-council-charter.md.
 */
class Sample {}
PHP;
        // Both docs exist on disk so no drift.
        file_put_contents($this->tmpRepo.'/docs/loop-canonical-definition.md', "# ok\n");
        file_put_contents($this->tmpRepo.'/docs/cortex-council-charter.md', "# ok\n");

        $obs = $this->lens()->observe($this->subject($source, $this->tmpRepo));

        $refs = array_filter($obs->facts['facts'], static fn (array $f): bool => ($f['kind'] ?? '') === 'declared_doc_ref');
        $refValues = array_column($refs, 'ref');
        $this->assertContains('docs/loop-canonical-definition.md', $refValues);
        $this->assertContains('docs/cortex-council-charter.md', $refValues);
        $this->assertSame([], $obs->disagreementSignals, 'all referenced docs exist ⇒ no drift');
    }

    public function test_emits_doc_intent_drift_for_nonexistent_doc_reference(): void
    {
        $source = <<<'PHP'
<?php
/**
 * Anchored at docs/loop-does-not-exist.md.
 */
class Sample {}
PHP;
        // Note: tmpRepo/docs is empty — the referenced file is missing.
        $obs = $this->lens()->observe($this->subject($source, $this->tmpRepo));

        $this->assertContains('doc_intent_drift:docs/loop-does-not-exist.md', $obs->disagreementSignals);
    }

    public function test_emits_purpose_and_invariant_tags_per_method(): void
    {
        $source = <<<'PHP'
<?php
/**
 * Outer class. Brief summary.
 */
class TaggedMethods {
    /**
     * @purpose recalibrate the dial
     * @invariant never crosses zero
     */
    public function recalibrate(): void {}
}
PHP;
        $obs = $this->lens()->observe($this->subject($source));

        $byKind = [];
        foreach ($obs->facts['facts'] as $f) {
            $byKind[$f['kind']][] = $f;
        }
        $this->assertArrayHasKey('purpose', $byKind);
        $this->assertArrayHasKey('invariant', $byKind);
        $this->assertSame('recalibrate', $byKind['purpose'][0]['method']);
        $this->assertSame('recalibrate the dial', $byKind['purpose'][0]['value']);
        $this->assertSame('never crosses zero', $byKind['invariant'][0]['value']);
    }

    public function test_lens_observation_carries_no_scoring_fields(): void
    {
        $reflection = new ReflectionClass(LensObservation::class);
        foreach ($reflection->getProperties() as $p) {
            $this->assertDoesNotMatchRegularExpression(
                '/score|confidence|grade|rank|weight/i',
                $p->getName(),
                'LensObservation must not carry a scoring property: '.$p->getName(),
            );
        }
    }

    public function test_source_unresolved_yields_disagreement_and_empty_facts(): void
    {
        $obs = $this->lens()->observe(new CortexSubject('subj-empty', 'php_source', []));

        $this->assertContains('source_unresolved', $obs->disagreementSignals);
        $this->assertSame([], $obs->facts['facts']);
    }
}
