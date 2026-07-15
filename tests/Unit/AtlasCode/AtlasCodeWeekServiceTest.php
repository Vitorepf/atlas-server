<?php

declare(strict_types=1);

use App\Services\AtlasCode\AtlasCodeWeekService;
use Tests\TestCase;

final class AtlasCodeWeekServiceTest extends TestCase
{
    public function test_week_card_returns_real_window_and_agent_buckets(): void
    {
        $result = app(AtlasCodeWeekService::class)->capture('atlas-server');

        self::assertSame(AtlasCodeWeekService::SCHEMA_VERSION, $result['schema_version']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}\.\.\d{4}-\d{2}-\d{2}$/', $result['window']);
        self::assertIsInt($result['commits']);
        self::assertIsInt($result['heals']);
        self::assertIsInt($result['prevented']);
        self::assertSame(['fable', 'codex', 'voce', 'autonomo:desconhecido'], array_keys($result['by_agent']));
        self::assertFalse($result['notifications']['enabled']);
    }
}
