<?php

declare(strict_types=1);

namespace Tests\Unit\Docs;

use PHPUnit\Framework\TestCase;

final class AdrLintTest extends TestCase
{
    private const ADR_DIR = __DIR__.'/../../../docs/adr';

    private const REQUIRED_SECTIONS = ['Status', 'Context', 'Decision', 'Consequences'];

    public function test_at_least_one_adr_exists(): void
    {
        $this->assertDirectoryExists(self::ADR_DIR);
        $files = glob(self::ADR_DIR.'/*.md') ?: [];
        $this->assertNotEmpty($files);
    }

    public function test_every_adr_has_required_sections(): void
    {
        foreach (glob(self::ADR_DIR.'/*.md') ?: [] as $file) {
            $md = (string) file_get_contents($file);
            foreach (self::REQUIRED_SECTIONS as $section) {
                $this->assertMatchesRegularExpression(
                    '/^##\s+'.preg_quote($section, '/').'\b/mi',
                    $md,
                    basename($file)." missing section {$section}",
                );
            }
        }
    }

    public function test_every_adr_has_two_alternatives(): void
    {
        foreach (glob(self::ADR_DIR.'/*.md') ?: [] as $file) {
            $md = (string) file_get_contents($file);
            $matches = preg_match_all('/^###\s+Alternative\b/mi', $md);
            $this->assertGreaterThanOrEqual(2, (int) $matches, basename($file).' needs ≥2 alternatives');
        }
    }

    public function test_every_adr_has_explicit_tradeoff_line(): void
    {
        foreach (glob(self::ADR_DIR.'/*.md') ?: [] as $file) {
            $md = (string) file_get_contents($file);
            $this->assertMatchesRegularExpression('/^-\s*Tradeoff:\s+.+$/mi', $md, basename($file).' missing tradeoff line');
        }
    }
}
