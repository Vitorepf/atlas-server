<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Decision\VslAwarenessAlignmentAuditor;
use PHPUnit\Framework\TestCase;

class VslAwarenessAlignmentAuditorTest extends TestCase
{
    private VslAwarenessAlignmentAuditor $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new VslAwarenessAlignmentAuditor;
    }

    public function test_aligned_problem_aware_vsl(): void
    {
        // problem_aware → prescribes problem_solution lead + unique_mechanism (needs mechanism_name).
        $asset = new AiMarketingVslAsset([
            'awareness_level' => 'problem_aware',
            'sophistication_level' => 'unique_mechanism',
            'transcript' => 'you are tired of the struggle with this problem and why you keep suffering',
            'mechanism_name' => 'Pink Gelatin Protocol',
        ]);

        $a = $this->svc->audit($asset);

        $this->assertSame('problem_solution', $a['lead_type_prescribed']);
        $this->assertSame('yes', $a['lead_match']);
        $this->assertTrue($a['mechanism_naming_ok']);
        $this->assertTrue($a['aligned']);
    }

    public function test_unique_mechanism_without_name_is_flagged(): void
    {
        $asset = new AiMarketingVslAsset([
            'awareness_level' => 'solution_aware', // prescribes big_secret + mechanism
            'transcript' => 'get 50% discount, order now, today only — buy now at this price',
            'mechanism_name' => '', // missing
        ]);

        $a = $this->svc->audit($asset);

        $this->assertFalse($a['mechanism_naming_ok']);
        // opening reads as an offer lead, not the prescribed big_secret
        $this->assertSame('big_secret', $a['lead_type_prescribed']);
        $this->assertSame('offer', $a['lead_type_used']);
        $this->assertSame('no', $a['lead_match']);
        $this->assertFalse($a['aligned']);
        $this->assertNotEmpty($a['correction_needed']);
    }

    public function test_undeclared_awareness_is_flagged(): void
    {
        $a = $this->svc->audit(new AiMarketingVslAsset(['awareness_level' => '', 'transcript' => 'hello']));
        $this->assertSame('no', $a['awareness_match']);
        $this->assertFalse($a['aligned']);
    }
}
