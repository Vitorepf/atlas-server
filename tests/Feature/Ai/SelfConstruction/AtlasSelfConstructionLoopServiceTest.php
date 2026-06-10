<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\RealExecution\AtlasMissionService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionDetector;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionLoopService;
use App\Services\Ai\SelfConstruction\AtlasSelfImprovementRelevanceGate;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * S3.F1 — the RECURSIVE GOVERNED SELF-IMPROVEMENT LOOP. Proves, cost-free (a fake
 * brain-anchored mission stands in for the spend step), that:
 *  - each signal is routed through {@see AtlasMissionService::run} (brain-anchored),
 *    NOT the bare orchestrator;
 *  - the mission request NAMES the signal's file:line + concern, and target_file is
 *    threaded into the mission opts (the AIM half of the 412-line-garbage fix);
 *  - the brain-context path is exercised (spy) and the outcome is recorded (the loop
 *    COMPOUNDS — the next cycle would see it);
 *  - the OUT-OF-PROCESS relevance gate REJECTS an off-target generation and the
 *    rejected branch is DISCARDED (the CHECK half of the fix) — never presented;
 *  - never merges, never main; the cycle meta-metric is HONEST (gate verdict, not
 *    loop optimism).
 */
final class AtlasSelfConstructionLoopServiceTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-selfconstruct-'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($this->root.'/app/Services', 0777, true, true);
        File::put(
            $this->root.'/app/Services/Widget.php',
            "<?php\n\nnamespace App\\Services;\n\nclass Widget\n{\n    // TODO: tighten the workspace guard here\n    public function run(): void {}\n}\n",
        );
    }

    protected function tearDown(): void
    {
        if ($this->root !== '') {
            File::deleteDirectory($this->root);
        }
        parent::tearDown();
    }

    public function test_routes_signal_through_brain_anchored_mission_with_file_anchored_request_and_records_outcome(): void
    {
        $mission = $this->fakeMission(touchedFiles: ['app/Services/Widget.php']);
        $loop = $this->loop($mission);

        $r = $loop->run(['repo_dir' => $this->root, 'max' => 3]);

        $this->assertSame(AtlasSelfConstructionLoopService::SCHEMA, $r['schema_version']);
        $this->assertGreaterThanOrEqual(1, $r['detected'], 'the TODO marker must be detected');
        $this->assertGreaterThanOrEqual(1, $r['accepted_count']);
        $this->assertNotEmpty($r['branches']);
        $this->assertTrue($r['never_merged']);
        $this->assertTrue($r['main_untouched']);
        $this->assertTrue($r['brain_anchored']);

        // The mission was called brain-anchored (no_brain=false) with the file-anchored
        // request + target_file — the AIM half of the 412-fix.
        $this->assertNotNull($mission->lastRequest, 'the loop must have called the mission');
        $req = (string) $mission->lastRequest;
        $this->assertStringContainsString('app/Services/Widget.php:', $req, 'the request must name the signal file:line');
        $this->assertStringContainsString('tighten the workspace guard', $req, 'the request must name the concern');
        $this->assertSame('app/Services/Widget.php', $mission->lastOpts['target_file'] ?? null, 'target_file must be threaded into the mission opts');
        $this->assertFalse((bool) ($mission->lastOpts['no_brain'] ?? true), 'brain-anchoring default => no_brain=false');

        // The accepted outcome is on-target + compounded the brain (loop recursion).
        $first = $r['outcomes'][0];
        $this->assertTrue($first['accepted']);
        $this->assertTrue($first['relevant']);
        $this->assertSame('on_target', $first['relevance_reason']);
        $this->assertTrue($first['brain_context_used'], 'brain context path was exercised');
        $this->assertTrue($first['evidence_recorded'], 'the outcome fed the brain back — the loop compounds');
        $this->assertStringStartsWith('atlas/materialize/', (string) $first['branch']);

        // Honest meta-metric: everything delivered was on-target ⇒ 1.0.
        $this->assertSame(1.0, $r['relevance_precision']);
        $this->assertSame(0, $r['rejected_count']);
        $this->assertGreaterThanOrEqual(1, $r['brain_compounded_count']);
    }

    public function test_off_target_generation_is_rejected_and_its_branch_discarded(): void
    {
        // The signal names Widget.php, but the (fake) generation touches an UNRELATED
        // file — the exact 412-line-garbage failure mode. The OUT-OF-PROCESS gate
        // must REJECT and the loop must DISCARD the branch via the real materializer.
        $repo = $this->bootGitRepo();
        // The 412-line-garbage case: a Hermes Kanban driver in a DIFFERENT directory
        // than the signal's app/Services/Widget.php — genuinely off-target.
        $mission = $this->realBranchMission($repo, touchedFile: 'app/Services/Hermes/HermesKanbanDriver.php');
        $loop = $this->loop($mission);

        $r = $loop->run(['repo_dir' => $repo, 'max' => 1, 'requests' => [], 'delivery' => []]);

        $first = $r['outcomes'][0];
        $this->assertTrue($first['delivered'], 'the fake delivery produced a real branch');
        $this->assertFalse($first['relevant'], 'off-target generation must NOT be relevant');
        $this->assertSame('off_target_generation', $first['relevance_reason']);
        $this->assertFalse($first['accepted'], 'off-target is never accepted');
        $this->assertNull($first['branch'], 'the rejected branch is no longer presented');
        $this->assertNotNull($first['rejected_branch']);
        $this->assertTrue((bool) ($first['discarded']['discarded'] ?? false), 'the rejected branch must be discarded');

        // The branch is actually GONE from the repo (governed delete, main untouched).
        $exists = new \Symfony\Component\Process\Process(
            ['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/'.$first['rejected_branch']],
            $repo,
        );
        $exists->run();
        $this->assertFalse($exists->isSuccessful(), 'the off-target branch must be deleted');

        // Honest meta-metric: 1 delivered, 0 accepted ⇒ precision 0.0, not hidden.
        $this->assertSame(0, $r['accepted_count']);
        $this->assertSame(1, $r['rejected_count']);
        $this->assertSame(0.0, $r['relevance_precision']);
        $this->assertSame([], $r['branches']);

        File::deleteDirectory($repo);
    }

    public function test_operator_gap_with_no_file_is_admitted_as_unverifiable(): void
    {
        $mission = $this->fakeMission(touchedFiles: ['app/Generated/Anything.php']);
        $loop = $this->loop($mission);

        $r = $loop->run([
            'repo_dir' => $this->root,
            'requests' => ['Harden the G-5 secret scanner recall for DB_PASSWORD assignments'],
            'max' => 1,
        ]);

        $first = $r['outcomes'][0];
        $this->assertSame('operator', $first['signal']['area']);
        $this->assertStringContainsString('G-5 secret scanner', (string) $mission->lastRequest, 'operator gap rides its own request');
        $this->assertNull($mission->lastOpts['target_file'] ?? null, 'an operator gap names no file => no target_file');
        $this->assertTrue($first['accepted']);
        $this->assertSame('no_target_unverifiable_admitted', $first['relevance_reason']);
        $this->assertTrue($r['never_merged']);
    }

    public function test_blocked_delivery_is_not_accepted_and_records_nothing(): void
    {
        $mission = $this->fakeMission(touchedFiles: [], delivered: false);
        $loop = $this->loop($mission);

        $r = $loop->run(['repo_dir' => $this->root, 'max' => 1]);

        $first = $r['outcomes'][0];
        $this->assertFalse($first['delivered']);
        $this->assertFalse($first['accepted']);
        $this->assertFalse($first['relevant']);
        $this->assertSame('no_delivery_to_check', $first['relevance_reason']);
        $this->assertNull($r['relevance_precision'], 'no delivery ⇒ no fabricated rate');
        $this->assertSame([], $r['branches']);
    }

    public function test_use_brain_context_false_bypasses_brain_anchoring_for_the_run(): void
    {
        $mission = $this->fakeMission(touchedFiles: ['app/Services/Widget.php']);
        $loop = $this->loop($mission);

        $r = $loop->run(['repo_dir' => $this->root, 'max' => 1, 'use_brain_context' => false]);

        $this->assertFalse($r['brain_anchored']);
        $this->assertTrue((bool) ($mission->lastOpts['no_brain'] ?? false), 'use_brain_context=false => no_brain=true');
    }

    // ------------------------------------------------------------------
    // fixtures
    // ------------------------------------------------------------------

    private function loop(AtlasMissionService $mission): AtlasSelfConstructionLoopService
    {
        return new AtlasSelfConstructionLoopService(
            new AtlasSelfConstructionDetector,
            $mission,
            new AtlasSelfImprovementRelevanceGate,
            new GovernedBranchMaterializationService,
        );
    }

    /**
     * A fake brain-anchored mission: zero spend, returns a flat envelope with the
     * given touched files, captures the request + opts it was called with (spy), and
     * reports brain flags ON so the compounding assertions are exercised.
     *
     * @param  list<string>  $touchedFiles
     */
    private function fakeMission(array $touchedFiles, bool $delivered = true): AtlasMissionService
    {
        return new class($touchedFiles, $delivered) extends AtlasMissionService
        {
            public ?string $lastRequest = null;

            /** @var array<string,mixed> */
            public array $lastOpts = [];

            /** @param list<string> $touchedFiles */
            public function __construct(private array $touchedFiles, private bool $delivered)
            {
                // no parent ctor — fully self-contained fake (no real deps).
            }

            public function run(string $request, array $opts = []): array
            {
                $this->lastRequest = $request;
                $this->lastOpts = $opts;
                $id = (string) ($opts['id'] ?? 'x');

                if (! $this->delivered) {
                    return [
                        'schema_version' => AtlasMissionService::SCHEMA,
                        'mission_id' => $id,
                        'request' => $request,
                        'delivered' => false,
                        'branch' => null,
                        'brain_context_used' => false,
                        'evidence_recorded' => false,
                        'main_untouched' => true,
                        'never_merged' => true,
                        'review_commands' => [],
                        'stage' => 'delivery',
                        'reason' => 'provider_returned_not_ok',
                    ];
                }

                return [
                    'schema_version' => AtlasMissionService::SCHEMA,
                    'mission_id' => $id,
                    'request' => $request,
                    'delivered' => true,
                    'branch' => 'atlas/materialize/'.$id,
                    'brain_context_used' => ! (bool) ($opts['no_brain'] ?? false),
                    'evidence_recorded' => true,
                    'main_untouched' => true,
                    'never_merged' => true,
                    'review_commands' => ['git checkout atlas/materialize/'.$id],
                    'delivery' => ['files' => $this->touchedFiles],
                ];
            }
        };
    }

    /**
     * A fake mission that, like the real one, cuts a REAL branch via the real
     * materializer (so the off-target DISCARD path is exercised end to end on a real
     * git repo) but spends zero tokens.
     */
    private function realBranchMission(string $repo, string $touchedFile): AtlasMissionService
    {
        $sandbox = sys_get_temp_dir().'/atlas-sc-sandbox-'.substr(md5(uniqid('', true)), 0, 8).'.php';
        File::put($sandbox, "<?php\n\nnamespace App\\Generated;\n\nclass OffTarget { public function ok(): bool { return true; } }\n");

        return new class($repo, $touchedFile, $sandbox) extends AtlasMissionService
        {
            public ?string $lastRequest = null;

            /** @var array<string,mixed> */
            public array $lastOpts = [];

            public function __construct(
                private string $repo,
                private string $touchedFile,
                private string $sandbox,
            ) {}

            public function run(string $request, array $opts = []): array
            {
                $this->lastRequest = $request;
                $this->lastOpts = $opts;
                $id = (string) ($opts['id'] ?? 'x');

                // Cut a REAL branch via the governed materializer (zero provider spend).
                $receipt = hash('sha256', 'gate'.$id);
                $m = (new GovernedBranchMaterializationService)->materialize([
                    'id' => $id,
                    'files' => [['path' => $this->touchedFile, 'content' => (string) file_get_contents($this->sandbox)]],
                    'repo_dir' => $this->repo,
                    'certified' => true,
                    'gate_receipt' => $receipt,
                ]);

                return [
                    'schema_version' => AtlasMissionService::SCHEMA,
                    'mission_id' => $id,
                    'request' => $request,
                    'delivered' => (bool) ($m['materialized'] ?? false),
                    'branch' => $m['branch'] ?? null,
                    'brain_context_used' => true,
                    'evidence_recorded' => true,
                    'main_untouched' => (bool) ($m['main_untouched'] ?? true),
                    'never_merged' => true,
                    'review_commands' => $m['review_commands'] ?? [],
                    // OFF-TARGET: touched an unrelated file, not the signal's Widget.php.
                    'delivery' => ['files' => [$this->touchedFile]],
                ];
            }
        };
    }

    private function bootGitRepo(): string
    {
        $repo = sys_get_temp_dir().'/atlas-sc-repo-'.substr(md5(uniqid('', true)), 0, 8);
        File::makeDirectory($repo.'/app/Services', 0777, true, true);
        File::put(
            $repo.'/app/Services/Widget.php',
            "<?php\n\nnamespace App\\Services;\n\nclass Widget\n{\n    // TODO: tighten the workspace guard here\n    public function run(): void {}\n}\n",
        );
        $this->git($repo, ['init', '-q']);
        $this->git($repo, ['add', '-A']);
        $this->git($repo, ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']);

        return $repo;
    }

    /** @param list<string> $argv */
    private function git(string $repo, array $argv): void
    {
        (new \Symfony\Component\Process\Process(array_merge(['git'], $argv), $repo))->run();
    }
}
