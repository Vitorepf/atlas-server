<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Provider;

use App\Services\Ai\Provider\ProviderCatalog;
use PHPUnit\Framework\TestCase;

final class ProviderCatalogTest extends TestCase
{
    public function test_defaults_include_hermes_and_codex_live(): void
    {
        $live = ProviderCatalog::autoLiveWorkerProviders();
        $this->assertContains('hermes_cli', $live);
        $this->assertContains('codex_cli', $live);
        $this->assertTrue(ProviderCatalog::isAutoLiveWorkerProvider('hermes_cli'));
        $this->assertFalse(ProviderCatalog::isAutoLiveWorkerProvider('claude_cli'));
    }

    public function test_invocation_set_contains_live_subset(): void
    {
        $inv = ProviderCatalog::invocationProviders();
        foreach (ProviderCatalog::autoLiveWorkerProviders() as $p) {
            $this->assertContains($p, $inv);
        }
        $this->assertTrue(ProviderCatalog::isInvocationProvider('gemini_cli'));
    }

    public function test_council_pair(): void
    {
        $this->assertSame(['claude_cli', 'codex_cli'], ProviderCatalog::councilProviders());
    }
}
