<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphRuntimeInvoker;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AP-815 · D7 — END-TO-END RETRIEVAL-QUALITY test for the `atlas:ctx` context pack.
 *
 * The I-2 command test ({@see AtlasCodeGraphContextCommandTest}) proves the plumbing:
 * a single seeded symbol is found, packed, budgeted, workspace-scoped and fail-safe.
 * This block proves something stronger and product-facing — RETRIEVAL QUALITY: when a
 * realistic workspace holds a MIX of on-topic and off-topic symbols, the assembled pack
 * surfaces the RIGHT ones for a natural-language query (high recall on the relevant set)
 * and does NOT drown them in unrelated distractors, while still honouring the token
 * budget. This is the "did we retrieve the correct thing, not just any keyword echo"
 * guarantee that a context engine lives or dies on.
 *
 * Labeled scenario (the oracle here is the COMMAND'S OWN BEHAVIOUR — characterization,
 * not a spec): for the query "payment gateway charge" the workspace 'atlas-server'
 * contains 3 clearly RELEVANT symbols (a PaymentGateway class, its interface, and a
 * ChargeProcessor) plus 8 clearly UNRELATED distractors (auth, mail, cache, logging,
 * etc. — none of whose names contain "payment", "gateway" or "charge"). The contract
 * asserted is: every relevant symbol is recalled into the pack; zero distractors leak
 * in; and estimated_tokens <= budget.
 *
 * E-6 ranker note (mirrors the I-2 test's skip discipline): the python hybrid (BM25)
 * ranker engages only when config `atlas.code_graph.real_edges=true` (set here). When
 * the venv is absent the command degrades to the deterministic keyword fallback
 * (longest-name-first). The recall/precision/budget assertions hold in BOTH paths
 * because the relevant names literally contain the query terms (so they LIKE-match) and
 * the distractor names do not. The ONE assertion that depends on BM25 *ordering*
 * specifically is guarded: it probes the runtime via {@see CodeGraphRuntimeInvoker} and
 * skips when the hybrid path is unavailable rather than failing on the fallback order.
 *
 * Boots only the needed code-intelligence tables in setUp (the repo's established
 * pattern — full RefreshDatabase is unreliable here because a core migration is
 * pgsql-only SQL). Pattern + private symbol() helper copied from
 * {@see AtlasCodeGraphContextCommandTest}.
 */
final class CodeGraphRetrievalQualityTest extends TestCase
{
    private const TABLES = [
        'atlas_engineering_doc_links',
        'atlas_engineering_code_symbols',
        'atlas_engineering_code_modules',
        'atlas_engineering_code_file_snapshots',
    ];

    /** The query under test and its three labeled-relevant symbol names. */
    private const QUERY = 'payment gateway charge';

    private const RELEVANT = [
        'App\\Services\\Payment\\PaymentGateway',
        'App\\Services\\Payment\\PaymentGatewayInterface',
        'App\\Services\\Payment\\ChargeProcessor',
    ];

    /** Eight off-topic distractors; none contains payment / gateway / charge. */
    private const DISTRACTORS = [
        'App\\Services\\Auth\\AuthSessionGuard',
        'App\\Services\\Mail\\MailerDispatcher',
        'App\\Services\\Cache\\CacheWarmer',
        'App\\Services\\Logging\\AuditLogger',
        'App\\Services\\Search\\IndexBuilder',
        'App\\Services\\User\\ProfileNormalizer',
        'App\\Services\\Queue\\JobThrottle',
        'App\\Services\\Geo\\RegionResolver',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    /**
     * Insert an active symbol into the W-1-keyed read-model (copied from the I-2 test).
     */
    private function symbol(string $workspace, string $type, string $name, string $file, ?string $signature = null): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace,
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => $file,
            'language' => 'php',
            'signature' => $signature,
            'status' => 'active',
            'source_hash' => substr(hash('sha256', $workspace.$name.$file), 0, 64),
        ]);
    }

    /**
     * Seed the full labeled scenario (3 relevant + 8 distractors) under 'atlas-server'.
     * Distractors are seeded with PLAUSIBLE signatures so a naive ranker has real text
     * to (wrongly) latch onto — the relevant set must still win on the query terms.
     */
    private function seedScenario(): void
    {
        $this->symbol('atlas-server', 'class', self::RELEVANT[0], 'app/Services/Payment/PaymentGateway.php', 'class PaymentGateway implements PaymentGatewayInterface');
        $this->symbol('atlas-server', 'interface', self::RELEVANT[1], 'app/Services/Payment/PaymentGatewayInterface.php', 'interface PaymentGatewayInterface { public function charge(): void; }');
        $this->symbol('atlas-server', 'class', self::RELEVANT[2], 'app/Services/Payment/ChargeProcessor.php', 'class ChargeProcessor { public function charge(Money $amount): Receipt {} }');

        $this->symbol('atlas-server', 'class', self::DISTRACTORS[0], 'app/Services/Auth/AuthSessionGuard.php', 'class AuthSessionGuard { public function authenticate(): bool {} }');
        $this->symbol('atlas-server', 'class', self::DISTRACTORS[1], 'app/Services/Mail/MailerDispatcher.php', 'class MailerDispatcher { public function dispatch(Message $m): void {} }');
        $this->symbol('atlas-server', 'class', self::DISTRACTORS[2], 'app/Services/Cache/CacheWarmer.php', 'class CacheWarmer { public function warm(): void {} }');
        $this->symbol('atlas-server', 'class', self::DISTRACTORS[3], 'app/Services/Logging/AuditLogger.php', 'class AuditLogger { public function record(Event $e): void {} }');
        $this->symbol('atlas-server', 'class', self::DISTRACTORS[4], 'app/Services/Search/IndexBuilder.php', 'class IndexBuilder { public function build(): void {} }');
        $this->symbol('atlas-server', 'class', self::DISTRACTORS[5], 'app/Services/User/ProfileNormalizer.php', 'class ProfileNormalizer { public function normalize(User $u): array {} }');
        $this->symbol('atlas-server', 'class', self::DISTRACTORS[6], 'app/Services/Queue/JobThrottle.php', 'class JobThrottle { public function throttle(Job $j): void {} }');
        $this->symbol('atlas-server', 'class', self::DISTRACTORS[7], 'app/Services/Geo/RegionResolver.php', 'class RegionResolver { public function resolve(string $ip): string {} }');
    }

    /**
     * Run `atlas:ctx --json`, assert exit 0, return the decoded pack envelope + raw output.
     * (Copied from the I-2 test — the repo's established command-output pattern.)
     *
     * @param  array<string,mixed>  $params
     * @return array{exit:int, raw:string, json:array<string,mixed>}
     */
    private function callCtx(array $params): array
    {
        $params['--json'] = true;
        $exit = Artisan::call('atlas:ctx', $params);
        $raw = Artisan::output();

        $this->assertSame(0, $exit, 'atlas:ctx must always exit 0 (best-effort recall, never a gate)');

        $decoded = json_decode(trim($raw), true);
        $this->assertIsArray($decoded, 'the --json pack must be valid JSON');

        return ['exit' => $exit, 'raw' => $raw, 'json' => $decoded];
    }

    /**
     * The ordered list of 'sym:'-prefixed ids actually packed into pack.included.
     *
     * @param  array<string,mixed>  $pack
     * @return array<int,string>
     */
    private function includedIds(array $pack): array
    {
        return array_map(
            static fn (array $n): string => (string) ($n['id'] ?? ''),
            $pack['pack']['included'],
        );
    }

    /**
     * Faithful probe of the SAME gate the command uses (real_edges flag + python3/venv
     * + the runtime op succeeding). Returns true only when the hybrid (BM25) ranker would
     * actually re-order candidates; false when the command falls back to keyword order.
     * Used to guard the single ordering-specific assertion (the recall/budget asserts run
     * in both paths). real_edges must already be set on by the caller.
     */
    private function hybridRankerEngages(): bool
    {
        $input = [
            ['id' => 'sym:a', 'text' => 'payment gateway charge'],
            ['id' => 'sym:b', 'text' => 'unrelated cache warmer'],
        ];
        $receipt = CodeGraphRuntimeInvoker::mintReceipt('hybrid_rank', ['n' => count($input)], 'atlas:ctx-test');
        $result = app(CodeGraphRuntimeInvoker::class)->invoke(
            'hybrid_rank',
            ['query' => self::QUERY, 'candidates' => $input, 'weights' => ['lexical' => 1.0]],
            ['timeout_seconds' => 30],
            $receipt,
        );

        return ($result['status'] ?? '') === CodeGraphRuntimeInvoker::STATUS_SUCCEEDED;
    }

    // ---------------------------------------------------------------------------------

    public function test_pack_recalls_every_relevant_symbol_for_the_query(): void
    {
        config()->set('atlas.code_graph.real_edges', true);
        config()->set('atlas.code_graph.hybrid_rank', true);
        $this->seedScenario();

        // Budget generous enough to fit all 3 relevant signatures (each ~10-20 tokens).
        $out = $this->callCtx(['query' => self::QUERY, '--budget' => 4000])['json'];
        $ids = $this->includedIds($out);

        // recall@k = 3/3: every labeled-relevant symbol is in the pack.
        foreach (self::RELEVANT as $relevant) {
            $this->assertContains(
                'sym:'.$relevant,
                $ids,
                $relevant.' is clearly relevant to "'.self::QUERY.'" and must be recalled into the pack',
            );
        }
        $this->assertSame('atlas-server', $out['workspace_id']);
        $this->assertGreaterThanOrEqual(count(self::RELEVANT), $out['included_count']);
    }

    public function test_pack_excludes_every_unrelated_distractor(): void
    {
        config()->set('atlas.code_graph.real_edges', true);
        config()->set('atlas.code_graph.hybrid_rank', true);
        $this->seedScenario();

        $out = $this->callCtx(['query' => self::QUERY, '--budget' => 4000])['json'];
        $ids = $this->includedIds($out);

        // precision: NOT one off-topic symbol leaks in. The query terms appear in none of
        // the distractor names, so a correct retrieval keyword-floor never matches them —
        // this is the "right symbols, not any keyword match" guarantee.
        foreach (self::DISTRACTORS as $distractor) {
            $this->assertNotContains(
                'sym:'.$distractor,
                $ids,
                $distractor.' is unrelated to "'.self::QUERY.'" and must NOT pollute the pack',
            );
        }

        // The pack is EXACTLY the relevant set — no more, no less — under a roomy budget.
        sort($ids);
        $expected = array_map(static fn (string $n): string => 'sym:'.$n, self::RELEVANT);
        sort($expected);
        $this->assertSame($expected, $ids, 'a generous-budget pack should be precisely the relevant set');
    }

    public function test_pack_respects_the_token_budget(): void
    {
        config()->set('atlas.code_graph.real_edges', true);
        config()->set('atlas.code_graph.hybrid_rank', true);
        $this->seedScenario();

        // A budget that admits SOME but not all candidates, to exercise real truncation.
        $budget = 8;
        $out = $this->callCtx(['query' => self::QUERY, '--budget' => $budget])['json'];

        // The hard contract: the pack never exceeds the budget it was given.
        $this->assertSame($budget, (int) $out['budget']);
        $this->assertLessThanOrEqual(
            (int) $out['budget'],
            (int) $out['estimated_tokens'],
            'the assembled pack must never exceed the token budget',
        );

        // With a tight budget and 11 matching/total candidates, something was excluded.
        $this->assertTrue((bool) $out['truncated'], 'a tight budget over many candidates must truncate');
        $this->assertGreaterThanOrEqual(
            $out['included_count'],
            (int) $out['estimated_tokens'],
            'token cost is accounted per included node',
        );
    }

    public function test_recall_is_robust_to_query_phrasing(): void
    {
        // Same intent, different surface phrasing / order / casing. The relevant set must
        // still be recalled — retrieval keys on terms, not an exact string.
        config()->set('atlas.code_graph.real_edges', true);
        config()->set('atlas.code_graph.hybrid_rank', true);
        $this->seedScenario();

        $out = $this->callCtx(['query' => 'Charge the PAYMENT Gateway', '--budget' => 4000])['json'];
        $ids = $this->includedIds($out);

        foreach (self::RELEVANT as $relevant) {
            $this->assertContains('sym:'.$relevant, $ids, $relevant.' must survive re-phrased/re-cased query terms');
        }
        foreach (self::DISTRACTORS as $distractor) {
            $this->assertNotContains('sym:'.$distractor, $ids);
        }
    }

    public function test_hybrid_ranker_orders_relevant_symbols_first(): void
    {
        // Ordering-specific: the relevant symbols must occupy the TOP of the ranked pack.
        // This is only a meaningful BM25 claim when the hybrid runtime engages; otherwise
        // skip (the keyword fallback order is a separately-tested deterministic floor).
        config()->set('atlas.code_graph.real_edges', true);
        config()->set('atlas.code_graph.hybrid_rank', true);
        $this->seedScenario();

        if (! $this->hybridRankerEngages()) {
            $this->markTestSkipped('hybrid ranker runtime (venv) unavailable — keyword fallback order in effect.');
        }

        $out = $this->callCtx(['query' => self::QUERY, '--budget' => 4000])['json'];
        $ids = $this->includedIds($out);

        // Every relevant symbol must rank ABOVE every distractor that managed to be packed.
        // (Under a roomy budget distractors are excluded entirely, so the top-3 are the
        // relevant set; this asserts the ranker did not interleave a distractor first.)
        $relevantIds = array_map(static fn (string $n): string => 'sym:'.$n, self::RELEVANT);
        $topThree = array_slice($ids, 0, 3);
        sort($topThree);
        $sortedRelevant = $relevantIds;
        sort($sortedRelevant);

        $this->assertSame(
            $sortedRelevant,
            $topThree,
            'BM25 must rank the 3 query-relevant symbols as the top-3 of the pack',
        );
    }
}
