<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopRefactorObraL410ProofService;
use App\Services\Ai\Obra\AtlasObraReceiptStamp;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ITEM8 — locks the CENSUS-EXTENSION contract the planning phase depends on: when the planner introduces
 * a NEW file (folded into $allowed by maybePlan), the L4-10 scope census must ADMIT it. The census reads
 * delivered_files from the HMAC-SEALED executor receipt (not from report() options — that only carries
 * allowed_files), so we stamp a real receipt whose sealed delivered_files includes the new file and pass
 * the EXTENDED allowed_files into report(); the gate must NOT emit 'delivered_files_outside_allowed'.
 *
 * Mirror image: if the same delivered new file is NOT in allowed_files (i.e. maybePlan FAILED to fold it
 * in), the gate correctly rejects it — proving the extension is load-bearing.
 */
final class AtlasLoopPlannedNewFileCensusTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    private AtlasObraReceiptStamp $stamp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->stamp = new AtlasObraReceiptStamp;
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

    /** Write an evidence envelope whose executor_receipt is HMAC-sealed over $facts. */
    private function evidence(array $facts): string
    {
        $receipt = $this->stamp->stamp($facts);
        $path = storage_path('framework/testing/planned-newfile-l410-'.Str::uuid().'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode(['executor_receipt' => $receipt], JSON_UNESCAPED_SLASHES));
        $this->paths[] = $path;

        return $path;
    }

    /**
     * A genuine real-provider create+redirect obra: the create-class node delivered the NEW file
     * app/NewHelper.php, the redirect node touched the hub. Both are sealed in delivered_files.
     */
    private function createRedirectFacts(): array
    {
        return [
            'obra_id' => 'obra-create-redirect-1', 'branch' => 'atlas/obra/obra-create-redirect-1',
            'status' => 'done', 'certified' => true, 'node_count' => 2, 'delivered_nodes' => 2,
            'provider' => 'hermes_cli', 'model' => 'gpt-5.5', 'main_untouched' => true, 'never_merged' => true,
            'execution_mode' => 'real_provider_obra_run', 'delivery_label' => 'hermes_cli:gpt-5.5',
            'delivered_files' => ['app/NewHelper.php', 'app/HubA.php'],
        ];
    }

    public function test_planned_new_file_passes_l4_10_scope_census_when_folded_into_allowed(): void
    {
        // The EXTENDED allowed set (maybePlan folded app/NewHelper.php in) admits the delivered new file.
        $extendedAllowed = ['app/HubA.php', 'app/NewHelper.php'];

        $r = $this->service()->report([
            'evidence_path' => $this->evidence($this->createRedirectFacts()),
            'allowed_files' => $extendedAllowed,
        ]);

        $blockers = implode('|', (array) ($r['blockers'] ?? $r['evidence']['blockers'] ?? []));
        $this->assertStringNotContainsString('delivered_files_outside_allowed', $blockers, 'a folded-in new file passes the census');
    }

    public function test_planned_new_file_no_t_folded_into_allowed_is_rejected_by_the_census(): void
    {
        // The NON-extended allowed set (the bug item8 prevents) — the delivered new file is outside scope.
        $nonExtendedAllowed = ['app/HubA.php', 'app/HubB.php'];

        $r = $this->service()->report([
            'evidence_path' => $this->evidence($this->createRedirectFacts()),
            'allowed_files' => $nonExtendedAllowed,
        ]);

        $blockers = implode('|', (array) ($r['blockers'] ?? $r['evidence']['blockers'] ?? []));
        $this->assertStringContainsString('delivered_files_outside_allowed', $blockers, 'an unfolded new file is correctly refused — the extension is load-bearing');
        $this->assertFalse((bool) ($r['certified'] ?? false));
    }
}
