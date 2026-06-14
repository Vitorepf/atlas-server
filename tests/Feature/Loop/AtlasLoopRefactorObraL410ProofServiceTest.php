<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopRefactorObraL410ProofService;
use App\Services\Ai\Obra\AtlasObraReceiptStamp;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * L4-10 for a loop multi-file refactor obra — frozen proof that the gate is UNGAMEABLE: it certifies
 * a real provider run, and REJECTS (a) a deterministic FIXTURE run (sealed execution_mode=
 * fixture_obra_run), (b) a hand-edited receipt (broken HMAC), (c) a real run that touched files
 * outside its allowed cluster, (d) a single-node run. A fixture can never mint a "real" L4-10.
 */
final class AtlasLoopRefactorObraL410ProofServiceTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    private AtlasObraReceiptStamp $stamp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stamp = new AtlasObraReceiptStamp();
    }

    protected function tearDown(): void
    {
        foreach ($this->paths as $p) {
            @File::delete($p);
        }
        parent::tearDown();
    }

    private function service(): AtlasLoopRefactorObraL410ProofService
    {
        // Share the SAME stamp instance so sign + verify resolve the same secret.
        return new AtlasLoopRefactorObraL410ProofService($this->stamp);
    }

    /** Write an evidence envelope whose executor_receipt is signed over $facts (after optional tamper). */
    private function evidence(array $facts, ?callable $tamper = null): string
    {
        $receipt = $this->stamp->stamp($facts);
        if ($tamper !== null) {
            $receipt = $tamper($receipt);
        }
        $path = storage_path('framework/testing/refactor-l410-'.Str::uuid().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(['executor_receipt' => $receipt], JSON_UNESCAPED_SLASHES));
        $this->paths[] = $path;

        return $path;
    }

    /** A genuine real-provider 2-node refactor run. */
    private function realFacts(): array
    {
        return [
            'obra_id' => 'obra-refactor-1', 'branch' => 'atlas/obra/obra-refactor-1', 'status' => 'done',
            'certified' => true, 'node_count' => 2, 'delivered_nodes' => 2, 'provider' => 'hermes_cli',
            'model' => 'gpt-5.5', 'main_untouched' => true, 'never_merged' => true,
            'execution_mode' => 'real_provider_obra_run', 'delivery_label' => 'hermes_cli:gpt-5.5',
            'delivered_files' => ['app/Services/Hub.php', 'app/Callers/CallerA.php'],
        ];
    }

    private array $allowed = ['app/Services/Hub.php', 'app/Callers/CallerA.php'];

    public function test_real_provider_run_is_certified(): void
    {
        $r = $this->service()->report(['evidence_path' => $this->evidence($this->realFacts()), 'allowed_files' => $this->allowed]);
        $this->assertTrue($r['certified'], json_encode($r['blockers']));
        $this->assertSame('certified', $r['status']);
        $this->assertTrue($r['evidence']['hmac_verified']);
    }

    public function test_fixture_run_is_rejected_never_mints_a_real_l4_10(): void
    {
        $facts = $this->realFacts();
        $facts['execution_mode'] = 'fixture_obra_run'; // the deterministic stub's sealed mode
        $facts['delivery_label'] = 'fixture';
        $r = $this->service()->report(['evidence_path' => $this->evidence($facts), 'allowed_files' => $this->allowed]);

        $this->assertFalse($r['certified'], 'a FIXTURE run can never be certified as a real L4-10');
        $this->assertSame('real_execution_evidence_rejected', $r['status']);
        $this->assertContains('not_a_real_provider_run:fixture_obra_run', $r['blockers']);
    }

    public function test_hand_edited_sealed_field_breaks_the_signature(): void
    {
        // Sign a real run, then hand-edit a SEALED fact (node_count) — the HMAC no longer matches.
        $r = $this->service()->report([
            'evidence_path' => $this->evidence($this->realFacts(), function (array $receipt): array {
                $receipt['node_count'] = 9; // tamper a sealed fact
                $receipt['delivered_nodes'] = 9;

                return $receipt;
            }),
            'allowed_files' => $this->allowed,
        ]);
        $this->assertFalse($r['certified']);
        $this->assertStringContainsString('signature_invalid', implode('|', $r['blockers']));
    }

    public function test_delivered_file_outside_allowed_cluster_is_rejected(): void
    {
        $facts = $this->realFacts();
        $facts['delivered_files'] = ['app/Services/Hub.php', 'app/Other/Sneaky.php']; // Sneaky not allowed
        $r = $this->service()->report(['evidence_path' => $this->evidence($facts), 'allowed_files' => $this->allowed]);

        $this->assertFalse($r['certified']);
        $this->assertStringContainsString('delivered_files_outside_allowed', implode('|', $r['blockers']));
    }

    public function test_single_node_run_is_below_min_and_rejected(): void
    {
        $facts = $this->realFacts();
        $facts['node_count'] = 1;
        $facts['delivered_nodes'] = 1;
        $facts['delivered_files'] = ['app/Services/Hub.php'];
        $r = $this->service()->report(['evidence_path' => $this->evidence($facts), 'allowed_files' => $this->allowed]);

        $this->assertFalse($r['certified']);
        $this->assertContains('node_count_below_min:1', $r['blockers']);
    }

    public function test_missing_evidence_path_is_blocked_fail_closed(): void
    {
        $r = $this->service()->report(['evidence_path' => null, 'allowed_files' => $this->allowed]);
        $this->assertFalse($r['certified']);
        $this->assertSame('real_execution_blocked', $r['status']);
        $this->assertContains('l4_10_evidence_path_missing', $r['blockers']);
    }
}
