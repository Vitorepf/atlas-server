<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\EmailFollowupForge;
use PHPUnit\Framework\TestCase;

/**
 * Locks the re-engagement email sequence: three emails escalating curiosity → authority+enemy →
 * scarcity, each with a short subject, carrying the VSL's real ammunition (mechanism, authority,
 * enemy). Market-language clean. Deterministic.
 */
class EmailFollowupForgeTest extends TestCase
{
    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset([
            'target_geo' => 'US / English',
            'mechanism_name' => 'Triple Hormone Drops Protocol',
            'metrics' => ['result_claims' => ['lost 63 lbs']],
            'persuasion_devices' => ['authority' => ['Melania Trump']],
        ]);
    }

    public function test_three_email_sequence_escalates_levers(): void
    {
        $seq = (new EmailFollowupForge)->forge($this->asset());

        $this->assertCount(3, $seq);
        foreach ($seq as $e) {
            $this->assertNotEmpty($e['subject']);
            $this->assertLessThanOrEqual(60, mb_strlen($e['subject']));
            $this->assertNotEmpty($e['body']);
            $this->assertStringContainsString('Triple Hormone Drops Protocol', $e['body']);
        }
        $this->assertStringContainsString('Melania Trump', $seq[1]['subject']);   // authority email
        $this->assertStringContainsStringIgnoringCase('tonight', $seq[2]['subject']); // scarcity email
    }

    public function test_english_sequence_has_no_portuguese_leak(): void
    {
        $blob = mb_strtolower(json_encode((new EmailFollowupForge)->forge($this->asset())));
        foreach (['você', 'agulha', 'injeção', 'mulheres'] as $pt) {
            $this->assertStringNotContainsString($pt, $blob);
        }
    }
}
