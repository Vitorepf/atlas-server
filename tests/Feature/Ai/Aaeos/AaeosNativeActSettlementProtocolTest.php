<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\EngineeringKernel\AuthorizedMergeAction;
use App\Services\Ai\EngineeringKernel\CanarySettlementRequest;
use App\Services\Ai\Governance\GovernanceConsultSkipCounter;
use App\Services\Ai\Governance\ProviderGovernanceCoverageLedger;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionHermeticSandboxApplyService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P1b.2 path-core: R70 observed write-set, LAND nonce / SETTLE hash bindings,
 * skip_counter dual-write into CoverageLedger.
 */
final class AaeosNativeActSettlementProtocolTest extends TestCase
{
    public function test_r70_false_read_only_claim_still_observes_writes(): void
    {
        $sandbox = new AtlasSelfConstructionHermeticSandboxApplyService;
        $key = 'p1b2-r70-'.bin2hex(random_bytes(4));
        $path = 'app/R70Probe.php';
        $result = $sandbox->execute([
            'idempotency_key' => $key,
            'read_only' => true,
            'allowed_files' => [$path],
            'patch_plan' => [
                'allowed_files' => [$path],
                'patches' => [[
                    'path' => $path,
                    'mode' => 'create',
                    'next' => "<?php\nreturn 'observed';\n",
                ]],
            ],
        ]);

        $this->assertTrue((bool) ($result['applied'] ?? false), json_encode($result));
        $this->assertNotEmpty($result['observed_write_set'] ?? []);
        $this->assertTrue((bool) ($result['read_only_claim'] ?? false));
        $this->assertTrue((bool) ($result['read_only_claim_false'] ?? false));
        $this->assertSame('read_only_claim_false_observed_write', $result['reason'] ?? null);
        $this->assertContains($path, $result['observed_write_set']);
    }

    public function test_settle_bindings_include_land_nonce_and_canary_idempotency_hash(): void
    {
        $action = new AuthorizedMergeAction(
            action: 'commit',
            taskPacketId: 'pkt-p1b2',
            candidateHash: str_repeat('a', 64),
            decisionHash: str_repeat('b', 64),
            releaseLedgerPath: '/tmp/release-ledger.jsonl',
            targetSha: str_repeat('c', 40),
            files: ['app/X.php'],
            riskLevel: 'medium',
            verificationHash: str_repeat('d', 64),
            rollbackHash: str_repeat('e', 64),
            changedFilesHash: str_repeat('f', 64),
            issuedAt: now()->toIso8601String(),
            expiresAt: now()->addHour()->toIso8601String(),
            revoked: false,
            settlementLedgerPath: '/tmp/settlement.jsonl',
            metadata: [],
            authorityHash: str_repeat('1', 64),
            canonicalEventId: 'evt-auth-1',
            canonicalEventHash: str_repeat('2', 64),
            nonce: 'land-nonce-p1b2',
            baseCommit: str_repeat('3', 40),
            treeHash: str_repeat('4', 64),
            scopeHash: str_repeat('5', 64),
            leaseId: 'lease-1',
            fencingToken: 1,
            orderHash: str_repeat('6', 64),
            deliveryId: 'delivery-p1b2',
            evidenceHash: str_repeat('7', 64),
        );

        // Synthetic landed/provisional ids — only binding shape is under test.
        $request = CanarySettlementRequest::fromLanded(
            $action,
            'landed-evt-1',
            str_repeat('8', 64),
            str_repeat('9', 40),
            str_repeat('6', 64),
            'delivery-p1b2',
            str_repeat('7', 64),
            'prov-evt-1',
            str_repeat('a', 64),
            str_repeat('b', 64),
            'observer-test',
        );

        $bindings = $request->actSettlementBindings();
        $this->assertSame('land-nonce-p1b2', $bindings['land_nonce']);
        $this->assertSame(64, strlen($bindings['settle_idempotency_hash']));
        $this->assertSame($request->idempotencyHash(), $bindings['settle_idempotency_hash']);
        $this->assertSame(str_repeat('9', 40), $bindings['landed_sha']);
        $this->assertSame('land-nonce-p1b2', $request->binding()['nonce']);
    }

    public function test_skip_counter_dual_writes_into_coverage_ledger(): void
    {
        $coveragePath = sys_get_temp_dir().'/atlas-p1b2-coverage-'.Str::uuid().'.jsonl';
        $skipPath = sys_get_temp_dir().'/atlas-p1b2-skip-'.Str::uuid().'.jsonl';
        @unlink($coveragePath);
        @unlink($skipPath);

        $ledger = new ProviderGovernanceCoverageLedger;
        $ledger->setLogPathForTesting($coveragePath);
        $this->app->instance(ProviderGovernanceCoverageLedger::class, $ledger);

        $counter = new GovernanceConsultSkipCounter($skipPath);
        $counter->record([
            'surface' => 'dev_claude_gateway',
            'executor' => 'dev',
            'provider' => 'claude_cli',
            'reason' => GovernanceConsultSkipCounter::REASON_SEAM_UNBOUND,
        ]);

        $this->assertFileExists($skipPath);
        $this->assertFileExists($coveragePath);
        $rows = array_values(array_filter(array_map(
            static fn (string $line): ?array => json_decode(trim($line), true) ?: null,
            file($coveragePath) ?: [],
        )));
        $this->assertNotEmpty($rows);
        $last = $rows[array_key_last($rows)];
        $this->assertSame(ProviderGovernanceCoverageLedger::PATH_SKIPPED, $last['path'] ?? null);
        $this->assertTrue((bool) ($last['skipped'] ?? false));
        $this->assertSame('claude_cli', $last['provider'] ?? null);

        @unlink($coveragePath);
        @unlink($skipPath);
    }
}
