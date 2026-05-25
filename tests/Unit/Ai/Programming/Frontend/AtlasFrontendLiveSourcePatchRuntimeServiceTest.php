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
