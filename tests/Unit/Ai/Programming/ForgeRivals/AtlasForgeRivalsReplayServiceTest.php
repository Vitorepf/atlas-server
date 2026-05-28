<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCollectEvidenceService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEventStream;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsEvidencePolicy;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsReplayService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsRunPathResolver;
use Tests\TestCase;

/**
 * Focused contract tests for {@see AtlasForgeRivalsReplayService}.
 */
final class AtlasForgeRivalsReplayServiceTest extends TestCase
{
    private string $tmpRoot;

    private AtlasForgeRivalsRunPathResolver $paths;

    private AtlasForgeRivalsCollectEvidenceService $collect;

    private AtlasForgeRivalsReplayService $replay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fr-replay-svc-'.bin2hex(random_bytes(6));
        @mkdir($this->tmpRoot, 0o755, true);
        config(['atlas_rivals.runs_root' => $this->tmpRoot]);

        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $events = new AtlasForgeRivalsEventStream($this->paths);
        $this->collect = new AtlasForgeRivalsCollectEvidenceService($this->paths);
        $this->replay = new AtlasForgeRivalsReplayService($this->paths, $events);
    }

    protected function tearDown(): void
    {
        $this->purge($this->tmpRoot);
        parent::tearDown();
    }

    public function test_replay_blocks_when_run_id_empty(): void
    {
        $result = $this->replay->replay(['run_id' => '   ']);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('run_id_required', $result['blockers']);
    }

    public function test_replay_response_carries_v2_schema_and_never_calls_provider(): void
    {
        $runId = $this->newRunId('schema');
        $this->seedComparableRun($runId, includePatchesAndLogs: true);
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->replay->replay([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame(AtlasForgeRivalsReplayService::SCHEMA_VERSION, $result['schema_version']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertTrue($result['replay_passes']);
    }

    public function test_replay_canonicalizes_manifest_verdict_for_claim_ready_gate(): void
    {
        $runId = $this->newRunId('verdict-case');
        $this->seedComparableRun($runId, includePatchesAndLogs: true);
        $paths = $this->paths->paths($runId);
        $manifest = json_decode((string) file_get_contents($paths['manifest_json']), true);
        $manifest['verdict'] = 'Comparable';
        $manifest['claim_ready'] = true;
        file_put_contents($paths['manifest_json'], $this->jsonEncode($manifest));

        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->replay->replay([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertTrue($result['replay_passes']);
        $this->assertSame('comparable', $result['verdict']);
        $this->assertTrue($result['decision']['claim_ready']);
    }

    public function test_replay_restores_artifact_paths_when_run_directory_relocated(): void
    {
        $runId = $this->newRunId('relocated');
        $oldRoot = $this->tmpRoot;
        $this->seedComparableRun($runId, includePatchesAndLogs: true);
        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $newRoot = $oldRoot.'-relocated';
        $this->copyDir($oldRoot.'/'.$runId, $newRoot.'/'.$runId);
        $this->purge($oldRoot.'/'.$runId);

        config(['atlas_rivals.runs_root' => $newRoot]);
        $this->paths = new AtlasForgeRivalsRunPathResolver;
        $events = new AtlasForgeRivalsEventStream($this->paths);
        $this->replay = new AtlasForgeRivalsReplayService($this->paths, $events);

        $result = $this->replay->replay([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertSame('ok', $result['status']);
        $this->assertTrue($result['replay_passes']);
        $this->assertSame([], $result['hash_mismatches']);
    }

    public function test_optional_missing_never_blocks_replay_or_appears_in_blockers(): void
    {
        $runId = $this->newRunId('optional');
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);

        file_put_contents($paths['manifest_json'], $this->jsonEncode([
            'verdict' => 'invalid_no_patch_diff',
            'mode' => 'local_fake',
            'claim_ready' => false,
        ]));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', '{}');

        $this->collect->collect([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $result = $this->replay->replay([
            'run_id' => $runId,
            'evidence_stage' => AtlasForgeRivalsEvidencePolicy::STAGE_PRE_ADJUDICATION,
        ]);

        $this->assertTrue($result['replay_passes']);
        $this->assertNotEmpty($result['optional_missing']);
        $this->assertSame([], $result['blockers']);
    }

    private function seedComparableRun(string $runId, bool $includePatchesAndLogs): void
    {
        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);

        file_put_contents($paths['evidence'].'/atlas_receipt.json', $this->jsonEncode(['arm' => 'atlas']));
        file_put_contents($paths['evidence'].'/rival_receipt.json', $this->jsonEncode(['arm' => 'rival']));
        file_put_contents($paths['evidence'].'/workspace_hashes.json', $this->jsonEncode([
            'before' => ['atlas' => 'h1', 'rival' => 'h1'],
            'after' => ['atlas' => 'h2', 'rival' => 'h2'],
            'dirty_after_run' => false,
            'workspace_blockers' => [],
        ]));

        if ($includePatchesAndLogs) {
            file_put_contents($paths['evidence'].'/atlas_patch.diff', '--- atlas patch ---');
            file_put_contents($paths['evidence'].'/rival_patch.diff', '--- rival patch ---');
            file_put_contents($paths['evidence'].'/atlas_test.log', '(50 tests)');
            file_put_contents($paths['evidence'].'/rival_test.log', '(50 tests)');
        }

        file_put_contents($paths['manifest_json'], $this->jsonEncode([
            'verdict' => 'comparable',
            'mode' => 'fair',
            'claim_ready' => true,
            'score' => 0.5,
        ]));
        file_put_contents($paths['events_jsonl'], json_encode(['kind' => 'run_started']).PHP_EOL);
        file_put_contents($paths['base'].'/intent.json', '{}');
    }

    private function newRunId(string $suffix): string
    {
        return 'replay-svc-'.bin2hex(random_bytes(4)).'-'.$suffix;
    }

    private function jsonEncode(mixed $value): string
    {
        return (string) json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function copyDir(string $from, string $to): void
    {
        if (! is_dir($from)) {
            return;
        }
        @mkdir(dirname($to), 0o755, true);
        @mkdir($to, 0o755, true);
        foreach (scandir($from) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $src = $from.DIRECTORY_SEPARATOR.$item;
            $dst = $to.DIRECTORY_SEPARATOR.$item;
            if (is_dir($src)) {
                $this->copyDir($src, $dst);
            } else {
                @mkdir(dirname($dst), 0o755, true);
                copy($src, $dst);
            }
        }
    }

    private function purge(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->purge($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
