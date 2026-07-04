<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasMaestroAdaptiveDecisionReceiptHardeningTest extends TestCase
{
    /**
     * Verify the source has explicit false/empty checks before hashing.
     */
    public function test_source_has_evidence_fail_closed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Adaptive/AtlasMaestroAdaptiveDecisionReceipt.php');

        $this->assertStringContainsString('evidence_unencodable', $source, 'must fail closed on unencodable evidence');
        $this->assertStringContainsString('record_unencodable', $source, 'must fail closed on unencodable record');
    }

    /**
     * Verify the old (string) json_encode pattern is gone.
     */
    public function test_source_no_bare_string_cast_on_json_encode(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Adaptive/AtlasMaestroAdaptiveDecisionReceipt.php');

        // The old pattern was hash('sha256', (string) json_encode(...)
        $this->assertStringNotContainsString(
            "hash('sha256', (string) json_encode",
            $source,
            'old (string) json_encode must be replaced with explicit check'
        );
    }

    /**
     * Verify the source uses explicit variable assignment + check pattern.
     */
    public function test_source_uses_payload_variable_pattern(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/Adaptive/AtlasMaestroAdaptiveDecisionReceipt.php');

        $this->assertStringContainsString('evidencePayload', $source, 'must use evidencePayload variable');
        $this->assertStringContainsString('recordPayload', $source, 'must use recordPayload variable');
    }
}
