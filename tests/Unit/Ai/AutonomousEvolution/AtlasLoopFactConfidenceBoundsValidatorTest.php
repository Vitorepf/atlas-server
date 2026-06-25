<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\FactConfidence\AtlasLoopFactBoundsMissingException;
use App\Services\Ai\AutonomousEvolution\FactConfidence\AtlasLoopFactConfidenceBoundsValidator;
use App\Services\Ai\AutonomousEvolution\FactConfidence\AtlasLoopFactConfidenceBoundsValidatorInterface;
use Tests\TestCase;

class AtlasLoopFactConfidenceBoundsValidatorTest extends TestCase
{
    /** @var list<array{string,string,array<string,mixed>}> */
    private array $warnings = [];

    private function makeValidator(bool $enforce, ?array $criticalPaths = null): AtlasLoopFactConfidenceBoundsValidator
    {
        $this->warnings = [];

        return new AtlasLoopFactConfidenceBoundsValidator(
            logger: function (string $message, array $context): void {
                $this->warnings[] = ['warning', $message, $context];
            },
            configReader: fn (): array => [
                'enforce' => $enforce,
                'critical_paths' => $criticalPaths ?? AtlasLoopFactConfidenceBoundsValidator::DEFAULT_CRITICAL_PATHS,
            ],
        );
    }

    public function test_enforce_on_critical_path_raw_fact_throws_bounds_missing(): void
    {
        $v = $this->makeValidator(enforce: true);
        $this->expectException(AtlasLoopFactBoundsMissingException::class);
        $v->validate('loop.comprehend.snapshot_writer', true);
    }

    public function test_enforce_on_critical_path_with_envelope_accepts_silently(): void
    {
        $v = $this->makeValidator(enforce: true);
        $v->validate('loop.comprehend.snapshot_writer', [
            'value' => true,
            'confidence_bounds' => ['lower' => 0.9, 'upper' => 0.95],
        ]);
        self::assertSame([], $this->warnings);
    }

    public function test_enforce_off_default_never_throws_or_warns_on_critical_path(): void
    {
        $v = $this->makeValidator(enforce: false);
        $v->validate('loop.comprehend.snapshot_writer', true);
        self::assertSame([], $this->warnings);
    }

    public function test_enforce_on_non_critical_path_missing_envelope_emits_warning_no_throw(): void
    {
        $v = $this->makeValidator(enforce: true);
        $v->validate('loop.observability.something', true);
        self::assertCount(1, $this->warnings);
        self::assertSame('atlas.loop.fact_confidence.bounds_missing', $this->warnings[0][1]);
        self::assertSame('loop.observability.something', $this->warnings[0][2]['emission_path']);
    }

    public function test_enforce_on_non_critical_path_with_envelope_is_silent(): void
    {
        $v = $this->makeValidator(enforce: true);
        $v->validate('loop.observability.something', [
            'value' => 42,
            'confidence_bounds' => ['lower' => 30, 'upper' => 50],
        ]);
        self::assertSame([], $this->warnings);
    }

    public function test_default_critical_paths_include_canonical_anchors(): void
    {
        self::assertContains('loop.comprehend.snapshot_writer', AtlasLoopFactConfidenceBoundsValidator::DEFAULT_CRITICAL_PATHS);
        self::assertContains('loop.next_work_decider', AtlasLoopFactConfidenceBoundsValidator::DEFAULT_CRITICAL_PATHS);
    }

    public function test_container_resolves_interface_to_concrete_singleton(): void
    {
        $a = app(AtlasLoopFactConfidenceBoundsValidatorInterface::class);
        $b = app(AtlasLoopFactConfidenceBoundsValidatorInterface::class);
        self::assertInstanceOf(AtlasLoopFactConfidenceBoundsValidator::class, $a);
        self::assertSame($a, $b);
    }

    public function test_partial_envelope_without_lower_or_upper_is_not_accepted(): void
    {
        $v = $this->makeValidator(enforce: true);
        $this->expectException(AtlasLoopFactBoundsMissingException::class);
        $v->validate('loop.comprehend.snapshot_writer', [
            'confidence_bounds' => ['lower' => 0.1],
        ]);
    }
}
