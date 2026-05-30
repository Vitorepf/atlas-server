<?php

declare(strict_types=1);

namespace App\Console\Commands\Foundry;

use App\Services\Ai\Foundry\Frontier\FrontierGenerationOrchestratorService;
use App\Services\Ai\Foundry\Frontier\Ports\AtlasDecideFrontierGeneratorService;
use App\Services\Ai\Foundry\Frontier\Ports\DeterministicFixtureFrontierGeneratorService;
use App\Services\Ai\Foundry\Frontier\Ports\FrontierGeneratorPort;
use Illuminate\Console\Command;

/**
 * Foundry AP-C · gated, proposal-only frontier generation entrypoint.
 *
 * DEFAULT-OFF. Generation requires the PERSISTENT config
 * config('atlas.software_company_stewardship.frontier_mode')===true (the single
 * source of truth, also read by the AP-B exhaustion/rarity gate). If the config
 * is false the command refuses with reason='frontier_mode_off'. There is NO
 * --frontier-mode transient toggle and NO --gate-eligible-override (that flag was
 * a catastrophic eligibility bypass and is REMOVED): eligibility comes ONLY from
 * a real FoundryExhaustionRarityGateService::decide() call.
 *
 * The command is read/propose-only: survivors are admitted to the operator
 * curation inbox as pending_operator_review. It NEVER writes canon/docs/code,
 * NEVER merges, NEVER executes, NEVER auto-approves. Emits
 * atlas.foundry.frontier_generator_result.v1.
 *
 * --fixture-generator is allowed ONLY with the explicit dev/test authorization
 * marker ATLAS_FRONTIER_FIXTURE_AUTHORIZED=1 and labels its output fixture; the
 * orchestrator refuses a fixture as a real proposal source otherwise.
 */
class FrontierGenerateCommand extends Command
{
    protected $signature = 'atlas:foundry:frontier-generate '
        .'{--area=agentic_engineering_os : Area id for the harvested dossier} '
        .'{--count=3 : Requested proposal count} '
        .'{--fixture-generator : DEV/TEST ONLY — use the labelled fixture generator (needs ATLAS_FRONTIER_FIXTURE_AUTHORIZED=1)} '
        .'{--json : Emit JSON}';

    protected $description = 'Gated, proposal-only Foundry AP-C frontier generation; requires persistent frontier_mode config + AP-B eligibility; never writes canon.';

    public function handle(): int
    {
        $useFixture = (bool) $this->option('fixture-generator');
        $fixtureAuthorized = $useFixture && getenv('ATLAS_FRONTIER_FIXTURE_AUTHORIZED') === '1';

        if ($useFixture && ! $fixtureAuthorized) {
            $this->error('--fixture-generator requires ATLAS_FRONTIER_FIXTURE_AUTHORIZED=1 (dev/test marker). Refusing.');

            return self::FAILURE;
        }

        $generator = $this->resolveGenerator($useFixture);

        $real = FrontierGenerationOrchestratorService::withRealGenerator(
            app(\App\Services\Ai\Foundry\FoundryExhaustionRarityGateService::class),
            $generator,
            app(\App\Services\Ai\Foundry\Frontier\Armor\FrontierEvidenceBoundGate::class),
            app(\App\Services\Ai\Foundry\Frontier\Armor\FrontierDedupPriorArtGate::class),
            $this->realJudgePanel(),
            app(\App\Services\Ai\Foundry\Frontier\Armor\FrontierDecomposerGate::class),
            app(\App\Services\Ai\Foundry\Frontier\Armor\FrontierDriftMapperGate::class),
            app(\App\Services\Ai\Foundry\Frontier\Armor\FrontierMetricRollbackGate::class),
            app(\App\Services\Ai\Foundry\Frontier\FrontierProposalToGapCandidateAdapter::class),
            app(\App\Services\Ai\SelfDirectedEvolution\SelfDirectedEvolutionCurationInboxService::class),
        );

        $result = $real->run([
            'area_id' => (string) $this->option('area'),
            'count' => (int) $this->option('count'),
            'fixture_authorized' => $fixtureAuthorized,
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['status'] === FrontierGenerationOrchestratorService::STATUS_GENERATED
                ? self::SUCCESS
                : self::SUCCESS; // skip/blocked are honest, not command failures
        }

        $this->info('Foundry AP-C frontier generation');
        $this->line('  status              : '.$result['status']);
        $this->line('  reason              : '.$result['reason']);
        $this->line('  gate_status         : '.$result['gate_status']);
        $this->line('  frontier_mode       : '.($result['frontier_mode'] ? 'on' : 'off'));
        $this->line('  generator_label     : '.($result['generator_label'] ?? '-'));
        $this->line('  proposals_generated : '.$result['proposals_generated']);
        $this->line('  survivors_count     : '.$result['survivors_count']);
        $this->line('  drops               : '.count($result['drops']));
        $this->line('  curation_inbox      : '.($result['curation_inbox'] === null ? 'null (proposal-only; nothing admitted)' : 'pending_operator_review'));
        $this->line('  result_hash         : '.$result['result_hash']);

        return self::SUCCESS;
    }

    /**
     * Build the real I3 judge panel: 3 seats, each a real AtlasDecide-backed judge.
     * With no provider execution bridge today, every seat honestly refutes
     * (judge_provider_real_execution_bridge_missing), so I3 drops proposals with a
     * recorded reason — the honest current state, never a fabricated accept.
     */
    private function realJudgePanel(): \App\Services\Ai\Foundry\Frontier\Armor\FrontierJudgePanelGate
    {
        $decide = app(\App\Services\Ai\AtlasDecideService::class);
        $seats = [
            new \App\Services\Ai\Foundry\Frontier\Ports\AtlasDecideFrontierJudgeService($decide, 'claude_codex'),
            new \App\Services\Ai\Foundry\Frontier\Ports\AtlasDecideFrontierJudgeService($decide, 'gemini_cli'),
            new \App\Services\Ai\Foundry\Frontier\Ports\AtlasDecideFrontierJudgeService($decide, 'composer_cli'),
        ];

        return new \App\Services\Ai\Foundry\Frontier\Armor\FrontierJudgePanelGate($seats);
    }

    private function resolveGenerator(bool $useFixture): FrontierGeneratorPort
    {
        if ($useFixture) {
            // Authorized dev/test marker only — no canned proposals on the CLI
            // path (the fixture self-labels and the orchestrator gates it).
            return new DeterministicFixtureFrontierGeneratorService;
        }

        return app(AtlasDecideFrontierGeneratorService::class);
    }
}
