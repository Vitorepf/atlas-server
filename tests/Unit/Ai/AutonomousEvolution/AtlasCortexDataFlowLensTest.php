<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\AtlasCortexDataFlowLens;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\CortexSubject;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\LensObservation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the dataflow lens emits read_set + write_set per method, surfaces cross-method state coupling, marks
 * dynamic property access in disagreement_signals, and ships NO score field anywhere on its public surface.
 */
final class AtlasCortexDataFlowLensTest extends TestCase
{
    private function lens(): AtlasCortexDataFlowLens
    {
        return new AtlasCortexDataFlowLens;
    }

    private function subject(string $source): CortexSubject
    {
        return new CortexSubject('subj-1', 'php_source', ['source_code' => $source]);
    }

    public function test_emits_read_set_and_write_set_per_method(): void
    {
        $source = <<<'PHP'
<?php
class Sample {
    private int $count = 0;
    public function increment(): void {
        $this->count = $this->count + 1;
    }
    public function get(): int {
        return $this->count;
    }
}
PHP;
        $obs = $this->lens()->observe($this->subject($source));

        $this->assertSame('dataflow', $obs->lensId);
        $methods = (array) $obs->facts['methods'];
        $byName = [];
        foreach ($methods as $m) {
            $byName[$m['method']] = $m;
        }
        $this->assertContains('count', $byName['increment']['write_set']);
        $this->assertContains('count', $byName['increment']['read_set']);
        $this->assertContains('count', $byName['get']['read_set']);
        $this->assertSame([], $byName['get']['write_set'] ?? [], 'get() only reads — empty write_set');
    }

    public function test_cross_method_state_coupling_appears_as_coupling_fact(): void
    {
        $source = <<<'PHP'
<?php
class TwoWriters {
    private int $balance = 0;
    public function deposit(int $n): void { $this->balance += $n; }
    public function withdraw(int $n): void { $this->balance = $this->balance - $n; }
}
PHP;
        $obs = $this->lens()->observe($this->subject($source));
        $coupling = (array) $obs->facts['coupling'];

        $this->assertCount(1, $coupling, 'two methods writing balance ⇒ one coupling fact');
        $this->assertSame('state_coupling', $coupling[0]['kind']);
        $this->assertSame('balance', $coupling[0]['property']);
        $this->assertSame(['deposit', 'withdraw'], $coupling[0]['methods'], 'methods sorted byte-stably');
    }

    public function test_dynamic_property_access_marks_disagreement_signal(): void
    {
        $source = <<<'PHP'
<?php
class Magic {
    public function get(string $k) {
        return $this->$k;
    }
}
PHP;
        $obs = $this->lens()->observe($this->subject($source));

        $this->assertContains('dynamic_property_access_in:get', $obs->disagreementSignals);
    }

    public function test_compound_assignment_counts_as_write(): void
    {
        $source = <<<'PHP'
<?php
class CompoundWriter {
    private array $items = [];
    public function add(string $item): void {
        $this->items[] = $item;
        $this->items = array_merge($this->items, []);
    }
}
PHP;
        $obs = $this->lens()->observe($this->subject($source));
        $byName = [];
        foreach ((array) $obs->facts['methods'] as $m) {
            $byName[$m['method']] = $m;
        }
        $this->assertContains('items', $byName['add']['write_set']);
    }

    public function test_source_unresolved_yields_disagreement_and_empty_facts(): void
    {
        $obs = $this->lens()->observe(new CortexSubject('subj-empty', 'php_source', []));

        $this->assertContains('source_unresolved', $obs->disagreementSignals);
        $this->assertSame([], $obs->facts['methods']);
        $this->assertSame([], $obs->facts['coupling']);
    }

    public function test_lens_observation_public_surface_carries_no_score_field(): void
    {
        // The pétreo invariant lives on LensObservation itself (asserted in the registry test) AND in the
        // lens's emitted facts shape — neither must surface a 'score'/'confidence' key.
        $obs = $this->lens()->observe($this->subject('<?php class A { private int $x = 0; public function go(){ $this->x = 1; } }'));

        foreach (array_keys($obs->facts) as $k) {
            $this->assertDoesNotMatchRegularExpression('/score|confidence|ranking|percent/i', (string) $k);
        }
        // Reflect LensObservation itself for completeness — the class has no score property.
        $r = new ReflectionClass(LensObservation::class);
        foreach ($r->getProperties() as $p) {
            $this->assertDoesNotMatchRegularExpression('/score|confidence|ranking/i', $p->getName());
        }
    }

    public function test_method_records_param_list(): void
    {
        $source = <<<'PHP'
<?php
class WithParams {
    public function handle(string $name, int $count): void {
        $this->name = $name;
        $this->count = $count;
    }
}
PHP;
        $obs = $this->lens()->observe($this->subject($source));
        $method = (array) $obs->facts['methods'][0];
        $this->assertSame(['name', 'count'], $method['params']);
        $this->assertContains('name', $method['write_set']);
        $this->assertContains('count', $method['write_set']);
    }
}
