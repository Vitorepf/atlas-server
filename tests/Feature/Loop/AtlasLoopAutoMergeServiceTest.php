<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\AtlasLoopAutoMergeService;
use App\Services\Ai\AutonomousEvolution\Contracts\BroaderRegressionGateContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Merge-livre v2 (decisão do operador): a travessia REAL. Uma proposta certificada cujo
 * contrato re-prova GREEN é mergeada EM MAIN (commit de verdade, merged_to_main=true);
 * uma proposta stale (diff não aplica mais) é aposentada honestamente, nunca mergeada;
 * fora do escopo governado, nada consegue marcar merged_to_main.
 */
final class AtlasLoopAutoMergeServiceTest extends TestCase
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
        // These exercise the drain MECHANICS (reprove / apply / lint / boot / canary / net-direction /
        // patch-scoping / trust-ladder) on minimal fixtures — NOT the value/substance gate, which is a
        // separate concern proven in test_substance_floor_*. The value-gate is default-ON for the live
        // drain (it exempts refactors), so disable it here so a 2-line vanilla fixture is not blocked.
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

    private function repo(string $original): string
    {
        $d = sys_get_temp_dir().'/atlas-automerge-'.bin2hex(random_bytes(4));
        mkdir($d, 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/snippet.php', $original);
        $this->git($d, ['init', '-q']);
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        // L5-9: estes repos temporários são ESTRANGEIROS por caminho (não são o home
        // repo do atlas-server). A travessia do merge agora passa pela PORTA GOVERNADA
        // POR-REPO: para os testes do pipeline de merge exercitarem o caminho feliz,
        // autoriza explicitamente cada repo temp na allow-list multi-repo (exatamente o
        // que o operador faria ao registrar um repo real como blackink/nivor). O default
        // never-merge para repos não-autorizados é provado em test_foreign_repo_*.
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

    private function makeDiff(string $original, string $modified): string
    {
        $repo = $this->repo($original);
        file_put_contents($repo.'/snippet.php', $modified);
        $p = new Process(['git', 'diff'], $repo);
        $p->run();

        return $p->getOutput();
    }

    public function test_substance_floor_blocks_low_value_vanilla_but_exempts_refactor(): void
    {
        // ~16-line change: clears the hard floor (>=10) but is UNDER the vanilla floor (30). So a
        // VANILLA contract is blocked (low value) while a REFACTOR contract is EXEMPT from the size
        // arm (its AST complexity drop is already cert-gated) — same size, opposite outcome by kind.
        $original = "<?php\nfunction val(){ return 1; }\n";
        $body = implode("\n", array_map(static fn (int $i): string => '    $x'.$i.' = '.$i.';', range(1, 15)));
        $modified = "<?php\nfunction val(){\n".$body."\n    return 2;\n}\n";
        $diff = $this->makeDiff($original, $modified);

        $svc = app(AtlasLoopAutoMergeService::class);
        $m = new \ReflectionMethod($svc, 'valueGateVerdict');
        $m->setAccessible(true);

        // VANILLA (metric_kind=gate, no complexity_proof) + flag ON => blocked by the substance floor.
        config(['atlas.ai.loop.substance_floor_enabled' => true]);
        $vanilla = $this->certifiedProposal($diff, 'sf-vanilla-1');
        $vVerdict = $m->invoke($svc, $vanilla, ['snippet.php'], 5);
        $this->assertFalse($vVerdict['passed'], json_encode($vVerdict));
        $this->assertStringContainsString('substance_floor', $vVerdict['reason']);
        $this->assertFalse((bool) ($vVerdict['substance_floor']['is_refactor'] ?? null));

        // REFACTOR contract (complexity_proof=true + metric_kind=minimize) => EXEMPT from the size arm
        // (its AST complexity drop is already cert-gated), so the same tiny diff is NOT blocked here.
        $refactor = $this->refactorContractProposal($diff, 'sf-refactor-1');
        $rVerdict = $m->invoke($svc, $refactor, ['snippet.php'], 5);
        $this->assertTrue((bool) ($rVerdict['substance_floor']['is_refactor'] ?? null));
        $this->assertStringNotContainsString('substance_floor', $rVerdict['reason'], 'refactor contracts are exempt from the substance floor');

        // FLAG OFF (default) => the substance floor never runs (byte-identical to legacy).
        config(['atlas.ai.loop.substance_floor_enabled' => false]);
        $off = $m->invoke($svc, $this->certifiedProposal($diff, 'sf-off-1'), ['snippet.php'], 5);
        $this->assertFalse((bool) ($off['substance_floor']['enabled'] ?? false));
        $this->assertStringNotContainsString('substance_floor', $off['reason']);
    }

    public function test_anti_farm_floor_blocks_cosmetic_but_clears_load_bearing(): void
    {
        // §3 ANTI-FARM FLOOR: a certified diff must BITE (revert→red) to auto-merge. A cosmetic flip (no
        // bite-proof in the frozen contract) is blocked at the merge boundary; a load-bearing one (diff_earned
        // =true, the certifier's universal bite-proof) clears it. Default-OFF ⇒ the floor never runs.
        $original = "<?php\nfunction val(){ return 1; }\n";
        $body = implode("\n", array_map(static fn (int $i): string => '    $x'.$i.' = '.$i.';', range(1, 15)));
        $modified = "<?php\nfunction val(){\n".$body."\n    return 2;\n}\n";
        $diff = $this->makeDiff($original, $modified);

        $svc = app(AtlasLoopAutoMergeService::class);
        $m = new \ReflectionMethod($svc, 'valueGateVerdict');
        $m->setAccessible(true);

        // COSMETIC (no bite-proof stamped) + flag ON ⇒ blocked by the anti-farm floor.
        config(['atlas.loop.anti_farm_floor_enabled' => true]);
        $cosmetic = $this->certifiedProposal($diff, 'af-cosmetic-1');
        $cVerdict = $m->invoke($svc, $cosmetic, ['snippet.php'], 5);
        $this->assertFalse($cVerdict['passed'], json_encode($cVerdict));
        $this->assertStringContainsString('anti_farm_floor', $cVerdict['reason']);
        $this->assertFalse((bool) ($cVerdict['anti_farm_floor']['bites'] ?? true), 'a cosmetic flip bites nothing');

        // LOAD-BEARING (diff_earned=true: revert→red) ⇒ the floor clears it.
        $earned = $this->earnedProposal($diff, 'af-earned-1');
        $eVerdict = $m->invoke($svc, $earned, ['snippet.php'], 5);
        $this->assertTrue((bool) ($eVerdict['anti_farm_floor']['bites'] ?? false), 'a real fix bites');
        $this->assertStringNotContainsString('anti_farm_floor', $eVerdict['reason']);

        // FLAG OFF (default) ⇒ the floor never runs (byte-identical to legacy).
        config(['atlas.loop.anti_farm_floor_enabled' => false]);
        $off = $m->invoke($svc, $this->certifiedProposal($diff, 'af-off-1'), ['snippet.php'], 5);
        $this->assertFalse((bool) ($off['anti_farm_floor']['enabled'] ?? false));
        $this->assertStringNotContainsString('anti_farm_floor', $off['reason']);
    }

    private function earnedProposal(string $diff, string $hash): AtlasLoopProposal
    {
        $proposal = $this->certifiedProposal($diff, $hash);
        $quality = $proposal->quality;
        $quality['_acceptance_contract']['diff_earned'] = true; // the certifier's universal bite-proof (revert→red)
        $proposal->quality = $quality;
        $proposal->save();

        return $proposal->fresh();
    }

    private function refactorContractProposal(string $diff, string $hash): AtlasLoopProposal
    {
        $proposal = $this->certifiedProposal($diff, $hash);
        $quality = $proposal->quality;
        $quality['_acceptance_contract']['complexity_proof'] = true;
        $quality['_acceptance_contract']['metric_kind'] = 'minimize';
        $proposal->quality = $quality;
        $proposal->save();

        return $proposal->fresh();
    }

    /**
     * @param  list<string>|null  $allowedGlobs
     * @param  list<string>|null  $commands
     */
    private function certifiedProposal(string $diff, string $hash, ?array $allowedGlobs = null, ?array $commands = null): AtlasLoopProposal
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'automerge proof',
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
                'commands' => $commands ?? ["php -r \"require 'snippet.php'; exit(val()===2?0:1);\""],
                'allowed_globs' => $allowedGlobs ?? ['snippet.php'],
                'frozen_globs' => ['composer.json'],
                'metric_kind' => 'gate',
            ]],
        ]);
    }

    private function markSelfImprovement(AtlasLoopProposal $p): void
    {
        $q = is_array($p->quality) ? $p->quality : [];
        $q['_is_self_improvement'] = true;
        $p->forceFill(['quality' => $q])->save();
    }

    public function test_self_improvement_parks_when_flag_off(): void
    {
        // Legacy safe default: a self-edit (the loop improving its own harness) parks for operator review.
        $original = "<?php\nfunction val(){ return 1; }\n";
        $repo = $this->repo($original);
        $p = $this->certifiedProposal($this->makeDiff($original, "<?php\nfunction val(){ return 2; }\n"), 'selfimp-off-1');
        $this->markSelfImprovement($p);
        config(['atlas.loop.self_improvement_auto_merge_enabled' => false]);

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame(0, $result['merged_count'], json_encode($result['results']));
        $this->assertFalse((bool) $p->fresh()->merged_to_main, 'self-improvement must NOT merge while the flag is off');
    }

    public function test_self_improvement_auto_merges_when_flag_on(): void
    {
        // OPERATOR DIRECTIVE: a legitimate certified self-improvement lands on main AUTONOMOUSLY when the flag
        // is ON — the cert + canary + reprove gates are the legitimacy proof; no human reviewer required.
        $original = "<?php\nfunction val(){ return 1; }\n";
        $repo = $this->repo($original);
        $p = $this->certifiedProposal($this->makeDiff($original, "<?php\nfunction val(){ return 2; }\n"), 'selfimp-on-1');
        $this->markSelfImprovement($p);
        config(['atlas.loop.self_improvement_auto_merge_enabled' => true]);

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame(1, $result['merged_count'], json_encode($result['results']));
        $this->assertTrue((bool) $p->fresh()->merged_to_main, 'a certified self-improvement auto-merges with the flag on');
        $this->assertStringContainsString('atlas loop auto-merge', $this->git($repo, ['log', '-1', '--pretty=%s']));
    }

    /**
     * Build a multi-file patch that MODIFIES snippet.php AND CREATES a new sibling file. `git add -N`
     * marks the new file intent-to-add so `git diff` emits a proper `new file` hunk for it.
     */
    private function makeRefactorDiff(string $snippetModified, string $newFile, string $newContent): string
    {
        $repo = $this->repo("<?php\nfunction val(){ return 2; }\n");
        file_put_contents($repo.'/snippet.php', $snippetModified);
        file_put_contents($repo.'/'.$newFile, $newContent);
        $this->git($repo, ['add', '-N', $newFile]);
        $p = new Process(['git', 'diff'], $repo);
        $p->run();

        return $p->getOutput();
    }

    public function test_extracted_new_sibling_file_is_committed_not_left_untracked(): void
    {
        // REGRESSION (real main corruption observed 2026-06-17): an EXTRACT-class refactor creates a NEW
        // sibling file that `git diff --name-only` never lists. The pre-fix drain `git add`ed only the
        // tracked target, so main landed a commit that REFERENCES an UNCOMMITTED class — green on the dirty
        // work tree (false canary), RED on a fresh checkout (`Class ... not found`). The fix teaches
        // changedPhpFiles to also surface untracked files; scopeToPatch still keeps only THIS patch's files.
        $modified = "<?php\nrequire __DIR__.'/support.php';\nfunction val(){ return sup(); }\n";
        $newContent = "<?php\nfunction sup(){ return 2; }\n";
        $repo = $this->repo("<?php\nfunction val(){ return 2; }\n");
        $diff = $this->makeRefactorDiff($modified, 'support.php', $newContent);

        $proposal = $this->certifiedProposal(
            $diff,
            'newfile-extract-1',
            ['snippet.php', 'support.php'],
            ["php -r \"require 'snippet.php'; exit(val()===2?0:1);\""],
        );

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame(1, $result['merged_count'], json_encode($result['results']));
        $this->assertTrue((bool) $proposal->fresh()->merged_to_main);
        // The extracted sibling is COMMITTED (tracked), not merely present on a dirty work tree.
        $headTree = $this->git($repo, ['ls-tree', '-r', '--name-only', 'HEAD']);
        $this->assertStringContainsString('support.php', $headTree, 'extracted sibling MUST be in the commit, not left untracked');
        $this->assertStringContainsString('snippet.php', $headTree);
        // Decisive proof: a pristine checkout of HEAD into a clean dir is self-consistent (green).
        $checkout = sys_get_temp_dir().'/atlas-automerge-co-'.bin2hex(random_bytes(4));
        $this->dirs[] = $checkout;
        $this->git($repo, ['worktree', 'add', '--detach', $checkout, 'HEAD']);
        $probe = new Process(['php', '-r', "require 'snippet.php'; exit(val()===2?0:1);"], $checkout);
        $probe->run();
        $this->assertSame(0, $probe->getExitCode(), 'fresh checkout of HEAD must be GREEN — no dangling uncommitted dependency');
    }

    public function test_fix_forward_routes_to_live_supervisor_not_dead_originating_campaign(): void
    {
        // A canary-red regression is global (it sits in main), but the task queue is campaign-scoped
        // and the proposal's ORIGINATING campaign is COMPLETED by merge time, so a fix-forward queued
        // there is unclaimable and main stays RED (observed: 12/14 fix-forwards orphaned; one sat ~107min).
        config(['atlas.ai.loop.fix_forward_live_campaign_freshness_seconds' => 1800]);

        $dead = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => AtlasLoopCampaign::STATUS_COMPLETED,
            'goal' => 'dead originating', 'config' => [], 'max_seconds' => 60,
            'kill_switch' => false, 'heartbeat_at' => now()->subSeconds(30),
        ]);
        // SIGTERM'd zombie: status still 'running' but kill_switch=true + stale heartbeat => excluded.
        AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'zombie', 'config' => [], 'max_seconds' => 60,
            'kill_switch' => true, 'heartbeat_at' => now()->subHours(5),
        ]);
        $live = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1', 'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'live supervisor', 'config' => [], 'max_seconds' => 60,
            'kill_switch' => false, 'heartbeat_at' => now()->subSeconds(60),
        ]);

        $svc = app(AtlasLoopAutoMergeService::class);
        $m = new \ReflectionMethod($svc, 'resolveFixForwardCampaignId');
        $m->setAccessible(true);

        // Routes to the LIVE campaign — not the dead originating one, not the zombie.
        $this->assertSame((string) $live->id, (string) $m->invoke($svc, (string) $dead->id));

        // No live supervisor => best-effort fall back to the originating id (never DROP the fix-forward).
        $live->forceFill(['kill_switch' => true])->save();
        $this->assertSame((string) $dead->id, (string) $m->invoke($svc, (string) $dead->id));
    }

    public function test_certified_reproven_proposal_merges_to_main_for_real(): void
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $modified = "<?php\nfunction val(){ return 2; }\n";
        $repo = $this->repo($original);
        $proposal = $this->certifiedProposal($this->makeDiff($original, $modified), 'automerge-pass-1');

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame(1, $result['merged_count'], json_encode($result['results']));
        // O arquivo na ÁRVORE REAL mudou e o commit existe em main.
        $this->assertSame($modified, file_get_contents($repo.'/snippet.php'));
        $this->assertStringContainsString('atlas loop auto-merge', $this->git($repo, ['log', '-1', '--pretty=%s']));
        // A proposta está marcada como mergeada (via escopo governado).
        $this->assertTrue((bool) $proposal->fresh()->merged_to_main);
        $impact = $proposal->fresh()->quality['_impact_receipt'] ?? null;
        $this->assertIsArray($impact, 'todo merge novo carrega impact receipt');
        $this->assertSame('atlas.loop.impact_receipt.v1', $impact['schema_version']);
        $this->assertSame('bug', $impact['category']);
        $this->assertSame('real', $impact['target_kind']);
        $this->assertSame('real', $impact['real_vs_generated']);
        $this->assertSame(1, $impact['size']['files_changed']);
        $this->assertSame(1, $impact['size']['added_lines']);
        $this->assertSame(1, $impact['size']['deleted_lines']);
        $this->assertGreaterThan(0.0, $impact['impact_score']);
        $this->assertSame($result['results'][0]['commit'], $impact['commit']);
        $this->assertSame($impact['category'], $result['results'][0]['impact_receipt']['category']);

        // L2-5: a âncora de snapshot pré-merge existe e aponta para o estado ANTES do
        // merge (fix-forward barato: restaurar = checkout da tag).
        $snapTag = (string) ($result['results'][0]['snapshot_tag'] ?? '');
        $this->assertNotSame('', $snapTag, 'merge deve carregar snapshot_tag');
        $tagSha = $this->git($repo, ['rev-parse', $snapTag]);
        $headParent = $this->git($repo, ['rev-parse', 'HEAD~1']);
        $this->assertSame($headParent, $tagSha, 'a tag de snapshot aponta para o estado pré-merge');
    }

    /**
     * HONEST ATTRIBUTION: the real git commit (block 5) lands in main BEFORE the row is stamped
     * merged_to_main=true in the attribution governedSave. If that save throws (a transient DB blip),
     * main holds the commit while the row stays merged_to_main=false / reviewed_at=NULL — a permanent
     * attribution lie (no later drain pass ever flips the flag; a stale re-apply only RETIRES the row).
     * The catch must RECONCILE the row to match main's actual state, through the governed scope only
     * (so the pétreo never-merge default is untouched — the mere success of the flip proves it).
     */
    public function test_governed_save_failure_after_commit_reconciles_attribution_to_match_main(): void
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $modified = "<?php\nfunction val(){ return 2; }\n";
        $repo = $this->repo($original);
        $proposal = $this->certifiedProposal($this->makeDiff($original, $modified), 'reconcile-attr-1');

        // One-shot fault injection on an ISOLATED dispatcher (cloned, restored in finally so the
        // throwing listener can never leak into a sibling test). The FIRST save that flips
        // merged_to_main=true is the post-commit attribution write — throw there exactly as a transient
        // DB error would, then let the catch's reconcile save (the second such flip) succeed.
        $dispatcher = AtlasLoopProposal::getEventDispatcher();
        AtlasLoopProposal::setEventDispatcher(clone $dispatcher);
        $faulted = false;
        AtlasLoopProposal::saving(function (AtlasLoopProposal $p) use (&$faulted): void {
            if ($p->merged_to_main === true && ! $faulted) {
                $faulted = true;
                throw new \RuntimeException('simulated transient DB blip during attribution save');
            }
        });

        try {
            $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);
        } finally {
            AtlasLoopProposal::setEventDispatcher($dispatcher);
        }

        // The post-commit attribution save DID throw, so the bug window was genuinely exercised...
        $this->assertTrue($faulted, 'the test must actually exercise a post-commit attribution save failure');

        // ...yet main durably holds the merge commit AND the row's attribution now MATCHES main.
        $headSha = $this->git($repo, ['rev-parse', 'HEAD']);
        $this->assertStringContainsString('atlas loop auto-merge', $this->git($repo, ['log', '-1', '--pretty=%s']), 'the commit IS in main');
        $this->assertSame($modified, file_get_contents($repo.'/snippet.php'), 'the working tree holds the merged change');

        $fresh = $proposal->fresh();
        $this->assertTrue((bool) $fresh->merged_to_main, 'row reconciled to merged — no false-negative attribution lie while main holds the commit');
        $this->assertNotNull($fresh->reviewed_at, 'reconciled row leaves the drain queue (reviewed_at set)');
        $this->assertSame($headSha, $fresh->quality['_attribution_reconciled']['commit'] ?? null, 'recorded commit matches main HEAD');

        // The drain reports the merge honestly (reconciled), not a swallowed error.
        $r = $result['results'][0];
        $this->assertTrue((bool) $r['merged'], 'reconciled merge is reported as merged: '.json_encode($r));
        $this->assertTrue((bool) ($r['attribution_reconciled'] ?? false));
        $this->assertSame($headSha, $r['commit']);
        $this->assertSame(1, $result['merged_count']);

        // PÉTREO INVARIANT INTACT: the model guard forces merged_to_main=false on every save OUTSIDE the
        // governed scope (proven independently in test_nothing_outside_the_governed_scope_can_mark_merged),
        // so the reconcile could only have flipped the flag to true THROUGH governedSave — the successful
        // flip asserted above is itself the proof — and the scope is closed again afterwards (try/finally).
        $this->assertFalse(AtlasLoopProposal::$governedMergeInProgress, 'the governed scope must be closed after the reconcile');
    }

    public function test_merge_scopes_to_the_proposal_patch_not_foreign_dirty_work(): void
    {
        // REGRESSION (HIGH false-accept): the drain built $changed from a whole-working-tree `git
        // diff`, so files the concurrently-grinding supervisor left dirty in base_path rode into the
        // merge commit via `git add -- $changed` AND mis-aimed the canary at an unrelated sibling
        // (measured 22.4% of merges canaried the WRONG test). The merge must scope to the proposal's
        // OWN patch — foreign dirty work is neither canaried nor committed under the proposal hash.
        $original = "<?php\nfunction val(){ return 1; }\n";
        $modified = "<?php\nfunction val(){ return 2; }\n";
        $repo = $this->repo($original);
        // A tracked file the supervisor is concurrently editing — DIRTY in the same tree.
        file_put_contents($repo.'/foreign.php', "<?php\n// v1\n");
        $this->git($repo, ['add', 'foreign.php']);
        $this->git($repo, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'add foreign', '--no-gpg-sign']);
        file_put_contents($repo.'/foreign.php', "<?php\n// v2 UNRELATED concurrent loop work\n");

        $proposal = $this->certifiedProposal($this->makeDiff($original, $modified), 'scope-1');
        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame(1, $result['merged_count'], json_encode($result['results']));
        // The merge commit contains ONLY snippet.php — foreign dirty work did NOT ride in.
        $committed = $this->git($repo, ['show', '--name-only', '--pretty=format:', 'HEAD']);
        $this->assertStringContainsString('snippet.php', $committed);
        $this->assertStringNotContainsString('foreign.php', $committed, 'foreign dirty work must NOT be committed under the proposal hash');
        // foreign.php remains present and still dirty (the scoped merge never touched it).
        $this->assertFileExists($repo.'/foreign.php');
        $this->assertStringContainsString('foreign.php', $this->git($repo, ['status', '--porcelain']));
    }

    public function test_stale_proposal_is_retired_never_merged(): void
    {
        // O diff foi gerado contra um conteúdo que NÃO é o da árvore atual → não aplica.
        $repo = $this->repo("<?php\nfunction val(){ return 99; }\n");
        $proposal = $this->certifiedProposal(
            $this->makeDiff("<?php\nfunction val(){ return 1; }\n", "<?php\nfunction val(){ return 2; }\n"),
            'automerge-stale-1',
        );

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame(0, $result['merged_count']);
        $fresh = $proposal->fresh();
        $this->assertFalse((bool) $fresh->merged_to_main, 'stale nunca mergeia');
        $this->assertNotNull($fresh->reviewed_at, 'stale é aposentada para não bloquear a fila');
        // ATTRIBUTABLE RETIRE: the reason is now PERSISTED on the row (no longer a bare reviewed_at
        // black hole) and marks this as a RECOVERABLE base-drift casualty (contract present), not a
        // genuinely dead one — so the funnel can split stale into recoverable vs irreprovable.
        $review = $fresh->quality['_operator_review'] ?? null;
        $this->assertIsArray($review, 'retire deve persistir um motivo auditável');
        $this->assertSame('retired_stale_diff', $review['status']);
        $this->assertSame('retire', $review['decision'], 'retire ≠ park — não polui a fila de operator-review');
        $this->assertTrue((bool) $review['had_acceptance_contract'], 'tinha contrato → recuperável');
    }

    public function test_contractless_proposal_retires_as_dead_not_recoverable(): void
    {
        // A legacy proposal with NO acceptance contract can never be re-proven → it retires as
        // genuinely DEAD (distinct from a base-drift casualty), and the persisted flag says so.
        $repo = $this->repo("<?php\nfunction val(){ return 1; }\n");
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'contractless retire proof',
            'config' => [],
            'max_seconds' => 60,
        ]);
        AtlasLoopProposal::$governedMergeInProgress = false;
        $proposal = AtlasLoopProposal::create([
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => AtlasLoopProposal::STATUS_CERTIFIED,
            'objective' => 'legacy contractless',
            'target_path' => 'snippet.php',
            'diff_text' => $this->makeDiff("<?php\nfunction val(){ return 1; }\n", "<?php\nfunction val(){ return 2; }\n"),
            'proposal_hash' => 'automerge-contractless-1',
            'metric' => null,
            'quality' => [], // no _acceptance_contract → irreprovable
        ]);

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame(0, $result['merged_count']);
        $review = $proposal->fresh()->quality['_operator_review'] ?? null;
        $this->assertIsArray($review);
        $this->assertSame('retired_contractless', $review['status']);
        $this->assertFalse((bool) $review['had_acceptance_contract'], 'sem contrato → morto, não recuperável');
    }

    public function test_drain_ignores_non_certified_rows_even_when_they_are_older(): void
    {
        $campaign = AtlasLoopCampaign::create([
            'schema_version' => 'atlas.loop.campaign.v1',
            'status' => AtlasLoopCampaign::STATUS_RUNNING,
            'goal' => 'raw non-certified row',
            'config' => [],
            'max_seconds' => 60,
        ]);
        DB::table('atlas_loop_proposals')->insert([
            'id' => (string) Str::uuid(),
            'campaign_id' => $campaign->id,
            'schema_version' => 'atlas.loop.proposal.v1',
            'status' => 'draft',
            'objective' => 'raw db draft must not consume drain slots',
            'target_path' => 'snippet.php',
            'diff_text' => 'not a real diff',
            'proposal_hash' => 'raw-draft-'.bin2hex(random_bytes(4)),
            'merged_to_main' => false,
            'created_at' => now()->subMinute(),
            'updated_at' => now()->subMinute(),
        ]);

        $original = "<?php\nfunction val(){ return 1; }\n";
        $modified = "<?php\nfunction val(){ return 2; }\n";
        $repo = $this->repo($original);
        $proposal = $this->certifiedProposal($this->makeDiff($original, $modified), 'automerge-cert-filter-1');

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 1);

        $this->assertSame(1, $result['merged_count'], json_encode($result['results']));
        $this->assertTrue((bool) $proposal->fresh()->merged_to_main);
    }

    public function test_nothing_outside_the_governed_scope_can_mark_merged(): void
    {
        $proposal = $this->certifiedProposal('diff --git a/x b/x', 'automerge-guard-1');

        $proposal->forceFill(['merged_to_main' => true])->save();

        $this->assertFalse((bool) $proposal->fresh()->merged_to_main, 'fora do escopo governado, o guard força false');
    }

    public function test_disabled_flag_merges_nothing(): void
    {
        $repo = $this->repo("<?php\nfunction val(){ return 1; }\n");
        // Desliga a porta multi-repo DEPOIS de criar o repo (repo() a liga p/ o caminho
        // feliz): com a porta por-repo fechada, o repo estrangeiro volta a never-merge.
        config(['atlas.ai.loop.multi_repo.enabled' => false]);
        config(['atlas.ai.loop.auto_merge_to_main' => false]);

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame('blocked', $result['status']);
        $this->assertSame('multi_repo_disabled', $result['repo_authority']['reason']);
    }

    /**
     * L2-6: o guard de saldo líquido aperta o dial SOZINHO quando a taxa medida de
     * falha do canário domina a janela (fix-forward perdendo a corrida) — e o drain
     * respeita o throttle. Com canários saudáveis, merges seguem LIVRES.
     */
    public function test_net_direction_guard_throttles_the_drain_on_measured_breakage(): void
    {
        // Semeia 4 merges com canário FALHO na janela. (certifiedProposal reseta o
        // escopo governado internamente — criar TUDO antes de flipar o flag.)
        $seeded = [];
        for ($i = 0; $i < 4; $i++) {
            $seeded[] = $this->certifiedProposal('diff --git a/x b/x', 'net-dir-'.$i);
        }
        \App\Models\AtlasLoopProposal::$governedMergeInProgress = true;
        try {
            foreach ($seeded as $i => $p) {
                $p->forceFill([
                    'merged_to_main' => true,
                    'reviewed_at' => now()->subMinutes(10 - $i),
                    'quality' => ['_canary' => ['ran' => true, 'passed' => false, 'target' => 't']],
                ])->save();
            }
        } finally {
            \App\Models\AtlasLoopProposal::$governedMergeInProgress = false;
        }

        $verdict = app(\App\Services\Ai\AutonomousEvolution\AtlasLoopNetDirectionGuard::class)->verdict();
        $this->assertTrue($verdict['throttled'], 'quebra dominando a janela tem que apertar o dial: '.json_encode($verdict).' merged='.\App\Models\AtlasLoopProposal::query()->where('merged_to_main', true)->count());

        $repo = $this->repo("<?php\nfunction val(){ return 1; }\n");
        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);
        $this->assertSame('throttled', $result['status'], 'o drain respeita o saldo líquido negativo');
    }

    public function test_net_direction_guard_keeps_merges_free_when_canaries_are_green(): void
    {
        $seeded = [];
        for ($i = 0; $i < 5; $i++) {
            $seeded[] = $this->certifiedProposal('diff --git a/y b/y', 'net-ok-'.$i);
        }
        \App\Models\AtlasLoopProposal::$governedMergeInProgress = true;
        try {
            foreach ($seeded as $i => $p) {
                $p->forceFill([
                    'merged_to_main' => true,
                    'reviewed_at' => now()->subMinutes(10 - $i),
                    'quality' => [
                        '_canary' => ['ran' => true, 'passed' => true, 'target' => 't'],
                        '_impact_receipt' => [
                            'schema_version' => 'atlas.loop.impact_receipt.v1',
                            'category' => 'bug',
                            'target_kind' => 'real',
                            'real_vs_generated' => 'real',
                            'target_path' => 'snippet.php',
                            'size' => [
                                'files_changed' => 1,
                                'php_files_changed' => 1,
                                'added_lines' => 1,
                                'deleted_lines' => 1,
                                'touched_lines' => 2,
                                'bucket' => 'small',
                            ],
                            'impact_score' => 0.8,
                        ],
                    ],
                ])->save();
            }
        } finally {
            \App\Models\AtlasLoopProposal::$governedMergeInProgress = false;
        }

        $verdict = app(\App\Services\Ai\AutonomousEvolution\AtlasLoopNetDirectionGuard::class)->verdict();
        $this->assertFalse($verdict['throttled'], 'direção líquida positiva = merges livres');
        $this->assertGreaterThanOrEqual(5, $verdict['impact_receipts']['observed']);
        $this->assertGreaterThan(0.0, $verdict['impact_receipts']['avg_impact_score']);
    }

    /**
     * F1 (L5-9 TOCTOU, agora ENFORCED): a identidade canônica autorizada é re-resolvida
     * imediatamente antes das git ops. Se o caminho não resolve mais idêntico (symlink/montagem
     * trocada entre authorize() e o apply), o merge ABORTA — main intocado, nada commitado.
     * Antes deste fix o 6º arg era silenciosamente descartado e a re-resolução nunca rodava.
     */
    public function test_toctou_canonical_change_aborts_the_merge(): void
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $repo = $this->repo($original);
        $proposal = $this->certifiedProposal($this->makeDiff($original, "<?php\nfunction val(){ return 2; }\n"), 'toctou-1');

        $svc = app(AtlasLoopAutoMergeService::class);
        $m = new \ReflectionMethod($svc, 'mergeOne');
        $m->setAccessible(true);
        // authorizedCanonical aponta para um caminho que $repo NÃO resolve → stillResolvesTo=false → abort.
        $res = $m->invoke($svc, $proposal, $repo, false, null, null, '/nonexistent/authorized/path');

        $this->assertFalse((bool) $res['merged'], 'identidade do repo mudou → merge abortado');
        $this->assertStringContainsString('canonical_path_changed_toctou', (string) $res['reason']);
        $this->assertSame($original, file_get_contents($repo.'/snippet.php'), 'a árvore real não foi tocada');
        $this->assertStringNotContainsString('atlas loop auto-merge', $this->git($repo, ['log', '-1', '--pretty=%s']));
        $this->assertFalse((bool) $proposal->fresh()->merged_to_main);
    }

    /**
     * F3 (pre-commit canary gate): um canário-irmão VERMELHO bloqueia o merge — o apply é
     * DESFEITO (main intocado, nada commitado), a proposta é aposentada e uma task fix-forward
     * é enfileirada. A regressão de comportamento NUNCA transita por main no caminho single-file.
     */
    public function test_precommit_canary_red_blocks_merge_reverts_apply_and_fix_forwards(): void
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $repo = $this->repoWithCanary($original, true); // fake artisan exits 1 = RED
        $proposal = $this->certifiedProposal($this->makeDiff($original, "<?php\nfunction val(){ return 2; }\n"), 'precommit-red-1');

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame(0, $result['merged_count'], json_encode($result['results']));
        $r = $result['results'][0];
        $this->assertFalse((bool) $r['merged']);
        $this->assertStringContainsString('canary_red_precommit_gate', (string) $r['reason']);
        $this->assertSame($original, file_get_contents($repo.'/snippet.php'), 'o apply foi desfeito (regressão não entra em main)');
        $this->assertStringNotContainsString('atlas loop auto-merge', $this->git($repo, ['log', '-1', '--pretty=%s']));
        $fresh = $proposal->fresh();
        $this->assertFalse((bool) $fresh->merged_to_main, 'canário vermelho nunca mergeia');
        $this->assertNotNull($fresh->reviewed_at, 'aposentada para não re-drenar e re-falhar o canário p/ sempre');
        $this->assertTrue((bool) ($r['fix_forward_task']['enqueued'] ?? false), 'fix-forward enfileirado (semântica preservada)');
    }

    /**
     * F3 contraprova: canário-irmão VERDE pré-commit → o merge atravessa normalmente.
     */
    public function test_precommit_canary_green_merges(): void
    {
        $original = "<?php\nfunction val(){ return 1; }\n";
        $repo = $this->repoWithCanary($original, false); // fake artisan exits 0 = GREEN
        $proposal = $this->certifiedProposal($this->makeDiff($original, "<?php\nfunction val(){ return 2; }\n"), 'precommit-green-1');

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame(1, $result['merged_count'], json_encode($result['results']));
        $this->assertTrue((bool) $proposal->fresh()->merged_to_main);
        $this->assertTrue((bool) ($result['results'][0]['canary']['ran'] ?? false), 'o canário rodou pré-commit');
        $this->assertTrue((bool) ($result['results'][0]['canary']['passed'] ?? false), 'e ficou verde');
    }

    /**
     * ACDE lever #2b: with the live broader-regression gate ARMED, a cross-suite RED (a regression the
     * single-sibling canary is blind to) blocks the merge pre-commit — apply undone, retired, fix-forwarded.
     */
    public function test_broader_regression_gate_live_red_blocks_merge(): void
    {
        config(['atlas.ai.loop.broader_regression_gate_live' => true]);
        $this->app->instance(BroaderRegressionGateContract::class, new class implements BroaderRegressionGateContract
        {
            public function evaluate(string $repoRoot, array $changedFiles): array
            {
                return ['passed' => false, 'reason' => 'cross_suite_red_fixture'];
            }
        });

        $original = "<?php\nfunction val(){ return 1; }\n";
        $repo = $this->repoWithCanary($original, false); // canary GREEN — only the broader gate blocks
        $proposal = $this->certifiedProposal($this->makeDiff($original, "<?php\nfunction val(){ return 2; }\n"), 'broader-red-1');

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame(0, $result['merged_count'], json_encode($result['results']));
        $r = $result['results'][0];
        $this->assertStringContainsString('broader_regression_gate', (string) $r['reason']);
        $this->assertSame($original, file_get_contents($repo.'/snippet.php'), 'o apply foi desfeito (regressão cross-suite não entra em main)');
        $this->assertFalse((bool) $proposal->fresh()->merged_to_main);
        $this->assertTrue((bool) ($r['fix_forward_task']['enqueued'] ?? false), 'fix-forward enfileirado');
    }

    /** ACDE lever #2b contraprova: gate ARMED but GREEN => the merge crosses normally. */
    public function test_broader_regression_gate_live_green_merges(): void
    {
        config(['atlas.ai.loop.broader_regression_gate_live' => true]);
        $this->app->instance(BroaderRegressionGateContract::class, new class implements BroaderRegressionGateContract
        {
            public function evaluate(string $repoRoot, array $changedFiles): array
            {
                return ['passed' => true, 'reason' => null];
            }
        });

        $original = "<?php\nfunction val(){ return 1; }\n";
        $repo = $this->repoWithCanary($original, false);
        $proposal = $this->certifiedProposal($this->makeDiff($original, "<?php\nfunction val(){ return 2; }\n"), 'broader-green-1');

        $result = app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        $this->assertSame(1, $result['merged_count'], json_encode($result['results']));
        $this->assertTrue((bool) $proposal->fresh()->merged_to_main);
    }

    public function test_precommit_canary_red_feeds_the_trust_ladder_a_revert_resetting_the_class_streak(): void
    {
        // REGRESSION (#10): a precommit canary-red is a DETECTED REGRESSION; the DEFAULT path retired
        // the proposal but never fed the trust ladder, so the change-class kept its clean streak (and
        // its earned autonomy) despite producing a breaking change. The asymmetric-revert invariant
        // (a regression resets the streak) must hold on this path too — exactly as on the success path.
        $ledger = sys_get_temp_dir().'/atlas-tl-precommit-'.bin2hex(random_bytes(4)).'.jsonl';
        @unlink($ledger);
        config([
            'atlas.ai.trust_ladder.enabled' => true,
            'atlas.ai.trust_ladder.log_path' => $ledger,
            'atlas.ai.trust_ladder.eligible_classes' => ['code'],
            'atlas.ai.trust_ladder.thresholds' => ['autonomous' => 1],
        ]);
        $ladder = app(\App\Services\Ai\Governance\AtlasChangeClassTrustLadder::class);
        // Earn a clean streak for the `code` class first, so a regression has something to reset.
        $ladder->recordEvidence('code', \App\Services\Ai\Governance\AtlasChangeClassTrustLadder::EVIDENCE_CLEAN_PROMOTION, 'seed-clean-1');
        $this->assertSame(1, $ladder->cleanStreak('code'));
        $this->assertSame(\App\Services\Ai\Policy\PolicyCanon::AUTONOMY_AUTONOMOUS, $ladder->earnedAutonomy('code'));

        $original = "<?php\nfunction val(){ return 1; }\n";
        $repo = $this->repoWithCanary($original, true); // RED canary => precommit regression on a `code` file
        $this->certifiedProposal($this->makeDiff($original, "<?php\nfunction val(){ return 2; }\n"), 'precommit-red-tl-1');

        app(AtlasLoopAutoMergeService::class)->drain($repo, 5);

        // The precommit-red retire fed the ladder a revert: the class streak is back to zero and the
        // earned autonomy fell off AUTONOMOUS (snippet.php => class `code`).
        $fresh = app(\App\Services\Ai\Governance\AtlasChangeClassTrustLadder::class);
        $this->assertSame(0, $fresh->cleanStreak('code'), 'a precommit regression resets the class streak (asymmetric trust)');
        $this->assertNotSame(\App\Services\Ai\Policy\PolicyCanon::AUTONOMY_AUTONOMOUS, $fresh->earnedAutonomy('code'));
        @unlink($ledger);
    }

    /**
     * A bare git repo whose convention sibling test EXISTS (so the resolver finds it) plus a
     * fake `artisan` stub that exits 1 (RED) or 0 (GREEN), so the pre-commit canary verdict is
     * deterministic without a full Laravel app in the temp tree.
     */
    private function repoWithCanary(string $original, bool $red): string
    {
        $d = sys_get_temp_dir().'/atlas-automerge-'.bin2hex(random_bytes(4));
        mkdir($d.'/tests/Unit', 0o755, true);
        $this->dirs[] = $d;
        file_put_contents($d.'/snippet.php', $original);
        // The canary runs `php artisan test <sibling>` in the repo; the stub decides red/green.
        file_put_contents($d.'/artisan', "<?php\nexit(".($red ? '1' : '0').");\n");
        // Presence is what AtlasLoopSiblingTestResolver keys on (basename snippet → snippetTest.php).
        file_put_contents($d.'/tests/Unit/snippetTest.php', "<?php\n// sibling presence; the fake artisan decides the verdict.\n");
        $this->git($d, ['init', '-q']);
        $this->git($d, ['add', '-A']);
        $this->git($d, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        config(['atlas.ai.loop.multi_repo.enabled' => true]);
        $allowed = (array) config('atlas.ai.loop.multi_repo.allowed_repos', []);
        $allowed[] = realpath($d) ?: $d;
        config(['atlas.ai.loop.multi_repo.allowed_repos' => array_values(array_unique($allowed))]);

        return $d;
    }
}
