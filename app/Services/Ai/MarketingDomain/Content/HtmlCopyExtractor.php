<?php

namespace App\Services\Ai\MarketingDomain\Content;

/**
 * HtmlCopyExtractor — turns page HTML into the plain reading text the conversion engines should see.
 * strip_tags() deletes tags, which GLUES words across block boundaries ("…$1,000/mo</p><p>the…" →
 * "$1,000/mothe") and corrupts every downstream marker/spoiler scan. Replacing tags with a SPACE
 * preserves word boundaries the way a human reads the page.
 */
class HtmlCopyExtractor
{
    public static function plainText(string $html): string
    {
        // Drop script/style bodies first, then turn every tag into a space, then collapse whitespace.
        $noScript = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $spaced = (string) preg_replace('/<[^>]+>/', ' ', $noScript);
        $decoded = html_entity_decode($spaced, ENT_QUOTES, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $decoded));
    }
}
