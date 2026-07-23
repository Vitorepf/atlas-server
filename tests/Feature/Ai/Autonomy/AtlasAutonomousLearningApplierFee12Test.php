<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Autonomy;

use App\Models\AiMemoryDelta;
use App\Services\Ai\Aaeos\Generated\AtlasLearningProposalsService;
use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\Memory\AtlasMemoryDeltaPromotionService;
use App\Services\Ai\Memory\AtlasMemoryRegistryService;
use App\Services\Ai\Autonomy\AtlasAutonomousLearningApplier;
use App\Services\Ai\Autonomy\AtlasWeeklyMemoryDigestService;
use App\Services\Ai\Compounding\AtlasLearningProposalApplier;
use App\Services\Ai\Compounding\AtlasLearningProposalService;
use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Memory\MemoryQueryInput;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FEE-12 — auto-apply-safe routes memory deltas + digest lists applied/held with reverse handles.
 */
class AtlasAutonomousLearningApplierFee12Test extends TestCase
{
    /** @var list<string> */
    private array $tmp = [];

    protected function tearDown(): void
    {
        foreach ($this->tmp as $p) {
            @unlink($p);
        }
        parent::tearDown();
    }

    private function tmp(string $tag): string
    {
        $p = sys_get_temp_dir().'/atlas-fee12-'.$tag.'-'.bin2hex(random_bytes(4)).'.jsonl';
        $this->tmp[] = $p;

        return $p;
    }

    private function applier(): AtlasAutonomousLearningApplier
    {
        $kernel = new AtlasConstitutionalKernelService();
        $kernel->setViolationsLogPathForTesting($this->tmp('kernel'));
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->tmp('tickets'));

        return new AtlasAutonomousLearningApplier(
            new AtlasLearningProposalsService(),
            $admission,
            new AtlasLearningProposalService(),
            new AtlasLearningProposalApplier(new AtlasConductorRoutingMemory()),
            new AtlasMemoryDeltaPromotionService(new AtlasMemoryRegistryService(), new MemoryQueryInput()),
        );
    }

    public function test_safe_delta_auto_applies_and_appears_in_digest_with_reverse_handle_weak_delta_is_held(): void
    {
        config(['atlas.ai.autonomous_learning.enabled' => true]);
        $this->migrateMemoryTable();
        $this->createMemoryDeltaTable();

        $safe = AiMemoryDelta::query()->create([
            'id' => Str::uuid()->toString(),
            'source_workspace' => base_path(),
            'type' => 'decision',
            'claim' => 'Prefer small reversible slices with focused tests.',
            'evidence' => [['kind' => 'test', 'excerpt' => 'fee12-safe']],
            'scope' => 'global',
            'confidence' => 0.92,
            'requires_confirmation' => false,
            'status' => 'pending',
        ]);

        $weak = AiMemoryDelta::query()->create([
            'id' => Str::uuid()->toString(),
            'source_workspace' => base_path(),
            'type' => 'decision',
            'claim' => 'Weak claim without enough evidence confidence.',
            'evidence' => [],
            'scope' => 'global',
            'confidence' => 0.2,
            'requires_confirmation' => false,
            'status' => 'pending',
        ]);

        $report = $this->applier()->run(20);

        $this->assertSame(1, $report['applied']);
        $this->assertSame(1, $report['held']);
        $this->assertSame('promoted', $safe->fresh()->status);
        $this->assertSame('pending', $weak->fresh()->status);
        $this->assertNotNull($safe->fresh()->promoted_memory_entry_id);

        $appliedItem = collect($report['items'])->firstWhere('action', 'auto_applied');
        $this->assertNotNull($appliedItem);
        $this->assertSame('memory_deltas', $appliedItem['queue']);
        $this->assertStringContainsString('memory-forget', (string) $appliedItem['reverse_handle']);

        $heldItem = collect($report['items'])->firstWhere('action', 'held');
        $this->assertNotNull($heldItem);
        $this->assertSame((string) $weak->getKey(), $heldItem['id']);
        $this->assertSame('missing_evidence', $heldItem['reason']);

        $digest = (new AtlasWeeklyMemoryDigestService(autoApplier: $this->applier()))->digest(7);
        $this->assertArrayHasKey('auto_apply_safe', $digest);
        $this->assertGreaterThanOrEqual(1, $digest['auto_apply_safe']['applied']['count']);
        $digestApplied = collect($digest['auto_apply_safe']['applied']['items'])->firstWhere('queue', 'memory_deltas');
        $this->assertNotNull($digestApplied);
        $this->assertStringContainsString('memory-forget', (string) $digestApplied['reverse_handle']);

        $digestHeld = collect($digest['auto_apply_safe']['held']['items'])->firstWhere('id', (string) $weak->getKey());
        $this->assertNotNull($digestHeld);
        $this->assertSame('held', $digestHeld['action']);
        $this->assertSame('missing_evidence', $digestHeld['reason']);
    }

    private function migrateMemoryTable(): void
    {
        Schema::dropIfExists('atlas_memory_entries');
        (require database_path('migrations/2026_05_02_000000_create_atlas_memory_entries_table.php'))->up();
    }

    private function createMemoryDeltaTable(): void
    {
        Schema::dropIfExists('ai_memory_deltas');
        Schema::create('ai_memory_deltas', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('source_trace_id')->nullable()->index();
            $table->uuid('source_session_id')->nullable()->index();
            $table->string('source_workspace')->nullable();
            $table->string('type', 32)->default('process');
            $table->text('claim');
            $table->json('evidence');
            $table->string('scope', 255)->default('global');
            $table->float('confidence')->default(0.5);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->json('use_when')->nullable();
            $table->json('do_not_use_when')->nullable();
            $table->boolean('requires_confirmation')->default(true);
            $table->string('status', 16)->default('pending');
            $table->uuid('superseded_by')->nullable();
            $table->uuid('promoted_memory_entry_id')->nullable()->index();
            $table->timestamp('promoted_at')->nullable()->index();
            $table->timestamps();
        });
    }
}
