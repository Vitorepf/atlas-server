<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\AcosMax\StructuredFactSchemaMap;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Multh01StructuredFactsTest extends TestCase
{
    #[Test]
    public function decision_structured_facts_validate_required_fields(): void
    {
        $out = StructuredFactSchemaMap::validate('decision', [
            'contexto' => 'routing',
            'alternativas' => ['a', 'b'],
            'porque' => 'lower risk',
            'expiry' => '2026-08-01',
        ]);

        $this->assertTrue($out['valid']);
        $this->assertSame([], $out['missing']);
    }

    #[Test]
    public function missing_fields_fail_open_without_blocking_entry(): void
    {
        $out = StructuredFactSchemaMap::validate('decision', ['contexto' => 'routing']);

        $this->assertFalse($out['valid']);
        $this->assertTrue($out['fail_open_entry_allowed']);
        $this->assertContains('alternativas', $out['missing']);
    }

    #[Test]
    public function unknown_memory_type_is_unschematized(): void
    {
        $out = StructuredFactSchemaMap::validate('random', ['anything' => 'goes']);

        $this->assertSame('unschematized', $out['status']);
        $this->assertTrue($out['fail_open_entry_allowed']);
    }
}
