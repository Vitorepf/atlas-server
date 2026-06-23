<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\HtmlCopyExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Locks the copy-extraction contract: tags become spaces, never deletions — so words at block
 * boundaries don't glue together ("$1,000/mo</p><p>the…" must NOT become "$1,000/mothe"). The glued
 * form silently corrupted the spoiler/marker scans.
 */
class HtmlCopyExtractorTest extends TestCase
{
    public function test_block_boundaries_do_not_glue_words(): void
    {
        $html = '<p>about $1,000/mo</p><p>the climbing cost</p>';
        $text = HtmlCopyExtractor::plainText($html);
        $this->assertStringContainsString('$1,000/mo the climbing cost', $text);
        $this->assertStringNotContainsString('mothe', $text);
    }

    public function test_strips_script_and_style_bodies(): void
    {
        $html = '<style>.x{color:red}</style><p>Hello</p><script>var t=720;</script>';
        $text = HtmlCopyExtractor::plainText($html);
        $this->assertSame('Hello', $text);
    }

    public function test_decodes_entities(): void
    {
        $this->assertSame("it's a no-brainer", HtmlCopyExtractor::plainText('<p>it&#039;s a no-brainer</p>'));
    }
}
