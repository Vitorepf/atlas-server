<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\EngineeringKernel\Adapters\TaskLaneMergeActuatorAdapter;
use App\Services\Ai\EngineeringKernel\AuthorizedMergeAction;
use App\Services\Ai\EngineeringKernel\AuthorizedRevertAction;
use App\Services\Ai\EngineeringKernel\CanarySettlementRequest;
use App\Services\Ai\EngineeringKernel\CanonicalReleaseAuthorizationRequest;
use App\Services\Ai\EngineeringKernel\EliteExecutorKernel;
use App\Services\Ai\EngineeringKernel\EngineeringOutcome;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\KernelEvidenceAuthority;
use App\Services\Ai\EngineeringKernel\MergeActuator;
use App\Services\Ai\Kernel\Decision\DecisionReceiptRuntimeGuard;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskMergeActuator;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskPostLandCanarySentinel;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtVerdictLedger;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class CanonicalCommitActuationTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_07_09_160000_repair_missing_atlas_ledger_events_table.php'))->up();
        $this->repo = sys_get_temp_dir().'/atlas_canonical_commit_'.uniqid('', true);
        mkdir($this->repo, 0775, true);
        $this->git(['init', '-b', 'main']);
        $this->git(['config', 'user.email', 'atlas@example.test']);
        $this->git(['config', 'user.name', 'Atlas Test']);
        mkdir($this->repo.'/app', 0775, true);
        file_put_contents($this->repo.'/app/target.txt', "before\n");
        file_put_contents($this->repo.'/unrelated.txt', "before\n");
        $this->git(['add', '.']);
        $this->git(['commit', '-m', 'baseline']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_ledger_events');
        (new Process(['rm', '-rf', $this->repo]))->run();
        parent::tearDown();
    }

    public function test_exact_verified_candidate_lands_and_preserves_unrelated_dirty_work(): void
    {
        file_put_contents($this->repo.'/app/target.txt', "after\n");
        file_put_contents($this->repo.'/unrelated.txt', "operator-wip\n");
        $ledger = $this->app->make(AtlasEvidenceLedger::class);
        $action = $this->authorizedAction($ledger);
        $actuator = new AtlasTaskMergeActuator(
            repoRootOverride: $this->repo,
            evidenceLedger: $ledger,
            scopedCommitter: new AtlasTaskScopedCommitter(repoRootOverride: $this->repo),
            allowedFilesResolver: static fn (): array => ['app/target.txt'],
            leaseValidator: static fn (string $leaseId, int $fence): bool => $leaseId === 'lease-1' && $fence === 4,
        );

        $result = $actuator->act($action);

        $this->assertTrue($result['committed']);
        $this->assertFalse($result['resolved']);
        $this->assertSame('landed_pending_canary', $result['status']);
        $this->assertSame(['app/target.txt'], $result['files']);
        $this->assertStringContainsString('unrelated.txt', $this->git(['status', '--porcelain']));
        $this->assertSame("before\n", $this->git(['show', 'HEAD^:unrelated.txt']));
        $this->assertNotNull($ledger->latestForCorrelation($action->nonce, 'release.landed'));
    }

    public function test_kernel_settles_only_after_canonical_canary_and_replays_without_duplicate_effect(): void
    {
        file_put_contents($this->repo.'/app/target.txt', "after\n");
        $ledger = $this->app->make(AtlasEvidenceLedger::class);
        $action = $this->authorizedAction($ledger);
        $actuator = new AtlasTaskMergeActuator(repoRootOverride: $this->repo, evidenceLedger: $ledger,
            scopedCommitter: new AtlasTaskScopedCommitter(repoRootOverride: $this->repo),
            allowedFilesResolver: static fn (): array => ['app/target.txt'],
            leaseValidator: static fn (string $leaseId, int $fence): bool => $leaseId === 'lease-1' && $fence === 4);
        $landed = $actuator->act($action);
        $landedEvent = AtlasLedgerEvent::query()->findOrFail((string) $landed['settlement_event_id']);
        $provisional = $this->provisionalOutcomeEvent($action);
        $request = CanarySettlementRequest::fromCanonicalLanded($action, $landedEvent, $provisional, hash('sha256', 'order'),
            'delivery-test', hash('sha256', 'evidence'), 'test-canary-observer');
        $sentinel = $this->createMock(AtlasTaskPostLandCanarySentinel::class);
        $sentinel->expects($this->once())->method('observe')->willReturn(['verdict' => 'canary_pass', 'revert_candidate' => [],
            'checks' => ['focused' => 'passed'], 'ledger_status' => 'ok']);
        $mechanical = $this->createMock(MergeActuator::class);
        $mechanical->expects($this->never())->method('revert');
        $kernel = $this->app->make(EliteExecutorKernel::class);

        $first = $kernel->settleLandedRelease($request, $mechanical, $sentinel);
        $replay = $kernel->settleLandedRelease($request, $mechanical, $sentinel);
        $this->travel(25)->hours();
        $durableReplay = $kernel->settleLandedRelease($request, $mechanical, $sentinel);

        $this->assertSame('settled', $first['status']);
        $this->assertSame('released', $first['engineering_outcome']['status']);
        $this->assertSame($request->deliveryId, $first['engineering_outcome']['delivery_id']);
        $this->assertSame($request->orderHash, $first['engineering_outcome']['correlated_hashes']['order']);
        $provisional = $ledger->eventById($request->provisionalOutcomeEventId);
        $this->assertEquals(data_get($provisional?->payload, 'outcome.role_dispositions'),
            $first['engineering_outcome']['role_dispositions']);
        $this->assertTrue($first['canary_before_terminal']);
        $this->assertTrue($replay['replayed']);
        $this->assertSame($first['engineering_outcome']['outcome_hash'], $replay['engineering_outcome']['outcome_hash']);
        $this->assertTrue($durableReplay['replayed']);
        $this->assertSame('released', $durableReplay['engineering_outcome']['status']);
        $this->assertSame($first['engineering_outcome']['outcome_hash'], $durableReplay['engineering_outcome']['outcome_hash']);
        $event = $ledger->eventById('canary-'.substr(hash('sha256', 'terminal:'.$request->idempotencyHash()), 0, 24));
        $this->assertNotNull($event);
        $this->assertTrue($this->app->make(KernelEvidenceAuthority::class)->verifyEvent($event, 'terminal_outcome'));
    }

    public function test_attributed_canary_failure_uses_separately_authorized_revert_once(): void
    {
        file_put_contents($this->repo.'/app/target.txt', "after\n");
        $ledger = $this->app->make(AtlasEvidenceLedger::class);
        $action = $this->authorizedAction($ledger);
        $landingActuator = new AtlasTaskMergeActuator(repoRootOverride: $this->repo, evidenceLedger: $ledger,
            scopedCommitter: new AtlasTaskScopedCommitter(repoRootOverride: $this->repo),
            allowedFilesResolver: static fn (): array => ['app/target.txt'],
            leaseValidator: static fn (string $leaseId, int $fence): bool => $leaseId === 'lease-1' && $fence === 4);
        $landed = $landingActuator->act($action);
        $landedEvent = AtlasLedgerEvent::query()->findOrFail((string) $landed['settlement_event_id']);
        $provisional = $this->provisionalOutcomeEvent($action);
        $request = CanarySettlementRequest::fromCanonicalLanded($action, $landedEvent, $provisional, hash('sha256', 'order'),
            'delivery-test', hash('sha256', 'evidence'), 'test-canary-observer');
        $sentinel = $this->createMock(AtlasTaskPostLandCanarySentinel::class);
        $sentinel->method('observe')->willReturn(['verdict' => 'canary_fail_attributed', 'revert_candidate' => [],
            'checks' => ['focused' => 'failed'], 'ledger_status' => 'ok']);
        $mechanical = new TaskLaneMergeActuatorAdapter(new AtlasTaskMergeActuator(
            repoRootOverride: $this->repo,
            ledgerOverride: new AtlasMergeGovernorReleaseDecisionLedger($action->releaseLedgerPath),
            evidenceLedger: $ledger,
            allowedFilesResolver: static fn (): array => ['app/target.txt'],
            leaseValidator: static fn (string $leaseId, int $fence): bool => $leaseId === 'lease-1' && $fence === 4,
            kernelEvidenceAuthority: new KernelEvidenceAuthority($ledger,
                $this->app->make(DecisionReceiptRuntimeGuard::class),
                new AtlasMergeGovernorReleaseDecisionLedger($action->releaseLedgerPath)),
        ));
        $result = $this->app->make(EliteExecutorKernel::class)->settleLandedRelease($request, $mechanical, $sentinel);

        $this->assertSame('reverted', $result['status'], json_encode($result));
        $this->assertTrue($result['resolved']);
        $this->assertSame('reverted', $result['engineering_outcome']['status']);
    }

    public function test_inconclusive_canary_is_durably_quarantined_without_revert(): void
    {
        [$request] = $this->landedCanaryRequest();
        $sentinel = $this->createMock(AtlasTaskPostLandCanarySentinel::class);
        $sentinel->method('observe')->willReturn(['verdict' => 'canary_inconclusive', 'ledger_status' => 'ok']);
        $mechanical = $this->createMock(MergeActuator::class);
        $mechanical->expects($this->never())->method('revert');

        $result = $this->app->make(EliteExecutorKernel::class)->settleLandedRelease($request, $mechanical, $sentinel);

        $this->assertSame('release_uncertain', $result['status']);
        $this->assertTrue($result['quarantined']);
        $this->assertSame('release_uncertain', $result['engineering_outcome']['status']);
        $this->assertNotNull($this->app->make(AtlasEvidenceLedger::class)->eventById(
            'canary-'.substr(hash('sha256', 'terminal:'.$request->idempotencyHash()), 0, 24),
        ));
    }

    public function test_diagnostic_ledger_failure_is_durably_quarantined_without_revert(): void
    {
        [$request] = $this->landedCanaryRequest();
        $sentinel = $this->createMock(AtlasTaskPostLandCanarySentinel::class);
        $sentinel->method('observe')->willReturn(['verdict' => 'canary_pass', 'ledger_status' => 'error']);
        $mechanical = $this->createMock(MergeActuator::class);
        $mechanical->expects($this->never())->method('revert');

        $result = $this->app->make(EliteExecutorKernel::class)->settleLandedRelease($request, $mechanical, $sentinel);

        $this->assertSame('release_uncertain', $result['status']);
        $this->assertTrue($result['quarantined']);
    }

    public function test_failed_revert_is_release_uncertain_and_replay_does_not_repeat_effect(): void
    {
        [$request] = $this->landedCanaryRequest();
        $sentinel = $this->createMock(AtlasTaskPostLandCanarySentinel::class);
        $sentinel->expects($this->once())->method('observe')->willReturn([
            'verdict' => 'canary_fail_attributed', 'ledger_status' => 'ok',
        ]);
        $mechanical = $this->createMock(MergeActuator::class);
        $mechanical->expects($this->once())->method('prepareRevert')->with($request)->willReturn(null);
        $mechanical->expects($this->never())->method('act');
        $kernel = $this->app->make(EliteExecutorKernel::class);

        $first = $kernel->settleLandedRelease($request, $mechanical, $sentinel);
        $replay = $kernel->settleLandedRelease($request, $mechanical, $sentinel);

        $this->assertSame('release_uncertain', $first['status']);
        $this->assertFalse($first['resolved']);
        $this->assertTrue($replay['replayed']);
    }

    public function test_revert_exception_is_durably_release_uncertain(): void
    {
        [$request] = $this->landedCanaryRequest();
        $sentinel = $this->createMock(AtlasTaskPostLandCanarySentinel::class);
        $sentinel->method('observe')->willReturn(['verdict' => 'canary_fail_attributed', 'ledger_status' => 'ok']);
        $mechanical = $this->createMock(MergeActuator::class);
        $mechanical->method('prepareRevert')->willThrowException(new \RuntimeException('revert unavailable'));
        $mechanical->expects($this->never())->method('act');

        $result = $this->app->make(EliteExecutorKernel::class)->settleLandedRelease($request, $mechanical, $sentinel);

        $this->assertSame('release_uncertain', $result['status']);
        $this->assertStringStartsWith('revert_failed:', $result['reason']);
    }

    public function test_cosmetic_typed_revert_without_canonical_revert_authority_has_zero_effect(): void
    {
        [$request] = $this->landedCanaryRequest();
        $payload = array_replace($request->action->toArray(), [
            'action' => 'revert_task', 'target_sha' => $request->landedSha, 'authority_hash' => '',
        ]);
        $cosmetic = new AuthorizedRevertAction(
            AuthorizedMergeAction::fromArray($payload), $request->idempotencyHash(), $request->landedEventId,
        );
        $sentinel = $this->createMock(AtlasTaskPostLandCanarySentinel::class);
        $sentinel->method('observe')->willReturn(['verdict' => 'canary_fail_attributed', 'ledger_status' => 'ok']);
        $mechanical = $this->createMock(MergeActuator::class);
        $mechanical->method('prepareRevert')->willReturn($cosmetic);
        $mechanical->expects($this->never())->method('act');

        $result = $this->app->make(EliteExecutorKernel::class)->settleLandedRelease($request, $mechanical, $sentinel);

        $this->assertSame('release_uncertain', $result['status']);
        $this->assertSame('revert_authority_invalid', $result['reason']);
    }

    public function test_legacy_jsonl_revert_action_without_canonical_hmac_has_zero_git_effect(): void
    {
        [$request] = $this->landedCanaryRequest();
        $actuator = new AtlasTaskMergeActuator(
            repoRootOverride: $this->repo,
            ledgerOverride: new AtlasMergeGovernorReleaseDecisionLedger($request->action->releaseLedgerPath),
            evidenceLedger: $this->app->make(AtlasEvidenceLedger::class),
            allowedFilesResolver: static fn (): array => ['app/target.txt'],
        );
        $legacy = $actuator->prepareAuthorizedRevert($request->action->taskPacketId, false);
        $action = AuthorizedMergeAction::fromArray((array) $legacy['authorized_merge_action']);
        $head = trim($this->git(['rev-parse', 'HEAD']));

        $result = $actuator->act($action);

        $this->assertSame('', $action->canonicalEventId);
        $this->assertTrue($result['zero_effect']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_AUTHORITY_NOT_PERSISTED, $result['reason']);
        $this->assertSame($head, trim($this->git(['rev-parse', 'HEAD'])));
    }

    public function test_direct_revert_rejects_substituted_canonical_event_hash(): void
    {
        [$request] = $this->landedCanaryRequest();
        $ledger = $this->app->make(AtlasEvidenceLedger::class);
        $releaseLedger = new AtlasMergeGovernorReleaseDecisionLedger($request->action->releaseLedgerPath);
        $actuator = new AtlasTaskMergeActuator(
            repoRootOverride: $this->repo, ledgerOverride: $releaseLedger, evidenceLedger: $ledger,
            allowedFilesResolver: static fn (): array => ['app/target.txt'],
            leaseValidator: static fn (): bool => true,
            kernelEvidenceAuthority: new KernelEvidenceAuthority($ledger,
                $this->app->make(DecisionReceiptRuntimeGuard::class), $releaseLedger),
        );
        $authorized = $actuator->prepareRevert($request);
        $this->assertNotNull($authorized);
        $payload = array_replace($authorized->action->toArray(), [
            'canonical_event_hash' => hash('sha256', 'substituted-canonical-event-hash'), 'authority_hash' => '',
        ]);
        $head = trim($this->git(['rev-parse', 'HEAD']));

        $result = $actuator->act(AuthorizedMergeAction::fromArray($payload));

        $this->assertTrue($result['zero_effect']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_AUTHORITY_NOT_PERSISTED, $result['reason']);
        $this->assertSame($head, trim($this->git(['rev-parse', 'HEAD'])));
    }

    public function test_crash_after_observed_effect_quarantines_replay_without_duplicate_revert(): void
    {
        [$request] = $this->landedCanaryRequest();
        $this->app->make(KernelEvidenceAuthority::class)->issueCanaryObservation($request, [
            'verdict' => 'canary_fail_attributed', 'status' => 'pending',
        ], 'observed');
        $sentinel = $this->createMock(AtlasTaskPostLandCanarySentinel::class);
        $sentinel->expects($this->never())->method('observe');
        $mechanical = $this->createMock(MergeActuator::class);
        $mechanical->expects($this->never())->method('revert');

        $result = $this->app->make(EliteExecutorKernel::class)->settleLandedRelease($request, $mechanical, $sentinel);

        $this->assertSame('release_uncertain', $result['status']);
        $this->assertTrue($result['quarantined']);
        $this->assertTrue($result['replayed']);
    }

    #[DataProvider('nonEffectCanaryVerdicts')]
    public function test_crash_after_pass_or_inconclusive_observation_never_reruns_sentinel(string $verdict): void
    {
        [$request] = $this->landedCanaryRequest();
        $this->app->make(KernelEvidenceAuthority::class)->issueCanaryObservation($request, [
            'verdict' => $verdict, 'status' => 'pending',
        ], 'observed');
        $sentinel = $this->createMock(AtlasTaskPostLandCanarySentinel::class);
        $sentinel->expects($this->never())->method('observe');
        $mechanical = $this->createMock(MergeActuator::class);
        $mechanical->expects($this->never())->method('revert');

        $result = $this->app->make(EliteExecutorKernel::class)->settleLandedRelease($request, $mechanical, $sentinel);

        $this->assertSame('release_uncertain', $result['status']);
        $this->assertTrue($result['quarantined']);
        $this->assertTrue($result['replayed']);
    }

    /** @return array<string,array{string}> */
    public static function nonEffectCanaryVerdicts(): array
    {
        return ['pass' => ['canary_pass'], 'inconclusive' => ['canary_inconclusive']];
    }

    public function test_canary_settlement_rejects_every_action_binding_substituted_after_canonical_landing(): void
    {
        [$request] = $this->landedCanaryRequest();
        $sentinel = $this->createMock(AtlasTaskPostLandCanarySentinel::class);
        $sentinel->expects($this->never())->method('observe');
        $mechanical = $this->createMock(MergeActuator::class);
        $mechanical->expects($this->never())->method('revert');
        $mutations = [
            ['candidate_hash' => hash('sha256', 'substituted-candidate')],
            ['decision_hash' => hash('sha256', 'substituted-decision')],
            ['verification_hash' => hash('sha256', 'substituted-verification')],
            ['rollback_hash' => hash('sha256', 'substituted-rollback')],
            ['files' => ['app/substituted.txt']],
            ['scope_hash' => hash('sha256', 'substituted-scope')],
            ['base_commit' => str_repeat('1', 40)],
            ['tree_hash' => hash('sha256', 'substituted-tree')],
            ['lease_id' => 'substituted-lease'],
            ['fencing_token' => 99],
            ['nonce' => 'substituted-nonce'],
            ['canonical_event_id' => 'substituted-event'],
            ['canonical_event_hash' => hash('sha256', 'substituted-event')],
            ['metadata' => array_replace($request->action->metadata, ['lease_owner' => 'substituted-owner'])],
            ['order_hash' => hash('sha256', 'substituted-order')],
            ['delivery_id' => 'substituted-delivery'],
            ['evidence_hash' => hash('sha256', 'substituted-evidence')],
        ];
        foreach ($mutations as $mutation) {
            $payload = array_replace($request->action->toArray(), $mutation, ['authority_hash' => '']);
            $substituted = CanarySettlementRequest::fromLanded(
                AuthorizedMergeAction::fromArray($payload), $request->landedEventId, $request->landedEventHash,
                $request->landedSha, $request->orderHash, $request->deliveryId, $request->evidenceHash,
                $request->provisionalOutcomeEventId, $request->provisionalOutcomeEventHash, $request->provisionalOutcomeHash,
                $request->observerIdentity,
            );
            $result = $this->app->make(EliteExecutorKernel::class)->settleLandedRelease($substituted, $mechanical, $sentinel);

            $this->assertSame('release_uncertain', $result['status'], json_encode($mutation));
            $this->assertSame('landed_release_binding_invalid', $result['reason'], json_encode($mutation));
        }
    }

    #[DataProvider('invalidProvisionalOutcomeRefs')]
    public function test_canary_settlement_rejects_invalid_provisional_outcome_before_observer(array $mutation): void
    {
        [$request] = $this->landedCanaryRequest();
        $refs = array_replace([
            'event_id' => $request->provisionalOutcomeEventId,
            'event_hash' => $request->provisionalOutcomeEventHash,
            'outcome_hash' => $request->provisionalOutcomeHash,
        ], $mutation);
        $invalid = CanarySettlementRequest::fromLanded(
            $request->action, $request->landedEventId, $request->landedEventHash, $request->landedSha,
            $request->orderHash, $request->deliveryId, $request->evidenceHash,
            $refs['event_id'], $refs['event_hash'], $refs['outcome_hash'], $request->observerIdentity,
        );
        $sentinel = $this->createMock(AtlasTaskPostLandCanarySentinel::class);
        $sentinel->expects($this->never())->method('observe');
        $mechanical = $this->createMock(MergeActuator::class);
        $mechanical->expects($this->never())->method('act');

        $result = $this->app->make(EliteExecutorKernel::class)->settleLandedRelease($invalid, $mechanical, $sentinel);

        $this->assertSame('release_uncertain', $result['status']);
        $this->assertSame('provisional_engineering_outcome_invalid', $result['reason']);
        $this->assertArrayNotHasKey('engineering_outcome', $result);
    }

    /** @return array<string,array{array<string,string>}> */
    public static function invalidProvisionalOutcomeRefs(): array
    {
        return [
            'absent' => [['event_id' => 'missing-provisional-event']],
            'foreign event hash' => [['event_hash' => hash('sha256', 'foreign-event')]],
            'tampered outcome hash' => [['outcome_hash' => hash('sha256', 'tampered-outcome')]],
        ];
    }

    public function test_release_signer_rejects_unpersisted_decision_and_has_no_array_api(): void
    {
        $parameter = (new \ReflectionMethod(KernelEvidenceAuthority::class, 'issueReleaseAuthorization'))->getParameters()[0];
        $this->assertSame(CanonicalReleaseAuthorizationRequest::class, $parameter->getType()?->getName());

        $request = new CanonicalReleaseAuthorizationRequest(
            decisionHash: str_repeat('a', 64), taskPacketId: 'task', candidateHash: str_repeat('e', 64),
            verificationHash: str_repeat('f', 64), rollbackHash: str_repeat('0', 64),
            files: ['app/target.txt'], scopeHash: str_repeat('b', 64), baseCommit: str_repeat('c', 40),
            treeHash: str_repeat('d', 64), leaseId: 'lease', leaseOwner: 'owner', fencingToken: 1,
            nonce: 'nonce', issuedAt: date(DATE_ATOM), expiresAt: date(DATE_ATOM, time() + 300), context: [],
        );

        $this->expectException(InvalidArgumentException::class);
        $authority = new KernelEvidenceAuthority(
            $this->app->make(AtlasEvidenceLedger::class),
            $this->app->make(DecisionReceiptRuntimeGuard::class),
            new AtlasMergeGovernorReleaseDecisionLedger($this->repo.'/missing-governor.jsonl'),
        );
        $authority->issueReleaseAuthorization($request);
    }

    public function test_release_signer_rejects_alternate_ledger_and_every_substituted_prepare_binding(): void
    {
        $trusted = new AtlasMergeGovernorReleaseDecisionLedger($this->repo.'/trusted-release.jsonl');
        $binding = [
            'task_packet_id' => 'task-bound', 'action' => 'commit', 'candidate_hash' => str_repeat('1', 64),
            'verification_hash' => str_repeat('2', 64), 'rollback_hash' => str_repeat('3', 64),
            'changed_files' => ['app/target.txt'], 'scope_hash' => str_repeat('4', 64),
            'base_commit' => str_repeat('5', 40), 'tree_hash' => str_repeat('6', 64),
            'lease_id' => 'lease-bound', 'lease_owner' => 'owner-bound', 'fencing_token' => 9,
        ];
        $row = $trusted->append([
            'task_packet_id' => $binding['task_packet_id'], 'candidate_hash' => $binding['candidate_hash'],
            'decision' => 'admitted', 'reasons' => [], 'risk_level' => 'low',
            'verification_hash' => $binding['verification_hash'], 'rollback_hash' => $binding['rollback_hash'],
            'changed_files_hash' => hash('sha256', 'files'), 'project_lane' => ['project_id' => 'atlas-server'],
            'decided_at' => date(DATE_ATOM), 'evidence_refs' => ['verification:test'],
            'rollback_posture' => 'revertible:git_revert_scoped_commit', 'rejected_alternatives' => [],
            'post_release_learning_hooks' => [], 'prepare_binding' => $binding,
        ])['row'];
        $authority = new KernelEvidenceAuthority(
            $this->app->make(AtlasEvidenceLedger::class),
            $this->app->make(DecisionReceiptRuntimeGuard::class),
            $trusted,
        );
        $make = static fn (array $overrides = []): CanonicalReleaseAuthorizationRequest => new CanonicalReleaseAuthorizationRequest(
            decisionHash: (string) ($overrides['decision_hash'] ?? $row['decision_hash']),
            taskPacketId: (string) ($overrides['task_packet_id'] ?? $binding['task_packet_id']),
            candidateHash: (string) ($overrides['candidate_hash'] ?? $binding['candidate_hash']),
            verificationHash: (string) ($overrides['verification_hash'] ?? $binding['verification_hash']),
            rollbackHash: (string) ($overrides['rollback_hash'] ?? $binding['rollback_hash']),
            files: (array) ($overrides['changed_files'] ?? $binding['changed_files']),
            scopeHash: (string) ($overrides['scope_hash'] ?? $binding['scope_hash']),
            baseCommit: (string) ($overrides['base_commit'] ?? $binding['base_commit']),
            treeHash: (string) ($overrides['tree_hash'] ?? $binding['tree_hash']),
            leaseId: (string) ($overrides['lease_id'] ?? $binding['lease_id']),
            leaseOwner: (string) ($overrides['lease_owner'] ?? $binding['lease_owner']),
            fencingToken: (int) ($overrides['fencing_token'] ?? $binding['fencing_token']),
            nonce: 'nonce-bound', issuedAt: date(DATE_ATOM), expiresAt: date(DATE_ATOM, time() + 300), context: [],
        );
        $this->assertNotNull($authority->issueReleaseAuthorization($make()));

        $mutations = [
            ['changed_files' => ['app/other.php']], ['scope_hash' => str_repeat('a', 64)],
            ['base_commit' => str_repeat('b', 40)], ['tree_hash' => str_repeat('c', 64)],
            ['lease_owner' => 'attacker'], ['fencing_token' => 10],
            ['candidate_hash' => str_repeat('d', 64)], ['verification_hash' => str_repeat('e', 64)],
        ];
        foreach ($mutations as $mutation) {
            try {
                $authority->issueReleaseAuthorization($make($mutation));
                $this->fail('substituted binding was signed: '.json_encode($mutation));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        $alternate = new AtlasMergeGovernorReleaseDecisionLedger($this->repo.'/attacker-release.jsonl');
        $attackerRow = $alternate->append(array_replace($row, ['decided_at' => date(DATE_ATOM, time() + 1)]))['row'];
        try {
            $authority->issueReleaseAuthorization($make(['decision_hash' => $attackerRow['decision_hash']]));
            $this->fail('alternate ledger decision was trusted');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    public function test_jsonl_authority_without_canonical_event_has_zero_git_effect(): void
    {
        file_put_contents($this->repo.'/app/target.txt', "after\n");
        $jsonl = new AtlasMergeGovernorReleaseDecisionLedger($this->repo.'/release-only.jsonl');
        $row = $jsonl->append([
            'task_packet_id' => 'packet-jsonl', 'candidate_hash' => hash('sha256', 'candidate'),
            'decision' => 'admitted', 'reasons' => [], 'risk_level' => 'low',
            'verification_hash' => hash('sha256', 'verified'), 'rollback_hash' => hash('sha256', 'rollback'),
            'changed_files_hash' => hash('sha256', 'files'), 'project_lane' => ['project_id' => 'atlas-server'],
            'decided_at' => date(DATE_ATOM), 'evidence_refs' => ['verification:test'],
            'rollback_posture' => 'revertible:git_revert_scoped_commit', 'rejected_alternatives' => [],
            'post_release_learning_hooks' => [],
        ])['row'];
        $action = AuthorizedMergeAction::fromReleaseDecisionRow($row, 'commit', $jsonl->path(), files: ['app/target.txt']);
        $head = trim($this->git(['rev-parse', 'HEAD']));

        $result = (new AtlasTaskMergeActuator(repoRootOverride: $this->repo, evidenceLedger: $this->app->make(AtlasEvidenceLedger::class)))->act($action);

        $this->assertTrue($result['zero_effect']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_AUTHORITY_NOT_PERSISTED, $result['reason']);
        $this->assertSame($head, trim($this->git(['rev-parse', 'HEAD'])));
    }

    public function test_lost_fencing_refuses_before_git_effect(): void
    {
        file_put_contents($this->repo.'/app/target.txt', "after\n");
        $ledger = $this->app->make(AtlasEvidenceLedger::class);
        $action = $this->authorizedAction($ledger);
        $head = trim($this->git(['rev-parse', 'HEAD']));
        $actuator = new AtlasTaskMergeActuator(
            repoRootOverride: $this->repo,
            evidenceLedger: $ledger,
            scopedCommitter: new AtlasTaskScopedCommitter(repoRootOverride: $this->repo),
            allowedFilesResolver: static fn (): array => ['app/target.txt'],
            leaseValidator: static fn (): bool => false,
        );

        $result = $actuator->act($action);

        $this->assertTrue($result['zero_effect']);
        $this->assertSame('lost_lease_or_fencing', $result['reason']);
        $this->assertSame($head, trim($this->git(['rev-parse', 'HEAD'])));
    }

    public function test_generic_self_labelled_release_event_cannot_forge_governor_authority(): void
    {
        file_put_contents($this->repo.'/app/target.txt', "after\n");
        $ledger = $this->app->make(AtlasEvidenceLedger::class);
        $real = $this->authorizedAction($ledger);
        $realEvent = $ledger->eventById($real->canonicalEventId);
        $forgedEvent = $ledger->record(LedgerEventType::ReleaseAuthorized, (array) $realEvent?->payload, [
            'correlation_id' => $real->nonce,
            'scope_type' => 'task_packet', 'scope_id' => $real->taskPacketId,
            'emitter_stage' => 'forged.generic_writer', 'emitter_version' => 'forged.v1',
        ]);
        $payload = $real->toArray();
        $payload['canonical_event_id'] = (string) $forgedEvent?->event_id;
        $payload['canonical_event_hash'] = (string) ($forgedEvent?->event_hash ?: $forgedEvent?->payload_hash);
        $payload['authority_hash'] = '';
        $unsigned = AuthorizedMergeAction::fromArray($payload);
        $payload['authority_hash'] = $unsigned->computeAuthorityHash();
        $forged = AuthorizedMergeAction::fromArray($payload);
        $head = trim($this->git(['rev-parse', 'HEAD']));
        $actuator = new AtlasTaskMergeActuator(
            repoRootOverride: $this->repo, evidenceLedger: $ledger,
            allowedFilesResolver: static fn (): array => ['app/target.txt'],
            scopedCommitter: new AtlasTaskScopedCommitter(repoRootOverride: $this->repo),
            leaseValidator: static fn (): bool => true,
        );

        $result = $actuator->act($forged);

        $this->assertTrue($result['zero_effect']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_AUTHORITY_NOT_PERSISTED, $result['reason']);
        $this->assertSame($head, trim($this->git(['rev-parse', 'HEAD'])));
    }

    public function test_lease_takeover_between_prepare_and_git_effect_is_caught_by_final_replay(): void
    {
        file_put_contents($this->repo.'/app/target.txt', "after\n");
        $ledger = $this->app->make(AtlasEvidenceLedger::class);
        $action = $this->authorizedAction($ledger);
        $head = trim($this->git(['rev-parse', 'HEAD']));
        $checks = 0;
        $actuator = new AtlasTaskMergeActuator(
            repoRootOverride: $this->repo, evidenceLedger: $ledger,
            allowedFilesResolver: static fn (): array => ['app/target.txt'],
            scopedCommitter: new AtlasTaskScopedCommitter(repoRootOverride: $this->repo),
            leaseValidator: static function () use (&$checks): bool {
                $checks++;

                return $checks === 1; // takeover occurs before the mutation-boundary replay
            },
        );

        $result = $actuator->act($action);

        $this->assertFalse($result['committed']);
        $this->assertTrue($result['zero_effect']);
        $this->assertSame('governed_pre_effect_revalidation_failed', $result['reason']);
        $this->assertSame(2, $checks);
        $this->assertSame($head, trim($this->git(['rev-parse', 'HEAD'])));
    }

    public function test_release_authorization_without_ephemeral_credential_scope_is_rejected(): void
    {
        $ledger = $this->app->make(AtlasEvidenceLedger::class);
        $action = $this->authorizedAction($ledger);
        $event = $ledger->eventById($action->canonicalEventId);
        $payload = (array) $event?->payload;
        unset($payload['credential_scope'], $payload['credential_hash']);

        $forged = $ledger->record(LedgerEventType::ReleaseAuthorized, $payload, [
            'correlation_id' => $action->nonce,
            'scope_type' => 'task_packet', 'scope_id' => $action->taskPacketId,
            'emitter_stage' => 'governor.credential_mutation',
        ]);

        $authority = new KernelEvidenceAuthority(
            $ledger,
            $this->app->make(DecisionReceiptRuntimeGuard::class),
            new AtlasMergeGovernorReleaseDecisionLedger($this->repo.'/release.jsonl'),
        );

        $this->assertFalse($authority->verifyReleaseAuthorization($forged));
    }

    public function test_landed_append_failure_after_commit_is_release_uncertain(): void
    {
        file_put_contents($this->repo.'/app/target.txt', "after\n");
        $ledger = $this->app->make(AtlasEvidenceLedger::class);
        $action = $this->authorizedAction($ledger);
        $actuator = new AtlasTaskMergeActuator(
            repoRootOverride: $this->repo,
            evidenceLedger: new LandedAppendFailingEvidenceLedger($ledger),
            scopedCommitter: new AtlasTaskScopedCommitter(repoRootOverride: $this->repo),
            allowedFilesResolver: static fn (): array => ['app/target.txt'],
            leaseValidator: static fn (): bool => true,
        );

        $result = $actuator->act($action);

        $this->assertTrue($result['committed']);
        $this->assertTrue($result['release_uncertain']);
        $this->assertFalse($result['resolved']);
        $this->assertSame(AtlasTaskMergeActuator::REASON_POST_EFFECT_PERSISTENCE_FAILED, $result['reason']);
    }

    public function test_stale_base_and_stale_tree_are_zero_effect(): void
    {
        file_put_contents($this->repo.'/app/target.txt', "after\n");
        $ledger = $this->app->make(AtlasEvidenceLedger::class);
        $baseAction = $this->authorizedAction($ledger);
        file_put_contents($this->repo.'/new.txt', "new\n");
        $this->git(['add', 'new.txt']);
        $this->git(['commit', '-m', 'concurrent landing']);
        $actuator = $this->actuator($ledger, static fn (): bool => true);
        $baseResult = $actuator->act($baseAction);
        $this->assertSame('stale_base_commit', $baseResult['reason']);

        $this->git(['reset', '--hard', 'HEAD^']);
        file_put_contents($this->repo.'/app/target.txt', "after\n");
        $treeAction = $this->authorizedAction($ledger);
        file_put_contents($this->repo.'/app/target.txt', "different-after-authorization\n");
        $treeResult = $actuator->act($treeAction);
        $this->assertSame('stale_candidate_tree', $treeResult['reason']);
        $this->assertTrue($treeResult['zero_effect']);
    }

    public function test_nonce_replay_and_authoritative_scope_mismatch_are_refused(): void
    {
        file_put_contents($this->repo.'/app/target.txt', "after\n");
        $ledger = $this->app->make(AtlasEvidenceLedger::class);
        $action = $this->authorizedAction($ledger);
        $actuator = $this->actuator($ledger, static fn (): bool => true);
        $this->assertTrue($actuator->act($action)['committed']);
        $replay = $actuator->act($action);
        $this->assertSame('authority_nonce_replayed', $replay['reason']);

        $this->git(['reset', '--hard', 'HEAD^']);
        file_put_contents($this->repo.'/app/target.txt', "after\n");
        $scopeAction = $this->authorizedAction($ledger);
        $wrongScope = new AtlasTaskMergeActuator(
            repoRootOverride: $this->repo, evidenceLedger: $ledger,
            allowedFilesResolver: static fn (): array => ['app/target.txt', 'unrelated.txt'],
            scopedCommitter: new AtlasTaskScopedCommitter(repoRootOverride: $this->repo),
            leaseValidator: static fn (): bool => true,
        );
        $scopeResult = $wrongScope->act($scopeAction);
        $this->assertSame(AtlasTaskMergeActuator::REASON_OUT_OF_SCOPE_FILE, $scopeResult['reason']);
        $this->assertTrue($scopeResult['zero_effect']);
    }

    private function authorizedAction(AtlasEvidenceLedger $ledger): AuthorizedMergeAction
    {
        $releaseLedger = new AtlasMergeGovernorReleaseDecisionLedger($this->repo.'/release.jsonl');
        $chain = new AtlasTaskCommitGovernanceChain(
            verdictLedger: new AtlasVerificationCourtVerdictLedger($this->repo.'/verdict.jsonl'),
            releaseLedger: $releaseLedger,
            modeOverride: AtlasTaskCommitGovernanceChain::MODE_ENFORCE,
            evidenceLedger: $ledger,
            kernelEvidenceAuthority: new KernelEvidenceAuthority(
                $ledger,
                $this->app->make(DecisionReceiptRuntimeGuard::class),
                $releaseLedger,
            ),
        );
        $governed = $chain->govern([
            'task_packet_id' => 'packet-commit', 'project_id' => 'atlas-server',
            'changed_files' => ['app/target.txt'],
            'verification' => ['passed' => true, 'evidence_hash' => hash('sha256', 'evidence')],
            'base_commit' => trim($this->git(['rev-parse', 'HEAD'])),
            'tree_hash' => hash('sha256', $this->git(['diff', '--binary', '--', 'app/target.txt'])),
            'lease_id' => 'lease-1', 'lease_owner' => 'owner-1', 'fencing_token' => 4,
            'requires_canary_settlement' => true, 'order_hash' => hash('sha256', 'order'),
            'delivery_id' => 'delivery-test', 'evidence_hash' => hash('sha256', 'evidence'),
        ]);
        $this->assertTrue($governed['admitted'], json_encode($governed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return AuthorizedMergeAction::fromArray($governed['authorized_merge_action']);
    }

    /** @return array{CanarySettlementRequest,AtlasEvidenceLedger} */
    private function landedCanaryRequest(): array
    {
        file_put_contents($this->repo.'/app/target.txt', "after\n");
        $ledger = $this->app->make(AtlasEvidenceLedger::class);
        $action = $this->authorizedAction($ledger);
        $landed = $this->actuator($ledger, static fn (string $leaseId, int $fence): bool => $leaseId === 'lease-1' && $fence === 4)
            ->act($action);
        $event = AtlasLedgerEvent::query()->findOrFail((string) $landed['settlement_event_id']);
        $provisional = $this->provisionalOutcomeEvent($action);

        return [CanarySettlementRequest::fromCanonicalLanded(
            $action, $event, $provisional, hash('sha256', 'order'), 'delivery-test', hash('sha256', 'evidence'), 'test-canary-observer',
        ), $ledger];
    }

    private function provisionalOutcomeEvent(AuthorizedMergeAction $action): AtlasLedgerEvent
    {
        $dispositions = [];
        foreach (EngineeringRoleRoster::OFFICIAL_ROLES as $role) {
            $dispositions[$role] = ['status' => 'pass', 'evidence_hash' => hash('sha256', $role),
                'signature' => hash('sha256', 'signature:'.$role)];
        }
        $hashes = ['order' => $action->orderHash, 'intent' => hash('sha256', 'intent'),
            'spec' => hash('sha256', 'spec'), 'world' => hash('sha256', 'world'), 'baseline' => hash('sha256', 'baseline'),
            'diff' => hash('sha256', 'diff'), 'evidence' => $action->evidenceHash,
            'release' => hash('sha256', 'release-pending:'.$action->nonce)];
        $data = ['schema_version' => 'atlas.engineering_outcome.v2', 'run_id' => 'run-test',
            'delivery_id' => $action->deliveryId, 'status' => 'held', 'correlated_hashes' => $hashes,
            'role_dispositions' => $dispositions, 'evidence_bundle' => ['hash' => $hashes['evidence']],
            'provider_receipt' => ['status' => 'accepted'], 'sandbox_receipt' => ['status' => 'accepted'],
            'release_receipt' => ['status' => 'pending_canary', 'hash' => $hashes['release']],
            'canary_rollback_receipt' => ['status' => 'pending'], 'operator_effort' => ['active_seconds' => 0],
            'cost' => ['amount' => 0, 'currency' => 'USD'], 'tokens' => ['input' => 0, 'output' => 0],
            'elapsed_ms' => 1, 'uncertainties' => ['release_pending_canary'],
            'observation_schedule' => array_fill_keys(EngineeringOutcome::WINDOWS, 'pending'), 'claim_eligible' => false];
        $data['evidence_bundle']['authority'] = $this->app->make(KernelEvidenceAuthority::class)->sealOutcome($data);
        $outcome = EngineeringOutcome::fromArray($data);

        return $this->app->make(KernelEvidenceAuthority::class)->issueProvisionalOutcome($outcome, $action);
    }

    private function actuator(AtlasEvidenceLedger $ledger, \Closure $leaseValidator): AtlasTaskMergeActuator
    {
        return new AtlasTaskMergeActuator(
            repoRootOverride: $this->repo, evidenceLedger: $ledger,
            allowedFilesResolver: static fn (): array => ['app/target.txt'],
            scopedCommitter: new AtlasTaskScopedCommitter(repoRootOverride: $this->repo),
            leaseValidator: $leaseValidator,
        );
    }

    /** @param list<string> $args */
    private function git(array $args): string
    {
        $process = new Process(array_merge(['git'], $args), $this->repo);
        $process->mustRun();

        return $process->getOutput();
    }
}

final class LandedAppendFailingEvidenceLedger extends AtlasEvidenceLedger
{
    public function __construct(private readonly AtlasEvidenceLedger $inner) {}

    public function record(LedgerEventType $type, array $payload, array $context = []): ?AtlasLedgerEvent
    {
        return $type === LedgerEventType::ReleaseLanded ? null : $this->inner->record($type, $payload, $context);
    }

    public function eventById(string $eventId, ?string $tenantId = null): ?AtlasLedgerEvent
    {
        return $this->inner->eventById($eventId, $tenantId);
    }

    public function latestForCorrelation(string $correlationId, ?string $eventName = null, ?string $tenantId = null): ?AtlasLedgerEvent
    {
        return $this->inner->latestForCorrelation($correlationId, $eventName, $tenantId);
    }

    public function eventIntegrityValid(AtlasLedgerEvent $event): bool
    {
        return $this->inner->eventIntegrityValid($event);
    }
}
