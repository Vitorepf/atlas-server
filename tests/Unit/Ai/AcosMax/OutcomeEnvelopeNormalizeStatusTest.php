<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AcosMax\OutcomeEnvelope;
use PHPUnit\Framework\TestCase;

final class OutcomeEnvelopeNormalizeStatusTest extends TestCase
{
    public function test_normalize_status_maps_aliases_and_fails_closed(): void
    {
        $this->assertSame('succeeded', OutcomeEnvelope::normalizeStatus('passed'));
        $this->assertSame('succeeded', OutcomeEnvelope::normalizeStatus('SUCCESS'));
        $this->assertSame('failed', OutcomeEnvelope::normalizeStatus('failure'));
        $this->assertSame('blocked', OutcomeEnvelope::normalizeStatus('needs_review'));
        $this->assertSame('blocked', OutcomeEnvelope::normalizeStatus(''));
    }
}
