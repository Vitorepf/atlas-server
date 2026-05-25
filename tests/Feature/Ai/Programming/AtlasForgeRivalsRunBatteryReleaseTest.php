<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryStateService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCasesRegistry;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCorpusPreValidationService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsModelMatrix;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunBatteryService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunRealService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Runner Real release-ready contract tests.
 *
 * Locks the multi-case orchestrator that turns `atlas:forge:rivals
 * run-battery --preset=release --mode=fair --atlas-model=sonnet
 * --rival=claude_sonnet` into the operator-facing real battery:
 *
 *   - three operator confirmations are mandatory for any real-provider mode;
 *   - preset=release without an explicit case-set maps to the full provider
 *     arena release corpus (multi-case);
 *   - unknown case-sets and unknown models block honestly before any
 *     provider invocation;
 *   - external_rivals_certification stays sealed regardless of mode;
 *   - claim_ready=false until adjudicator/report run end-to-end on a green
 *     pipeline (local_fake never claims).
 *
 * No provider is invoked in these tests; the three confirmation gates and
 * driver-availability guards keep every case fail-closed before run-real.
 */
final class AtlasForgeRivalsRunBatteryReleaseTest extends TestCase
{
    public function test_run_battery_preset_release_blocks_without_any_of_the_three_confirmations(): void
    {
        $response = $this->dispatchRunBattery([
            'mode' => 'fair',
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'release',
            'confirmations' => [
                'runbook_reviewed' => false,
                'provider_cost' => false,
                'real_provider_call' => false,
            ],
        ]);

        $this->assertSame('blocked', $response['status']);
        foreach (['runbook_reviewed', 'provider_cost', 'real_provider_call'] as $required) {
            $this->assertContains('missing_confirmation:'.$required, $response['blockers']);
        }
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
        $this->assertNull($response['winner']);
    }

    public function test_run_battery_preset_release_with_partial_confirmations_still_blocks_without_provider_call(): void
    {
        $response = $this->dispatchRunBattery([
            'mode' => 'fair',
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'release',
            'confirmations' => [
                'runbook_reviewed' => true,
                'provider_cost' => true,
                'real_provider_call' => false,
            ],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertContains('missing_confirmation:real_provider_call', $response['blockers']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
    }

    public function test_run_battery_preset_release_rejects_unknown_case_set_honestly(): void
    {
        $response = $this->dispatchRunBattery([
            'mode' => 'fair',
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'release',
            'case_set' => 'no-such-case-set',
            'confirmations' => [
                'runbook_reviewed' => true,
                'provider_cost' => true,
                'real_provider_call' => true,
            ],
        ]);

        $this->assertSame('blocked', $response['status']);
        // honest fail-closed before any provider call
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
        // blocker either surfaces from preflight (cases registry) or run-real
        // (corpus). Either is honest — assert at least one fingerprint exists.
        $joined = implode('|', array_map('strval', $response['blockers'] ?? []));
        $this->assertTrue(
            str_contains($joined, 'unknown_case_set:no-such-case-set')
            || str_contains($joined, 'empty_case_set:no-such-case-set')
            || str_contains($joined, 'preset_unknown'),
            'expected an honest unknown-case-set blocker, got: '.$joined,
        );
    }

    public function test_run_battery_fair_blocks_model_mismatch_before_any_provider_call(): void
    {
        // fair mode demands the same model on both arms; opus vs sonnet must
        // trip the model matrix gate, not the confirmation gate.
        $response = $this->dispatchRunBattery([
            'mode' => 'fair',
            'atlas_model' => 'sonnet',
            'rival' => 'claude_opus',
            'preset' => 'release',
            'confirmations' => [
                'runbook_reviewed' => true,
                'provider_cost' => true,
                'real_provider_call' => true,
            ],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
        $joined = implode('|', array_map('strval', $response['blockers'] ?? []));
        $this->assertTrue(
            str_contains($joined, 'fair_mode_requires_same_model')
            || str_contains($joined, 'model_matrix'),
            'expected an honest fair-mode same-model blocker, got: '.$joined,
        );
    }

    public function test_run_battery_release_pipeline_keeps_external_rivals_certification_sealed(): void
    {
        $response = $this->dispatchRunBattery([
            'mode' => 'fair',
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'release',
            'confirmations' => [
                'runbook_reviewed' => false,
                'provider_cost' => false,
                'real_provider_call' => false,
            ],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertTrue(
            $response['separated_from_external_rivals_certification'] ?? false,
            'release pipeline must always keep external_rivals_certification sealed',
        );
        $this->assertNull($response['winner']);
        $this->assertNull($response['scorecard']);
    }

    public function test_run_battery_rejects_unknown_prompt_mode_before_provider_call(): void
    {
        $response = $this->dispatchRunBattery([
            'mode' => 'fair',
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'release',
            'prompt_mode' => 'marketing-demo',
            'confirmations' => [
                'runbook_reviewed' => true,
                'provider_cost' => true,
                'real_provider_call' => true,
            ],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertContains('prompt_mode_not_admissible_for_run_battery:marketing-demo', $response['blockers']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
    }

    public function test_case_set_release_resolves_to_full_provider_arena_corpus(): void
    {
        $corpus = app(AtlasForgeRivalsProviderArenaCorpusService::class);
        $cases = $corpus->casesForCaseSet(AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_RELEASE);

        $this->assertGreaterThanOrEqual(
            10,
            count($cases),
            'release case_set must carry the full canonical corpus, not a single legacy case',
        );

        $ids = array_values(array_map(static fn (array $c): string => (string) $c['case_id'], $cases));
        $this->assertCount(
            count($ids),
            array_unique($ids),
            'release corpus must not repeat the same case id twice',
        );
    }

    public function test_run_real_resolves_preset_release_to_multi_case_corpus_without_truncation(): void
    {
        // Verify the runner consumes ALL cases from the release case_set,
        // not just the first one. This is the core multi-case gap fix.
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'resolveCaseContext');
        $reflection->setAccessible(true);

        $context = $reflection->invoke(
            $runReal,
            ['case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_RELEASE],
            AtlasForgeRivalsCasesRegistry::PRESET_RELEASE,
        );

        $this->assertSame('provider_arena_corpus', $context['source']);
        $this->assertGreaterThanOrEqual(
            10,
            count($context['cases']),
            'resolveCaseContext must return the full release corpus, not [cases[0]]',
        );
    }

    public function test_run_real_resolves_preset_release_without_explicit_case_set_via_corpus(): void
    {
        // preset=release without --case-set should auto-map to the canonical
        // provider arena release corpus (the spec's "single button" command
        // path: `php artisan atlas:forge:rivals run-battery --preset=release …`).
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'resolveCaseContext');
        $reflection->setAccessible(true);

        $context = $reflection->invoke($runReal, [], AtlasForgeRivalsCasesRegistry::PRESET_RELEASE);

        $this->assertSame('provider_arena_corpus', $context['source']);
        $this->assertGreaterThanOrEqual(10, count($context['cases']));
    }

    public function test_run_real_release_preserves_case_specific_validation_command(): void
    {
        // Release batteries must validate the current challenge, not a broad
        // category-wide suite. The planning L1 case declares a narrow command;
        // if the adapter replaces it with full_test_command, real batteries
        // measure unrelated repo failures instead of the competitor output.
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'resolveCaseContext');
        $reflection->setAccessible(true);

        $context = $reflection->invoke(
            $runReal,
            ['case' => 'planning-l1-acceptance-checklist'],
            AtlasForgeRivalsCasesRegistry::PRESET_RELEASE,
        );

        $this->assertSame('provider_arena_corpus', $context['source']);
        $this->assertSame(
            "php artisan test --filter='AcceptanceChecklistTest'",
            $context['cases'][0]['test_command'],
        );
        $this->assertNotSame(
            $context['cases'][0]['full_test_command'],
            $context['cases'][0]['test_command'],
        );
    }

    public function test_run_real_release_adapts_expected_changed_files_for_scope_guard(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'resolveCaseContext');
        $reflection->setAccessible(true);

        $context = $reflection->invoke(
            $runReal,
            ['case' => 'planning-l1-acceptance-checklist'],
            AtlasForgeRivalsCasesRegistry::PRESET_RELEASE,
        );

        $this->assertSame(
            ['docs/planning/inbox/feature.acceptance.md'],
            $context['cases'][0]['expected_changed_files'],
        );
    }

    public function test_run_real_release_preserves_l5_complexity_metadata_for_360_reports(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'resolveCaseContext');
        $reflection->setAccessible(true);

        $context = $reflection->invoke(
            $runReal,
            ['case' => 'bugfix-l5-cascade-failure-fanout'],
            AtlasForgeRivalsCasesRegistry::PRESET_RELEASE,
        );
        $case = $context['cases'][0];

        $this->assertSame('provider_arena_corpus', $context['source']);
        $this->assertSame('bugfix-l5-cascade-failure-fanout', $case['id']);
        $this->assertSame('L5', $case['difficulty_level']);
        $this->assertSame(5.0, $case['difficulty_score']);
        $this->assertIsArray($case['context_profile']);
        $this->assertSame('atlas.forge.rivals.context_profile.v1', $case['context_profile']['schema_version']);
        $this->assertIsArray($case['human_prompt_probe']);
        $this->assertSame('atlas.forge.rivals.human_prompt_probe.v1', $case['human_prompt_probe']['schema_version']);
        $this->assertContains('long_context', $case['measurement_tags']);
        $this->assertNotNull($case['human_prompt_hash']);
    }

    public function test_provider_usage_receipt_parses_stream_json_without_requiring_provider_call(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'providerUsageFromJsonLog');
        $reflection->setAccessible(true);

        $path = sys_get_temp_dir().'/atlas-rivals-usage-'.bin2hex(random_bytes(6)).'.jsonl';
        file_put_contents($path, implode("\n", [
            json_encode(['type' => 'system', 'model' => 'claude-sonnet-4-6']),
            json_encode(['type' => 'result', 'costUSD' => 0.10344075, 'usage' => [
                'input_tokens' => 1000,
                'output_tokens' => 250,
                'cache_creation_input_tokens' => 50,
                'cache_read_input_tokens' => 25,
            ]]),
            '',
        ]));

        try {
            $usage = $reflection->invoke($runReal, $path);
        } finally {
            @unlink($path);
        }

        $this->assertSame(0.10344075, $usage['token_cost']);
        $this->assertSame(1325, $usage['tokens_used']);
        $this->assertTrue($usage['provider_usage']['complete']);
        $this->assertContains('claude-sonnet-4-6', $usage['provider_usage']['models_observed']);
    }

    public function test_single_case_battery_is_not_claim_ready_even_when_completed(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'computeBatteryClaimReady');
        $reflection->setAccessible(true);
        $runId = 'single-case-claim-'.bin2hex(random_bytes(4));

        $battery = app(AtlasForgeRivalsBatteryStateService::class);
        $battery->initialize($runId, [
            'mode' => 'fair',
        ], [[
            'id' => 'bugfix-l5-cascade-failure-fanout',
            'task_category' => 'bugfix',
            'difficulty_level' => 'L5',
        ]]);
        $battery->markCaseFinished($runId, 'bugfix-l5-cascade-failure-fanout', 'comparable');

        $this->assertFalse($reflection->invoke($runReal, $runId, 'fair'));
    }

    public function test_human_normal_prompt_mode_is_a_real_runner_prompt_style(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $resolve = new \ReflectionMethod($runReal, 'resolveCaseContext');
        $resolve->setAccessible(true);
        $command = new \ReflectionMethod($runReal, 'resolveProviderCommand');
        $command->setAccessible(true);

        $context = $resolve->invoke(
            $runReal,
            ['case' => 'planning-l1-acceptance-checklist'],
            AtlasForgeRivalsCasesRegistry::PRESET_RELEASE,
        );
        $case = array_replace($context['cases'][0], ['prompt_mode' => 'human-normal']);

        $argv = $command->invoke(
            $runReal,
            'atlas',
            AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET,
            $case,
            base_path(),
        );
        $prompt = implode("\n", array_map(static fn (mixed $part): string => (string) $part, $argv));

        $this->assertStringContainsString('Pedido do operador:', $prompt);
        $this->assertStringContainsString('Regras do benchmark:', $prompt);
        $this->assertStringContainsString("php artisan test --filter='AcceptanceChecklistTest'", $prompt);
        $this->assertStringNotContainsString("Objetivo:\n", $prompt);
    }

    public function test_messy_real_prompt_mode_measures_ambiguity_without_opening_scope(): void
    {
        $prompt = $this->runnerPromptForPromptMode('messy-real');

        $this->assertStringContainsString('Pedido do operador, do jeito que chegou:', $prompt);
        $this->assertStringContainsString('Tem algo errado ou incompleto nesta área', $prompt);
        $this->assertStringContainsString('Se houver ambiguidade, faça a menor suposição compatível', $prompt);
        $this->assertStringContainsString('Não invente arquivos fora do escopo', $prompt);
        $this->assertStringContainsString("php artisan test --filter='AcceptanceChecklistTest'", $prompt);
        $this->assertStringNotContainsString("Objetivo:\n", $prompt);
    }

    public function test_enterprise_change_prompt_mode_requires_evidence_risk_and_rollback_reasoning(): void
    {
        $prompt = $this->runnerPromptForPromptMode('enterprise-change');

        $this->assertStringContainsString('Mudança enterprise solicitada:', $prompt);
        $this->assertStringContainsString('Motivo de negócio:', $prompt);
        $this->assertStringContainsString('Controles obrigatórios:', $prompt);
        $this->assertStringContainsString('compatibilidade, evidência e rollback mental', $prompt);
        $this->assertStringContainsString('risco residual', $prompt);
        $this->assertStringContainsString("php artisan test --filter='AcceptanceChecklistTest'", $prompt);
        $this->assertStringNotContainsString("Objetivo:\n", $prompt);
    }

    public function test_resume_cleanup_restores_dirty_isolated_arms_before_preflight(): void
    {
        $service = app(AtlasForgeRivalsRunBatteryService::class);
        $root = sys_get_temp_dir().'/atlas-rivals-resume-cleanup-'.bin2hex(random_bytes(6));

        try {
            foreach (['atlas', 'rival'] as $arm) {
                $worktree = $root.'/'.$arm.'/workspace';
                @mkdir($worktree.'/vendor', 0o755, true);
                file_put_contents($worktree.'/tracked.txt', "baseline\n");
                file_put_contents($worktree.'/.gitignore', "vendor/\n.env\n.env.testing\n");
                file_put_contents($worktree.'/vendor/autoload.php', "<?php\n// runtime\n");
                file_put_contents($worktree.'/.env', "APP_ENV=testing\n");
                file_put_contents($worktree.'/.env.testing', "APP_ENV=testing\n");

                (new Process(['git', '-C', $worktree, 'init']))->mustRun();
                (new Process(['git', '-C', $worktree, 'config', 'user.email', 'atlas-rivals@example.test']))->mustRun();
                (new Process(['git', '-C', $worktree, 'config', 'user.name', 'Atlas Rivals']))->mustRun();
                (new Process(['git', '-C', $worktree, 'add', 'tracked.txt', '.gitignore']))->mustRun();
                (new Process(['git', '-C', $worktree, 'commit', '-m', 'seed']))->mustRun();

                file_put_contents($worktree.'/tracked.txt', "partial provider output\n");
                file_put_contents($worktree.'/provider-output.tmp', "remove me\n");
            }

            $cleanup = new \ReflectionMethod($service, 'prepareResumeWorktrees');
            $cleanup->setAccessible(true);
            $result = $cleanup->invoke($service, [
                'arms_root' => $root,
                'atlas' => $root.'/atlas/workspace',
                'rival' => $root.'/rival/workspace',
            ]);

            $this->assertSame('ok', $result['status'], json_encode($result, JSON_PRETTY_PRINT));
            foreach (['atlas', 'rival'] as $arm) {
                $worktree = $root.'/'.$arm.'/workspace';
                $this->assertSame("baseline\n", file_get_contents($worktree.'/tracked.txt'));
                $this->assertFileDoesNotExist($worktree.'/provider-output.tmp');
                $this->assertFileExists($worktree.'/vendor/autoload.php');
                $this->assertFileExists($worktree.'/.env');
                $this->assertFileExists($worktree.'/.env.testing');
                $status = new Process(['git', '-C', $worktree, 'status', '--porcelain']);
                $status->mustRun();
                $this->assertSame('', trim($status->getOutput()));
            }
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_resume_refuses_to_run_while_same_battery_has_fresh_events(): void
    {
        $service = app(AtlasForgeRivalsRunBatteryService::class);
        $root = sys_get_temp_dir().'/atlas-rivals-active-resume-'.bin2hex(random_bytes(6));
        @mkdir($root, 0o755, true);
        file_put_contents($root.'/battery.json', json_encode([
            'cases' => [
                ['case_id' => 'case-a', 'state' => 'completed'],
                ['case_id' => 'case-b', 'state' => 'running'],
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($root.'/events.jsonl', json_encode(['kind' => 'heartbeat'])."\n");
        @touch($root.'/events.jsonl', time());

        try {
            $active = new \ReflectionMethod($service, 'resumeActiveRunnerBlockers');
            $active->setAccessible(true);

            $blockers = $active->invoke($service, [
                'base' => $root,
                'events_jsonl' => $root.'/events.jsonl',
            ]);

            $this->assertNotEmpty($blockers);
            $this->assertStringStartsWith('resume_refused_active_runner_heartbeat:', $blockers[0]);

            @touch($root.'/events.jsonl', time() - 120);
            $this->assertSame([], $active->invoke($service, [
                'base' => $root,
                'events_jsonl' => $root.'/events.jsonl',
            ]));
        } finally {
            $this->removeDirectory($root);
        }
    }

    public function test_run_battery_codex_rival_blocks_when_binary_missing_before_provider_call(): void
    {
        if ($this->whichBinary('codex') !== '') {
            $this->markTestSkipped('codex CLI is installed; cannot exercise the honest blocker path.');
        }

        $response = $this->dispatchRunBattery([
            'mode' => 'full_power',
            'atlas_model' => 'sonnet',
            'rival' => 'codex',
            'preset' => 'release',
            'confirmations' => [
                'runbook_reviewed' => true,
                'provider_cost' => true,
                'real_provider_call' => true,
            ],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertContains('rival_driver_not_configured:codex', $response['blockers']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
    }

    public function test_run_battery_release_dry_run_path_never_calls_provider(): void
    {
        // dry-run never requires the three operator confirmations because it
        // is provider-free by contract. It must complete or block without
        // touching any external provider, regardless of preset.
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('dry-run', [
            'mode' => 'fair',
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'release',
            'confirmations' => [
                'runbook_reviewed' => false,
                'provider_cost' => false,
                'real_provider_call' => false,
            ],
        ]);

        $this->assertFalse($response['external_provider_call'] ?? true);
        $this->assertFalse($response['provider_tokens_spent'] ?? true);
    }

    public function test_run_battery_release_dry_run_uses_provider_arena_corpus_without_legacy_manifest_blocker(): void
    {
        $response = $this->dispatchRunBattery([
            'mode' => 'local_fake',
            'atlas_model' => 'claude_sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'release',
            'dry_run' => true,
            'confirmations' => [
                'runbook_reviewed' => false,
                'provider_cost' => false,
                'real_provider_call' => false,
            ],
        ]);

        $this->assertSame('ok', $response['status']);
        $this->assertSame('dry_run_planned', $response['verdict']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
        $this->assertFalse($response['claim_ready']);
        $this->assertNull($response['winner']);
        $this->assertNull($response['scorecard']);

        $blockers = implode('|', array_map('strval', $response['blockers'] ?? []));
        $this->assertStringNotContainsString('case_manifest_invalid', $blockers);
        $this->assertStringNotContainsString('atlas_arm_not_forge', $blockers);
        $this->assertContains('preflight', array_column($response['phases'], 'phase'));
        $this->assertContains('plan-real', array_column($response['phases'], 'phase'));
    }

    public function test_runtime_aggregate_receipt_worst_of_propagates_failures_through_hard_gates(): void
    {
        // Confirm the worst-of aggregation contract: if any case has a non-zero
        // exit code, the top-level receipt surfaces a non-zero exit code so the
        // adjudicator's hard gate trips honestly instead of letting a partial
        // success hide a single-case failure.
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'aggregateReceipt');
        $reflection->setAccessible(true);

        $base = [
            'arm' => 'atlas',
            'exit_code' => 0,
            'test_exit_code' => 0,
            'patch_diff_bytes' => 2_000,
            'changed_files' => [],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'workspace_blockers' => [],
            'killed' => false,
        ];
        $perCase = [
            ['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 1_500, 'killed' => false],
            ['exit_code' => 1, 'test_exit_code' => 1, 'patch_diff_bytes' => 0, 'killed' => false],
            ['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 4_200, 'killed' => false],
        ];
        $aggregated = $reflection->invoke($runReal, $base, $perCase, ['file_a.php'], [], []);

        $this->assertSame(1, $aggregated['exit_code'], 'worst-of exit_code must propagate the failing case');
        $this->assertSame(1, $aggregated['test_exit_code'], 'worst-of test_exit_code must propagate the failing case');
        $this->assertSame(0, $aggregated['patch_diff_bytes'], 'worst-of patch_diff_bytes must surface the empty-patch case');
        $this->assertSame('worst_of_per_case', $aggregated['aggregate_kind']);
    }

    public function test_runtime_aggregate_receipt_clean_run_keeps_top_level_receipt_green(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'aggregateReceipt');
        $reflection->setAccessible(true);

        $base = [
            'arm' => 'atlas',
            'exit_code' => 0,
            'test_exit_code' => 0,
            'patch_diff_bytes' => 3_000,
            'changed_files' => [],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'workspace_blockers' => [],
            'killed' => false,
        ];
        $perCase = [
            ['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 1_500, 'killed' => false],
            ['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 3_200, 'killed' => false],
        ];
        $aggregated = $reflection->invoke($runReal, $base, $perCase, ['file_a.php', 'file_b.php'], [], []);

        $this->assertSame(0, $aggregated['exit_code']);
        $this->assertSame(0, $aggregated['test_exit_code']);
        $this->assertSame(1_500, $aggregated['patch_diff_bytes'], 'patch_diff_bytes must be MIN across cases');
        $this->assertFalse($aggregated['workspace_has_blocking_changes']);
        $this->assertSame(['file_a.php', 'file_b.php'], $aggregated['changed_files']);
    }

    public function test_runtime_aggregate_receipt_trips_when_any_case_bleeds_out_of_scope_or_bytecode(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'aggregateReceipt');
        $reflection->setAccessible(true);

        $base = [
            'arm' => 'atlas',
            'exit_code' => 0,
            'test_exit_code' => 0,
            'patch_diff_bytes' => 1_000,
            'changed_files' => [],
            'out_of_scope_files' => [],
            'bytecode_artifacts' => [],
            'workspace_blockers' => [],
            'killed' => false,
        ];
        $aggregated = $reflection->invoke(
            $runReal,
            $base,
            [['exit_code' => 0, 'test_exit_code' => 0, 'patch_diff_bytes' => 1_000, 'killed' => false]],
            ['file_a.php', 'bad/foo.php'],
            ['bad/foo.php'],
            ['runtimes/python/__pycache__/x.pyc'],
        );

        $this->assertTrue($aggregated['workspace_has_blocking_changes']);
        $this->assertContains('out_of_scope_change:bad/foo.php', $aggregated['arm_contract_blockers']);
        $this->assertContains('bytecode_artifact_after_run:runtimes/python/__pycache__/x.pyc', $aggregated['workspace_blockers']);
        $this->assertTrue($aggregated['arm_contract_has_failures']);
    }

    public function test_safe_case_dir_normalises_unusual_case_ids_without_path_traversal(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'safeCaseDir');
        $reflection->setAccessible(true);

        $this->assertSame('case-unknown', $reflection->invoke($runReal, ''));
        $this->assertSame('backend-pagination-off-by-one', $reflection->invoke($runReal, 'backend-pagination-off-by-one'));
        $sanitized = $reflection->invoke($runReal, 'a/b/../etc/passwd');
        $this->assertStringStartsWith('case-', $sanitized);
        $this->assertStringNotContainsString('/', $sanitized);
        $this->assertStringNotContainsString('..', $sanitized);
    }

    public function test_artisan_command_for_release_battery_emits_json_envelope_with_blocked_status_without_confirmations(): void
    {
        Artisan::call('atlas:forge:rivals', [
            'action' => 'run-battery',
            '--mode' => 'fair',
            '--atlas-model' => 'sonnet',
            '--rival' => 'claude_sonnet',
            '--preset' => 'release',
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('run-battery', $payload['action']);
        $this->assertSame('atlas.forge.rivals.action_response.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['separated_from_external_rivals_certification'] ?? false);
    }

    public function test_existing_quick_preset_path_keeps_single_case_backward_compat(): void
    {
        // Quick preset still maps to the legacy single-case registry; the
        // release multi-case path must not regress quick's contract.
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $reflection = new \ReflectionMethod($runReal, 'resolveCaseContext');
        $reflection->setAccessible(true);

        $context = $reflection->invoke($runReal, [], AtlasForgeRivalsCasesRegistry::PRESET_QUICK);

        $this->assertCount(1, $context['cases'], 'quick preset stays single-case for legacy compat');
        $this->assertSame('legacy_preset', $context['source']);
    }

    public function test_provider_arena_fixture_staging_falls_back_to_source_corpus_and_reads_nested_files(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);

        $resolve = new \ReflectionMethod($runReal, 'resolveCaseContext');
        $resolve->setAccessible(true);
        $context = $resolve->invoke(
            $runReal,
            ['case' => 'frontend-form-validation-accessibility'],
            AtlasForgeRivalsCasesRegistry::PRESET_QUICK,
        );

        $worktree = sys_get_temp_dir().'/atlas-rivals-fixture-stage-'.bin2hex(random_bytes(6));
        @mkdir($worktree, 0o755, true);

        try {
            $stage = new \ReflectionMethod($runReal, 'stageCaseFixture');
            $stage->setAccessible(true);

            $result = $stage->invoke(
                $runReal,
                'fixture-stage-source-corpus-test',
                'atlas',
                $worktree,
                $context['cases'][0],
            );

            $this->assertSame('ok', $result['status'], json_encode($result, JSON_PRETTY_PRINT));
            $this->assertSame('source_repo', $result['seed_source']);
            $this->assertContains('atlas-desktop/src/components/forge/SignupForm.tsx', $result['staged_files']);
            $this->assertContains('atlas-desktop/src/components/forge/__tests__/SignupForm.test.tsx', $result['staged_files']);
            $this->assertArrayHasKey('atlas-desktop/src/components/forge/SignupForm.tsx', $result['file_hashes']);
            $this->assertArrayHasKey('atlas-desktop/src/components/forge/__tests__/SignupForm.test.tsx', $result['file_hashes']);
            $this->assertFileExists($worktree.'/atlas-desktop/src/components/forge/SignupForm.tsx');
            $this->assertFileExists($worktree.'/atlas-desktop/src/components/forge/__tests__/SignupForm.test.tsx');
            $this->assertNotContains('fixture_seed_dir_not_found:storage/forge-rivals-corpus/frontend-form-validation-accessibility/seed', $result['blockers']);
        } finally {
            $this->removeDirectory($worktree);
        }
    }

    public function test_provider_arena_fixture_staging_blocks_empty_seed_before_provider_call(): void
    {
        // Pick a case whose seed is still README-only in the corpus. The
        // contract under test is the per-arm stage gate, not the case picked.
        $emptyCaseId = $this->firstEmptySeedCaseId();
        if ($emptyCaseId === null) {
            $this->markTestSkipped('No README-only seeds left in the corpus; gate is exercised by other tests.');
        }

        $runReal = app(AtlasForgeRivalsRunRealService::class);

        $resolve = new \ReflectionMethod($runReal, 'resolveCaseContext');
        $resolve->setAccessible(true);
        $context = $resolve->invoke(
            $runReal,
            ['case' => $emptyCaseId],
            AtlasForgeRivalsCasesRegistry::PRESET_RELEASE,
        );

        $worktree = sys_get_temp_dir().'/atlas-rivals-empty-fixture-'.bin2hex(random_bytes(6));
        @mkdir($worktree, 0o755, true);

        try {
            $stage = new \ReflectionMethod($runReal, 'stageCaseFixture');
            $stage->setAccessible(true);

            $result = $stage->invoke(
                $runReal,
                'fixture-empty-source-corpus-test',
                'atlas',
                $worktree,
                $context['cases'][0],
            );

            $this->assertSame('blocked', $result['status'], json_encode($result, JSON_PRETTY_PRINT));
            $this->assertSame('source_repo', $result['seed_source']);
            $this->assertSame([], $result['staged_files']);
            $this->assertContains('fixture_seed_empty:'.$emptyCaseId, $result['blockers']);
        } finally {
            $this->removeDirectory($worktree);
        }
    }

    public function test_provider_arena_fixture_readiness_blocks_empty_seed_before_battery_can_score(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $seedRoot = base_path('storage/forge-rivals-corpus/__test_empty_readiness_seed/seed');
        @mkdir($seedRoot, 0o755, true);
        file_put_contents($seedRoot.'/README.md', "fixture intentionally empty\n");

        try {
            $readiness = new \ReflectionMethod($runReal, 'fixtureReadinessBlockers');
            $readiness->setAccessible(true);

            $blockers = $readiness->invoke($runReal, [[
                'id' => 'readiness-empty-seed',
                'case_source' => 'provider_arena_corpus',
                'allowed_files' => ['app/Services/Ai/Programming/ReadinessProbe.php'],
                'expected_changed_files' => ['app/Services/Ai/Programming/ReadinessProbe.php'],
                'setup_fixture' => [
                    'seed_dir' => 'storage/forge-rivals-corpus/__test_empty_readiness_seed/seed',
                ],
            ]]);

            $this->assertContains('fixture_seed_empty:readiness-empty-seed', $blockers);
        } finally {
            $this->removeDirectory(base_path('storage/forge-rivals-corpus/__test_empty_readiness_seed'));
        }
    }

    private function firstEmptySeedCaseId(): ?string
    {
        $cases = app(AtlasForgeRivalsProviderArenaCorpusService::class)
            ->casesForCaseSet(AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_RELEASE);

        foreach ($cases as $case) {
            $name = (string) ($case['case_id'] ?? '');
            $seedDir = (string) data_get($case, 'setup_fixture.seed_dir', '');
            if ($name === '' || $seedDir === '') {
                continue;
            }
            $seed = base_path($seedDir);
            if (! is_dir($seed)) {
                continue;
            }
            $hasNonReadme = false;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($seed, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()
                    && strtolower($file->getFilename()) !== 'readme.md'
                ) {
                    $hasNonReadme = true;
                    break;
                }
            }
            if (! $hasNonReadme) {
                return $name;
            }
        }

        return null;
    }

    public function test_provider_arena_scope_ignores_unchanged_fixture_files_and_blocks_fixture_mutation(): void
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $worktree = sys_get_temp_dir().'/atlas-rivals-scope-fixture-'.bin2hex(random_bytes(6));
        @mkdir($worktree.'/docs/planning/inbox', 0o755, true);
        @mkdir($worktree.'/tests/Unit/Planning', 0o755, true);

        file_put_contents($worktree.'/docs/planning/inbox/feature.md', "Feature input\n");
        file_put_contents($worktree.'/tests/Unit/Planning/AcceptanceChecklistTest.php', "<?php\n// locked test\n");
        file_put_contents($worktree.'/docs/planning/inbox/feature.acceptance.md', "- Entregar criterio verificavel\n");

        (new Process(['git', '-C', $worktree, 'init']))->mustRun();

        $case = [
            'id' => 'planning-l1-acceptance-checklist',
            'case_source' => 'provider_arena_corpus',
            'allowed_files' => [
                'docs/planning/inbox/feature.acceptance.md',
                'tests/Unit/Planning/AcceptanceChecklistTest.php',
            ],
            'expected_changed_files' => ['docs/planning/inbox/feature.acceptance.md'],
            '_fixture_baseline_hashes' => [
                'docs/planning/inbox/feature.md' => hash_file('sha256', $worktree.'/docs/planning/inbox/feature.md'),
                'tests/Unit/Planning/AcceptanceChecklistTest.php' => hash_file('sha256', $worktree.'/tests/Unit/Planning/AcceptanceChecklistTest.php'),
            ],
        ];

        try {
            $scope = new \ReflectionMethod($runReal, 'scopeCheck');
            $scope->setAccessible(true);

            $cleanFixtureResult = $scope->invoke($runReal, $worktree, $case);
            $this->assertSame(['docs/planning/inbox/feature.acceptance.md'], $cleanFixtureResult['changed_files']);
            $this->assertSame([], $cleanFixtureResult['blockers']);

            file_put_contents($worktree.'/tests/Unit/Planning/AcceptanceChecklistTest.php', "<?php\n// tampered\n");
            $tamperedFixtureResult = $scope->invoke($runReal, $worktree, $case);
            $this->assertContains('tests/Unit/Planning/AcceptanceChecklistTest.php', $tamperedFixtureResult['out_of_scope_files']);
            $this->assertContains('fixture_file_modified:tests/Unit/Planning/AcceptanceChecklistTest.php', $tamperedFixtureResult['blockers']);
        } finally {
            $this->removeDirectory($worktree);
        }
    }

    public function test_corpus_pre_validation_blocks_release_when_any_seed_is_empty(): void
    {
        $emptyCaseId = $this->firstEmptySeedCaseId();
        if ($emptyCaseId === null) {
            $this->markTestSkipped('Corpus has no README-only seeds; pre-validation gate is exercised by other tests.');
        }

        $service = app(AtlasForgeRivalsCorpusPreValidationService::class);
        $result = $service->validate([
            'preset' => AtlasForgeRivalsCasesRegistry::PRESET_RELEASE,
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_RELEASE,
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['provider_tokens_spent']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertContains('fixture_seed_empty:'.$emptyCaseId, $result['blockers']);
        $this->assertContains($emptyCaseId, $result['blocked_case_ids']);
        $this->assertGreaterThan(0, $result['blocked_count']);
    }

    public function test_corpus_pre_validation_blocks_unknown_case_set_honestly(): void
    {
        $service = app(AtlasForgeRivalsCorpusPreValidationService::class);
        $result = $service->validate([
            'preset' => AtlasForgeRivalsCasesRegistry::PRESET_RELEASE,
            'case_set' => 'no-such-case-set',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['provider_tokens_spent']);
        $joined = implode('|', $result['blockers']);
        $this->assertTrue(
            str_contains($joined, 'unknown_case_set:no-such-case-set')
            || str_contains($joined, 'empty_case_set:no-such-case-set'),
            'expected an honest unknown-case-set blocker, got: '.$joined,
        );
    }

    public function test_run_battery_release_local_fake_blocks_on_corpus_contamination_before_any_provider_call(): void
    {
        $emptyCaseId = $this->firstEmptySeedCaseId();
        if ($emptyCaseId === null) {
            $this->markTestSkipped('Corpus has no README-only seeds; gate is exercised by other tests.');
        }

        $response = $this->dispatchRunBattery([
            'mode' => 'local_fake',
            'atlas_model' => 'claude_sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'release',
            'confirmations' => [
                'runbook_reviewed' => false,
                'provider_cost' => false,
                'real_provider_call' => false,
            ],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
        $this->assertNull($response['winner']);
        $this->assertNull($response['scorecard']);
        $this->assertSame('invalid_corpus_contaminated', $response['verdict']);
        $this->assertTrue($response['human_review_required'] ?? false);
        $phases = array_column($response['phases'] ?? [], 'phase');
        $this->assertContains('corpus-pre-validation', $phases, 'corpus pre-validation phase must be reachable');

        $joined = implode('|', $response['blockers'] ?? []);
        $this->assertStringContainsString('fixture_seed_empty:', $joined, 'aggregated blockers must surface fixture_seed_empty');
    }

    public function test_run_battery_terminal_response_never_emits_score_when_blocked(): void
    {
        $response = $this->dispatchRunBattery([
            'mode' => 'fair',
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'release',
            'confirmations' => [
                'runbook_reviewed' => false,
                'provider_cost' => false,
                'real_provider_call' => false,
            ],
        ]);

        $this->assertSame('blocked', $response['status']);
        $this->assertArrayHasKey('score', $response);
        $this->assertNull($response['score'], 'score must be null on any blocked battery');
        $this->assertNull($response['winner']);
        $this->assertNull($response['scorecard']);
        $this->assertSame('invalid_operator_confirmations_missing', $response['verdict']);
        $this->assertTrue($response['human_review_required'] ?? false);
    }

    public function test_corpus_pre_validation_passes_only_when_every_case_has_real_seeds(): void
    {
        // The release case-set may contain still-empty seeds in the current
        // corpus, so we exercise the green path by feeding a curated list of
        // case_ids that we can verify are populated.
        $service = app(AtlasForgeRivalsCorpusPreValidationService::class);
        $populatedIds = $this->populatedCaseIds(limit: 5);
        if ($populatedIds === []) {
            $this->markTestSkipped('Corpus has no populated seeds yet.');
        }

        $result = $service->validate([
            'preset' => AtlasForgeRivalsCasesRegistry::PRESET_RELEASE,
            'cases' => $populatedIds,
        ]);

        $this->assertSame('ok', $result['status'], json_encode($result, JSON_PRETTY_PRINT));
        $this->assertSame(0, $result['blocked_count']);
        $this->assertSame(count($populatedIds), $result['valid_count']);
        foreach ($result['per_case'] as $case) {
            $this->assertTrue($case['valid']);
            $this->assertGreaterThan(0, $case['stageable_file_count']);
            $this->assertNotEmpty($case['expected_changed_files']);
        }
    }

    /**
     * @return list<string>
     */
    private function populatedCaseIds(int $limit): array
    {
        $root = base_path('storage/forge-rivals-corpus');
        if (! is_dir($root)) {
            return [];
        }
        $dirs = scandir($root) ?: [];
        sort($dirs);
        $populated = [];
        foreach ($dirs as $name) {
            if ($name === '.' || $name === '..' || ! is_dir($root.'/'.$name)) {
                continue;
            }
            $seed = $root.'/'.$name.'/seed';
            if (! is_dir($seed)) {
                continue;
            }
            $hasNonReadme = false;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($seed, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && $file->isFile()
                    && strtolower($file->getFilename()) !== 'readme.md'
                ) {
                    $hasNonReadme = true;
                    break;
                }
            }
            if ($hasNonReadme) {
                $populated[] = $name;
            }
            if (count($populated) >= $limit) {
                break;
            }
        }

        return $populated;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function dispatchRunBattery(array $input): array
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);

        return $dispatcher->dispatch('run-battery', $input);
    }

    private function whichBinary(string $binary): string
    {
        $proc = Process::fromShellCommandline('which '.escapeshellarg($binary));
        $proc->setTimeout(5);
        $proc->run();
        if (! $proc->isSuccessful()) {
            return '';
        }

        return trim((string) $proc->getOutput());
    }

    private function runnerPromptForPromptMode(string $promptMode): string
    {
        $runReal = app(AtlasForgeRivalsRunRealService::class);
        $resolve = new \ReflectionMethod($runReal, 'resolveCaseContext');
        $resolve->setAccessible(true);
        $command = new \ReflectionMethod($runReal, 'resolveProviderCommand');
        $command->setAccessible(true);

        $context = $resolve->invoke(
            $runReal,
            ['case' => 'planning-l1-acceptance-checklist'],
            AtlasForgeRivalsCasesRegistry::PRESET_RELEASE,
        );
        $case = array_replace($context['cases'][0], ['prompt_mode' => $promptMode]);

        $argv = $command->invoke(
            $runReal,
            'atlas',
            AtlasForgeRivalsModelMatrix::MODEL_CLAUDE_SONNET,
            $case,
            base_path(),
        );

        return implode("\n", array_map(static fn (mixed $part): string => (string) $part, $argv));
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item instanceof \SplFileInfo && $item->isDir()) {
                @rmdir($item->getPathname());
            } elseif ($item instanceof \SplFileInfo) {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
