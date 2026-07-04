<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasMaestroProviderRecommendationReceiptLedgerHardeningTest extends TestCase
{
    /**
     * Verify the source has a field() method with length-prefix escaping.
     */
    public function test_source_has_field_escaping(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/ProviderLearning/AtlasMaestroProviderRecommendationReceiptLedger.php');

        $this->assertStringContainsString('private function field', $source, 'must have field() method');
        $this->assertStringContainsString("strlen(\$value).':'.\$value", $source, 'must length-prefix');
    }

    /**
     * Verify the old bare concatenation is gone from receiptId().
     */
    public function test_source_no_bare_concat_in_receipt_id(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/ProviderLearning/AtlasMaestroProviderRecommendationReceiptLedger.php');

        // The old pattern was $taskClass.'|'.$provider.'|'.$sampleSize
        // After fix it uses $this->field() — ensure the bare pattern is gone.
        $this->assertStringNotContainsString(
            "\$taskClass.'|'.\$provider.'|'.\$sampleSize.'|'.\$successRate.'|'.\$requestedAt",
            $source,
            'old bare concatenation in receiptId must be replaced'
        );
    }

    /**
     * Verify the old bare concatenation is gone from computeFactHash().
     */
    public function test_source_no_bare_concat_in_fact_hash(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/SelfConstruction/Maestro/ProviderLearning/AtlasMaestroProviderRecommendationReceiptLedger.php');

        $this->assertStringNotContainsString(
            "\$taskClass.'|'.\$provider.'|'.\$sampleSize.'|'.\$successRate.'|'.\$reason",
            $source,
            'old bare concatenation in computeFactHash must be replaced'
        );
    }
}
