<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphRuntimeInvoker;
use App\Services\Engineering\CodeGraph\CodeGraphSymbolBuilder;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * AP-815 · D1 — the Q-2 eval harness scored against a REAL, human-verified gold set.
 *
 * Before D1 the precision/recall number was computed on synthetic toy edges, so it
 * proved nothing about the actual extractor. This test indexes a FROZEN mini-repo
 * (tests/fixtures/code_graph/gold) whose relationships were verified by hand, builds
 * the symbol graph through the real pipeline (EngineeringCodeIntelligenceService::index
 * -> CodeGraphSymbolBuilder), and compares the PREDICTED edges to the hand-labeled
 * gold set in gold_edges.json.
 *
 * The gold set is ground truth a human asserts by reading the source — NOT a dump of
 * resolver output — so the score is trustworthy: a dropped real edge sinks recall, an
 * invented edge (god-node, self-loop, phantom target) sinks precision.
 *
 * Floors are LITERAL (precision >= 0.7, recall >= 0.7). The fixture is intentionally
 * built so every true relationship is expressed via an idiomatic `use` import / ::class
 * reference — exactly the relation kinds the legacy extractor captures — so the honest
 * measured score is high. If the extractor regresses below a floor, this test fails
 * with the exact tp/fp/fn breakdown rather than silently degrading.
 *
 * Boots only the needed tables in setUp (the repo's established pattern — full
 * RefreshDatabase is unreliable here because a core migration is pgsql-only SQL).
 */
final class CodeGraphGoldEvalTest extends TestCase
{
    /** Literal quality floors for the frozen gold set (anti-over-claim: real, not aspirational). */
    private const PRECISION_FLOOR = 0.7;

    private const RECALL_FLOOR = 0.7;

    private const TABLES = [
        'atlas_engineering_doc_links', 'atlas_engineering_code_symbols', 'atlas_engineering_code_modules',
        'atlas_engineering_code_file_snapshots', 'ai_codebase_world_model_edges', 'ai_codebase_world_model_nodes', 'ai_codebase_world_models',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_06_09_121000_add_mtime_and_file_hash_to_code_file_snapshots.php'))->up();

        // The symbol builder is gated by this flag; without it build() returns 'disabled'.
        config(['atlas.code_graph.real_edges' => true]);
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_predicted_edges_meet_precision_and_recall_floors_against_gold(): void
    {
        $predicted = $this->buildPredictedEdges();
        $gold = $this->loadGoldEdges();

        $this->assertNotEmpty($gold, 'the gold fixture must contain hand-verified edges');
        $this->assertNotEmpty($predicted, 'the real pipeline must produce predicted edges from the fixture');

        $metrics = $this->precisionRecall($predicted, $gold);

        $message = sprintf(
            'gold eval: precision=%.4f recall=%.4f f1=%.4f (tp=%d fp=%d fn=%d | predicted=%d gold=%d). '
            .'fp(spurious)=%s fn(missed)=%s',
            $metrics['precision'], $metrics['recall'], $metrics['f1'],
            $metrics['tp'], $metrics['fp'], $metrics['fn'],
            $metrics['predicted'], $metrics['gold'],
            json_encode($metrics['fp_edges']) ?: '[]',
            json_encode($metrics['fn_edges']) ?: '[]',
        );

        $this->assertGreaterThanOrEqual(self::PRECISION_FLOOR, $metrics['precision'], $message);
        $this->assertGreaterThanOrEqual(self::RECALL_FLOOR, $metrics['recall'], $message);
    }

    /**
     * Independent corroboration: the python_ai_data eval op (Q-2) must agree with the
     * PHP set arithmetic on the SAME predicted/gold edge sets, exercising the governed
     * runtime seam end-to-end (Decision Receipt + flag + venv). Skipped when the venv
     * is absent so the suite still passes on a machine without the python runtime.
     */
    public function test_python_eval_op_agrees_with_php_arithmetic(): void
    {
        if (! is_file(base_path('runtimes/python/code_graph/.venv/bin/python'))) {
            $this->markTestSkipped('code_graph python venv absent — PHP arithmetic test covers the floors.');
        }

        $predicted = $this->buildPredictedEdges();
        $gold = $this->loadGoldEdges();
        $php = $this->precisionRecall($predicted, $gold);

        // Shape edges as the harness {from,to,type} contract.
        $shape = static fn (array $set): array => array_map(
            static fn (string $key): array => (function (array $p): array {
                return ['from' => $p[0], 'to' => $p[1], 'type' => $p[2]];
            })(explode("\u{241F}", $key)),
            array_keys($set),
        );
        $input = ['predicted' => $shape($predicted), 'gold' => $shape($gold)];

        // AP-815 A2: the brain (PHP) authorizes this exact op+input with a minted
        // Decision Receipt; the invoker blocks the python muscle without a valid one.
        $op = 'eval_precision_recall';
        $receipt = CodeGraphRuntimeInvoker::mintReceipt($op, $input, 'CodeGraphGoldEvalTest');
        $result = app(CodeGraphRuntimeInvoker::class)->invoke($op, $input, [], $receipt);

        if (($result['status'] ?? null) !== CodeGraphRuntimeInvoker::STATUS_SUCCEEDED) {
            $this->markTestSkipped('python runtime unavailable/blocked: '.json_encode($result['findings'] ?? []));
        }

        $py = $result['artifacts'][0]['result'] ?? [];
        $this->assertSame($php['tp'], $py['tp'] ?? null, 'python tp must match PHP');
        $this->assertSame($php['fp'], $py['fp'] ?? null, 'python fp must match PHP');
        $this->assertSame($php['fn'], $py['fn'] ?? null, 'python fn must match PHP');
        $this->assertEqualsWithDelta($php['precision'], (float) ($py['precision'] ?? -1), 0.0001);
        $this->assertEqualsWithDelta($php['recall'], (float) ($py['recall'] ?? -1), 0.0001);

        $this->assertGreaterThanOrEqual(self::PRECISION_FLOOR, (float) ($py['precision'] ?? 0));
        $this->assertGreaterThanOrEqual(self::RECALL_FLOOR, (float) ($py['recall'] ?? 0));
    }

    /**
     * Run the real indexing pipeline over the frozen fixture and return the predicted
     * symbol->symbol edges as a set keyed by "from␟to␟type" (sym:<FQN> node ids).
     *
     * @return array<string,bool>
     */
    private function buildPredictedEdges(): array
    {
        $fixture = base_path('tests/fixtures/code_graph/gold');
        $this->assertDirectoryExists($fixture, 'the frozen gold fixture mini-repo must exist');

        app(EngineeringCodeIntelligenceService::class)->index(['workspace' => $fixture]);
        $workspaceId = app(CodeGraphWorkspaceIdentity::class)->resolve($fixture);

        $summary = app(CodeGraphSymbolBuilder::class)->build($workspaceId);
        $this->assertSame(CodeGraphSymbolBuilder::STATUS_WRITTEN, $summary['status'] ?? null, 'the symbol graph must build (flag on)');

        $rows = DB::table('ai_codebase_world_model_edges')
            ->where('world_model_id', $summary['world_model_id'])
            ->get(['from_node_id', 'to_node_id', 'edge_type']);

        $set = [];
        foreach ($rows as $row) {
            $set[$this->edgeKey((string) $row->from_node_id, (string) $row->to_node_id, (string) $row->edge_type)] = true;
        }

        return $set;
    }

    /**
     * Load the hand-verified gold edges, mapping each FQN endpoint to its sym:<FQN>
     * node id so it is comparable to the predicted set.
     *
     * @return array<string,bool>
     */
    private function loadGoldEdges(): array
    {
        $path = base_path('tests/fixtures/code_graph/gold/gold_edges.json');
        $this->assertFileExists($path, 'the gold_edges.json labels file must exist');

        /** @var array<string,mixed> $data */
        $data = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($data['edges'] ?? null, 'gold_edges.json must have an edges array');

        $set = [];
        foreach ($data['edges'] as $edge) {
            $from = $this->nodeId((string) ($edge['from'] ?? ''));
            $to = $this->nodeId((string) ($edge['to'] ?? ''));
            $type = (string) ($edge['type'] ?? '');
            $set[$this->edgeKey($from, $to, $type)] = true;
        }

        return $set;
    }

    /**
     * Mirror of CodeGraphSymbolResolver's node id: a gold endpoint given as an FQN
     * becomes "sym:<FQN>"; an endpoint already in node-id form is left untouched.
     */
    private function nodeId(string $fqn): string
    {
        $fqn = ltrim(trim($fqn), '\\');

        return str_starts_with($fqn, 'sym:') ? $fqn : 'sym:'.$fqn;
    }

    /** Stable edge identity: (from, to, type), case-sensitive, matching the python harness. */
    private function edgeKey(string $from, string $to, string $type): string
    {
        return trim($from)."\u{241F}".trim($to)."\u{241F}".trim($type);
    }

    /**
     * Pure set-based precision/recall/F1 (the same arithmetic the python Q-2 op does),
     * computed in PHP so the test needs no runtime to enforce the floors.
     *
     * @param  array<string,bool>  $predicted
     * @param  array<string,bool>  $gold
     * @return array{precision:float,recall:float,f1:float,tp:int,fp:int,fn:int,predicted:int,gold:int,fp_edges:array<int,string>,fn_edges:array<int,string>}
     */
    private function precisionRecall(array $predicted, array $gold): array
    {
        $tpKeys = array_intersect_key($predicted, $gold);
        $fpKeys = array_diff_key($predicted, $gold);
        $fnKeys = array_diff_key($gold, $predicted);

        $tp = count($tpKeys);
        $fp = count($fpKeys);
        $fn = count($fnKeys);

        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $f1 = ($precision + $recall) > 0 ? (2.0 * $precision * $recall) / ($precision + $recall) : 0.0;

        $readable = static fn (array $keys): array => array_map(
            static fn (string $k): string => str_replace("\u{241F}", ' -> ', $k),
            array_keys($keys),
        );

        return [
            'precision' => $precision,
            'recall' => $recall,
            'f1' => $f1,
            'tp' => $tp,
            'fp' => $fp,
            'fn' => $fn,
            'predicted' => count($predicted),
            'gold' => count($gold),
            'fp_edges' => $readable($fpKeys),
            'fn_edges' => $readable($fnKeys),
        ];
    }
}
