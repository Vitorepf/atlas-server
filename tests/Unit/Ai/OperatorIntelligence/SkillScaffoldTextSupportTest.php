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

    #[Test]
    public function frontmatter_body_and_scaffold_safety(): void
    {
        $md = SkillScaffoldTextSupport::frontmatter([
            'name' => 'op-test',
            'trust' => 'untrusted',
            'allowed-tools' => '',
            'metadata' => ['schema_version' => 'v1'],
        ]).SkillScaffoldTextSupport::body('T', 'summary', 'op-test', 3, 14, 0.9, ['- 2026-07-01']);

        $this->assertTrue(SkillScaffoldTextSupport::scaffoldIsSafe($md));
        $this->assertStringContainsString('Você repetiu **3x**', $md);
        $this->assertFalse(SkillScaffoldTextSupport::scaffoldIsSafe("trust: trusted\nallowed-tools: \"\"\n"));
    }
}
