<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\Adjudicator;
use App\Services\Ai\Rivals\Core\ReplayVerifier;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AdjudicationHashReplayTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_adj_replay_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_adjudication_hash_ignores_timestamp_but_binds_content(): void
    {
        $base = ['run_id' => 'r1', 'verdict' => 'valid', 'internal_claim_blockers' => []];

        $a = Adjudicator::hashAdjudication($base + ['adjudicated_at' => '2026-01-01T00:00:00+00:00']);
        $b = Adjudicator::hashAdjudication($base + ['adjudicated_at' => '2026-07-12T09:00:00+00:00']);
        $this->assertSame($a, $b, 'timestamp must not change the adjudication hash');

        $c = Adjudicator::hashAdjudication(
            ['run_id' => 'r1', 'verdict' => 'invalid', 'internal_claim_blockers' => ['x']]
            + ['adjudicated_at' => '2026-01-01T00:00:00+00:00']
        );
        $this->assertNotSame($a, $c, 'a blocker change must change the hash');
    }

    public function test_verify_adjudication_detects_tamper(): void
    {
        $runId = 'r_replay';
        RunPaths::ensureDir(RunPaths::runDir($runId));
        $adj = ['run_id' => $runId, 'verdict' => 'valid', 'internal_claim_blockers' => [], 'adjudicated_at' => 'now'];
        $adj['adjudication_hash'] = Adjudicator::hashAdjudication($adj);
        File::put(RunPaths::adjudicationPath($runId), json_encode($adj));

        $this->assertTrue((new ReplayVerifier)->verifyAdjudication($runId)['verified']);

        // tamper a scored field without recomputing the hash
        $adj['verdict'] = 'invalid';
        File::put(RunPaths::adjudicationPath($runId), json_encode($adj));
        $out = (new ReplayVerifier)->verifyAdjudication($runId);
        $this->assertFalse($out['verified']);
        $this->assertContains('adjudication_hash_mismatch', $out['failures']);
    }

    public function test_verify_adjudication_missing_file(): void
    {
        $out = (new ReplayVerifier)->verifyAdjudication('nope');
        $this->assertFalse($out['verified']);
        $this->assertContains('adjudication_not_found', $out['failures']);
    }
}
