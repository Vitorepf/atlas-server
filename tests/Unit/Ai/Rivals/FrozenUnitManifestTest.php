<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Core\ArmRegistry;
use App\Services\Ai\Rivals\Core\FrozenUnitManifest;
use App\Services\Ai\Rivals\Core\RunPlan;
use App\Services\Ai\Rivals\Core\RunStateMachine;
use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

final class FrozenUnitManifestTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storage = sys_get_temp_dir().'/rivals_frozen_units_'.uniqid();
        config()->set('atlas_rivals.storage_root', $this->storage);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    public function test_freeze_persists_case_repo_and_repetition_hashes(): void
    {
        $plan = $this->plan()->persist();
        $loaded = RunPlan::load($plan);
        $manifest = FrozenUnitManifest::fromPlan($loaded, [$this->unit('case-a')]);

        $path = $manifest->persist();

        self::assertSame(RunPaths::unitFreezePath($plan), $path);
        self::assertSame($manifest->hash(), FrozenUnitManifest::load($plan)->hash());
        self::assertSame([1, 2], FrozenUnitManifest::load($plan)->data['units'][0]['repetition_ids']);
    }

    public function test_freeze_rejects_missing_hidden_proof_and_invalid_commit_hashes(): void
    {
        $plan = RunPlan::fromArray(array_replace($this->plan()->data, ['repetitions' => 1]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('hidden_proof_missing');
        FrozenUnitManifest::fromPlan($plan, [[
            'case_id' => 'case-a',
            'base_sha' => str_repeat('a', 40),
            'golden_sha' => str_repeat('b', 40),
            'changed_files' => ['tests' => []],
        ]]);
    }

    public function test_freeze_is_immutable_for_same_run(): void
    {
        $runId = $this->plan()->persist();
        $manifest = FrozenUnitManifest::fromPlan(RunPlan::load($runId), [$this->unit('case-a')]);
        $manifest->persist();

        $changed = FrozenUnitManifest::fromPlan(RunPlan::load($runId), [
            $this->unit('case-a', str_repeat('c', 40)),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('frozen_unit_manifest_immutable');
        $changed->persist();
    }

    public function test_external_run_cannot_enter_native_execution_without_freeze(): void
    {
        $runId = $this->plan()->persist();
        $state = new RunStateMachine;
        $state->mark($runId, RunStateMachine::PLANNED);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rivals_unit_freeze_required');
        $state->mark($runId, RunStateMachine::NATIVE_RUNNING);
    }

    private function plan(): RunPlan
    {
        return RunPlan::fromArray(array_replace($this->basePlan()->data, [
            'preregistration_hash' => str_repeat('d', 64),
        ]));
    }

    private function basePlan(): RunPlan
    {
        return RunPlan::make(
            'atlasbench',
            ['case-a'],
            [(new ArmRegistry)->parse('local_fake_model@bare')],
            2,
            ['max_usd' => 0.0, 'max_minutes' => 1],
            42,
        );
    }

    private function unit(string $caseId, string $goldenSha = ''): array
    {
        return [
            'case_id' => $caseId,
            'base_sha' => str_repeat('a', 40),
            'golden_sha' => $goldenSha !== '' ? $goldenSha : str_repeat('b', 40),
            'changed_files' => ['tests' => ['tests/Feature/HiddenTest.php']],
        ];
    }
}
