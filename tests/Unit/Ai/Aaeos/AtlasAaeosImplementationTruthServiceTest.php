<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos;

use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationEvidenceResolver;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationTruthService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasAaeosImplementationTruthServiceTest extends TestCase
{
    public function test_verified_when_all_evidence_resolves_green_and_claim_matches(): void
    {
        // B3 / criterion C2: verified now REQUIRES the test to be green (greenTestRun=true),
        // not merely resolved. With a green run, all-evidence-resolves => verified.
        $result = $this->service()->evaluate('runtime_verified', [
            $this->res('symbol', true),
            $this->res('route', true),
            $this->res('test', true),
            $this->res('receipt', true),
        ], greenTestRun: true);

        $this->assertSame('verified', $result['computed_state']);
        $this->assertSame('verified', $result['claimed_state']);
        $this->assertFalse($result['drift']);
        $this->assertSame([], $result['unmet_evidence']);
        $this->assertTrue($result['resolved']['test_green']);
        $this->assertSame('green_run', $result['test_resolution']);
    }

    /**
     * B3 / criterion C2 — the assertion that CHANGED, and the change is the point:
     * the SAME all-evidence-resolves input WITHOUT a green run (existence-only) no
     * longer reaches verified. It computes partial with test_resolution=existence_only_unrun.
     * If the old existence-only=verified logic were restored, this test fails.
     */
    public function test_all_evidence_resolves_but_no_green_run_is_partial_not_verified(): void
    {
        $resolutions = [
            $this->res('symbol', true),
            $this->res('route', true),
            $this->res('test', true),
            $this->res('receipt', true),
        ];

        // greenTestRun=false (existence-only proof) and null (unknown) both stay partial.
        foreach ([false, null] as $green) {
            $result = $this->service()->evaluate('runtime_verified', $resolutions, $green);
            $this->assertSame('partial', $result['computed_state'], 'existence-only must not be verified');
            $this->assertFalse($result['resolved']['test_green']);
            $this->assertSame('existence_only_unrun', $result['test_resolution']);
            $this->assertTrue($result['drift'], 'claiming verified on existence-only is an over-claim');
        }
    }

    public function test_over_claim_drift_when_claim_exceeds_computed(): void
    {
        $result = $this->service()->evaluate('runtime_verified', [
            $this->res('symbol', true),
            $this->res('command', true),
            $this->res('test', false),
            $this->res('receipt', false),
        ]);

        $this->assertSame('partial', $result['computed_state']);
        $this->assertTrue($result['drift'], 'claiming verified with only symbol+command must be over-claim');
        $this->assertContains('needs >=1 resolved test for verified', $result['unmet_evidence']);
        $this->assertContains('needs >=1 resolved receipt (evidence file) for verified', $result['unmet_evidence']);
    }

    public function test_spec_when_nothing_resolves_and_spec_claim_has_no_drift(): void
    {
        $result = $this->service()->evaluate('spec_only', [
            $this->res('symbol', false),
            $this->res('route', false),
        ]);

        $this->assertSame('spec', $result['computed_state']);
        $this->assertFalse($result['drift']);
    }

    public function test_partial_requires_symbol_plus_wiring(): void
    {
        // symbol alone (no route/command) is not enough for partial.
        $symbolOnly = $this->service()->evaluate('spec_only', [$this->res('symbol', true)]);
        $this->assertSame('spec', $symbolOnly['computed_state']);
        $this->assertContains('needs >=1 resolved route or command for partial', $symbolOnly['unmet_evidence']);

        $symbolPlusRoute = $this->service()->evaluate('spec_only', [
            $this->res('symbol', true),
            $this->res('route', true),
        ]);
        $this->assertSame('partial', $symbolPlusRoute['computed_state']);
    }

    public function test_under_claim_is_not_drift(): void
    {
        $result = $this->service()->evaluate('spec_only', [
            $this->res('symbol', true),
            $this->res('route', true),
        ]);

        $this->assertSame('partial', $result['computed_state']);
        $this->assertFalse($result['drift']);
        $this->assertTrue($result['under_claim']);
    }

    public function test_normalize_state_maps_existing_vocabulary(): void
    {
        $service = $this->service();

        $this->assertSame('verified', $service->normalizeState('runtime_verified'));
        $this->assertSame('verified', $service->normalizeState('solid_runtime'));
        $this->assertSame('partial', $service->normalizeState('partial_runtime'));
        $this->assertSame('partial', $service->normalizeState('implemented_partial'));
        $this->assertSame('spec', $service->normalizeState('spec_only'));
        $this->assertSame('spec', $service->normalizeState('backlog_only_no_runtime'));
        $this->assertSame('spec', $service->normalizeState('north_star'));
        $this->assertSame('spec', $service->normalizeState(''));
    }

    /**
     * PIP-01 (a): same impl file via duplicate / reordered path projections => identical hash;
     * content change => different hash.
     */
    public function test_impl_files_hash_is_stable_for_path_projection_and_sensitive_to_content(): void
    {
        $relativePath = 'tests/Unit/Ai/Aaeos/Fixtures/pip01-impl.php';
        $absolutePath = base_path($relativePath);
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }
        file_put_contents($absolutePath, '<?php // pip01-v1');

        $refs = [['kind' => 'symbol', 'ref' => 'Pip01TargetService']];
        $service = $this->serviceWithResolver(new OrderShufflingSymbolPathResolver($relativePath));

        $first = $service->explainImplFilesHash($refs)['impl_files_hash'];
        $second = $service->explainImplFilesHash($refs)['impl_files_hash'];
        $this->assertSame($first, $second);

        file_put_contents($absolutePath, '<?php // pip01-v2');
        $changed = $service->explainImplFilesHash($refs)['impl_files_hash'];
        $this->assertNotSame($first, $changed);

        @unlink($absolutePath);
    }

    /**
     * PIP-01 — explain lists path=>content_hash and matches the combined impl_files_hash.
     */
    public function test_explain_impl_files_hash_breaks_down_paths(): void
    {
        $relativePath = 'tests/Unit/Ai/Aaeos/Fixtures/pip01-explain.php';
        $absolutePath = base_path($relativePath);
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }
        file_put_contents($absolutePath, '<?php // explain-me');

        $refs = [['kind' => 'symbol', 'ref' => 'ExplainTargetService']];
        $service = $this->serviceWithResolver(new SinglePathSymbolResolver($relativePath));

        $explain = $service->explainImplFilesHash($refs);

        $this->assertIsArray($explain);
        $this->assertSame(AtlasImplementationTruthService::IMPL_FILES_HASH_FORMAT, $explain['format']);
        $this->assertArrayHasKey($relativePath, $explain['paths']);
        $this->assertSame(hash('sha256', '<?php // explain-me'), $explain['paths'][$relativePath]);
        $this->assertSame(
            $explain['impl_files_hash'],
            $service->explainImplFilesHash($refs)['impl_files_hash'],
        );

        @unlink($absolutePath);
    }

    /**
     * PIP-01 (b): suffix-set drift — a NEW indexed symbol that suffix-matches the ref
     * must NOT inflate the hashed path set when read from a FRESH resolver instance.
     */
    public function test_impl_files_hash_survives_suffix_matchable_symbol_added_on_reindex(): void
    {
        $this->seedCodeSymbolsTable();

        $canonicalFile = 'tests/Unit/Ai/Aaeos/Fixtures/pip01-canonical.php';
        $imposterFile = 'tests/Unit/Ai/Aaeos/Fixtures/pip01-imposter.php';
        foreach ([$canonicalFile, $imposterFile] as $relativePath) {
            $absolutePath = base_path($relativePath);
            if (! is_dir(dirname($absolutePath))) {
                mkdir(dirname($absolutePath), 0777, true);
            }
            file_put_contents($absolutePath, '<?php // '.basename($relativePath));
        }

        $this->seedSymbol('class', 'App\\Real\\Pip01AnchorService', $canonicalFile);
        $refs = [['kind' => 'symbol', 'ref' => 'Pip01AnchorService']];

        $before = $this->freshTruthService()->freshnessHashes($refs, 'ignored')['impl_files_hash'];
        $storedReceiptHash = $before;

        // Simulate post-reindex: another class whose FQN suffix-matches the short ref.
        $this->seedSymbol('class', 'App\\Ghost\\Pip01AnchorService', $imposterFile);
        $this->app->forgetScopedInstances();

        $after = $this->freshTruthService()->freshnessHashes($refs, 'ignored')['impl_files_hash'];

        $this->assertSame($before, $after, 'canonical FQN anchor must ignore suffix-only imposters');
        $this->assertSame($storedReceiptHash, $after, 'MED-01: fresh re-read matches pre-reindex receipt hash');

        @unlink(base_path($canonicalFile));
        @unlink(base_path($imposterFile));
    }

    /**
     * PIP-01 (c) MED-01 dual-read: two fresh resolver instances in the same process agree.
     */
    public function test_dual_read_impl_files_hash_matches_across_fresh_instances(): void
    {
        $this->seedCodeSymbolsTable();

        $relativePath = 'tests/Unit/Ai/Aaeos/Fixtures/pip01-dual.php';
        $absolutePath = base_path($relativePath);
        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }
        file_put_contents($absolutePath, '<?php // dual-read');

        $this->seedSymbol('class', 'App\\Real\\DualReadService', $relativePath);
        $refs = [['kind' => 'symbol', 'ref' => 'DualReadService']];

        $a = $this->freshTruthService()->freshnessHashes($refs, 'ignored')['impl_files_hash'];
        $this->app->forgetScopedInstances();
        $b = $this->freshTruthService()->freshnessHashes($refs, 'ignored')['impl_files_hash'];

        $this->assertSame($a, $b);

        @unlink($absolutePath);
    }

    private function seedCodeSymbolsTable(): void
    {
        $migration = require base_path(
            'database/migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php',
        );
        $migration->up();
        $this->assertTrue(Schema::hasTable('atlas_engineering_code_symbols'));
    }

    private function seedSymbol(string $type, string $name, string $filePath): void
    {
        AtlasEngineeringCodeSymbol::query()->create([
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => $filePath,
            'language' => 'php',
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => 'seed-'.md5($type.'|'.$name),
        ]);
    }

    private function freshTruthService(): AtlasImplementationTruthService
    {
        return new AtlasImplementationTruthService(
            new AtlasImplementationEvidenceResolver,
            new CanonicalDocsFrontmatterParser,
        );
    }

    private function serviceWithResolver(AtlasImplementationEvidenceResolver $resolver): AtlasImplementationTruthService
    {
        return new AtlasImplementationTruthService(
            $resolver,
            new CanonicalDocsFrontmatterParser,
        );
    }

    /**
     * @return array{kind:string, ref:string, resolved:bool, matched:?string}
     */
    private function res(string $kind, bool $resolved): array
    {
        return [
            'kind' => $kind,
            'ref' => $kind.'-ref',
            'resolved' => $resolved,
            'matched' => $resolved ? 'Matched\\'.$kind : null,
        ];
    }

    private function service(): AtlasImplementationTruthService
    {
        return new AtlasImplementationTruthService(
            new AtlasImplementationEvidenceResolver,
            new CanonicalDocsFrontmatterParser,
        );
    }
}

/** Returns the same path in different orders / with duplicates — hash must dedupe+sort. */
final class OrderShufflingSymbolPathResolver extends AtlasImplementationEvidenceResolver
{
    private int $calls = 0;

    public function __construct(private readonly string $path) {}

    public function resolveSymbolFilePaths(string $ref): array
    {
        $this->calls++;

        return match ($this->calls % 3) {
            1 => [$this->path, $this->path],
            2 => [$this->path],
            default => [$this->path, $this->path],
        };
    }
}

/** Single deterministic path for explain / content sensitivity tests. */
final class SinglePathSymbolResolver extends AtlasImplementationEvidenceResolver
{
    public function __construct(private readonly string $path) {}

    public function resolveSymbolFilePaths(string $ref): array
    {
        return [$this->path];
    }
}
