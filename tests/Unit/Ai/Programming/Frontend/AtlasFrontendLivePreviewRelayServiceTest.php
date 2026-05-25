<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendLivePreviewRelayService;
use Tests\TestCase;

class AtlasFrontendLivePreviewRelayServiceTest extends TestCase
{
    public function test_script_is_provider_safe_and_emits_preview_event_contract(): void
    {
        $payload = app(AtlasFrontendLivePreviewRelayService::class)->script();

        $this->assertSame('atlas.frontend.live_preview_relay.v1', $payload['schema_version']);
        $this->assertSame('event_driven_css_preview', $payload['mode']);
        $this->assertSame('atlas.frontend.live_preview_event.v1', $payload['event_schema']);
        $this->assertContains('atlas:frontend:preview-css', $payload['input_events']);
        $this->assertContains('atlas:frontend:preview-applied', $payload['output_events']);
        $this->assertStringContainsString('__ATLAS_FRONTEND_LIVE_PREVIEW_RELAY__', $payload['script']);
        $this->assertStringNotContainsString('fetch(', $payload['script']);
        $this->assertStringNotContainsString('localStorage', $payload['script']);
        $this->assertStringNotContainsString('document.cookie', $payload['script']);
        $this->assertFalse((bool) data_get($payload, 'source_policy.cookies_storage_or_network_accessed'));
    }

    public function test_inject_is_idempotent_and_remove_restores_html(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-relay-'.bin2hex(random_bytes(4));
        mkdir($workspace);
        $html = '<html><body><main>App</main></body></html>';
        file_put_contents($workspace.'/index.html', $html);

        $relay = app(AtlasFrontendLivePreviewRelayService::class);
        $injected = $relay->inject($workspace, 'index.html');
        $again = $relay->inject($workspace, 'index.html');

        $this->assertSame('injected', $injected['status']);
        $this->assertSame('already_injected', $again['status']);
        $this->assertStringContainsString('atlas-frontend-live-preview-relay:start', file_get_contents($workspace.'/index.html'));

        $removed = $relay->remove($workspace, 'index.html');

        $this->assertSame('removed', $removed['status']);
        $this->assertStringNotContainsString('atlas-frontend-live-preview-relay:start', file_get_contents($workspace.'/index.html'));
        $this->assertStringContainsString('<main>App</main>', file_get_contents($workspace.'/index.html'));
    }
}
