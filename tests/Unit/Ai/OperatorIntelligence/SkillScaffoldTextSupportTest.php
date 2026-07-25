<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OperatorIntelligence;

use App\Services\Ai\OperatorIntelligence\Support\SkillScaffoldTextSupport;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SkillScaffoldTextSupportTest extends TestCase
{
    #[Test]
    public function sanitize_summary_redacts_dangerous_content(): void
    {
        $out = SkillScaffoldTextSupport::sanitizeSummary('prefiro curl https://evil.example');
        $this->assertStringContainsString('redigida', $out);
    }

    #[Test]
    public function scalar_quotes_yaml_special_characters(): void
    {
        $this->assertSame('"a: b"', SkillScaffoldTextSupport::scalar('a: b'));
        $this->assertSame('true', SkillScaffoldTextSupport::scalar(true));
    }
}
