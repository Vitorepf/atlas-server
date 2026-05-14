<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\RivalsForgeReadinessFingerprintService;
use Tests\TestCase;

class RivalsForgeReadinessFingerprintServiceTest extends TestCase
{
    public function test_identical_intents_produce_identical_fingerprint(): void
    {
        /** @var RivalsForgeReadinessFingerprintService $service */
        $service = app(RivalsForgeReadinessFingerprintService::class);

        $a = $service->compute([
            'suite_id' => 'atlas-fair-claude-v1',
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'atlas_workspace' => '/tmp/atlas',
            'baseline_workspace' => '/tmp/baseline',
            'case_ids' => ['case-1'],
            'gate_profile' => 'strict',
        ]);
        $b = $service->compute([
            'suite_id' => 'atlas-fair-claude-v1',
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'atlas_workspace' => '/tmp/atlas',
            'baseline_workspace' => '/tmp/baseline',
            'case_ids' => ['case-1'],
            'gate_profile' => 'strict',
        ]);

        $this->assertSame($a['value'], $b['value']);
        $this->assertSame($a['components'], $b['components']);
        $this->assertSame(64, strlen((string) $a['value']));
    }

    public function test_changing_model_breaks_fingerprint_and_diagnose_lists_diff(): void
    {
        $service = app(RivalsForgeReadinessFingerprintService::class);

        $opus = $service->compute([
            'suite_id' => 'atlas-fair-claude-v1',
            'preset' => 'quick',
            'atlas_model' => 'opus',
            'baseline_model' => 'opus',
            'atlas_workspace' => '/tmp/atlas',
            'baseline_workspace' => '/tmp/baseline',
            'case_ids' => ['case-1'],
        ]);
        $sonnet = $service->compute([
            'suite_id' => 'atlas-fair-claude-v1',
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'atlas_workspace' => '/tmp/atlas',
            'baseline_workspace' => '/tmp/baseline',
            'case_ids' => ['case-1'],
        ]);

        $this->assertNotSame($opus['value'], $sonnet['value']);

        $diagnosis = $service->diagnose($opus, $sonnet);
        $this->assertFalse($diagnosis['matches']);
        $this->assertSame('fingerprint_mismatch_runbook_vs_run', $diagnosis['reason']);
        $this->assertContains('atlas_model', $diagnosis['diff_fields']);
        $this->assertContains('baseline_model', $diagnosis['diff_fields']);
        $this->assertNotEmpty($diagnosis['reconciliation_hint']);
    }

    public function test_changing_workspace_breaks_fingerprint(): void
    {
        $service = app(RivalsForgeReadinessFingerprintService::class);

        $a = $service->compute([
            'suite_id' => 'atlas-fair-claude-v1',
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'atlas_workspace' => '/tmp/atlas',
            'baseline_workspace' => '/tmp/baseline-a',
            'case_ids' => ['case-1'],
        ]);
        $b = $service->compute([
            'suite_id' => 'atlas-fair-claude-v1',
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'atlas_workspace' => '/tmp/atlas',
            'baseline_workspace' => '/tmp/baseline-b',
            'case_ids' => ['case-1'],
        ]);

        $this->assertNotSame($a['value'], $b['value']);
        $diff = $service->diagnose($a, $b);
        $this->assertContains('baseline_workspace_hash', $diff['diff_fields']);
        $this->assertNotContains('atlas_workspace_hash', $diff['diff_fields']);
    }

    public function test_case_ids_order_does_not_affect_fingerprint(): void
    {
        $service = app(RivalsForgeReadinessFingerprintService::class);

        $a = $service->compute(['case_ids' => ['beta', 'alpha']]);
        $b = $service->compute(['case_ids' => ['alpha', 'beta']]);

        $this->assertSame($a['value'], $b['value']);
    }

    public function test_diagnose_treats_missing_expected_as_full_diff(): void
    {
        $service = app(RivalsForgeReadinessFingerprintService::class);

        $actual = $service->compute([
            'suite_id' => 'atlas-fair-claude-v1',
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
        ]);
        $diff = $service->diagnose(null, $actual);

        $this->assertFalse($diff['matches']);
        $this->assertSame('no_expected_fingerprint', $diff['reason']);
        foreach (RivalsForgeReadinessFingerprintService::COMPONENT_KEYS as $key) {
            $this->assertContains($key, $diff['diff_fields']);
        }
    }

    public function test_preflight_and_dry_run_share_fingerprint_for_identical_intent(): void
    {
        $service = app(RivalsForgeReadinessFingerprintService::class);
        $atlasWorkspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_rivals_fp_atlas';
        $baselineWorkspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_rivals_fp_baseline';

        $intent = [
            'suite_id' => 'atlas-fair-claude-v1',
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'atlas_workspace' => $atlasWorkspace,
            'baseline_workspace' => $baselineWorkspace,
            'case_ids' => ['atlas-fair-claude-baseline-case-01'],
            'gate_profile' => 'strict',
        ];

        $preflightFp = $service->compute($intent);
        $dryRunFp = $service->compute($intent);

        $this->assertSame($preflightFp['value'], $dryRunFp['value']);
    }
}
