<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * OBRA #4 S0 — a CANCELA: o único caminho de merge 100% autônomo (o drain do auto-merge) consulta o
 * AcceptanceGate soberano do Engineering Kernel ANTES do commit. Prova os ACs congelados da spec
 * (docs/obra4-self-hardening-harness-spec-2026-07-05.md):
 *   AC-0.1 proposta alegando pass com tests_run=0 é REFUSADA no drain (false_claim_blocked)
 *   AC-0.2 proposta com evidência soberana VERDE é PROMOVIDA e commitada como no fluxo atual
 *   AC-0.3 um REFUSE não derruba o drain (a próxima proposta segue)
 *   AC-0.4 topologia git intacta: main única, commit direto, zero branch/worktree novo
 * + comportamento honesto para proposta legada sem evidência threaded: parqueia com invariantes NOMEADOS.
 */
final class AtlasLoopAutoMergeSovereignGateTest extends TestCase
{
    /** @var list<string> */
    private array $dirs = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (! Schema::hasTable('atlas_loop_campaigns')) {
            foreach ([
                '2026_06_02_000100_create_atlas_loop_runtime_tables.php',
                '2026_06_02_000200_complete_atlas_loop_runtime_schema.php',
                '2026_06_11_000100_add_quality_columns_to_atlas_loop_tables.php',
            ] as $file) {
                (require base_path('database/migrations/'.$file))->up();
            }
        }
        config(['atlas.ai.loop.auto_merge_to_main' => true]);
        config(['atlas.ai.loop.impact_receipts_enabled' => true]);
        config(['atlas.ai.loop.value_gate_enabled' => false]);
        config(['atlas.ai.loop.substance_floor_enabled' => false]);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    public function test_ac01_claimed_pass_with_zero_tests_run_is_refused(): void
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $diff = $this->makeDiff($original, "<?php\nfunction val(){ return 2; }\n");
        $repo = $this->repo($original);

        // Evidência threaded MENTIROSA: alega pass com zero testes executados. Judges e contexto são
        // verdes de propósito — o ÚNICO invariante que deve derrubar é o false_claim_blocked.
        $proposal = $this->certifiedProposal($diff, 'sov-ac01-1', sovereignEvidence: [
            'execution' => [
                'commands' => ['./vendor/bin/phpunit tests/Unit/SnippetTest.php'],
                'claimed_status' => 'passed',
                'tests_run' => 0,
                'assertions_executed' => 0,
                'selected_tests' => ['tests/Unit/SnippetTest.php'],
                'artifacts' => [],
            ],
            'judges' => $this->greenJudges(),
            'context_sufficiency' => 90,
        ]);

        $headBefore = $this->git($repo, ['rev-parse', 'HEAD']);
        $result = app(AtlasLoopAutoMergeService::class)->drain($repo);

        $one = $result['results'][0] ?? [];
        $this->assertFalse((bool) ($one['merged'] ?? true), json_encode($one));
        $this->assertStringContainsString('sovereign_gate_refused', (string) ($one['reason'] ?? ''));
        $this->assertStringContainsString('false_claim_blocked', (string) ($one['reason'] ?? ''));

        $fresh = $proposal->fresh();
        $this->assertFalse((bool) $fresh->merged_to_main, 'REFUSE nunca marca merged_to_main');
        $this->assertNotNull($fresh->reviewed_at, 'REFUSE parqueia para revisão do operador');
        $this->assertSame('fail', (string) data_get($fresh->quality, '_sovereign_gate.invariants.false_claim_blocked.status'));

        $this->assertSame($headBefore, $this->git($repo, ['rev-parse', 'HEAD']), 'main byte-idêntica');
        $this->assertSame('', $this->git($repo, ['status', '--porcelain']), 'apply desfeito: working tree limpa');
    }

    public function test_ac02_green_sovereign_evidence_promotes_and_merges(): void
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $diff = $this->makeDiff($original, "<?php\nfunction val(){ return 2; }\n");
        $repo = $this->repo($original);

        $proposal = $this->certifiedProposal($diff, 'sov-ac02-1', sovereignEvidence: $this->greenEvidence());

        $headBefore = $this->git($repo, ['rev-parse', 'HEAD']);
        $result = app(AtlasLoopAutoMergeService::class)->drain($repo);

        $one = $result['results'][0] ?? [];
        $this->assertTrue((bool) ($one['merged'] ?? false), json_encode($one));

        $fresh = $proposal->fresh();
        $this->assertTrue((bool) $fresh->merged_to_main);
        $this->assertSame('promote', (string) data_get($fresh->quality, '_sovereign_gate.status'));
        $this->assertNotSame('', (string) data_get($fresh->quality, '_sovereign_gate.receipt_ref'), 'recibo soberano selado');

        $headAfter = $this->git($repo, ['rev-parse', 'HEAD']);
        $this->assertNotSame($headBefore, $headAfter, 'commit real landou');
        $this->assertSame($headBefore, $this->git($repo, ['rev-parse', 'HEAD~1']), 'exatamente 1 commit à frente (fluxo atual)');
    }

    public function test_ac03_refusal_does_not_abort_drain(): void
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $diff = $this->makeDiff($original, "<?php\nfunction val(){ return 2; }\n");
        $repo = $this->repo($original);

        $lying = $this->certifiedProposal($diff, 'sov-ac03-lie', sovereignEvidence: [
            'execution' => [
                'commands' => ['./vendor/bin/phpunit tests/Unit/SnippetTest.php'],
                'claimed_status' => 'passed',
                'tests_run' => 0,
                'assertions_executed' => 0,
                'selected_tests' => ['tests/Unit/SnippetTest.php'],
                'artifacts' => [],
            ],
            'judges' => $this->greenJudges(),
            'context_sufficiency' => 90,
        ]);
        $lying->forceFill(['created_at' => now()->subMinutes(2)])->save();

        $green = $this->certifiedProposal($diff, 'sov-ac03-green', sovereignEvidence: $this->greenEvidence());
        $green->forceFill(['created_at' => now()->subMinute()])->save();

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo);

        $this->assertCount(2, $result['results']);
        $this->assertStringContainsString('sovereign_gate_refused', (string) ($result['results'][0]['reason'] ?? ''));
        $this->assertTrue((bool) ($result['results'][1]['merged'] ?? false), 'o drain seguiu após o REFUSE');
        $this->assertSame(1, (int) $result['merged_count']);
    }

    public function test_ac04_topology_intact_no_new_branches_or_worktrees(): void
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $diff = $this->makeDiff($original, "<?php\nfunction val(){ return 2; }\n");
        $repo = $this->repo($original);

        $this->certifiedProposal($diff, 'sov-ac04-lie', sovereignEvidence: [
            'execution' => ['claimed_status' => 'passed', 'tests_run' => 0, 'assertions_executed' => 0],
            'judges' => $this->greenJudges(),
            'context_sufficiency' => 90,
        ])->forceFill(['created_at' => now()->subMinutes(2)])->save();
        $this->certifiedProposal($diff, 'sov-ac04-green', sovereignEvidence: $this->greenEvidence())
            ->forceFill(['created_at' => now()->subMinute()])->save();

        $branchesBefore = $this->git($repo, ['branch', '--list']);
        $worktreesBefore = substr_count($this->git($repo, ['worktree', 'list']), "\n");

        app(AtlasLoopAutoMergeService::class)->drain($repo);

        $this->assertSame($branchesBefore, $this->git($repo, ['branch', '--list']), 'zero branch novo (refuse E promote)');
        $this->assertSame($worktreesBefore, substr_count($this->git($repo, ['worktree', 'list']), "\n"), 'zero worktree novo no repo alvo');
    }

    public function test_unthreaded_legacy_proposal_parks_with_named_invariants(): void
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $diff = $this->makeDiff($original, "<?php\nfunction val(){ return 2; }\n");
        $repo = $this->repo($original);

        // Sem _sovereign_evidence: o fallback derivado atesta o reprove (1 command real) + canário
        // (sem irmão ⇒ não roda). Judges e contexto AUSENTES ⇒ o piso recusa com invariantes NOMEADOS
        // — comportamento honesto para proposta legada até o grinder tredar evidência.
        $proposal = $this->certifiedProposal($diff, 'sov-legacy-1', sovereignEvidence: null);

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo);

        $one = $result['results'][0] ?? [];
        $reason = (string) ($one['reason'] ?? '');
        $this->assertStringContainsString('sovereign_gate_refused', $reason, json_encode($one));
        $this->assertStringContainsString('context_sufficiency', $reason);
        $this->assertStringContainsString('judge_diversity', $reason);
        $this->assertStringNotContainsString('false_claim_blocked', $reason, 'o reprove real conta como execução — não é fake-green');

        $this->assertSame('', $this->git($repo, ['status', '--porcelain']), 'apply desfeito');
        $this->assertNotNull($proposal->fresh()->reviewed_at, 'parqueada, não descartada');
    }

    /**
     * @return list<array{name:string,provider_family:string,approved:bool}>
     */
    private function greenJudges(): array
    {
        return [
            ['name' => 'frozen_judge', 'provider_family' => 'atlas_deterministic', 'approved' => true],
            ['name' => 'adversarial_certifier', 'provider_family' => 'codex', 'approved' => true],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function greenEvidence(): array
    {
        return [
            'execution' => [
                'commands' => ['./vendor/bin/phpunit tests/Unit/SnippetTest.php'],
                'claimed_status' => 'passed',
                'tests_run' => 3,
                'assertions_executed' => 7,
                'selected_tests' => ['tests/Unit/SnippetTest.php'],
                'artifacts' => [],
            ],
            'judges' => $this->greenJudges(),
            'context_sufficiency' => 90,
        ];
    }

    private function repo(string $original): string
    {
        $d = sys_get_temp_dir().'/atlas-sovgate-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/snippet.php', $original);
        $this->gitOk($d, ['init', '-q']);
        $this->gitOk($d, ['add', '-A']);
        $this->gitOk($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        config(['atlas.ai.loop.multi_repo.enabled' => true]);
        $allowed = (array) config('atlas.ai.loop.multi_repo.allowed_repos', []);
        $allowed[] = realpath($d) ?: $d;
        config(['atlas.ai.loop.multi_repo.allowed_repos' => array_values(array_unique($allowed))]);

        return $d;
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $cwd, array $argv): string
    {
        $p = new Process(array_merge(['git'], $argv), $cwd);
        $p->run();

        return trim($p->getOutput());
    }

    /**
     * @param  list<string>  $argv
     */
    private function gitOk(string $cwd, array $argv): void
    {
        $p = new Process(array_merge(['git'], $argv), $cwd);
        $p->run();
        if (! $p->isSuccessful()) {
            $this->fail('git '.implode(' ', $argv).' failed: '.$p->getErrorOutput());
        }
    }

    private function makeDiff(string $original, string $modified): string
    {
        $repo = $this->repo($original);
        file_put_contents($repo.'/snippet.php', $modified);
        $p = new Process(['git', 'diff'], $repo);
        $p->run();

        return $p->getOutput();
    }

    /**
     * @param  array<string,mixed>|null  $sovereignEvidence
     */
    private function certifiedProposal(string $diff, string $hash, ?array $sovereignEvidence): AtlasLoopProposal
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'sovereign gate proof',
            'config' => [],
            'max_seconds' => 60,
        ]);
        AtlasLoopProposal::$governedMergeInProgress = false;

        $quality = [
            '_acceptance_contract' => [
                'commands' => ["php -r \"require 'snippet.php'; exit(val()===2?0:1);\""],
                'allowed_globs' => ['snippet.php'],
                'frozen_globs' => ['composer.json'],
                'metric_kind' => 'gate',
            ],
        ];
        if ($sovereignEvidence !== null) {
            $quality['_sovereign_evidence'] = $sovereignEvidence;
        }

        return AtlasLoopProposal::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'fix val to 2',
            'target_path' => 'snippet.php',
            'diff_text' => $diff,
            'proposal_hash' => $hash,
            'metric' => null,
            'quality' => $quality,
        ]);
    }
}
