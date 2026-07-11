<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\EngineeringKernel;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\EngineeringKernel\AuthorizedMergeAction;
use App\Services\Ai\EngineeringKernel\CanonicalReleaseAuthorizationRequest;
use App\Services\Ai\EngineeringKernel\KernelEvidenceAuthority;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\SelfConstruction\AtlasTaskScopedCommitter;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskMergeActuator;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use App\Services\Ai\SelfConstruction\VerificationCourt\AtlasVerificationCourtVerdictLedger;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
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

    public function test_release_signer_rejects_unpersisted_decision_and_has_no_array_api(): void
    {
        $parameter = (new \ReflectionMethod(KernelEvidenceAuthority::class, 'issueReleaseAuthorization'))->getParameters()[0];
        $this->assertSame(CanonicalReleaseAuthorizationRequest::class, $parameter->getType()?->getName());

        $request = new CanonicalReleaseAuthorizationRequest(
            releaseLedgerPath: $this->repo.'/missing-governor.jsonl', decisionHash: str_repeat('a', 64),
            files: ['app/target.txt'], scopeHash: str_repeat('b', 64), baseCommit: str_repeat('c', 40),
            treeHash: str_repeat('d', 64), leaseId: 'lease', leaseOwner: 'owner', fencingToken: 1,
            nonce: 'nonce', issuedAt: date(DATE_ATOM), expiresAt: date(DATE_ATOM, time() + 300), context: [],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->app->make(KernelEvidenceAuthority::class)->issueReleaseAuthorization($request);
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
        $chain = new AtlasTaskCommitGovernanceChain(
            verdictLedger: new AtlasVerificationCourtVerdictLedger($this->repo.'/verdict.jsonl'),
            releaseLedger: new AtlasMergeGovernorReleaseDecisionLedger($this->repo.'/release.jsonl'),
            modeOverride: AtlasTaskCommitGovernanceChain::MODE_ENFORCE,
            evidenceLedger: $ledger,
        );
        $governed = $chain->govern([
            'task_packet_id' => 'packet-commit', 'project_id' => 'atlas-server',
            'changed_files' => ['app/target.txt'],
            'verification' => ['passed' => true, 'evidence_hash' => hash('sha256', 'verified')],
            'base_commit' => trim($this->git(['rev-parse', 'HEAD'])),
            'tree_hash' => hash('sha256', $this->git(['diff', '--binary', '--', 'app/target.txt'])),
            'lease_id' => 'lease-1', 'lease_owner' => 'owner-1', 'fencing_token' => 4,
        ]);
        $this->assertTrue($governed['admitted'], json_encode($governed, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return AuthorizedMergeAction::fromArray($governed['authorized_merge_action']);
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

    public function eventById(string $eventId): ?AtlasLedgerEvent
    {
        return $this->inner->eventById($eventId);
    }

    public function latestForCorrelation(string $correlationId, ?string $eventName = null): ?AtlasLedgerEvent
    {
        return $this->inner->latestForCorrelation($correlationId, $eventName);
    }

    public function eventIntegrityValid(AtlasLedgerEvent $event): bool
    {
        return $this->inner->eventIntegrityValid($event);
    }
}
