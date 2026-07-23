<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDomainWaveCampaignRunner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDomainWaveCampaignRunnerTest extends TestCase
{
    public function test_hermetic_fixture_executes_and_feeds_only_receipts_to_rivals(): void
    {
        $forwarded = null;
        $runner = new AtlasExternalBrainDomainWaveCampaignRunner(
            static fn (array $input): array => ['evidence_refs' => ['fixture://'.$input['wave_version']]],
            static function (array $payload) use (&$forwarded): array { $forwarded = $payload; return ['accepted' => true, 'receipt_ref' => 'rivals://receipt-1']; },
        );

        $result = $runner->run(['campaign_mode' => 'hermetic', 'domain_id' => 'programming', 'wave_version' => 'wave-1.v1']);

        self::assertSame('executed', $result['status']);
        self::assertSame(['fixture://wave-1.v1'], $result['evidence_refs']);
        self::assertSame(['fixture://wave-1.v1'], $forwarded['evidence_refs']);
        self::assertFalse($result['claim_allowed']);
    }

    public function test_private_campaign_requires_explicit_authorization_receipt(): void
    {
        $called = false;
        $runner = new AtlasExternalBrainDomainWaveCampaignRunner(
            static function () use (&$called): array { $called = true; return ['evidence_refs' => ['private://evidence']]; },
            static fn (array $payload): array => ['accepted' => true],
        );

        $result = $runner->run(['campaign_mode' => 'private', 'authorized' => false]);

        self::assertSame('blocked', $result['status']);
        self::assertContains('private_campaign_authorization_required', $result['blockers']);
        self::assertFalse($called);
    }

    public function test_rivals_rejection_blocks_campaign_completion(): void
    {
        $runner = new AtlasExternalBrainDomainWaveCampaignRunner(
            static fn (array $input): array => ['evidence_refs' => ['fixture://wave']],
            static fn (array $payload): array => ['accepted' => false],
        );

        $result = $runner->run(['campaign_mode' => 'private', 'authorized' => true, 'authorization_receipt' => 'auth://1']);

        self::assertSame('blocked', $result['status']);
        self::assertContains('rivals_evidence_rejected', $result['blockers']);
    }
}
