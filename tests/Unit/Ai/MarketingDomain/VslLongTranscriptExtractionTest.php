<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\VslIntelligenceExtractorService;
use ReflectionClass;
use Tests\TestCase;

/**
 * Locks the 2-hour fidelity contract for the EXTRACTION step: a long VSL transcript is never dumped
 * raw into the model context (which risks silently truncating the END — where the offer/price/CTA
 * live). Above the budget the head (hook/lead/mechanism) and the tail (offer/price/CTA) are kept
 * verbatim, the middle is marked, and the offer pass weights the tail. Deterministic; uses Laravel
 * config (no LLM, no DB).
 */
class VslLongTranscriptExtractionTest extends TestCase
{
    private function invoke(string $method, array $args): mixed
    {
        $svc = (new ReflectionClass(VslIntelligenceExtractorService::class))->newInstanceWithoutConstructor();
        $m = (new ReflectionClass(VslIntelligenceExtractorService::class))->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($svc, $args);
    }

    /** Build a ~2h transcript (~150k chars) with unique anchors at the very start and very end. */
    private function twoHourTranscript(): string
    {
        $head = 'HOOKZONE_START Melania said she lost 63 pounds with a triple-hormone drops protocol. ';
        $mid = 'In the middle the doctors explain the mechanism and show testimonials over and over. ';
        $tail = ' The offer: 6 bottles for OFFERZONE_PRICE_49 dollars each, 60 day guarantee, last bottles. CTA_END';

        return $head.str_repeat($mid, 1800).$tail; // ~150k chars
    }

    public function test_short_transcript_passes_through_untouched(): void
    {
        $short = 'A short VSL transcript that fits the budget easily.';
        $this->assertSame($short, $this->invoke('prepareForExtraction', [$short, 'market']));
        $this->assertSame('full', $this->invoke('transcriptInputDiagnostics', [$short])['mode']);
    }

    public function test_long_transcript_preserves_head_and_tail_anchors(): void
    {
        $long = $this->twoHourTranscript();
        $budget = (int) config('atlas.marketing.extraction_max_transcript_chars', 80000);
        $this->assertGreaterThan($budget, mb_strlen($long));

        foreach (['lead', 'offer'] as $key) {
            $prepared = $this->invoke('prepareForExtraction', [$long, $key]);

            // both critical anchors survive — the pitch at the END is never lost
            $this->assertStringContainsString('HOOKZONE_START', $prepared, "head anchor lost for {$key}");
            $this->assertStringContainsString('OFFERZONE_PRICE_49', $prepared, "tail/offer anchor lost for {$key}");
            $this->assertStringContainsString('CTA_END', $prepared, "CTA anchor lost for {$key}");
            $this->assertStringContainsString('MEIO DA VSL OMITIDO', $prepared);

            // stays within budget (+ a small allowance for the marker text)
            $this->assertLessThanOrEqual($budget + 400, mb_strlen($prepared), "over budget for {$key}");
        }
    }

    public function test_offer_pass_keeps_more_of_the_tail_than_the_lead_pass(): void
    {
        $long = $this->twoHourTranscript();
        $leadPrepared = $this->invoke('prepareForExtraction', [$long, 'lead']);
        $offerPrepared = $this->invoke('prepareForExtraction', [$long, 'offer']);

        $tailLen = static fn (string $s): int => mb_strlen(mb_substr($s, mb_strpos($s, 'MEIO DA VSL OMITIDO') ?: 0));
        // offer pass is tail-heavy → its preserved tail is larger than the lead pass's tail
        $this->assertGreaterThan($tailLen($leadPrepared), $tailLen($offerPrepared));
    }

    public function test_diagnostics_flag_anchored_mode_for_long_input(): void
    {
        $diag = $this->invoke('transcriptInputDiagnostics', [$this->twoHourTranscript()]);

        $this->assertSame('anchored', $diag['mode']);
        $this->assertGreaterThan(0, $diag['approx_tokens']);
        $this->assertStringContainsString('janela ancorada', $diag['note']);
    }
}
