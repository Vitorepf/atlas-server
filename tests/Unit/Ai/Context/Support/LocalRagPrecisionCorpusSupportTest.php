<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context\Support;

use App\Services\Ai\Context\LocalRagPrecisionCorpusService as Host;
use App\Services\Ai\Context\Support\LocalRagPrecisionCorpusSupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Pure Support peel for Local RAG independent-precision corpus projectors —
 * no I/O, no host DI, no FS, no config.
 *
 * Explicit path proof: host imports Support and no longer declares the peeled
 * private pure helpers (independence / score / aggregate / unmeasured / tokens).
 */
final class LocalRagPrecisionCorpusSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/Context/Support/LocalRagPrecisionCorpusSupport.php';

    private const HOST_PATH = 'app/Services/Ai/Context/LocalRagPrecisionCorpusService.php';

    /** @var list<string> */
    private const PEELED = [
        'queryIndependenceReport',
        'scoreCaseFromRanked',
        'engineErrorCase',
        'rankedIdsFromMatches',
        'aggregate',
        'rankOfFirstRelevant',
        'runtimeDocuments',
        'unmeasured',
        'measuredReport',
        'allChecksPassed',
        'engineDescriptor',
        'documents',
        'cases',
        'kValues',
        'primaryK',
        'tokens',
        'normalise',
        'jaccard',
    ];

    /** Host residual private helpers that must stay (FS / runtime I/O). */
    /** @var list<string> */
    private const HOST_RESIDUAL = [
        'evaluateCase',
        'loadCorpus',
        'corpusPath',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 5);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\Context\Support\LocalRagPrecisionCorpusSupport;',
            $hostSrc,
            'Host must import LocalRagPrecisionCorpusSupport',
        );

        foreach ([
            'LocalRagPrecisionCorpusSupport::unmeasured',
            'LocalRagPrecisionCorpusSupport::documents',
            'LocalRagPrecisionCorpusSupport::cases',
            'LocalRagPrecisionCorpusSupport::kValues',
            'LocalRagPrecisionCorpusSupport::queryIndependenceReport',
            'LocalRagPrecisionCorpusSupport::measuredReport',
            'LocalRagPrecisionCorpusSupport::runtimeDocuments',
            'LocalRagPrecisionCorpusSupport::engineErrorCase',
            'LocalRagPrecisionCorpusSupport::rankedIdsFromMatches',
            'LocalRagPrecisionCorpusSupport::scoreCaseFromRanked',
        ] as $needle) {
            $this->assertStringContainsString($needle, $hostSrc, "Host must call {$needle}");
        }

        $support = new ReflectionClass(Support::class);
        foreach (self::PEELED as $method) {
            $this->assertTrue($support->hasMethod($method), "Support must expose {$method}");
            $rm = $support->getMethod($method);
            $this->assertTrue($rm->isPublic() && $rm->isStatic(), "{$method} must be public static");
        }

        $host = new ReflectionClass(Host::class);
        foreach (self::PEELED as $method) {
            // queryIndependenceReport remains as thin public host facade.
            if ($method === 'queryIndependenceReport') {
                $this->assertTrue($host->hasMethod($method), 'Host keeps public queryIndependenceReport facade');
                $this->assertTrue($host->getMethod($method)->isPublic());

                continue;
            }
            $this->assertFalse(
                $host->hasMethod($method),
                "Host must no longer declare private {$method} after peel",
            );
        }

        foreach (self::HOST_RESIDUAL as $method) {
            $this->assertTrue($host->hasMethod($method), "Host residual I/O/orchestrator {$method} must remain");
        }

        $this->assertTrue($host->hasMethod('report'));
        $this->assertSame(Support::SCHEMA_VERSION, Host::SCHEMA_VERSION);
    }

    #[Test]
    public function query_independence_report_flags_substring_and_high_overlap(): void
    {
        $documents = [
            ['id' => 'd1', 'text' => 'git revert creates a fresh commit that cancels out an earlier one'],
            ['id' => 'd2', 'text' => 'semantic retrieval ranks documents by embedding similarity'],
        ];

        $cheat = Support::queryIndependenceReport(
            $documents,
            [['id' => 'c_cheat', 'query' => 'git revert creates a fresh commit', 'relevant_ids' => ['d1']]],
        );
        $this->assertFalse($cheat['independent']);
        $this->assertSame(1, $cheat['violation_count']);
        $this->assertSame('query_is_substring_of_target', $cheat['violations'][0]['reason']);

        $independent = Support::queryIndependenceReport(
            $documents,
            [['id' => 'c_ok', 'query' => 'how do I undo the last change safely', 'relevant_ids' => ['d1']]],
            0.34,
        );
        $this->assertTrue($independent['independent']);
        $this->assertSame(0, $independent['violation_count']);
        $this->assertSame(1, $independent['checked_pair_count']);

        $missingDoc = Support::queryIndependenceReport(
            $documents,
            [['id' => 'c_missing', 'query' => 'anything', 'relevant_ids' => ['d_missing']]],
        );
        $this->assertFalse($missingDoc['independent']);
        $this->assertSame('relevant_id_not_in_documents', $missingDoc['violations'][0]['reason']);
    }

    #[Test]
    public function score_case_from_ranked_computes_precision_recall_and_rank(): void
    {
        $scored = Support::scoreCaseFromRanked(
            'c1',
            'find leading subject',
            ['d1'],
            ['d1', 'd2', 'd3'],
            [1, 3],
            true,
            false,
        );

        $this->assertSame('recalled', $scored['status']);
        $this->assertSame(1.0, $scored['precision_at_k']['1']);
        $this->assertSame(1.0, $scored['recall_at_k']['1']);
        $this->assertSame(1, $scored['rank_of_first_relevant']);
        $this->assertTrue($scored['real_embeddings']);
        $this->assertFalse($scored['fabricated_vectors']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $scored['query_hash']);

        $second = Support::scoreCaseFromRanked(
            'c2',
            'trailing',
            ['d2'],
            ['d1', 'd2', 'd3'],
            [1, 3],
            true,
            false,
        );
        // primary_k = max(kValues)=3, recall@3 = 1.0 → recalled
        $this->assertSame('recalled', $second['status']);
        $this->assertSame(0.0, $second['precision_at_k']['1']);
        $this->assertSame(1.0, $second['recall_at_k']['3']);
        $this->assertSame(2, $second['rank_of_first_relevant']);

        $missed = Support::scoreCaseFromRanked(
            'c3',
            'miss',
            ['d9'],
            ['d1', 'd2', 'd3'],
            [1, 3],
            true,
            false,
        );
        $this->assertSame('missed_at_primary_k', $missed['status']);
        $this->assertNull($missed['rank_of_first_relevant']);
    }

    #[Test]
    public function aggregate_mean_precision_recall_and_mrr(): void
    {
        $cases = [
            Support::scoreCaseFromRanked('a', 'q1', ['d1'], ['d1', 'd2', 'd3'], [1, 3], true, false),
            Support::scoreCaseFromRanked('b', 'q2', ['d2'], ['d1', 'd2', 'd3'], [1, 3], true, false),
        ];

        $metrics = Support::aggregate($cases, [1, 3]);
        $this->assertSame(0.5, $metrics['precision_at_k']['1']);
        $this->assertSame(0.5, $metrics['recall_at_k']['1']);
        $this->assertSame(1.0, $metrics['recall_at_k']['3']);
        $this->assertSame(0.75, $metrics['mean_reciprocal_rank']);
        $this->assertSame(2, $metrics['recalled_case_count']);
    }

    #[Test]
    public function rank_of_first_relevant_and_ranked_ids_from_matches(): void
    {
        $this->assertSame(2, Support::rankOfFirstRelevant(['a', 'b', 'c'], ['b', 'z']));
        $this->assertNull(Support::rankOfFirstRelevant(['a', 'b'], ['z']));
        $this->assertSame(
            ['d1', 'd2'],
            Support::rankedIdsFromMatches([
                ['id' => 'd1', 'score' => 0.9],
                ['id' => '', 'score' => 0.1],
                'skip',
                ['id' => 'd2'],
            ]),
        );
    }

    #[Test]
    public function corpus_extractors_and_primary_k_defaults(): void
    {
        $corpus = [
            'documents' => [
                ['id' => 'd1', 'text' => 'keep'],
                ['id' => 'd2', 'text' => '  '],
                ['no_id' => true, 'text' => 'x'],
            ],
            'cases' => [
                ['id' => 'c1', 'query' => 'q', 'relevant_ids' => ['d1']],
                ['id' => 'c2', 'query' => '', 'relevant_ids' => ['d1']],
                ['id' => 'c3', 'query' => 'q', 'relevant_ids' => []],
            ],
            'k_values' => [5, 1, 1, 0, -2],
        ];

        $docs = Support::documents($corpus);
        $this->assertCount(1, $docs);
        $this->assertSame('d1', $docs[0]['id']);

        $cases = Support::cases($corpus);
        $this->assertCount(1, $cases);
        $this->assertSame('c1', $cases[0]['id']);

        $this->assertSame([1, 5], Support::kValues($corpus));
        $this->assertSame([1, 3, 5], Support::kValues([]));
        $this->assertSame(5, Support::primaryK([1, 5]));
        $this->assertSame(3, Support::primaryK([]));
    }

    #[Test]
    public function unmeasured_envelope_is_honest_zero_never_fabricated(): void
    {
        $payload = Support::unmeasured('semantic_rag_runtime_unavailable', [
            'corpus_id' => 'c',
            'schema_version' => 'v1',
            'documents' => [['id' => 'd1', 'text' => 't']],
        ], [1, 3]);

        $this->assertSame(Support::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('attention', $payload['status']);
        $this->assertFalse($payload['measured']);
        $this->assertTrue($payload['unmeasured_honestly']);
        $this->assertSame(0.0, $payload['metrics']['recall_at_primary_k']);
        $this->assertSame(0.0, $payload['metrics']['precision_at_primary_k']);
        $this->assertSame(0.0, $payload['metrics']['mean_reciprocal_rank']);
        $this->assertSame(1, $payload['document_count']);
        $this->assertTrue($payload['limits']['no_fabricated_score']);
        $this->assertSame(
            'set_up_semantic_rag_runtime_then_remeasure_precision',
            $payload['next_action'],
        );

        $missing = Support::unmeasured('corpus_fixture_missing_or_invalid');
        $this->assertSame(
            'restore_independent_precision_corpus_fixture_then_remeasure',
            $missing['next_action'],
        );
    }

    #[Test]
    public function measured_report_applies_thresholds_and_engine_descriptor(): void
    {
        $cases = [];
        for ($i = 0; $i < 5; $i++) {
            $cases[] = Support::scoreCaseFromRanked(
                "c{$i}",
                "query {$i}",
                ['d1'],
                ['d1', 'd2'],
                [1, 3],
                true,
                false,
            );
        }

        $report = Support::measuredReport(
            ['corpus_id' => 'tmp', 'schema_version' => 'v1'],
            [['id' => 'd1', 'text' => 'a'], ['id' => 'd2', 'text' => 'b']],
            $cases,
            0,
            [1, 3],
            ['independent' => true],
        );

        $this->assertTrue($report['measured']);
        $this->assertSame('passed', $report['status']);
        $this->assertSame(5, $report['case_count']);
        $this->assertSame(3, $report['primary_k']);
        $this->assertTrue($report['checks']['minimum_case_count']);
        $this->assertTrue($report['checks']['recall_at_primary_k_threshold']);
        $this->assertSame('python_ai_data', $report['engine']['runtime_family']);
        $this->assertTrue($report['engine']['real_embeddings']);
        $this->assertFalse($report['engine']['fabricated_vectors']);
        $this->assertSame(
            'use_real_precision_as_the_independent_retrieval_signal',
            $report['next_action'],
        );

        $attention = Support::measuredReport(
            ['corpus_id' => 'tmp'],
            [['id' => 'd1', 'text' => 'a']],
            [Support::scoreCaseFromRanked('c0', 'q', ['d1'], ['d2', 'd1'], [1], true, false)],
            1,
            [1],
            ['independent' => false],
        );
        $this->assertSame('attention', $attention['status']);
        $this->assertFalse($attention['checks']['no_engine_errors']);
        $this->assertFalse($attention['checks']['queries_independent_of_targets']);
        $this->assertFalse($attention['checks']['minimum_case_count']);
    }

    #[Test]
    public function tokens_normalise_jaccard_and_runtime_documents(): void
    {
        $this->assertSame('hello world', Support::normalise('  Hello WORLD  '));
        $this->assertSame(['hello', 'world'], Support::tokens('Hello, WORLD!'));
        $this->assertSame(0.0, Support::jaccard([], ['a']));
        $this->assertSame(1.0, Support::jaccard(['a', 'b'], ['a', 'b']));
        $this->assertEqualsWithDelta(1 / 3, Support::jaccard(['a', 'b'], ['b', 'c']), 0.0001);

        $this->assertSame(
            [['id' => 'd1', 'text' => 'body']],
            Support::runtimeDocuments([['id' => 'd1', 'text' => 'body', 'extra' => true]]),
        );

        $this->assertTrue(Support::allChecksPassed(['a' => true, 'b' => true]));
        $this->assertFalse(Support::allChecksPassed(['a' => true, 'b' => false]));

        $err = Support::engineErrorCase('c1', 'q', 2, str_repeat('x', 250));
        $this->assertSame('engine_error', $err['status']);
        $this->assertSame(200, mb_strlen($err['error']));
    }
}
