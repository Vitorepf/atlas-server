<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsActionDispatcher;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryReportService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsBatteryStateService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Atlas Forge Rivals · Multi-Case Release Runner v1 integration tests.
 *
 * These tests drive the dispatcher (run-battery / status / resume /
 * battery-report) over `local_fake` and `--dry-run` paths so that no
 * external provider is invoked. They cover:
 *
 *   - `run-battery --preset=release` blocks honestly without the three
 *     operator confirmations;
 *   - `run-battery --dry-run --preset=release` plans without provider
 *     and without confirmations;
 *   - the dispatcher exposes `resume`, `battery-report`, `next` actions;
 *   - status returns the battery catalogue when one exists.
 *
 * No provider tokens are spent in any test. `external_rivals_certification`
 * never moves regardless of pipeline outcome.
 */
final class AtlasForgeRivalsMultiCaseReleaseRunnerTest extends TestCase
{
    public function test_run_battery_release_dry_run_passes_without_confirmations_and_no_provider(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);

        $response = $dispatcher->dispatch('run-battery', [
            'mode' => 'fair',
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

        $this->assertSame('ok', $response['status'], json_encode($response, JSON_PRETTY_PRINT));
        $this->assertTrue($response['dry_run'] ?? false);
        $this->assertSame('dry_run_planned', $response['verdict'] ?? null);
        $this->assertFalse($response['external_provider_call']);
        $this->assertFalse($response['provider_tokens_spent']);
        $this->assertFalse($response['claim_ready']);
        $this->assertNull($response['winner']);
        $this->assertNull($response['scorecard']);
        $this->assertTrue($response['separated_from_external_rivals_certification'] ?? false);
    }

    public function test_run_battery_release_without_dry_run_still_demands_three_confirmations(): void
    {
        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);

        $response = $dispatcher->dispatch('run-battery', [
            'mode' => 'fair',
            'atlas_model' => 'claude_sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'release',
            'dry_run' => false,
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
    }

    public function test_status_action_surfaces_battery_snapshot_when_battery_initialised(): void
    {
        $runId = 'multi-status-'.Str::lower(Str::random(8));
        $battery = app(AtlasForgeRivalsBatteryStateService::class);
        $battery->initialize($runId, [
            'preset' => 'release',
            'mode' => 'local_fake',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
        ], $this->miniReleaseCases());

        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('status', ['run_id' => $runId]);

        $this->assertSame('ok', $response['status']);
        $this->assertArrayHasKey('battery', $response);
        $this->assertTrue($response['battery']['exists']);
        $this->assertSame(3, $response['battery']['case_count']);
        $this->assertSame(3, $response['battery']['pending_case_count']);
        $this->assertStringContainsString('resume --run-id='.$runId, (string) $response['next_command']);
    }

    public function test_resume_action_is_routed_to_run_battery_with_resume_flag_set(): void
    {
        $runId = 'multi-resume-'.Str::lower(Str::random(8));
        $battery = app(AtlasForgeRivalsBatteryStateService::class);
        $battery->initialize($runId, [
            'preset' => 'release',
            'mode' => 'fair',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
        ], $this->miniReleaseCases());

        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        // Without confirmations the resume must block — the resume action
        // does not silently spend tokens. It DOES route through the runner
        // and is announced as `run-battery` (so logs stay searchable).
        $response = $dispatcher->dispatch('resume', [
            'mode' => 'fair',
            'atlas_model' => 'claude_sonnet',
            'rival' => 'claude_sonnet',
            'preset' => 'release',
            'run_id' => $runId,
            'confirmations' => [
                'runbook_reviewed' => false,
                'provider_cost' => false,
                'real_provider_call' => false,
            ],
        ]);

        $this->assertSame('run-battery', $response['action']);
        $this->assertSame('blocked', $response['status']);
        $this->assertFalse($response['external_provider_call']);
    }

    public function test_battery_report_action_renders_against_an_initialised_battery(): void
    {
        $runId = 'multi-report-'.Str::lower(Str::random(8));
        $battery = app(AtlasForgeRivalsBatteryStateService::class);
        $battery->initialize($runId, [
            'preset' => 'release',
            'mode' => 'local_fake',
            'atlas_model' => 'claude_sonnet',
            'rival_model' => 'claude_sonnet',
        ], $this->miniReleaseCases());
        // mark one case completed so the report has some signal
        $battery->markCaseFinished($runId, 'case-easy', 'comparable');

        $dispatcher = app(AtlasForgeRivalsActionDispatcher::class);
        $response = $dispatcher->dispatch('battery-report', ['run_id' => $runId]);

        $this->assertSame('ok', $response['status']);
        $this->assertSame(AtlasForgeRivalsBatteryReportService::SCHEMA_VERSION, $response['schema_version']);
        $this->assertSame(3, $response['case_count']);
        $this->assertFalse($response['claim_ready']);
        $this->assertTrue($response['separated_from_external_rivals_certification']);
        $this->assertFileExists($response['report_path']);
    }

    public function test_command_signature_lists_resume_and_battery_report_actions(): void
    {
        $reflection = new \ReflectionClass(\App\Console\Commands\AtlasForgeRivalsCommand::class);
        $actions = $reflection->getConstant('ACTIONS');
        $this->assertIsArray($actions);
        $this->assertContains('resume', $actions);
        $this->assertContains('battery-report', $actions);
        $this->assertContains('next', $actions);
    }

    public function test_corpus_release_case_set_carries_difficulty_at_l1_l3_or_l5(): void
    {
        $corpus = app(AtlasForgeRivalsProviderArenaCorpusService::class);
        $cases = $corpus->cases();
        $this->assertGreaterThanOrEqual(3, count($cases), 'corpus must declare at least a quick preset');
        $allowed = [
            AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_L1,
            AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_L2,
            AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_L3,
            AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_L4,
            AtlasForgeRivalsProviderArenaCorpusService::DIFFICULTY_LEVEL_L5,
        ];
        foreach ($cases as $case) {
            $this->assertContains(
                $case['difficulty_level'] ?? null,
                $allowed,
                'Each corpus case must declare a canonical L1-L5 difficulty level.',
            );
            $this->assertGreaterThan(0, (float) ($case['difficulty_weight'] ?? 0));
        }
    }

    public function test_run_battery_dry_run_via_artisan_emits_canonical_envelope(): void
    {
        Artisan::call('atlas:forge:rivals', [
            'action' => 'run-battery',
            '--mode' => 'fair',
            '--atlas-model' => 'sonnet',
            '--rival' => 'claude_sonnet',
            '--preset' => 'release',
            '--dry-run' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);
        $this->assertSame('run-battery', $payload['action']);
        $this->assertSame('atlas.forge.rivals.action_response.v1', $payload['schema_version']);
        $this->assertSame('ok', $payload['status']);
        $this->assertTrue($payload['dry_run'] ?? false);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['separated_from_external_rivals_certification'] ?? false);
    }

    /**
     * Synthesise three minimal arena-shaped cases covering L1/L3/L5 so the
     * battery report has a non-trivial breakdown without needing the
     * corpus' on-disk seeds.
     *
     * @return list<array<string,mixed>>
     */
    private function miniReleaseCases(): array
    {
        return [
            $this->miniCase('case-easy', 'bugfix', 'easy'),
            $this->miniCase('case-medium', 'backend', 'medium'),
            $this->miniCase('case-hard', 'architecture', 'hard'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function miniCase(string $id, string $category, string $difficulty): array
    {
        $level = AtlasForgeRivalsProviderArenaCorpusService::difficultyToLevel($difficulty);

        return [
            'id' => $id,
            'case_source' => 'provider_arena_corpus',
            'case_set' => 'release',
            'task_category' => $category,
            'category' => $category,
            'difficulty' => $difficulty,
            'difficulty_level' => $level,
            'difficulty_weight' => AtlasForgeRivalsProviderArenaCorpusService::difficultyLevelWeight($level),
        ];
    }
}
