<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService;
use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionOperatorEvidenceSubmissionCanonicalPathTruthTest extends TestCase
{
    public function test_public_readiness_payload_uses_private_canonical_paths_for_every_persisted_artifact(): void
    {
        Storage::fake('local');

        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(
            app(AtlasSelfConstructionReadinessService::class),
        ))->build();

        $plan = (array) data_get($payload, 'canonical_submission_persistence_plan');

        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions',
            data_get($plan, 'canonical_submission_directory'),
        );
        $this->assertSame(
            [
                'storage/app/private/atlas/self-construction/operator-submissions/runtime-promotion.json',
                'storage/app/private/atlas/self-construction/operator-submissions/real-provider-smoke.json',
                'storage/app/private/atlas/self-construction/operator-submissions/completion-receipt.json',
            ],
            array_column(array_slice((array) data_get($plan, 'steps', []), 0, 3), 'canonical_submission_path'),
        );
    }
}
