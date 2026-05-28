<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendLiveVisualSelectionInboxService;
use RuntimeException;
use Tests\TestCase;

class AtlasFrontendLiveVisualSelectionInboxServiceTest extends TestCase
{
    public function test_record_and_latest_roundtrip_sanitizes_visual_selection_without_raw_fields(): void
    {
        $workspace = $this->workspace();
        $inbox = app(AtlasFrontendLiveVisualSelectionInboxService::class);
        $selectionInput = [
            'route' => '/checkout',
            'selector' => '[data-testid="save"]',
            'text_excerpt' => 'Save private customer draft',
            'component_hint' => 'CheckoutSaveButton',
            'screenshot_hash' => str_repeat('a', 64),
            'confidence' => 0.91,
            'bounding_box' => ['x' => 12, 'y' => 24, 'width' => 144, 'height' => 38],
            'viewport' => ['width' => 1440, 'height' => 900],
        ];

        $recorded = $inbox->record($workspace, 'session-visual', $selectionInput);

        $this->assertSame(AtlasFrontendLiveVisualSelectionInboxService::SCHEMA_VERSION, $recorded['schema_version']);
        $this->assertSame('recorded', $recorded['status']);
        $this->assertSame('session-visual', $recorded['session']);
        $this->assertSame(hash('sha256', realpath($workspace) ?: $workspace), $recorded['workspace_hash']);
        $this->assertSame(AtlasFrontendLiveVisualSelectionInboxService::SELECTION_SCHEMA_VERSION, data_get($recorded, 'selection.schema_version'));
        $this->assertSame('provided', data_get($recorded, 'selection.status'));
        $this->assertSame(12, data_get($recorded, 'selection.bounding_box.x'));
        $this->assertSame(1440, data_get($recorded, 'selection.viewport.width'));
        $this->assertSame(str_repeat('a', 64), data_get($recorded, 'selection.screenshot_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($recorded, 'selection.selection_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $recorded['inbox_hash']);
        $this->assertTrue((bool) data_get($recorded, 'source_policy.provider_safe_only'));
        $this->assertTrue((bool) data_get($recorded, 'source_policy.selection_is_not_visual_quality_proof'));
        $this->assertFalse((bool) data_get($recorded, 'selection.policy.raw_selector_returned'));
        $this->assertFalse((bool) data_get($recorded, 'selection.policy.raw_text_returned'));

        $json = json_encode($recorded, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('[data-testid="save"]', $json);
        $this->assertStringNotContainsString('Save private customer draft', $json);
        $this->assertStringNotContainsString('CheckoutSaveButton', $json);
        $this->assertStringNotContainsString('/checkout', $json);

        $latest = $inbox->latest($workspace, 'session-visual');

        $this->assertSame('found', $latest['status']);
        $this->assertSame(data_get($recorded, 'selection.selection_hash'), data_get($latest, 'selection.selection_hash'));
        $this->assertSame(data_get($recorded, 'selection.route_hash'), data_get($latest, 'selection.route_hash'));
        $this->assertSame(data_get($recorded, 'selection.selector_hash'), data_get($latest, 'selection.selector_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $latest['inbox_hash']);
    }

    public function test_latest_returns_not_found_when_inbox_file_is_missing(): void
    {
        $workspace = $this->workspace();
        $latest = app(AtlasFrontendLiveVisualSelectionInboxService::class)->latest($workspace, 'missing-session');

        $this->assertSame('not_found', $latest['status']);
        $this->assertNull($latest['selection']);
        $this->assertSame('missing-session', $latest['session']);
        $this->assertTrue((bool) data_get($latest, 'source_policy.provider_safe_only'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $latest['inbox_hash']);
    }

    public function test_record_blocks_invalid_visual_selection_screenshot_hash(): void
    {
        $workspace = $this->workspace();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('visual_selection_screenshot_hash_invalid');

        app(AtlasFrontendLiveVisualSelectionInboxService::class)->record(
            $workspace,
            'session-invalid',
            ['screenshot_hash' => 'not-a-valid-hash'],
        );
    }

    public function test_latest_blocks_corrupt_inbox_payload(): void
    {
        $workspace = $this->workspace();
        $path = $workspace.'/.atlas/frontend/live-visual-selection-inbox/corrupt-session.json';
        mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'not-json');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('live_visual_selection_inbox_corrupt');

        app(AtlasFrontendLiveVisualSelectionInboxService::class)->latest($workspace, 'corrupt-session');
    }

    public function test_record_blocks_missing_workspace(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('workspace_not_found');

        app(AtlasFrontendLiveVisualSelectionInboxService::class)->record(
            sys_get_temp_dir().'/atlas-missing-workspace-'.bin2hex(random_bytes(6)),
            'session-1',
            ['route' => '/home'],
        );
    }

    public function test_record_with_empty_selection_stores_not_provided_and_latest_reports_not_found(): void
    {
        $workspace = $this->workspace();
        $inbox = app(AtlasFrontendLiveVisualSelectionInboxService::class);

        $recorded = $inbox->record($workspace, 'empty-session', []);

        $this->assertSame('recorded', $recorded['status']);
        $this->assertSame('not_provided', data_get($recorded, 'selection.status'));
        $this->assertNull(data_get($recorded, 'selection.selection_hash'));
        $this->assertFalse((bool) data_get($recorded, 'selection.policy.raw_selector_returned'));

        $latest = $inbox->latest($workspace, 'empty-session');

        $this->assertSame('not_found', $latest['status']);
        $this->assertNull($latest['selection']);
    }

    public function test_session_is_sanitized_before_inbox_path_is_written(): void
    {
        $workspace = $this->workspace();
        $inbox = app(AtlasFrontendLiveVisualSelectionInboxService::class);
        $recorded = $inbox->record($workspace, '  live/session with spaces!!  ', ['route' => '/home']);

        $this->assertSame('live-session-with-spaces', $recorded['session']);
        $this->assertFileExists($workspace.'/.atlas/frontend/live-visual-selection-inbox/live-session-with-spaces.json');
    }

    private function workspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-live-visual-inbox-'.bin2hex(random_bytes(4));
        mkdir($workspace);

        return $workspace;
    }
}
