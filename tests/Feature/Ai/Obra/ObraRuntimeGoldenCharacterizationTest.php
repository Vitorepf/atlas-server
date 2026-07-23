<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use App\Services\Ai\Obra\AtlasObraCertificationService;
use App\Services\Ai\Obra\AtlasObraExecutor;
use App\Services\Ai\Obra\AtlasObraPlanService;
use App\Services\Ai\Obra\AtlasObraService;
use App\Services\Ai\Obra\DeterministicObraDecomposer;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * GOD-DEBULK golden characterization (2026-07-22) — Ai/Company step 1.
 *
 * Freezes the CURRENT AtlasObraService / AtlasObraExecutor operator surface
 * before the EnterpriseExecutorUnification restructure:
 *   - the public API fingerprint (methods + signatures) and constants of both;
 *   - the refused-commission envelopes (exact deep arrays — order-sensitive);
 *   - the status() read-model envelopes (empty / unknown / planned obra);
 *   - the deterministic planning/decomposition envelope (F1) deep-frozen.
 *
 * ZERO provider calls: commission() is exercised ONLY on its refuse-before-plan
 * paths, and decomposition uses the cost-free DeterministicObraDecomposer with
 * brain=null. No git runs (no executePlanId / execute). Everything asserted is
 * deterministic by construction: the obra id is sha256(intent|workspace)-derived
 * and node ids/topological order follow from it.
 */
final class ObraRuntimeGoldenCharacterizationTest extends TestCase
{
    private const INTENT = 'create the golden alpha service; then wire alpha into the beta registry; then test the assembled pair';

    private const PLAN_ID = 'obra-0041f7068fd6';

    /** Exact deep snapshot of the F1 deterministic decomposition envelope. */
    private const PLAN_ENVELOPE = [
        'schema' => 'atlas.obra.plan.v1',
        'plan_id' => self::PLAN_ID,
        'workspace_id' => 'atlas-server',
        'status' => 'planned',
        'node_count' => 3,
        'decomposer' => 'deterministic',
        'nodes' => [
            [
                'id' => self::PLAN_ID.':n0',
                'seq' => 0,
                'title' => 'create the golden alpha service',
                'request' => 'create the golden alpha service',
                'target_area' => null,
                'depends_on' => [],
                'status' => 'pending',
                'brain_refs' => ['sources_present' => [], 'code' => [], 'reality' => [], 'memory' => []],
            ],
            [
                'id' => self::PLAN_ID.':n1',
                'seq' => 1,
                'title' => 'wire alpha into the beta registry',
                'request' => 'wire alpha into the beta registry',
                'target_area' => null,
                'depends_on' => [self::PLAN_ID.':n0'],
                'status' => 'pending',
                'brain_refs' => ['sources_present' => [], 'code' => [], 'reality' => [], 'memory' => []],
            ],
            [
                'id' => self::PLAN_ID.':n2',
                'seq' => 2,
                'title' => 'test the assembled pair',
                'request' => 'test the assembled pair',
                'target_area' => null,
                'depends_on' => [self::PLAN_ID.':n1'],
                'status' => 'pending',
                'brain_refs' => ['sources_present' => [], 'code' => [], 'reality' => [], 'memory' => []],
            ],
        ],
        'topological_order' => [self::PLAN_ID.':n0', self::PLAN_ID.':n1', self::PLAN_ID.':n2'],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $migration = require database_path('migrations/2026_06_10_140000_create_atlas_obra_plan_tables.php');
        if (Schema::hasTable('atlas_obra_nodes')) {
            $migration->down();
        }
        $migration->up();
        config()->set('atlas.obra.enabled', true);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_obra_nodes');
        Schema::dropIfExists('atlas_obra_plans');
        parent::tearDown();
    }

    public function test_service_and_executor_public_api_fingerprints_are_frozen(): void
    {
        $this->assertSame([
            '__construct(App\Services\Ai\Obra\AtlasObraPlanService $planService, App\Services\Ai\Obra\AtlasObraExecutor $executor): mixed',
            'commission(string $intent, array $opts): array',
            'discard(string $repoDir, string $obraId): array',
            'status(string $obraId): array',
        ], $this->fingerprint(AtlasObraService::class));

        $this->assertSame([
            '__construct(App\Services\Ai\Obra\ObraNodeDelivery $delivery, App\Services\Ai\RealExecution\GovernedBranchMaterializationService $materializer, ?App\Services\Ai\Reality\AtlasRealityGraphIngestionService $brain, ?App\Services\Ai\Obra\ObraNodeGate $gate, ?App\Services\Ai\Obra\AtlasObraCertificationService $certification, ?App\Services\Ai\Obra\AtlasObraReceiptStamp $receiptStamp, ?App\Services\Ai\Obra\AtlasObraWaveScheduler $waveScheduler): mixed',
            'discardObra(string $repoDir, string $planId): array',
            'execute(array $plan, array $opts): array',
            'executePlanId(string $planId, array $opts): array',
        ], $this->fingerprint(AtlasObraExecutor::class));

        $this->assertSame('atlas.obra.commission.v1', AtlasObraService::SCHEMA);
        $this->assertSame('refused', AtlasObraService::STATUS_REFUSED);
        $this->assertSame('atlas.obra.executor.v1', AtlasObraExecutor::SCHEMA);
        $this->assertSame('done', AtlasObraExecutor::STATUS_DONE);
        $this->assertSame('failed', AtlasObraExecutor::STATUS_FAILED);
        $this->assertSame('halted', AtlasObraExecutor::STATUS_HALTED);
        $this->assertSame('needs_review', AtlasObraExecutor::STATUS_NEEDS_REVIEW);
        $this->assertSame('done', AtlasObraExecutor::NODE_DONE);
        $this->assertSame('failed', AtlasObraExecutor::NODE_FAILED);
        $this->assertSame('skipped', AtlasObraExecutor::NODE_SKIPPED);
        $this->assertSame('atlas.obra.plan.v1', AtlasObraPlanService::SCHEMA);
        $this->assertSame('planned', AtlasObraPlanService::STATUS_PLANNED);
    }

    public function test_refused_commission_envelopes_are_frozen_deep(): void
    {
        $service = app(AtlasObraService::class);

        // Empty intent → refused BEFORE any plan/executor/provider work.
        $this->assertSame(
            $this->refusedEnvelope(reason: 'intent_required'),
            $service->commission('   '),
        );

        // Kill switch → the whole surface refuses with the same honest envelope.
        config()->set('atlas.obra.enabled', false);
        $this->assertSame(
            $this->refusedEnvelope(reason: 'obra_disabled'),
            $service->commission('anything at all'),
        );
    }

    public function test_status_read_model_envelopes_are_frozen(): void
    {
        $service = app(AtlasObraService::class);

        $this->assertSame(
            ['schema' => 'atlas.obra.commission.v1', 'found' => false, 'obra_id' => '', 'reason' => 'obra_id_required'],
            $service->status('   '),
        );

        $this->assertSame(
            ['schema' => 'atlas.obra.commission.v1', 'found' => false, 'obra_id' => 'obra-does-not-exist', 'reason' => 'obra_not_found'],
            $service->status('obra-does-not-exist'),
        );

        // A planned-but-never-run obra: found envelope, branch honestly null.
        $this->planService()->decompose(self::INTENT, ['workspace' => 'atlas-server']);
        $status = $service->status(self::PLAN_ID);

        $this->assertSame([
            'schema' => 'atlas.obra.commission.v1',
            'found' => true,
            'obra_id' => self::PLAN_ID,
            'intent' => self::INTENT,
            'status' => 'planned',
            'workspace_id' => 'atlas-server',
            'branch' => null,
            'decomposer' => 'deterministic',
            'node_count' => 3,
            'plan' => array_map(static function (array $node): array {
                unset($node['brain_refs']);

                return [...$node, 'commit' => null, 'files_changed' => []];
            }, self::PLAN_ENVELOPE['nodes']),
        ], $status);
    }

    public function test_deterministic_decomposition_envelope_is_frozen_deep(): void
    {
        $plan = $this->planService()->decompose(self::INTENT, ['workspace' => 'atlas-server']);

        // The obra id is deterministic from (intent|workspace) — formula frozen.
        $this->assertSame('obra-'.substr(hash('sha256', self::INTENT.'|atlas-server'), 0, 12), $plan['plan_id']);
        // Exact deep envelope (assertSame is key-order-sensitive at every level).
        $this->assertSame(self::PLAN_ENVELOPE, $plan);

        // The persisted spine row: redacted intent label, planned status, bounded meta.
        $row = DB::table('atlas_obra_plans')->where('id', self::PLAN_ID)->first();
        $this->assertNotNull($row);
        $this->assertSame(self::INTENT, $row->intent);
        $this->assertSame('atlas-server', $row->workspace_id);
        $this->assertSame('planned', $row->status);
        $this->assertSame([
            'schema' => 'atlas.obra.plan.v1',
            'node_count' => 3,
            'max_nodes' => 12,
            'decomposer' => 'deterministic',
            'honesty' => 'plan only — no execution; cost-free unless the provider decomposer ran',
        ], json_decode((string) $row->meta, true));

        // Idempotent re-plan: same intent+workspace → same id, same envelope, no dupes.
        $this->assertSame($plan, $this->planService()->decompose(self::INTENT, ['workspace' => 'atlas-server']));
        $this->assertSame(3, DB::table('atlas_obra_nodes')->where('plan_id', self::PLAN_ID)->count());
    }

    /**
     * Cost-free F1: deterministic decomposer + null brain (same wiring the obra
     * test-suite proves; the provider decomposer is never touched here).
     */
    private function planService(): AtlasObraPlanService
    {
        return new AtlasObraPlanService(
            new DeterministicObraDecomposer,
            app(CodeGraphWorkspaceIdentity::class),
            null,
        );
    }

    /**
     * The exact refused envelope AtlasObraService::commission emits before any
     * execution (deep key order included).
     *
     * @return array<string,mixed>
     */
    private function refusedEnvelope(string $reason): array
    {
        return [
            'schema' => AtlasObraService::SCHEMA,
            'obra_id' => '',
            'intent' => '',
            'status' => AtlasObraService::STATUS_REFUSED,
            'plan' => [],
            'node_count' => 0,
            'delivered_nodes' => 0,
            'failed_node' => null,
            'branch' => null,
            'certified' => false,
            'disposition' => AtlasObraCertificationService::DISPOSITION_HALTED,
            'evidence' => null,
            'integrated_test_result' => null,
            'main_untouched' => true,
            'never_merged' => true,
            'never_pushed' => true,
            'reversible' => true,
            'brain_recorded' => false,
            'review_commands' => [],
            'decomposer' => '',
            'reason' => $reason,
        ];
    }

    /**
     * @param  class-string  $class
     * @return list<string>
     */
    private function fingerprint(string $class): array
    {
        $reflection = new \ReflectionClass($class);

        return collect($reflection->getMethods(\ReflectionMethod::IS_PUBLIC))
            ->map(fn (\ReflectionMethod $method): string => sprintf(
                '%s(%s): %s',
                $method->getName(),
                collect($method->getParameters())
                    ->map(fn (\ReflectionParameter $parameter): string => (string) $parameter->getType().' $'.$parameter->getName())
                    ->implode(', '),
                (string) ($method->getReturnType() ?? 'mixed'),
            ))
            ->sort()
            ->values()
            ->all();
    }
}
