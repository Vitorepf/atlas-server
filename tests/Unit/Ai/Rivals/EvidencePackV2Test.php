<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Adapters\LocalFakeSuiteAdapter;
use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\EvidencePackBuilder;
use App\Services\Ai\Rivals\Core\ReplayVerifier;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\RunStateMachine;
use App\Services\Ai\Rivals\Support\RunPaths;
use App\Services\Ai\Rivals\Support\SchemaContract;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class EvidencePackV2Test extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_evidence_v2_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_pack_hashes_state_events_and_remains_valid_after_state_advances(): void
    {
        [$runId, $state] = $this->fakeRun();
        $pack = (new EvidencePackBuilder)->build($runId);

        $this->assertSame(SchemaContract::EVIDENCE_PACK, $pack['schema_version']);
        $this->assertTrue($pack['state_hash']['present']);
        $this->assertTrue($pack['events_hash']['present']);
        $this->assertNotEmpty($pack['evidence_hash']);
        $this->assertSame([], $pack['orphan_artifacts']);
        $this->assertTrue((new ReplayVerifier)->verify($runId)['verified']);

        $state->mark($runId, RunStateMachine::EVIDENCE_BUILT);
        $this->assertTrue((new ReplayVerifier)->verify($runId)['verified']);
    }

    public function test_orphan_artifact_and_pack_tamper_fail_closed(): void
    {
        [$runId] = $this->fakeRun();
        file_put_contents(RunPaths::artifactsDir($runId).'/orphan.txt', 'orphan');
        $pack = (new EvidencePackBuilder)->build($runId);
        $this->assertContains('artifacts/orphan.txt', $pack['orphan_artifacts']);
        $this->assertFalse((new ReplayVerifier)->verify($runId)['verified']);

        unlink(RunPaths::artifactsDir($runId).'/orphan.txt');
        (new EvidencePackBuilder)->build($runId);
        $data = json_decode(file_get_contents(RunPaths::evidencePath($runId)), true);
        $data['adapter']['class'] = 'tampered';
        file_put_contents(RunPaths::evidencePath($runId), json_encode($data));
        $this->assertContains(
            'evidence_hash_mismatch',
            (new ReplayVerifier)->verify($runId)['failures'],
        );
    }

    /** @return array{string, RunStateMachine} */
    private function fakeRun(): array
    {
        $adapter = new LocalFakeSuiteAdapter;
        $plan = RunPlan::make(
            $adapter->suiteId(),
            ['fake_patch_ok'],
            [(new ArmRegistry)->parse('local_fake_model@bare')],
            1,
            ['max_usd' => 0.0, 'max_minutes' => 1],
            1,
        );
        $runId = $plan->persist();
        $state = new RunStateMachine;
        $state->mark($runId, RunStateMachine::PLANNED);
        $state->mark($runId, RunStateMachine::NATIVE_RUNNING);
        $adapter->execute($plan);
        $state->mark($runId, RunStateMachine::RESULTS_IMPORTED);

        return [$runId, $state];
    }
}
