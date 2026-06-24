<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\AtlasCortexLensRegistry;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\CortexSubject;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\LensContract;
use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Council\LensObservation;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Proves the Cortex Council spine:
 *   - register() is IDEMPOTENT for the same (id, class) pair.
 *   - register() THROWS when re-registering the same id with a DIFFERENT class (silent perspective-swap
 *     would be a Goodhart hazard).
 *   - all() returns every registered lens; byId() resolves a single lens; missing id throws.
 *   - PÉTREO INVARIANT: LensObservation carries NO numeric 'score' / 'confidence' / 'ranking' property —
 *     proven by reflection.
 */
final class AtlasCortexLensRegistryTest extends TestCase
{
    public function test_register_is_idempotent_for_same_id_and_class(): void
    {
        $registry = new AtlasCortexLensRegistry;
        $registry->register(new FakeSecurityLens);
        $registry->register(new FakeSecurityLens); // second call ⇒ no-op

        $this->assertCount(1, $registry->all());
    }

    public function test_register_throws_when_same_id_with_different_class(): void
    {
        $registry = new AtlasCortexLensRegistry;
        $registry->register(new FakeSecurityLens);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/already registered/');
        $registry->register(new ImpostorSecurityLens); // same id, different class
    }

    public function test_register_throws_on_empty_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AtlasCortexLensRegistry)->register(new EmptyIdLens);
    }

    public function test_all_returns_id_to_lens_map_in_registration_order(): void
    {
        $registry = new AtlasCortexLensRegistry;
        $registry->register(new FakeSecurityLens);
        $registry->register(new FakePerformanceLens);

        $all = $registry->all();
        $this->assertSame(['security', 'performance'], array_keys($all), 'registration order preserved');
    }

    public function test_by_id_returns_the_registered_lens(): void
    {
        $registry = new AtlasCortexLensRegistry;
        $lens = new FakeSecurityLens;
        $registry->register($lens);

        $this->assertSame($lens, $registry->byId('security'));
    }

    public function test_by_id_throws_for_unknown_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AtlasCortexLensRegistry)->byId('nonexistent');
    }

    public function test_lens_observation_has_no_numeric_score_property(): void
    {
        $reflection = new ReflectionClass(LensObservation::class);
        foreach ($reflection->getProperties() as $prop) {
            $this->assertDoesNotMatchRegularExpression(
                '/score|confidence|ranking/i',
                $prop->getName(),
                'LensObservation must not carry a numeric verdict property: '.$prop->getName(),
            );
        }
    }

    public function test_lens_contract_is_an_interface_not_a_class(): void
    {
        $reflection = new ReflectionClass(LensContract::class);
        $this->assertTrue($reflection->isInterface(), 'LensContract must be an interface so any class can implement it');
    }

    public function test_observe_produces_a_lens_observation_for_a_cortex_subject(): void
    {
        $subject = new CortexSubject('orig-1', 'origination_candidate', ['file' => 'app/Foo.php']);
        $obs = (new FakeSecurityLens)->observe($subject);

        $this->assertSame('security', $obs->lensId);
        $this->assertSame('orig-1', $obs->subjectId);
        $this->assertArrayHasKey('observed_kind', $obs->facts);
        $this->assertContains('default_disagreement', $obs->disagreementSignals);
    }
}

/* ───── Test doubles: minimal concrete lenses to drive the spine ─────────────────────────────────────── */

final class FakeSecurityLens implements LensContract
{
    public function id(): string
    {
        return 'security';
    }

    public function name(): string
    {
        return 'Security';
    }

    public function observe(CortexSubject $subject): LensObservation
    {
        return new LensObservation(
            $this->id(),
            $subject->id,
            ['observed_kind' => $subject->kind],
            ['default_disagreement'],
        );
    }
}

final class ImpostorSecurityLens implements LensContract
{
    public function id(): string
    {
        return 'security';
    }

    public function name(): string
    {
        return 'Security (Impostor)';
    }

    public function observe(CortexSubject $subject): LensObservation
    {
        return new LensObservation($this->id(), $subject->id, []);
    }
}

final class FakePerformanceLens implements LensContract
{
    public function id(): string
    {
        return 'performance';
    }

    public function name(): string
    {
        return 'Performance';
    }

    public function observe(CortexSubject $subject): LensObservation
    {
        return new LensObservation($this->id(), $subject->id, []);
    }
}

final class EmptyIdLens implements LensContract
{
    public function id(): string
    {
        return '';
    }

    public function name(): string
    {
        return 'Empty';
    }

    public function observe(CortexSubject $subject): LensObservation
    {
        return new LensObservation($this->id(), $subject->id, []);
    }
}
