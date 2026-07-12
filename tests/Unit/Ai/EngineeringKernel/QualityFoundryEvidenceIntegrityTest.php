<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\CertVerdict;
use App\Services\Ai\EngineeringKernel\SovereignHonestyFloor;
use App\Services\Ai\EngineeringKernel\TrustLevel;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtEvidenceContract;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QualityFoundryEvidenceIntegrityTest extends TestCase
{
    #[DataProvider('falseGreenEvidenceProvider')]
    public function test_false_green_evidence_is_refused(string $field, mixed $value): void
    {
        $execution = [
            'commands' => ['php artisan test tests/Unit/Ai/EngineeringKernel/SovereignFloorTest.php'],
            'claimed_status' => 'passed',
            'tests_run' => 6,
            'assertions_executed' => 21,
            'selected_tests' => ['tests/Unit/Ai/EngineeringKernel/SovereignFloorTest.php'],
            'artifacts' => [],
        ];
        $execution[$field] = $value;

        $verdict = (new SovereignHonestyFloor(configMutationFloor: 0.0))->certify(
            AcceptanceBundleFactory::honest(['execution' => $execution]),
            TrustLevel::Dev,
        );

        self::assertSame(CertVerdict::REFUSE, $verdict->status);
        self::assertContains('false_claim_blocked', $verdict->blockers);
    }

    /** @return array<string,array{0:string,1:mixed}> */
    public static function falseGreenEvidenceProvider(): array
    {
        return [
            'zero tests' => ['tests_run', 0],
            'zero assertions' => ['assertions_executed', 0],
            'fixed smoke' => ['artifacts', ['atlas_real_execution_smoke.php']],
            'lint as suite' => ['commands', ['php -l app/Foo.php']],
            'suite without runner' => ['commands', ['echo passed']],
        ];
    }

    public function test_stale_autonomous_evidence_is_not_admissible(): void
    {
        $result = (new AtlasVerificationCourtEvidenceContract)->verify(
            [
                'task_packet_id' => 'packet', 'lease_id' => 'lease',
                'allowed_files_hash' => 'files', 'command_hash' => 'command',
                'claims_autonomous_execution_quality' => true,
            ],
            [
                'receipt_chain' => [
                    'task_packet_id' => 'packet', 'lease_id' => 'lease',
                    'allowed_files_hash' => 'files', 'command_hash' => 'command',
                ],
                'runtime_owner' => 'atlas_native', 'runnable_proof' => true,
                'evidence_age_seconds' => 3601,
            ],
        );

        self::assertFalse($result['accepted']);
        self::assertContains('blocker:evidence_stale', $result['blockers']);
    }
}
