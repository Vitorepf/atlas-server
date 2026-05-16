<?php

declare(strict_types=1);

namespace Tests\Unit\Captures;

use PHPUnit\Framework\TestCase;

final class PublicApiReadmeTest extends TestCase
{
    private const README_PATH = __DIR__.'/../../../app/Domain/Captures/README.md';

    private const PUBLIC_METHODS = ['store', 'find', 'delete', 'search'];

    private const PRIVATE_METHODS = ['normalize'];

    public function test_readme_exists(): void
    {
        $this->assertFileExists(self::README_PATH);
    }

    public function test_documents_every_public_method(): void
    {
        $md = (string) file_get_contents(self::README_PATH);
        foreach (self::PUBLIC_METHODS as $method) {
            $this->assertMatchesRegularExpression(
                '/^###\s+'.preg_quote($method, '/').'\s*\(/mi',
                $md,
                "README missing heading for {$method}()",
            );
        }
    }

    public function test_does_not_leak_private_helpers(): void
    {
        $md = (string) file_get_contents(self::README_PATH);
        foreach (self::PRIVATE_METHODS as $method) {
            $this->assertDoesNotMatchRegularExpression(
                '/^###\s+'.preg_quote($method, '/').'\s*\(/mi',
                $md,
                "README documents private helper {$method}()",
            );
        }
    }

    public function test_every_method_section_has_signature_description_error_example(): void
    {
        $md = (string) file_get_contents(self::README_PATH);
        $sections = preg_split('/^###\s+/m', $md);
        array_shift($sections); // discard intro
        $this->assertCount(count(self::PUBLIC_METHODS), $sections, 'README must contain exactly 4 method sections');

        foreach ($sections as $section) {
            $this->assertMatchesRegularExpression('/```\w*\n.*?```/s', $section, 'method section missing fenced code block (signature or example)');
            $this->assertMatchesRegularExpression('/(throws|returns|throw|return)/i', $section, 'method section missing error/return doc');
        }
    }
}
