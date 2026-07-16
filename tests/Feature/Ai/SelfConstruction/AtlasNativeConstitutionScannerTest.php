<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasNativeConstitutionScanner;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasNativeConstitutionScannerTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = sys_get_temp_dir().'/atlas-native-constitution-'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($this->repo);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    public function test_r1_flags_oversized_view_as_observe(): void
    {
        $this->write('App/Atlas/GiantView.swift', implode("\n", array_fill(0, 401, '// line')));

        $finding = $this->singleFinding();

        $this->assertSame('R1', $finding['rule_id']);
        $this->assertSame('file_over_limit', $finding['rule_slug']);
        $this->assertSame('observe', $finding['policy']);
        $this->assertSame('medium', $finding['severity']);
        $this->assertSame('App/Atlas/GiantView.swift', $finding['target']);
        $this->assertSame(['line_count' => 401, 'limit' => 400], $finding['evidence']['measure']);
    }

    public function test_r2_flags_dead_public_atlas_core_symbol_as_heal(): void
    {
        $this->write('Sources/AtlasCore/DeadSymbol.swift', "public struct OrphanTool {\n    public init() {}\n}\n");

        $finding = $this->singleFinding();

        $this->assertSame('R2', $finding['rule_id']);
        $this->assertSame('dead_symbol', $finding['rule_slug']);
        $this->assertSame('heal', $finding['policy']);
        $this->assertSame('medium', $finding['severity']);
        $this->assertSame('Sources/AtlasCore/DeadSymbol.swift:OrphanTool', $finding['target']);
        $this->assertSame(['references' => 0], $finding['evidence']['measure']);
    }

    public function test_r3_flags_warning_count_above_baseline_as_heal(): void
    {
        $this->write('docs/evidence/perf-baseline/warnings.txt', "0\n");

        $finding = $this->singleFinding(['build_output' => "A.swift:1:1: warning: synthetic warning\n"]);

        $this->assertSame('R3', $finding['rule_id']);
        $this->assertSame('warning_regression', $finding['rule_slug']);
        $this->assertSame('heal', $finding['policy']);
        $this->assertSame('high', $finding['severity']);
        $this->assertSame(['warnings' => 1, 'baseline' => 0], $finding['evidence']['measure']);
    }

    public function test_r4_flags_check_count_below_baseline_as_heal(): void
    {
        $this->write('docs/evidence/perf-baseline/checks.txt', "10\n");

        $finding = $this->singleFinding(['checks_output' => "9 checks passed\n"]);

        $this->assertSame('R4', $finding['rule_id']);
        $this->assertSame('check_count_regression', $finding['rule_slug']);
        $this->assertSame('heal', $finding['policy']);
        $this->assertSame('high', $finding['severity']);
        $this->assertSame(['checks' => 9, 'baseline' => 10], $finding['evidence']['measure']);
    }

    public function test_r5_flags_dead_theme_token_as_heal(): void
    {
        $this->write('App/Atlas/AtlasTheme.swift', "enum AtlasTheme {\n    static let live = Color.red\n    static let deadGold = Color.yellow\n}\n");
        $this->write('App/Atlas/UsesTheme.swift', "let _ = AtlasTheme.live\n");

        $finding = $this->singleFinding();

        $this->assertSame('R5', $finding['rule_id']);
        $this->assertSame('dead_theme_token', $finding['rule_slug']);
        $this->assertSame('heal', $finding['policy']);
        $this->assertSame('low', $finding['severity']);
        $this->assertSame('App/Atlas/AtlasTheme.swift:deadGold', $finding['target']);
        $this->assertSame(['references' => 0], $finding['evidence']['measure']);
    }

    public function test_clean_repo_returns_zero_findings(): void
    {
        $this->write('App/Atlas/TidyView.swift', "struct TidyView {}\n");
        $this->write('App/Atlas/AtlasTheme.swift', "enum AtlasTheme { static let accent = Color.yellow }\n");
        $this->write('App/Atlas/UsesTheme.swift', "let _ = AtlasTheme.accent\n");
        $this->write('Sources/AtlasCore/InternalOnly.swift', "struct InternalOnly {}\n");

        $report = $this->scanner()->scan($this->repo);

        $this->assertSame(0, $report['finding_count']);
        $this->assertSame([], $report['findings']);
    }

    public function test_finding_hash_is_idempotent_for_same_rule_and_target(): void
    {
        $this->write('App/Atlas/GiantView.swift', implode("\n", array_fill(0, 401, '// line')));

        $first = $this->scanner()->scan($this->repo);
        $second = $this->scanner()->scan($this->repo);

        $this->assertSame($first['findings'][0]['finding_hash'], $second['findings'][0]['finding_hash']);
        $this->assertStringStartsWith('sha1:', $first['findings'][0]['finding_hash']);
    }

    public function test_command_emits_json_and_writes_existing_backlog_finding_cache(): void
    {
        $this->write('App/Atlas/GiantView.swift', implode("\n", array_fill(0, 401, '// line')));
        $path = $this->repo.'/scanner-findings.json';

        $this->artisan('atlas:native:constitution-scan', [
            '--repo' => $this->repo,
            '--json' => true,
            '--file-findings' => true,
            '--findings-path' => $path,
        ])->assertExitCode(0);

        $this->assertFileExists($path);
        $payload = json_decode((string) file_get_contents($path), true);
        $this->assertSame('atlas.native.constitution_scan.v1', $payload['schema_version']);
        $this->assertSame(1, $payload['finding_count']);
        $this->assertSame('R1', $payload['findings'][0]['rule_id']);
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function singleFinding(array $options = []): array
    {
        $report = $this->scanner()->scan($this->repo, $options);

        $this->assertSame(1, $report['finding_count'], json_encode($report['findings'], JSON_PRETTY_PRINT) ?: '');

        return $report['findings'][0];
    }

    private function scanner(): AtlasNativeConstitutionScanner
    {
        return new AtlasNativeConstitutionScanner;
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->repo.'/'.$relative;
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
    }
}
