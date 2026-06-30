<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasOpenBrainProviderSafeMemoryToneFilter;
use Tests\TestCase;

final class AtlasOpenBrainProviderSafeMemoryToneFilterTest extends TestCase
{
    private function filter(): AtlasOpenBrainProviderSafeMemoryToneFilter
    {
        return new AtlasOpenBrainProviderSafeMemoryToneFilter;
    }

    public function test_hostile_quoted_memory_is_not_emitted_verbatim(): void
    {
        $hostile = 'Que merda é que você tá fazendo? Você não tá entendendo nada do que é pra fazer.';

        $result = $this->filter()->filter($hostile);

        $this->assertSame(AtlasOpenBrainProviderSafeMemoryToneFilter::RISK_HOSTILE, $result['risk_level']);
        $this->assertStringNotContainsString('merda', $result['safe_text']);
        $this->assertTrue($result['dropped_raw_quote']);
        $this->assertNotEmpty($result['redaction_flags']);
    }

    public function test_over_engineering_complaint_rewrites_to_neutral_summary(): void
    {
        $hostile = "This is such garbage over-engineered complexity, why would you do this.";

        $result = $this->filter()->filter($hostile);

        $this->assertSame('operator flagged over-engineering or unnecessary complexity', $result['safe_text']);
        $this->assertSame($result['safe_text'], $result['preserved_intent']);
    }

    public function test_token_waste_complaint_rewrites_to_neutral_summary(): void
    {
        $hostile = 'Stupid, you are wasting so many tokens on this garbage approach.';

        $result = $this->filter()->filter($hostile);

        $this->assertSame('operator flagged token-waste risk', $result['safe_text']);
    }

    public function test_benign_technical_memory_remains_unchanged_and_marked_clean(): void
    {
        $clean = 'Migrations devem ser idempotentes; nunca carimbar manualmente o registro.';

        $result = $this->filter()->filter($clean);

        $this->assertSame(AtlasOpenBrainProviderSafeMemoryToneFilter::RISK_CLEAN, $result['risk_level']);
        $this->assertSame($clean, $result['safe_text']);
        $this->assertSame([], $result['redaction_flags']);
        $this->assertFalse($result['dropped_raw_quote']);
    }

    public function test_panic_marker_is_flagged(): void
    {
        $panic = 'URGENTE!!! Pelo amor de deus, conserte isso agora.';

        $result = $this->filter()->filter($panic);

        $this->assertSame(AtlasOpenBrainProviderSafeMemoryToneFilter::RISK_HOSTILE, $result['risk_level']);
        $this->assertContains('panic_or_emotional', $result['redaction_flags']);
    }

    public function test_output_includes_all_five_required_fields(): void
    {
        $result = $this->filter()->filter('some clean memory text');

        $this->assertArrayHasKey('safe_text', $result);
        $this->assertArrayHasKey('risk_level', $result);
        $this->assertArrayHasKey('redaction_flags', $result);
        $this->assertArrayHasKey('preserved_intent', $result);
        $this->assertArrayHasKey('dropped_raw_quote', $result);
    }

    public function test_filter_is_deterministic_for_identical_input(): void
    {
        $filter = $this->filter();
        $text = 'Que merda é que você tá fazendo?';

        $this->assertSame($filter->filter($text), $filter->filter($text));
    }

    public function test_filter_makes_no_network_or_model_calls_via_source_inspection(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/AtlasOpenBrainProviderSafeMemoryToneFilter.php'));
        foreach (['Http::', 'curl_', 'file_get_contents(\'http', 'exec(', 'shell_exec(', 'proc_open('] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $src, "tone filter must not perform {$forbidden}");
        }
    }
}
