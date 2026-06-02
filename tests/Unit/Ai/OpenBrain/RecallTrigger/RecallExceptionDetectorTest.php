<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OpenBrain\RecallTrigger;

use App\Services\Ai\OpenBrain\RecallTrigger\RecallExceptionDetector;
use PHPUnit\Framework\TestCase;

final class RecallExceptionDetectorTest extends TestCase
{
    private RecallExceptionDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new RecallExceptionDetector();
    }

    public function testRenameInsideFunctionIsAnException(): void
    {
        $result = $this->detector->detect('rename the local variable inside this function');

        $this->assertTrue($result['is_exception']);
        $this->assertSame('rename_in_function', $result['exception']);
        $this->assertSame('rename_in_function', $result['reason']);
    }

    public function testTypoOrWhitespacePhraseIsAnException(): void
    {
        $result = $this->detector->detect('fix a typo and trailing whitespace in the header');

        $this->assertTrue($result['is_exception']);
        $this->assertSame('typo_or_whitespace', $result['exception']);
        $this->assertSame('typo_or_whitespace', $result['reason']);
    }

    public function testOperationalLeadingCommandIsAnException(): void
    {
        $result = $this->detector->detect('rodar php artisan test on the suite');

        $this->assertTrue($result['is_exception']);
        $this->assertSame('operational_command', $result['exception']);
        $this->assertSame('operational_command', $result['reason']);
    }

    public function testMetaQuestionAboutAtlasMemoryIsAnException(): void
    {
        $result = $this->detector->detect('what does atlas memory governance actually store?');

        $this->assertTrue($result['is_exception']);
        $this->assertSame('meta_about_atlas', $result['exception']);
        $this->assertSame('meta_about_atlas', $result['reason']);
    }

    public function testNormalFeatureDescriptionIsNotAnException(): void
    {
        $result = $this->detector->detect('add a checkout flow that charges the customer and emails a receipt');

        $this->assertFalse($result['is_exception']);
        $this->assertNull($result['exception']);
        $this->assertNull($result['reason']);
    }

    public function testFirstMatchOrderingPrefersRenameWhenRenameAndTypoCoFire(): void
    {
        $result = $this->detector->detect('rename the function and fix the typo while you are there');

        $this->assertTrue($result['is_exception']);
        $this->assertSame('rename_in_function', $result['exception']);
        $this->assertSame('rename_in_function', $result['reason']);
    }

    public function testFirstMatchOrderingPrefersTypoOverOperational(): void
    {
        $result = $this->detector->detect('run a quick whitespace cleanup');

        $this->assertTrue($result['is_exception']);
        $this->assertSame('typo_or_whitespace', $result['exception']);
        $this->assertSame('typo_or_whitespace', $result['reason']);
    }

    public function testOperationalRequiresLeadingVerbNotMidSentenceMention(): void
    {
        $result = $this->detector->detect('design a dashboard that lets the operator run reports');

        $this->assertFalse($result['is_exception']);
        $this->assertNull($result['exception']);
        $this->assertNull($result['reason']);
    }

    public function testMetaRequiresSubjectNotJustQuestionShape(): void
    {
        $result = $this->detector->detect('how should the pricing tiers be structured?');

        $this->assertFalse($result['is_exception']);
        $this->assertNull($result['exception']);
        $this->assertNull($result['reason']);
    }

    public function testRenameRequiresBothVerbAndTargetToken(): void
    {
        $result = $this->detector->detect('renomear a estrategia de marketing da empresa');

        $this->assertFalse($result['is_exception']);
        $this->assertNull($result['exception']);
        $this->assertNull($result['reason']);
    }

    public function testDetectionIsDeterministicForIdenticalInput(): void
    {
        $description = 'renomear o metodo dentro desta funcao';

        $first = $this->detector->detect($description);
        $second = $this->detector->detect($description);

        $this->assertSame($first, $second);
        $this->assertSame('rename_in_function', $first['exception']);
    }
}
