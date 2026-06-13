<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMultiRepoMergeAuthority;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * L5-9 — A PORTA GOVERNADA POR-REPO (campanhas multi-repo: o Atlas trabalha nos SEUS
 * projetos). Invariante pétreo: um repo ESTRANGEIRO (não o home repo) é NEVER-MERGE
 * default e fail-closed. O auto-merge só atravessa para um repo estrangeiro quando o
 * operador (a) ligou `multi_repo.enabled` E (b) listou o caminho absoluto canônico em
 * `allowed_repos`. O home repo continua governado SÓ por `auto_merge_to_main` — ligar
 * multi-repo nunca reabre a porta do home.
 *
 * Provas:
 *   1. authorize() classifica home vs foreign e aplica a porta certa a cada um.
 *   2. repo estrangeiro: multi-repo OFF ⇒ never-merge; ON mas fora da lista ⇒ never-merge;
 *      ON + na lista ⇒ autorizado (a única combinação que abre a porta).
 *   3. ligar multi-repo NÃO abre o home repo (que segue preso em auto_merge_to_main).
 *   4. END-TO-END: o drain() do auto-merge BLOQUEIA um repo estrangeiro não-autorizado
 *      (status=blocked, nada commitado, merged_to_main permanece false) e MERGEIA DE
 *      VERDADE o mesmo repo quando autorizado — a travessia governada por-repo real.
 *   5. caminho irresolúvel / vazio ⇒ fail-closed.
 */
final class AtlasLoopMultiRepoMergeAuthorityTest extends TestCase
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
        // Estado de partida limpo: porta multi-repo FECHADA por padrão.
        config(['atlas.ai.loop.multi_repo.enabled' => false]);
        config(['atlas.ai.loop.multi_repo.allowed_repos' => []]);
        config(['atlas.ai.loop.auto_merge_to_main' => true]);
        config(['atlas.ai.loop.impact_receipts_enabled' => true]);
    }

    protected function tearDown(): void
    {
        foreach ($this->dirs as $d) {
            (new Process(['rm', '-rf', $d]))->run();
        }
        parent::tearDown();
    }

    private function authority(): AtlasLoopMultiRepoMergeAuthority
    {
        return app(AtlasLoopMultiRepoMergeAuthority::class);
    }

    private function gitRepo(string $original): string
    {
        $d = sys_get_temp_dir().'/atlas-multirepo-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/snippet.php', $original);
        $this->git($d, ['init', '-q']);
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        return (string) (realpath($d) ?: $d);
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

    private function certifiedProposal(string $diff, string $hash): AtlasLoopProposal
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'multi-repo proof',
            'config' => [],
            'max_seconds' => 60,
        ]);
        AtlasLoopProposal::$governedMergeInProgress = false;

        return AtlasLoopProposal::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'fix val to 2',
            'target_path' => 'snippet.php',
            'diff_text' => $diff,
            'proposal_hash' => $hash,
            'metric' => null,
            'quality' => ['_acceptance_contract' => [
                'commands' => ["php -r \"require 'snippet.php'; exit(val()===2?0:1);\""],
                'allowed_globs' => ['snippet.php'],
                'frozen_globs' => ['composer.json'],
                'metric_kind' => 'gate',
            ]],
        ]);
    }

    private function makeDiff(string $repo, string $original, string $modified): string
    {
        file_put_contents($repo.'/snippet.php', $modified);
        $p = new Process(['git', 'diff'], $repo);
        $p->run();
        $diff = $p->getOutput();
        // restaura a árvore para o estado-base (o diff é aplicado pelo drain depois)
        file_put_contents($repo.'/snippet.php', $original);
        $this->git($repo, ['checkout', '--', 'snippet.php']);

        return $diff;
    }

    public function test_home_repo_is_governed_only_by_auto_merge_flag(): void
    {
        // base_path() é o home repo no contexto de teste.
        config(['atlas.ai.loop.auto_merge_to_main' => true]);
        $verdict = $this->authority()->authorize(base_path());
        $this->assertSame('home', $verdict['scope']);
        $this->assertTrue($verdict['allowed']);
        $this->assertSame('home_repo_auto_merge_enabled', $verdict['reason']);

        config(['atlas.ai.loop.auto_merge_to_main' => false]);
        $verdict = $this->authority()->authorize(base_path());
        $this->assertSame('home', $verdict['scope']);
        $this->assertFalse($verdict['allowed'], 'home repo never-merge quando o flag de home está OFF');
    }

    public function test_enabling_multi_repo_does_not_reopen_the_home_repo_door(): void
    {
        // Multi-repo ON e até o home repo listado: o home AINDA depende do flag de home.
        config(['atlas.ai.loop.multi_repo.enabled' => true]);
        config(['atlas.ai.loop.multi_repo.allowed_repos' => [base_path()]]);
        config(['atlas.ai.loop.auto_merge_to_main' => false]);

        $verdict = $this->authority()->authorize(base_path());
        $this->assertSame('home', $verdict['scope']);
        $this->assertFalse($verdict['allowed'], 'ligar multi-repo NUNCA reabre a porta do home repo');
    }

    public function test_foreign_repo_is_never_merge_by_default(): void
    {
        $repo = $this->gitRepo("<?php\nfunction val(){ return 1; }\n");

        // Default: multi-repo OFF.
        $verdict = $this->authority()->authorize($repo);
        $this->assertSame('foreign', $verdict['scope']);
        $this->assertFalse($verdict['allowed']);
        $this->assertSame('multi_repo_disabled', $verdict['reason']);
    }

    public function test_foreign_repo_enabled_but_not_listed_is_still_never_merge(): void
    {
        $repo = $this->gitRepo("<?php\nfunction val(){ return 1; }\n");
        config(['atlas.ai.loop.multi_repo.enabled' => true]);
        config(['atlas.ai.loop.multi_repo.allowed_repos' => []]); // ligado, mas vazio

        $verdict = $this->authority()->authorize($repo);
        $this->assertSame('foreign', $verdict['scope']);
        $this->assertFalse($verdict['allowed'], 'feature ON sem o repo na lista ⇒ never-merge (fail-closed)');
        $this->assertSame('foreign_repo_not_in_allowed_list', $verdict['reason']);
    }

    public function test_foreign_repo_enabled_and_listed_is_authorized(): void
    {
        $repo = $this->gitRepo("<?php\nfunction val(){ return 1; }\n");
        config(['atlas.ai.loop.multi_repo.enabled' => true]);
        config(['atlas.ai.loop.multi_repo.allowed_repos' => [$repo]]);

        $verdict = $this->authority()->authorize($repo);
        $this->assertSame('foreign', $verdict['scope']);
        $this->assertTrue($verdict['allowed'], 'a ÚNICA combinação que abre a porta estrangeira: ON + listado');
        $this->assertSame('foreign_repo_operator_authorized', $verdict['reason']);
    }

    public function test_unresolvable_repo_path_fails_closed(): void
    {
        $verdict = $this->authority()->authorize('/nope/this/path/does/not/exist-'.bin2hex(random_bytes(4)));
        $this->assertFalse($verdict['allowed']);
        $this->assertSame('repo_path_unresolvable', $verdict['reason']);

        $verdict = $this->authority()->authorize('');
        $this->assertFalse($verdict['allowed'], 'caminho vazio ⇒ fail-closed');
    }

    public function test_drain_blocks_unauthorized_foreign_repo_end_to_end(): void
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $modified = "<?php\nfunction val(){ return 2; }\n";
        $repo = $this->gitRepo($original);
        $proposal = $this->certifiedProposal($this->makeDiff($repo, $original, $modified), 'multirepo-blocked-1');
        $headBefore = $this->git($repo, ['rev-parse', 'HEAD']);

        // multi-repo OFF (default do setUp): repo estrangeiro NUNCA mergeia.
        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('foreign', $result['repo_authority']['scope']);
        $this->assertSame('multi_repo_disabled', $result['repo_authority']['reason']);
        $this->assertSame(0, $result['merged_count']);
        // Nada commitado na árvore real; a proposta NÃO foi marcada como mergeada.
        $this->assertSame($headBefore, $this->git($repo, ['rev-parse', 'HEAD']), 'nenhum commit em repo estrangeiro não-autorizado');
        $this->assertSame($original, file_get_contents($repo.'/snippet.php'), 'a árvore do repo estrangeiro fica intacta');
        $this->assertFalse((bool) $proposal->fresh()->merged_to_main);
    }

    public function test_drain_merges_authorized_foreign_repo_for_real(): void
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $modified = "<?php\nfunction val(){ return 2; }\n";
        $repo = $this->gitRepo($original);
        $proposal = $this->certifiedProposal($this->makeDiff($repo, $original, $modified), 'multirepo-merge-1');

        // Operador autoriza ESTE repo estrangeiro (o que faria com blackink/nivor).
        config(['atlas.ai.loop.multi_repo.enabled' => true]);
        config(['atlas.ai.loop.multi_repo.allowed_repos' => [$repo]]);

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame(1, $result['merged_count'], json_encode($result['results']));
        // O merge REAL atravessou a porta estrangeira: commit + árvore mudada + marcação.
        $this->assertSame($modified, file_get_contents($repo.'/snippet.php'));
        $this->assertStringContainsString('atlas loop auto-merge', $this->git($repo, ['log', '-1', '--pretty=%s']));
        $this->assertTrue((bool) $proposal->fresh()->merged_to_main, 'a porta governada por-repo permite o merge real do repo autorizado');
    }

    public function test_operator_approval_cannot_override_an_unregistered_foreign_repo(): void
    {
        // Mesmo com aprovação humana explícita de UMA proposta, um repo estrangeiro
        // NÃO-REGISTRADO permanece never-merge — a aprovação de uma proposta não vira
        // autorização estrutural do repo (o invariante por-repo do L5-9).
        $repo = $this->gitRepo("<?php\nfunction val(){ return 1; }\n");
        $proposal = $this->certifiedProposal('diff --git a/x b/x', 'multirepo-op-override-1');
        // multi-repo OFF (default): repo estrangeiro não-registrado.

        $result = app(AtlasLoopAutoMergeService::class)->mergeOperatorApproved($proposal, $repo, [
            'operator_id' => 'vitor',
            'approved' => true,
            'reason' => 'tentativa de override sem registrar o repo',
        ]);

        $this->assertSame('blocked', $result['status']);
        $this->assertStringContainsString('foreign_repo_not_registered', (string) $result['reason']);
        $this->assertSame('foreign', $result['repo_authority']['scope']);
        $this->assertFalse((bool) $proposal->fresh()->merged_to_main, 'override do operador não fura a porta por-repo de um repo não-registrado');
    }
}
