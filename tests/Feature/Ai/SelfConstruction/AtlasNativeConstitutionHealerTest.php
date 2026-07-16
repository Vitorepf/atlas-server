<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasNativeConstitutionHealer;
use App\Services\Ai\SelfConstruction\AtlasNativeConstitutionScanner;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasNativeConstitutionHealerTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repo = sys_get_temp_dir().'/atlas-native-heal-'.bin2hex(random_bytes(5));
        File::ensureDirectoryExists($this->repo.'/Sources/AtlasCore');
        File::ensureDirectoryExists($this->repo.'/App/Atlas');
        File::put($this->repo.'/App/Atlas/UsesCore.swift', "import Foundation\n");
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->repo);

        parent::tearDown();
    }

    public function test_dry_run_plans_r2_file_deletion_without_mutating(): void
    {
        $path = $this->repo.'/Sources/AtlasCore/DeadSymbol.swift';
        File::put($path, "public struct OrphanTool {\n    public init() {}\n}\n");

        $finding = $this->r2Finding();
        $receipt = $this->healer()->heal($this->repo, (string) $finding['finding_hash'], execute: false);

        $this->assertSame(AtlasNativeConstitutionHealer::SCHEMA_VERSION, $receipt['schema_version']);
        $this->assertSame('dry_run_planned', $receipt['status']);
        $this->assertSame('R2', $receipt['rule_id']);
        $this->assertSame(['Sources/AtlasCore/DeadSymbol.swift'], $receipt['files_to_delete']);
        $this->assertFalse($receipt['executed']);
        $this->assertFalse($receipt['mutated']);
        $this->assertFileExists($path);
    }

    public function test_execute_deletes_dead_symbol_file_and_clears_finding(): void
    {
        $path = $this->repo.'/Sources/AtlasCore/DeadSymbol.swift';
        File::put($path, "public struct OrphanTool {\n    public init() {}\n}\n");

        $finding = $this->r2Finding();
        $receipt = $this->healer()->heal($this->repo, (string) $finding['finding_hash'], execute: true);

        $this->assertSame('healed', $receipt['status']);
        $this->assertTrue($receipt['executed']);
        $this->assertTrue($receipt['mutated']);
        $this->assertSame(['Sources/AtlasCore/DeadSymbol.swift'], $receipt['files_deleted']);
        $this->assertFileDoesNotExist($path);
        $this->assertFalse($receipt['finding_present_after']);
        $this->assertSame(0, $receipt['finding_count_after']);
    }

    public function test_refuses_observe_findings(): void
    {
        File::put(
            $this->repo.'/App/Atlas/GiantView.swift',
            implode("\n", array_fill(0, 401, '// line')),
        );

        $report = (new AtlasNativeConstitutionScanner)->scan($this->repo);
        $hash = (string) $report['findings'][0]['finding_hash'];

        $receipt = $this->healer()->heal($this->repo, $hash, execute: true);

        $this->assertSame('blocked', $receipt['status']);
        $this->assertSame('policy_not_heal', $receipt['reason']);
        $this->assertFalse($receipt['mutated']);
    }

    public function test_refuses_unknown_hash(): void
    {
        $receipt = $this->healer()->heal($this->repo, 'sha1:deadbeef', execute: false);

        $this->assertSame('blocked', $receipt['status']);
        $this->assertSame('finding_not_found', $receipt['reason']);
    }

    public function test_command_emits_json_receipt(): void
    {
        File::put(
            $this->repo.'/Sources/AtlasCore/DeadSymbol.swift',
            "public struct OrphanTool {\n    public init() {}\n}\n",
        );
        $finding = $this->r2Finding();

        $this->artisan('atlas:native:constitution-heal', [
            '--repo' => $this->repo,
            '--finding-hash' => $finding['finding_hash'],
            '--json' => true,
        ])->assertExitCode(0);
    }

    /** @return array<string,mixed> */
    private function r2Finding(): array
    {
        $report = (new AtlasNativeConstitutionScanner)->scan($this->repo);
        $finding = collect($report['findings'])->firstWhere('rule_id', 'R2');
        $this->assertIsArray($finding);

        return $finding;
    }

    private function healer(): AtlasNativeConstitutionHealer
    {
        return new AtlasNativeConstitutionHealer(new AtlasNativeConstitutionScanner);
    }
}
