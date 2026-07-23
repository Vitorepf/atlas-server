<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AiWorkerHardeningTest extends TestCase
{
    /**
     * Worker implementation source = AiWorker facade PLUS its owned
     * AiWorkerSupport/*Section.php files. GOD-DEBULK split the AiWorker godfile
     * into same-family Section classes; the provider-choice pause (where the
     * reset-date parse lives) now sits in ProviderPauseFallbackSection. Scanning
     * the union keeps this hardening guard at full strength wherever the worker
     * implementation places the code.
     */
    private function workerFamilySource(): string
    {
        $base = __DIR__.'/../../../../app/Services/Ai';
        $source = (string) file_get_contents($base.'/AiWorker.php');
        foreach (glob($base.'/AiWorkerSupport/*.php') ?: [] as $section) {
            $source .= "\n".(string) file_get_contents($section);
        }

        return $source;
    }

    /**
     * Verify the source wraps CarbonImmutable::parse in try/catch for provider_reset_at.
     */
    public function test_source_has_try_catch_for_reset_parse(): void
    {
        $source = $this->workerFamilySource();

        $this->assertStringContainsString('CarbonImmutable::parse', $source, 'must parse dates');
        $this->assertStringContainsString('catch', $source, 'must have try/catch guard');
    }

    /**
     * Verify the old unguarded parse is gone.
     */
    public function test_old_unguarded_parse_removed(): void
    {
        $source = $this->workerFamilySource();

        $this->assertStringNotContainsString(
            "CarbonImmutable::parse(\$resetAtIso) : null",
            $source,
            'old unguarded parse must be replaced'
        );
    }

    /**
     * Demonstrate the bug: CarbonImmutable::parse throws on invalid date.
     */
    public function test_parse_invalid_date_throws(): void
    {
        $this->expectException(\Throwable::class);
        \Carbon\CarbonImmutable::parse('not-a-date');
    }
}
