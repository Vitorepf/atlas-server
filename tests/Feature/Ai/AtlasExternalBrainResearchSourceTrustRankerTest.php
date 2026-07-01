<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainResearchSourceTrustRanker;
use Tests\TestCase;

final class AtlasExternalBrainResearchSourceTrustRankerTest extends TestCase
{
    private function ranker(): AtlasExternalBrainResearchSourceTrustRanker
    {
        return new AtlasExternalBrainResearchSourceTrustRanker;
    }

    public function test_primary_documentation_with_full_grounding_is_adopted_directly(): void
    {
        $result = $this->ranker()->rank([
            'source_type' => 'primary_documentation',
            'has_concrete_claim' => true,
            'source_date' => '2026-05-01',
            'as_of' => '2026-06-30',
            'has_source_url' => true,
            'is_hype_heavy' => false,
            'grounding' => 'repo_local',
        ]);

        self::assertSame(AtlasExternalBrainResearchSourceTrustRanker::USE_ADOPT_DIRECTLY, $result['use_decision']);
        self::assertSame([], $result['adoption_blockers']);
    }

    public function test_generic_summary_cannot_be_adopted_directly(): void
    {
        $result = $this->ranker()->rank([
            'source_type' => 'generic_summary',
            'has_concrete_claim' => false,
            'source_date' => '2026-05-01',
            'as_of' => '2026-06-30',
            'has_source_url' => true,
            'grounding' => 'repo_local',
        ]);

        self::assertNotSame(AtlasExternalBrainResearchSourceTrustRanker::USE_ADOPT_DIRECTLY, $result['use_decision']);
        self::assertContains('no_concrete_claim', $result['adoption_blockers']);
    }

    public function test_hype_heavy_claim_cannot_be_adopted_directly(): void
    {
        $result = $this->ranker()->rank([
            'source_type' => 'primary_documentation',
            'has_concrete_claim' => true,
            'source_date' => '2026-05-01',
            'as_of' => '2026-06-30',
            'has_source_url' => true,
            'is_hype_heavy' => true,
            'grounding' => 'repo_local',
        ]);

        self::assertNotSame(AtlasExternalBrainResearchSourceTrustRanker::USE_ADOPT_DIRECTLY, $result['use_decision']);
        self::assertContains('hype_heavy', $result['adoption_blockers']);
        self::assertContains('hype_heavy', $result['penalties']);
    }

    public function test_missing_source_url_cannot_be_adopted_directly(): void
    {
        $result = $this->ranker()->rank([
            'source_type' => 'primary_documentation',
            'has_concrete_claim' => true,
            'source_date' => '2026-05-01',
            'as_of' => '2026-06-30',
            'has_source_url' => false,
            'grounding' => 'repo_local',
        ]);

        self::assertNotSame(AtlasExternalBrainResearchSourceTrustRanker::USE_ADOPT_DIRECTLY, $result['use_decision']);
        self::assertContains('no_source_url', $result['adoption_blockers']);
        self::assertContains('source_url_missing', $result['penalties']);
    }

    public function test_missing_source_date_cannot_be_adopted_directly(): void
    {
        $result = $this->ranker()->rank([
            'source_type' => 'primary_documentation',
            'has_concrete_claim' => true,
            'has_source_url' => true,
            'grounding' => 'repo_local',
        ]);

        self::assertNotSame(AtlasExternalBrainResearchSourceTrustRanker::USE_ADOPT_DIRECTLY, $result['use_decision']);
        self::assertContains('missing_source_date', $result['penalties']);
    }

    public function test_missing_grounding_cannot_be_adopted_directly(): void
    {
        $result = $this->ranker()->rank([
            'source_type' => 'primary_documentation',
            'has_concrete_claim' => true,
            'source_date' => '2026-05-01',
            'as_of' => '2026-06-30',
            'has_source_url' => true,
        ]);

        self::assertNotSame(AtlasExternalBrainResearchSourceTrustRanker::USE_ADOPT_DIRECTLY, $result['use_decision']);
        self::assertContains('no_repo_local_or_primary_source_grounding', $result['adoption_blockers']);
        self::assertContains('requires_repo_local_verification', $result['grounding_requirements']);
    }

    public function test_implausible_year_date_is_not_treated_as_fresh_trustworthy_evidence(): void
    {
        $result = $this->ranker()->rank([
            'source_type' => 'primary_documentation',
            'has_concrete_claim' => true,
            'source_date' => '3026-05-01',
            'as_of' => '2026-06-30',
            'has_source_url' => true,
            'grounding' => 'repo_local',
        ]);

        self::assertContains('invalid_source_date', $result['penalties']);
        self::assertNotSame(AtlasExternalBrainResearchSourceTrustRanker::USE_ADOPT_DIRECTLY, $result['use_decision']);
    }

    public function test_garbage_date_string_is_not_treated_as_fresh_trustworthy_evidence(): void
    {
        $result = $this->ranker()->rank([
            'source_type' => 'primary_documentation',
            'has_concrete_claim' => true,
            'source_date' => 'not-a-real-date-at-all',
            'as_of' => '2026-06-30',
            'has_source_url' => true,
            'grounding' => 'repo_local',
        ]);

        self::assertContains('invalid_source_date', $result['penalties']);
        self::assertNotSame(AtlasExternalBrainResearchSourceTrustRanker::USE_ADOPT_DIRECTLY, $result['use_decision']);
    }
}
