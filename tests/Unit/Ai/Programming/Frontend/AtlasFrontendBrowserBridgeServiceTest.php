<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendBrowserBridgeService;
use Tests\TestCase;

class AtlasFrontendBrowserBridgeServiceTest extends TestCase
{
    public function test_script_is_provider_safe_and_emits_pick_event_contract(): void
    {
        $payload = app(AtlasFrontendBrowserBridgeService::class)->script();

        $this->assertSame('atlas.frontend.browser_bridge.v1', $payload['schema_version']);
        $this->assertSame('alt_click_picker', $payload['mode']);
        $this->assertSame('atlas.frontend.browser_pick_event.v1', $payload['event_schema']);
        $this->assertSame(AtlasFrontendBrowserBridgeService::BROWSER_DETECTOR_EVENT_SCHEMA_VERSION, $payload['detector_event_schema']);
        $this->assertSame('atlas.frontend.live_visual_selection.v1', $payload['live_visual_selection_schema']);
        $this->assertContains('atlas:frontend:browser-detect', $payload['output_events']);
        $this->assertContains('atlas:frontend:live-visual-selection', $payload['output_events']);
        $this->assertContains('browser_icon_button_without_accessible_name', $payload['detector_rules']);
        $this->assertStringContainsString('window.__ATLAS_FRONTEND_PICK_EVENTS__', $payload['script']);
        $this->assertStringContainsString('window.__ATLAS_FRONTEND_BROWSER_DETECTOR_EVENTS__', $payload['script']);
        $this->assertStringContainsString('window.__ATLAS_FRONTEND_LIVE_VISUAL_SELECTION_EVENTS__', $payload['script']);
        $this->assertStringContainsString('atlas:frontend:pick', $payload['script']);
        $this->assertStringContainsString('atlas:frontend:browser-detect', $payload['script']);
        $this->assertStringContainsString('atlas:frontend:live-visual-selection', $payload['script']);
        $this->assertStringContainsString('atlas.frontend.live_visual_selection.v1', $payload['script']);
        $this->assertStringContainsString('BroadcastChannel', $payload['script']);
        $this->assertStringContainsString('__ATLAS_FRONTEND_LAST_LIVE_VISUAL_SELECTION__', $payload['script']);
        $this->assertStringContainsString('localStorage.setItem', $payload['script']);
        $this->assertStringContainsString('atlas.frontend.live_visual_selection_message.v1', $payload['script']);
        $this->assertStringContainsString('window.parent.postMessage', $payload['script']);
        $this->assertStringContainsString('window.opener.postMessage', $payload['script']);
        $this->assertStringContainsString('post_message_contains_sanitized_selection_only', $payload['script']);
        $this->assertStringContainsString('selection_can_guide_patch_but_not_replace_visual_gate', $payload['script']);
        $this->assertStringContainsString('browser_small_interactive_target', $payload['script']);
        $this->assertStringContainsString('browser_detector_event_is_not_final_design_proof', $payload['script']);
        $this->assertStringContainsString('window.atlasFrontendPath(el)', $payload['script']);
        $this->assertStringNotContainsString('fetch(', $payload['script']);
        $this->assertStringNotContainsString('document.cookie', $payload['script']);
        $this->assertTrue((bool) data_get($payload, 'source_policy.text_is_hashed'));
        $this->assertTrue((bool) data_get($payload, 'source_policy.live_visual_selection_is_hashed'));
        $this->assertTrue((bool) data_get($payload, 'source_policy.live_visual_selection_broadcast_channel'));
        $this->assertTrue((bool) data_get($payload, 'source_policy.live_visual_selection_local_storage_is_sanitized'));
        $this->assertTrue((bool) data_get($payload, 'source_policy.live_visual_selection_post_message_is_sanitized'));
        $this->assertFalse((bool) data_get($payload, 'source_policy.cookies_storage_or_network_accessed'));
    }

    public function test_inject_is_idempotent_and_remove_restores_html(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-bridge-'.bin2hex(random_bytes(4));
        mkdir($workspace);
        $html = '<html><body><button>Save</button></body></html>';
        file_put_contents($workspace.'/index.html', $html);

        $bridge = app(AtlasFrontendBrowserBridgeService::class);
        $injected = $bridge->inject($workspace, 'index.html');
        $again = $bridge->inject($workspace, 'index.html');

        $this->assertSame('injected', $injected['status']);
        $this->assertSame('already_injected', $again['status']);
        $this->assertStringContainsString('atlas-frontend-browser-bridge:start', file_get_contents($workspace.'/index.html'));

        $removed = $bridge->remove($workspace, 'index.html');

        $this->assertSame('removed', $removed['status']);
        $this->assertStringNotContainsString('atlas-frontend-browser-bridge:start', file_get_contents($workspace.'/index.html'));
        $this->assertStringContainsString('<button>Save</button>', file_get_contents($workspace.'/index.html'));
    }
}
