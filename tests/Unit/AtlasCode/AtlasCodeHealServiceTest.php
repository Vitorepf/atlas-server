<?php

declare(strict_types=1);

use App\Services\AtlasCode\AtlasCodeHealService;
use Tests\TestCase;

final class AtlasCodeHealServiceTest extends TestCase
{
    public function test_policy_defaults_to_observe_and_exposes_only_canonical_actions(): void
    {
        $service = app(AtlasCodeHealService::class);
        $policy = $service->policy('observe');

        self::assertSame('observe', $policy['mode']);
        self::assertSame([
            'cherry_pick_to_main',
            'delete_branch',
            'merge_ff',
            'stash_quarantine',
            'cite_rule_to_agent',
        ], $policy['allowed_actions']);
    }

    public function test_observe_tick_is_read_only_and_returns_step_receipts_collection(): void
    {
        $service = app(AtlasCodeHealService::class);

        $result = $service->tick('atlas-server');

        self::assertSame(AtlasCodeHealService::SCHEMA_VERSION, $result['schema_version']);
        self::assertSame('observe', $result['mode']);
        self::assertSame([], $result['step_receipts']);
        self::assertNotEmpty($result['violations']);
    }
}
