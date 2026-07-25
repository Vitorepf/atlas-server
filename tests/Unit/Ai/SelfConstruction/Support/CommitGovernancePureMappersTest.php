<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\Support\CommitGovernancePureMappers;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtFalseGreenDetector;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure-unit lock for {@see CommitGovernancePureMappers}
 * (string/array-only; no FS / DI / I/O / policy plane).
 *
 * Explicit path proof: organ labels and scope roots are derived from concrete
 * SelfConstruction file paths that the commit governance chain feeds in live.
 */
final class CommitGovernancePureMappersTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/SelfConstruction/Support/CommitGovernancePureMappers.php';

    private const CHAIN_PATH = 'app/Services/Ai/SelfConstruction/Governance/AtlasTaskCommitGovernanceChain.php';

    #[Test]
    public function explicit_path_proof_support_and_chain_files_exist_and_chain_calls_mappers(): void
    {
        $root = dirname(__DIR__, 5);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $chainAbs = $root.'/'.self::CHAIN_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($chainAbs, 'Chain must remain at '.self::CHAIN_PATH);

        $chainSrc = (string) file_get_contents($chainAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\SelfConstruction\Support\CommitGovernancePureMappers;',
            $chainSrc,
            'Chain must import CommitGovernancePureMappers',
        );
        foreach ([
            'CommitGovernancePureMappers::touchedOrgans',
            'CommitGovernancePureMappers::scopeRoots',
            'CommitGovernancePureMappers::normalizeFiles',
            'CommitGovernancePureMappers::decisionToVerdict',
            'CommitGovernancePureMappers::rollbackPosture',
            'CommitGovernancePureMappers::ledgerErrorBlockers',
            'CommitGovernancePureMappers::missingRerun',
            'CommitGovernancePureMappers::deterministicHash',
        ] as $call) {
            $this->assertStringContainsString($call, $chainSrc, "Chain must call {$call}");
        }

        // Peeled privates must not remain on the chain (byte-proof of peel).
        foreach ([
            'private function touchedOrgans',
            'private function scopeRoots',
            'private function normalizeFiles',
            'private function decisionToVerdict',
            'private function rollbackPosture',
            'private function ledgerErrorBlockers',
            'private function missingRerun(',
            'private function deterministicHash',
        ] as $dead) {
            $this->assertStringNotContainsString($dead, $chainSrc, "Peeled method residual: {$dead}");
        }
    }

    #[Test]
    public function pure_mappers_are_static_and_final_with_no_instance_state(): void
    {
        $ref = new ReflectionClass(CommitGovernancePureMappers::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->getConstructor()?->isPrivate() ?? false);

        foreach ([
            'touchedOrgans',
            'scopeRoots',
            'normalizeFiles',
            'decisionToVerdict',
            'rollbackPosture',
            'ledgerErrorBlockers',
            'missingRerun',
            'deterministicHash',
        ] as $method) {
            $m = new ReflectionMethod(CommitGovernancePureMappers::class, $method);
            $this->assertTrue($m->isPublic() && $m->isStatic(), "{$method} must be public static");
        }
    }

    #[Test]
    public function touched_organs_maps_explicit_self_construction_paths(): void
    {
        $paths = [
            'app/Services/Ai/SelfConstruction/MergeGovernor/AtlasMergeGovernorAdmissionPolicy.php',
            'app/Services/Ai/SelfConstruction/VerificationCourt/AtlasVerificationCourtVerdictLedger.php',
            'app/Services/Ai/SelfConstruction/AtlasTaskServingService.php',
            'app/Services/Ai/SelfConstruction/TaskQueue/AgentControlPlaneTaskDependencyClassifier.php',
            'app/Services/Ai/SelfConstruction/HarnessGuard/Something.php',
            'app/Services/Ai/SelfConstruction/MasterSwitch/LoopMasterSwitch.php',
            'app/Services/Ai/SelfConstruction/WorkspaceMaterializer/Materializer.php',
            'app/Services/Ai/SelfConstruction/Support/CommitGovernancePureMappers.php',
        ];

        $organs = CommitGovernancePureMappers::touchedOrgans($paths);

        $this->assertSame([
            'Merge Governor',
            'Verification Court',
            'Task Fabric',
            'Constitution',
            'MasterSwitch',
            'WorkspaceMaterializer',
        ], $organs);
        $this->assertNotContains('Support', $organs);
    }

    #[Test]
    public function touched_organs_task_fabric_path_variants(): void
    {
        $this->assertSame(
            ['Task Fabric'],
            CommitGovernancePureMappers::touchedOrgans([
                'app/Services/Ai/SelfConstruction/TaskPacket/Foo.php',
            ]),
        );
        $this->assertSame(
            ['Task Fabric'],
            CommitGovernancePureMappers::touchedOrgans([
                'app/Services/Ai/SelfConstruction/AtlasTaskQueue.php',
            ]),
        );
        $this->assertSame(
            ['Task Fabric'],
            CommitGovernancePureMappers::touchedOrgans([
                'app/Services/Ai/SelfConstruction/TaskQueueOrchestrator.php',
            ]),
        );
        $this->assertSame(
            ['Constitution'],
            CommitGovernancePureMappers::touchedOrgans([
                'app/Services/Ai/SelfConstruction/Constitution/Rules.php',
            ]),
        );
    }

    #[Test]
    public function scope_roots_from_explicit_file_paths(): void
    {
        $roots = CommitGovernancePureMappers::scopeRoots([
            'app/Services/Ai/SelfConstruction/Governance/AtlasTaskCommitGovernanceChain.php',
            'app/Services/Ai/SelfConstruction/Support/CommitGovernancePureMappers.php',
            'tests/Unit/Ai/SelfConstruction/Support/CommitGovernancePureMappersTest.php',
        ]);

        $this->assertSame([
            'app/Services/Ai/SelfConstruction/Governance',
            'app/Services/Ai/SelfConstruction/Support',
            'tests/Unit/Ai/SelfConstruction/Support',
        ], $roots);
    }

    #[Test]
    public function scope_roots_empty_or_bare_file_defaults_to_dot(): void
    {
        $this->assertSame(['.'], CommitGovernancePureMappers::scopeRoots([]));
        $this->assertSame(['.'], CommitGovernancePureMappers::scopeRoots(['README.md']));
    }

    #[Test]
    public function normalize_files_strips_slash_dedupes_and_normalizes_separators(): void
    {
        $out = CommitGovernancePureMappers::normalizeFiles([
            '/app/Services/Ai/SelfConstruction/Governance/AtlasTaskCommitGovernanceChain.php',
            'app\\Services\\Ai\\SelfConstruction\\Support\\CommitGovernancePureMappers.php',
            '  ',
            'app/Services/Ai/SelfConstruction/Governance/AtlasTaskCommitGovernanceChain.php',
        ]);

        $this->assertSame([
            'app/Services/Ai/SelfConstruction/Governance/AtlasTaskCommitGovernanceChain.php',
            'app/Services/Ai/SelfConstruction/Support/CommitGovernancePureMappers.php',
        ], $out);
    }

    #[Test]
    public function decision_to_verdict_maps_admission_space(): void
    {
        $this->assertSame(
            AtlasVerificationCourtFalseGreenDetector::VERDICT_PASSED,
            CommitGovernancePureMappers::decisionToVerdict(AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED),
        );
        $this->assertSame(
            AtlasVerificationCourtFalseGreenDetector::VERDICT_BLOCKED,
            CommitGovernancePureMappers::decisionToVerdict(AtlasMergeGovernorAdmissionPolicy::DECISION_BLOCKED),
        );
        $this->assertSame(
            AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED,
            CommitGovernancePureMappers::decisionToVerdict(AtlasMergeGovernorAdmissionPolicy::DECISION_REPAIR),
        );
        $this->assertSame(
            AtlasVerificationCourtFalseGreenDetector::VERDICT_FAILED,
            CommitGovernancePureMappers::decisionToVerdict('false_green_replay_contradiction'),
        );
    }

    #[Test]
    public function rollback_posture_conformant_and_blocked(): void
    {
        $this->assertSame(
            'revertible:git_revert_scoped_commit',
            CommitGovernancePureMappers::rollbackPosture([
                'conformant' => true,
                'facts' => ['restore_strategy' => 'git_revert_scoped_commit'],
            ]),
        );
        $this->assertSame(
            'revertible:custom_restore',
            CommitGovernancePureMappers::rollbackPosture([
                'conformant' => true,
                'facts' => ['restore_strategy' => 'custom_restore'],
            ]),
        );
        $this->assertSame(
            'blocked:missing_pre_image,scope_drift',
            CommitGovernancePureMappers::rollbackPosture([
                'conformant' => false,
                'blockers' => ['missing_pre_image', 'scope_drift'],
            ]),
        );
        $this->assertSame(
            'blocked:rollback_not_conformant',
            CommitGovernancePureMappers::rollbackPosture(['conformant' => false]),
        );
    }

    #[Test]
    public function ledger_error_blockers_flags_error_statuses(): void
    {
        $this->assertSame([], CommitGovernancePureMappers::ledgerErrorBlockers([
            'verdict_ledger' => 'appended',
            'release_ledger' => 'appended',
        ]));
        $this->assertSame(
            ['verdict_ledger_error'],
            CommitGovernancePureMappers::ledgerErrorBlockers([
                'verdict_ledger' => 'error',
                'release_ledger' => 'ok',
            ]),
        );
        $this->assertSame(
            ['release_ledger_error'],
            CommitGovernancePureMappers::ledgerErrorBlockers([
                'verdict_ledger' => 'ok',
                'release_ledger' => 'error:RuntimeException',
            ]),
        );
        $this->assertSame(
            ['verdict_ledger_error', 'release_ledger_error'],
            CommitGovernancePureMappers::ledgerErrorBlockers([]),
        );
    }

    #[Test]
    public function missing_rerun_only_flags_observed_non_pass_non_exempt(): void
    {
        $required = ['php_lint', 'unit_suite', 'gate_x', 'gate_y', 'gate_z'];
        $checks = [
            'php_lint' => 'pass',
            'unit_suite' => 'fail',
            'gate_x' => 'skip_infra',
            'gate_y' => 'fail_unattributed_open',
            'gate_z' => 'FAIL_OPEN_RUNNER_ERROR',
            // required check absent entirely → leave alone
        ];

        $this->assertSame(
            ['unit_suite'],
            CommitGovernancePureMappers::missingRerun($required, $checks),
        );
        $this->assertSame([], CommitGovernancePureMappers::missingRerun([], $checks));
        $this->assertSame([], CommitGovernancePureMappers::missingRerun(['never_ran'], ['php_lint' => 'pass']));
    }

    #[Test]
    public function deterministic_hash_is_stable_sha256_of_json(): void
    {
        $payload = [
            'changed' => [
                'app/Services/Ai/SelfConstruction/Support/CommitGovernancePureMappers.php',
            ],
            'green' => true,
        ];
        $expected = hash(
            'sha256',
            (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        $this->assertSame($expected, CommitGovernancePureMappers::deterministicHash($payload));
        $this->assertSame(
            CommitGovernancePureMappers::deterministicHash($payload),
            CommitGovernancePureMappers::deterministicHash($payload),
        );
        $this->assertNotSame(
            CommitGovernancePureMappers::deterministicHash($payload),
            CommitGovernancePureMappers::deterministicHash(['green' => false]),
        );
    }
}
