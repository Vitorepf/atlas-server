<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition;

use App\Models\AtlasAaeosTestRunReceipt;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasCapabilityTestExecutionService;
use App\Services\Ai\Cognition\AtlasCognitionEvidenceResolver;
use App\Services\Ai\Cognition\AtlasCognitionScoreCardService;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ANTI-OVER-CLAIM proof for the ACOS scorecard.
 *
 * The scorecard used to HARDCODE doc_status='ready' and pipeline_status='ready' for
 * all 73 subsystems — so "10/10" was 1/3 proven (code_status=class_exists) + 2/3
 * self-declared, exactly the ADRS "52/52" over-claim. This test PROVES the fix:
 *
 *   (a) a subsystem whose service_class has NO owning doc resolves doc_status != ready;
 *   (b) one with no green-run receipt resolves pipeline_status != ready;
 *   (c) flipping the EVIDENCE flips the status (a hardcoded value cannot move);
 *   (d) the AGGREGATE score CHANGES when one subsystem's evidence changes.
 *
 * Hermetic + load-safe: sqlite :memory: (extends Tests\TestCase, NOT RefreshDatabase),
 * a STUB resolver for the scorecard-wiring proof (no live code-intel index touched),
 * and a seeded receipts table for the real B3 green-receipt-gate flip. No PHPUnit is
 * spawned; the live pgsql is never touched.
 */
class AtlasCognitionScoreCardEvidenceResolutionTest extends TestCase
{
    /**
     * Build the receipts table in the :memory: DB (the B3 green-receipt gate reads it).
     * Mirrors the production migration's load-bearing columns; dropped in tearDown.
     */
    private function migrateReceiptsTable(): void
    {
        Schema::create('atlas_aaeos_test_run_receipts', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('capability_id', 300)->index();
            $table->string('test_ref', 400)->index();
            $table->string('filter', 400);
            $table->boolean('passed')->default(false)->index();
            $table->unsignedInteger('tests_run')->default(0);
            $table->integer('exit_code')->nullable();
            $table->string('commit_stamp', 64)->nullable()->index();
            $table->string('test_file_hash', 64)->nullable();
            $table->string('impl_files_hash', 64)->nullable();
            $table->text('output_tail')->nullable();
            $table->string('runner', 120)->nullable();
            $table->json('metadata')->default('{}');
            $table->timestamp('ran_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_aaeos_test_run_receipts');
        parent::tearDown();
    }

    /**
     * (a) + (c)-doc — A service_class with NO owning doc resolves doc != ready; giving it
     * an owning doc flips it to ready. Proven through the SCORECARD (build() reads the
     * resolver), with a stub resolver so the assertion is hermetic and deterministic.
     */
    public function test_doc_status_is_resolved_not_hardcoded_and_flips_with_evidence(): void
    {
        $fqn = 'App\\Services\\Ai\\AtlasDecide\\AtlasSwarmConductorService';

        $blind = $this->stubResolver(docOwned: [], pipelineGreen: []);
        $owned = $this->stubResolver(docOwned: [$fqn], pipelineGreen: []);

        $blindRow = $this->rowForClass((new AtlasCognitionScoreCardService($blind))->build(), $fqn);
        $ownedRow = $this->rowForClass((new AtlasCognitionScoreCardService($owned))->build(), $fqn);

        // No owning doc -> NOT ready (the over-claim killed).
        $this->assertNotSame('ready', $blindRow['doc_status']);
        // Owning doc resolves -> ready. The literal in SUBSYSTEMS is identical for both
        // builds, so a hardcoded read could not differ — this difference proves resolution.
        $this->assertSame('ready', $ownedRow['doc_status']);
        $this->assertNotSame($blindRow['doc_status'], $ownedRow['doc_status']);
    }

    /**
     * (b) + (c)-pipeline — A service_class with no green run resolves pipeline != ready;
     * marking it green flips it to ready. Proven through the scorecard with a stub
     * resolver (hermetic). The REAL receipt-gate flip is proven separately below.
     */
    public function test_pipeline_status_is_resolved_not_hardcoded_and_flips_with_evidence(): void
    {
        $fqn = 'App\\Services\\Ai\\AtlasDecide\\AtlasSwarmConductorService';

        $noGreen = $this->stubResolver(docOwned: [$fqn], pipelineGreen: []);
        $green = $this->stubResolver(docOwned: [$fqn], pipelineGreen: [$fqn]);

        $noGreenRow = $this->rowForClass((new AtlasCognitionScoreCardService($noGreen))->build(), $fqn);
        $greenRow = $this->rowForClass((new AtlasCognitionScoreCardService($green))->build(), $fqn);

        $this->assertNotSame('ready', $noGreenRow['pipeline_status']);
        $this->assertSame('ready', $greenRow['pipeline_status']);
        $this->assertNotSame($noGreenRow['pipeline_status'], $greenRow['pipeline_status']);
    }

    /**
     * (d) — The AGGREGATE score and the scorecard hash CHANGE when a single subsystem's
     * evidence changes. A hardcoded scorecard yields a frozen number; a resolved one
     * moves. This is the core anti-hardcode proof the contract demands.
     */
    public function test_aggregate_score_changes_when_one_subsystems_evidence_changes(): void
    {
        $fqn = 'App\\Services\\Ai\\AtlasDecide\\AtlasSwarmConductorService';

        $before = (new AtlasCognitionScoreCardService(
            $this->stubResolver(docOwned: [], pipelineGreen: [])
        ))->build();

        // Flip exactly ONE subsystem's doc evidence on.
        $after = (new AtlasCognitionScoreCardService(
            $this->stubResolver(docOwned: [$fqn], pipelineGreen: [])
        ))->build();

        $this->assertNotSame(
            $before['score']['overall_out_of_10'],
            $after['score']['overall_out_of_10'],
            'Overall score must MOVE when a subsystem gains doc evidence — a hardcoded value cannot.',
        );
        $this->assertGreaterThan(
            $before['score']['dimensions']['doc']['score_out_of_10'],
            $after['score']['dimensions']['doc']['score_out_of_10'],
        );
        // The deterministic hash folds the row statuses, so it must change too.
        $this->assertNotSame($before['scorecard_hash'], $after['scorecard_hash']);
    }

    /**
     * (b) + (c) REAL B3 gate — proves the EXACT receipt gate that
     * AtlasCognitionEvidenceResolver::resolvePipelineStatus() delegates to actually
     * flips with seeded evidence in :memory:. Seeding a fresh green receipt makes
     * hasGreenReceipt() true; deleting the row makes it false. This is the real
     * evidence flip (no stub), keyed exactly as atlas:aeos:verify-tests writes it.
     */
    public function test_real_green_receipt_gate_flips_with_seeded_evidence(): void
    {
        $this->migrateReceiptsTable();

        $execution = new AtlasCapabilityTestExecutionService;
        $capabilityId = 'doc.cognition.swarm-conductor';
        $testRef = 'AtlasSwarmConductorServiceTest';

        // No receipt yet -> the gate withholds (honest non-ready).
        $this->assertFalse($execution->hasGreenReceipt($capabilityId, $testRef));

        // Seed a REAL green receipt (passed + tests_run>=1), no freshness hashes
        // (grandfathered) so the green scope alone decides — exactly the legacy path.
        AtlasAaeosTestRunReceipt::query()->create([
            'capability_id' => $capabilityId,
            'test_ref' => $testRef,
            'filter' => $testRef,
            'passed' => true,
            'tests_run' => 3,
            'exit_code' => 0,
            'output_tail' => 'OK (3 tests, 9 assertions)',
            'runner' => 'phpunit',
            'ran_at' => now(),
        ]);

        // Evidence present -> gate flips to true.
        $this->assertTrue($execution->hasGreenReceipt($capabilityId, $testRef));

        // A current freshness hash that the (null-hash) receipt cannot satisfy is
        // grandfathered (legacy receipt makes no claim) -> still green. But a receipt
        // that DID carry a hash would decay: prove that path too.
        AtlasAaeosTestRunReceipt::query()->where('capability_id', $capabilityId)->update([
            'impl_files_hash' => hash('sha256', 'ORIGINAL-IMPL'),
        ]);
        // Same hash -> fresh -> green.
        $this->assertTrue($execution->hasGreenReceipt(
            $capabilityId,
            $testRef,
            null,
            hash('sha256', 'ORIGINAL-IMPL'),
        ));
        // Edited impl (different current hash) -> stale -> NOT green (evidence moved).
        $this->assertFalse($execution->hasGreenReceipt(
            $capabilityId,
            $testRef,
            null,
            hash('sha256', 'EDITED-IMPL'),
        ));

        // Delete the receipt -> gate withholds again (evidence removed).
        AtlasAaeosTestRunReceipt::query()->where('capability_id', $capabilityId)->delete();
        $this->assertFalse($execution->hasGreenReceipt($capabilityId, $testRef));
    }

    /**
     * Degrade-safe: with the receipts table ABSENT, the real B3 gate returns false (no
     * subsystem is pipeline-ready-by-existence). This is why a :memory: unit test with no
     * receipts legitimately sees pipeline=0 — correct honest under-claim, not a bug.
     */
    public function test_pipeline_gate_degrades_safe_when_receipts_table_absent(): void
    {
        Schema::dropIfExists('atlas_aaeos_test_run_receipts');

        $execution = new AtlasCapabilityTestExecutionService;
        $this->assertFalse($execution->hasGreenReceipt('any.capability', 'AnyTest'));
    }

    /**
     * The row for a service_class FQN in a built envelope.
     *
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function rowForClass(array $envelope, string $fqn): array
    {
        foreach ($envelope['subsystems'] as $row) {
            if (($row['service_class'] ?? null) === $fqn) {
                return $row;
            }
        }
        $this->fail("No scorecard row for service_class {$fqn}");
    }

    /**
     * A deterministic, hermetic stub of the evidence resolver: doc_status=ready ONLY for
     * FQNs in $docOwned, pipeline_status=ready ONLY for FQNs in $pipelineGreen, else
     * 'building'. Proves the scorecard READS doc/pipeline from the resolver (not the
     * SUBSYSTEMS constant) — the literal is identical across builds, so any difference
     * must come from the resolver.
     *
     * @param  array<int,string>  $docOwned
     * @param  array<int,string>  $pipelineGreen
     */
    private function stubResolver(array $docOwned, array $pipelineGreen): AtlasCognitionEvidenceResolver
    {
        return new class($docOwned, $pipelineGreen) extends AtlasCognitionEvidenceResolver
        {
            /**
             * @param  array<int,string>  $docOwned
             * @param  array<int,string>  $pipelineGreen
             */
            public function __construct(
                private readonly array $docOwned,
                private readonly array $pipelineGreen,
            ) {
                // Intentionally do NOT call parent::__construct — the stub never touches
                // the ADRS resolver / index / receipts; it returns controlled values only.
            }

            public function resolveDocStatus(?string $serviceClass): string
            {
                return in_array($serviceClass, $this->docOwned, true)
                    ? self::STATUS_READY
                    : self::STATUS_BUILDING;
            }

            public function resolvePipelineStatus(?string $serviceClass): string
            {
                return in_array($serviceClass, $this->pipelineGreen, true)
                    ? self::STATUS_READY
                    : self::STATUS_BUILDING;
            }
        };
    }
}
