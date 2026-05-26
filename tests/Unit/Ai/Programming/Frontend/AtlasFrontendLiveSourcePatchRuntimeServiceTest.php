<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendLiveSourcePatchRuntimeService;
use RuntimeException;
use Tests\TestCase;

class AtlasFrontendLiveSourcePatchRuntimeServiceTest extends TestCase
{
    public function test_prepare_accept_and_recover_apply_source_patch_with_journal(): void
    {
        $workspace = $this->workspace();
        file_put_contents($workspace.'/Card.tsx', '<button className="compact">Save</button>');

        $runtime = app(AtlasFrontendLiveSourcePatchRuntimeService::class);
        $prepared = $runtime->prepare($workspace, 'Card.tsx', '<button className="compact">Save</button>', [
            ['id' => 'bolder', 'content' => '<button className="compact elevated">Save</button>'],
        ], 'session-1');

        $this->assertSame('prepared', $prepared['status']);
        $this->assertSame('atlas.frontend.live_source_patch_session.v1', data_get($prepared, 'session.schema_version'));
        $this->assertFalse((bool) data_get($prepared, 'session.source_policy.raw_original_returned'));
        $this->assertTrue((bool) data_get($prepared, 'session.source_policy.private_session_integrity_verified_before_patch'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($prepared, 'session.private_integrity_hash'));

        $accepted = $runtime->accept($workspace, 'session-1', 'bolder');

        $this->assertSame('accepted', $accepted['status']);
        $this->assertStringContainsString('compact elevated', file_get_contents($workspace.'/Card.tsx'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($accepted, 'session.accepted_diff_hash'));
        $this->assertSame(AtlasFrontendLiveSourcePatchRuntimeService::DECISION_RECEIPT_SCHEMA_VERSION, data_get($accepted, 'decision_receipt.schema_version'));
        $this->assertSame('accepted', data_get($accepted, 'decision_receipt.status'));
        $this->assertSame(hash('sha256', 'bolder'), data_get($accepted, 'decision_receipt.accepted_variant_id_hash'));
        $this->assertSame(data_get($accepted, 'session.accepted_diff_hash'), data_get($accepted, 'decision_receipt.accepted_diff_hash'));
        $this->assertContains('visual_quality_gate', data_get($accepted, 'decision_receipt.required_next_gates'));
        $this->assertContains('run_certification', data_get($accepted, 'decision_receipt.required_next_gates'));
        $this->assertTrue((bool) data_get($accepted, 'claim_policy.live_patch_decision_is_not_delivery_evidence'));
        $this->assertTrue((bool) data_get($accepted, 'decision_receipt.claim_policy.receipt_is_decision_evidence_not_delivery_completion'));
        $this->assertFalse((bool) data_get($accepted, 'decision_receipt.source_policy.raw_original_or_variants_returned'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($accepted, 'decision_receipt.decision_receipt_hash'));

        $recovered = $runtime->recover($workspace, 'session-1');

        $this->assertSame('recovered', $recovered['status']);
        $this->assertSame('<button className="compact">Save</button>', file_get_contents($workspace.'/Card.tsx'));
        $this->assertCount(3, data_get($recovered, 'session.journal'));
        $this->assertSame('recovered', data_get($recovered, 'decision_receipt.status'));
        $this->assertContains('confirm_workspace_restored_or_prepare_new_live_patch', data_get($recovered, 'decision_receipt.required_next_gates'));
    }

    public function test_accept_blocks_tampered_private_session_payload(): void
    {
        $workspace = $this->workspace();
        file_put_contents($workspace.'/Card.html', '<button>Save</button>');
        $runtime = app(AtlasFrontendLiveSourcePatchRuntimeService::class);
        $runtime->prepare($workspace, 'Card.html', '<button>Save</button>', [
            ['id' => 'v1', 'content' => '<button class="primary">Save</button>'],
        ], 'session-tamper');

        $sessionPath = $workspace.'/.atlas/frontend-live/sessions/session-tamper.json';
        $session = json_decode((string) file_get_contents($sessionPath), true);
        $session['variant_payloads'][0]['content'] = '<button onclick="steal()">Save</button>';
        file_put_contents($sessionPath, json_encode($session, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('session_integrity_mismatch');

        $runtime->accept($workspace, 'session-tamper', 'v1');
    }

    public function test_prepare_blocks_ambiguous_targets(): void
    {
        $workspace = $this->workspace();
        file_put_contents($workspace.'/Card.html', '<button>Save</button><button>Save</button>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('target_must_match_exactly_once');

        app(AtlasFrontendLiveSourcePatchRuntimeService::class)->prepare($workspace, 'Card.html', '<button>Save</button>', [
            ['id' => 'v1', 'content' => '<button class="primary">Save</button>'],
        ]);
    }

    public function test_prepare_records_sanitized_visual_selection_without_raw_selector_or_text(): void
    {
        $workspace = $this->workspace();
        file_put_contents($workspace.'/Card.html', '<button data-testid="save">Save</button>');

        $prepared = app(AtlasFrontendLiveSourcePatchRuntimeService::class)->prepare(
            $workspace,
            'Card.html',
            '<button data-testid="save">Save</button>',
            [
                ['id' => 'v1', 'content' => '<button data-testid="save" class="primary">Save</button>'],
            ],
            'session-visual',
            [
                'route' => '/checkout',
                'selector' => '[data-testid="save"]',
                'text_excerpt' => 'Save private customer draft',
                'component_hint' => 'CheckoutSaveButton',
                'screenshot_hash' => str_repeat('a', 64),
                'confidence' => 0.91,
                'bounding_box' => ['x' => 12, 'y' => 24, 'width' => 144, 'height' => 38],
                'viewport' => ['width' => 1440, 'height' => 900],
            ],
        );

        $this->assertSame('provided', data_get($prepared, 'session.visual_selection.status'));
        $this->assertSame(12, data_get($prepared, 'session.visual_selection.bounding_box.x'));
        $this->assertSame(1440, data_get($prepared, 'session.visual_selection.viewport.width'));
        $this->assertSame(str_repeat('a', 64), data_get($prepared, 'session.visual_selection.screenshot_hash'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($prepared, 'session.visual_selection.selection_hash'));
        $this->assertSame(data_get($prepared, 'session.visual_selection.selection_hash'), data_get($prepared, 'decision_receipt.visual_selection_hash'));
        $this->assertSame('provided', data_get($prepared, 'decision_receipt.visual_selection_status'));
        $this->assertTrue((bool) data_get($prepared, 'decision_receipt.claim_policy.visual_selection_is_not_visual_quality_proof'));

        $json = json_encode($prepared, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('[data-testid="save"]', $json);
        $this->assertStringNotContainsString('Save private customer draft', $json);
        $this->assertStringNotContainsString('CheckoutSaveButton', $json);
        $this->assertStringNotContainsString('/checkout', $json);
    }

    public function test_prepare_blocks_invalid_visual_selection_screenshot_hash(): void
    {
        $workspace = $this->workspace();
        file_put_contents($workspace.'/Card.html', '<button>Save</button>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('visual_selection_screenshot_hash_invalid');

        app(AtlasFrontendLiveSourcePatchRuntimeService::class)->prepare(
            $workspace,
            'Card.html',
            '<button>Save</button>',
            [
                ['id' => 'v1', 'content' => '<button class="primary">Save</button>'],
            ],
            'session-invalid-visual',
            ['screenshot_hash' => 'not-a-valid-hash'],
        );
    }

    public function test_prepare_accepts_prehashed_browser_bridge_visual_selection(): void
    {
        $workspace = $this->workspace();
        file_put_contents($workspace.'/Card.html', '<button>Save</button>');
        $routeHash = str_repeat('b', 64);
        $selectorHash = str_repeat('c', 64);

        $prepared = app(AtlasFrontendLiveSourcePatchRuntimeService::class)->prepare(
            $workspace,
            'Card.html',
            '<button>Save</button>',
            [
                ['id' => 'v1', 'content' => '<button class="primary">Save</button>'],
            ],
            'session-browser-visual',
            [
                'route_hash' => $routeHash,
                'selector_hash' => $selectorHash,
                'text_excerpt_hash' => str_repeat('d', 64),
                'component_hint_hash' => str_repeat('e', 64),
                'bounding_box' => ['x' => 8, 'y' => 13, 'width' => 89, 'height' => 34],
                'viewport' => ['width' => 1280, 'height' => 720],
                'confidence' => 0.82,
            ],
        );

        $this->assertSame($routeHash, data_get($prepared, 'session.visual_selection.route_hash'));
        $this->assertSame($selectorHash, data_get($prepared, 'session.visual_selection.selector_hash'));
        $this->assertSame(89, data_get($prepared, 'session.visual_selection.bounding_box.width'));
        $this->assertSame('provided', data_get($prepared, 'decision_receipt.visual_selection_status'));

        $accepted = app(AtlasFrontendLiveSourcePatchRuntimeService::class)->accept($workspace, 'session-browser-visual', 'v1');

        $this->assertSame('accepted', $accepted['status']);
        $this->assertSame(data_get($prepared, 'session.visual_selection.selection_hash'), data_get($accepted, 'decision_receipt.visual_selection_hash'));
    }

    public function test_accept_blocks_when_source_changed_after_prepare(): void
    {
        $workspace = $this->workspace();
        file_put_contents($workspace.'/Card.html', '<button>Save</button>');
        $runtime = app(AtlasFrontendLiveSourcePatchRuntimeService::class);
        $runtime->prepare($workspace, 'Card.html', '<button>Save</button>', [
            ['id' => 'v1', 'content' => '<button class="primary">Save</button>'],
        ], 'session-change');
        file_put_contents($workspace.'/Card.html', '<button>Changed</button>');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('source_changed_since_prepare');

        $runtime->accept($workspace, 'session-change', 'v1');
    }

    private function workspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-live-'.bin2hex(random_bytes(4));
        mkdir($workspace);

        return $workspace;
    }
}
