<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Services\Ai\EngineeringKernel\KernelEvidenceAuthority;
use App\Services\Ai\SelfConstruction\AtlasAutonomousLandVerificationGate;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * MULTV-10 — enforce SEAM for verified autonomous landings.
 *
 * Petreas verified here:
 *  - default-OFF path is byte-identical (autonomous land lands with no receipt);
 *  - flag ON + autonomous land without receipt ⇒ verification_receipt_missing;
 *  - flag ON + invalid seal ⇒ verification_receipt_seal_invalid;
 *  - flag ON + tier below required ⇒ verification_receipt_tier_below_risk;
 *  - flag ON + sealed receipt of correct tier ⇒ landing proceeds;
 *  - operator port (atlas:land) lands with flag ON even without a receipt.
 *
 * The flip itself belongs to ASI-10 via ELEV-26s — never this slice.
 */
final class Multv10VerificationSeamTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-multv10-'.bin2hex(random_bytes(5));
        @mkdir($this->repo, 0775, true);
        $this->git(['init', '-q']);
        $this->git(['config', 'user.email', 'test@atlas.local']);
        $this->git(['config', 'user.name', 'Atlas Test']);
        @file_put_contents($this->repo.'/README.md', "seed\n");
        $this->git(['add', 'README.md']);
        $this->git(['commit', '-q', '-m', 'seed']);

        Schema::dropIfExists('atlas_aobg_blackboard');
        $migration = require database_path('migrations/2026_06_10_120000_create_atlas_aobg_blackboard_table.php');
        $migration->up();

        // Baseline: the flip is OFF by default.
        config(['atlas.multv.autonomous_land_verification_enabled' => false]);
        config(['atlas.multv.autonomous_land_verification_required_tier' => 'T1']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_aobg_blackboard');
        File::deleteDirectory($this->repo);
        parent::tearDown();
    }

    public function test_flag_off_is_byte_identical_autonomous_land_lands_without_receipt(): void
    {
        $this->writeFile('app/Foo.php', "<?php // foo\n");

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            ['app/Foo.php'], 'task-off', 'client-off', 'flag-off landing'
        );

        $this->assertTrue($res['committed'], 'reason: '.(string) ($res['reason'] ?? ''));
    }

    public function test_flag_on_autonomous_land_without_receipt_is_refused_with_mechanical_reason(): void
    {
        config(['atlas.multv.autonomous_land_verification_enabled' => true]);
        $this->writeFile('app/Foo.php', "<?php // foo\n");

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            ['app/Foo.php'], 'task-miss', 'client-miss', 'no receipt'
        );

        $this->assertFalse($res['committed']);
        $this->assertSame(
            AtlasAutonomousLandVerificationGate::REASON_MISSING,
            (string) $res['reason'],
            'The flag-ON autonomous land without a receipt MUST be refused with the mechanical MULTV-10 reason.',
        );
        $this->assertSame('seed', trim($this->git(['log', '-1', '--pretty=%s'])['out']), 'nothing landed');
    }

    public function test_flag_on_autonomous_land_with_invalid_seal_is_refused(): void
    {
        config(['atlas.multv.autonomous_land_verification_enabled' => true]);
        $this->writeFile('app/Foo.php', "<?php // foo\n");

        $forged = [
            'flow' => 'atlas_autonomos',
            'tier' => 'T1',
            'evidence_bundle' => [
                'authority' => [
                    'schema_version' => 'atlas.engineering.kernel_evidence.v1',
                    'kind' => 'engineering_outcome',
                    'issued_at' => now()->toIso8601String(),
                    'expires_at' => null,
                    'provenance' => 'kernel_evidence_authority',
                    'key_id' => 'atlas-kernel-v1',
                    'payload_hash' => str_repeat('0', 64),
                    'signature' => str_repeat('f', 64),
                ],
            ],
        ];

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            ['app/Foo.php'], 'task-forge', 'client-forge', 'forged receipt',
            verification: ['verification_receipt' => $forged],
        );

        $this->assertFalse($res['committed']);
        $this->assertSame(
            AtlasAutonomousLandVerificationGate::REASON_SEAL_INVALID,
            (string) $res['reason'],
            'A tampered/forged seal MUST be refused by the seam.',
        );
    }

    public function test_flag_on_receipt_below_required_tier_is_refused(): void
    {
        config(['atlas.multv.autonomous_land_verification_enabled' => true]);
        config(['atlas.multv.autonomous_land_verification_required_tier' => 'T2']);

        $this->writeFile('app/Foo.php', "<?php // foo\n");

        $receipt = $this->sealReceipt('T1');

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            ['app/Foo.php'], 'task-low', 'client-low', 'tier too low',
            verification: ['verification_receipt' => $receipt],
        );

        $this->assertFalse($res['committed']);
        $this->assertSame(
            AtlasAutonomousLandVerificationGate::REASON_TIER_BELOW_RISK,
            (string) $res['reason'],
        );
        $this->assertSame('T1', $res['receipt_tier'] ?? null);
        $this->assertSame('T2', $res['required_tier'] ?? null);
    }

    public function test_flag_on_receipt_of_correct_tier_lands(): void
    {
        config(['atlas.multv.autonomous_land_verification_enabled' => true]);
        $this->writeFile('app/Foo.php', "<?php // foo\n");

        $receipt = $this->sealReceipt('T1');

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            ['app/Foo.php'], 'task-ok', 'client-ok', 'sealed receipt',
            verification: ['verification_receipt' => $receipt],
        );

        $this->assertTrue($res['committed'], 'reason: '.(string) ($res['reason'] ?? ''));
    }

    public function test_operator_port_lands_with_flag_on_and_no_receipt(): void
    {
        // Petrea: the operator port (atlas:land, commitAuthority=operator) is EXEMPT.
        // The MULTV-10 seam must never touch that path even under flag ON.
        config(['atlas.multv.autonomous_land_verification_enabled' => true]);
        $this->writeFile('app/OperatorHand.php', "<?php // operator\n");

        $res = (new AtlasTaskScopedCommitter(null, $this->repo))->commitScope(
            ['app/OperatorHand.php'], 'task-op', 'client-op', 'operator landing',
            commitAuthority: 'operator',
        );

        $this->assertTrue($res['committed'], 'operator port must remain intact under flag ON');
    }

    public function test_seam_never_flips_the_master_by_itself(): void
    {
        // The AtlasAutonomousLandVerificationGate must NEVER mutate its own flag.
        // Pétreo: the flip is ASI-10's alone via ELEV-26s (1 flip per family per window).
        $engaged = (bool) config('atlas.multv.autonomous_land_verification_enabled');
        $this->assertFalse($engaged, 'default OFF preserved');

        $gate = new AtlasAutonomousLandVerificationGate;
        // Interrogating the gate does not change the flag.
        $gate->isEngaged();
        $gate->evaluate('autonomous', null);
        $gate->evaluate('operator', null);

        $this->assertSame(
            $engaged,
            (bool) config('atlas.multv.autonomous_land_verification_enabled'),
            'the seam MUST never mutate its own promotion flag — that flip belongs to ASI-10',
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function sealReceipt(string $tier): array
    {
        // KernelEvidenceAuthority::verifyOutcome unsets `evidence_bundle.authority`
        // before hashing, so the payload the seal covers must ALREADY include the
        // `evidence_bundle` scaffold — otherwise the pre-seal hash differs from the
        // post-seal verify hash. This is the same pattern EliteExecutorKernel uses
        // when it seals engineering outcomes (see :225).
        $authority = app(KernelEvidenceAuthority::class);
        $receipt = [
            'flow' => 'atlas_autonomos',
            'tier' => $tier,
            'landing_id' => 'landing:'.bin2hex(random_bytes(4)),
            'evidence_bundle' => [],
        ];
        $receipt['evidence_bundle']['authority'] = $authority->sealOutcome($receipt);

        // Sanity: the receipt verifies straight after sealing.
        $this->assertTrue($authority->verifyOutcome($receipt), 'sanity — sealed receipt should verify');

        return $receipt;
    }

    private function writeFile(string $rel, string $content): void
    {
        $path = $this->repo.'/'.$rel;
        @mkdir(\dirname($path), 0775, true);
        @file_put_contents($path, $content);
    }

    /** @param list<string> $args @return array{code:int,out:string,err:string} */
    private function git(array $args): array
    {
        $p = new Process(array_merge(['git'], $args), $this->repo);
        $p->run();

        return ['code' => (int) $p->getExitCode(), 'out' => $p->getOutput(), 'err' => $p->getErrorOutput()];
    }
}
