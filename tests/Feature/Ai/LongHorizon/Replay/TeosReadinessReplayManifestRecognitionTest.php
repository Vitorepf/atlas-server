<?php

namespace Tests\Feature\Ai\LongHorizon\Replay;

use App\Services\Ai\LongHorizon\AtlasTeosReadinessCertificationService;
use Tests\TestCase;

class TeosReadinessReplayManifestRecognitionTest extends TestCase
{
    public function test_teos_readiness_lists_replay_manifest_check(): void
    {
        $payload = app(AtlasTeosReadinessCertificationService::class)->certify();

        $this->assertContains(
            AtlasTeosReadinessCertificationService::CHECK_REPLAY_MANIFEST_AVAILABLE,
            AtlasTeosReadinessCertificationService::ALL_CHECK_IDS,
        );

        $found = null;
        foreach ((array) ($payload['checks'] ?? []) as $check) {
            if (($check['check_id'] ?? null) === AtlasTeosReadinessCertificationService::CHECK_REPLAY_MANIFEST_AVAILABLE) {
                $found = $check;
                break;
            }
        }
        $this->assertNotNull($found, 'TEOS readiness must surface replay_manifest_available check');
        $this->assertSame(AtlasTeosReadinessCertificationService::CHECK_STATUS_PASS, $found['status']);
        $this->assertSame(AtlasTeosReadinessCertificationService::SEVERITY_P1, $found['severity']);
        // The evidence_refs must point at real files.
        $refs = (array) ($found['evidence_refs'] ?? []);
        $this->assertNotEmpty($refs);
        foreach ($refs as $ref) {
            $this->assertIsString($ref);
            $this->assertFileExists(base_path((string) $ref), "TEOS readiness evidence_ref does not exist on disk: {$ref}");
        }
    }
}
