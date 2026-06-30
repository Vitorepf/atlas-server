<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\SelfExtension\AtlasLoopAutopoieticContractVerifier;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopAutopoieticContractVerifierTest extends TestCase
{
    private const NS = 'Acme\\NewScope';

    private string $base = '';

    private string $nsDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir().'/atlas-autopoiesis-'.bin2hex(random_bytes(6));
        $this->nsDir = $this->base.'/Acme/NewScope';
        @mkdir($this->nsDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->base !== '' && is_dir($this->base)) {
            (new Process(['rm', '-rf', $this->base]))->run();
        }
        parent::tearDown();
    }

    public function test_fresh_untouched_bootstrap_output_passes(): void
    {
        $manifest = $this->writeScope();

        $report = (new AtlasLoopAutopoieticContractVerifier)->verify($manifest);

        $this->assertTrue($report->ok, json_encode($report->violations));
        $this->assertSame([], $report->violations);
    }

    public function test_single_byte_mutation_trips_manifest_sha_mismatch(): void
    {
        $manifest = $this->writeScope(); // manifest carries the sha of the pristine files
        // Flip one byte in a stub AFTER the manifest is sealed.
        file_put_contents($manifest['primitives']['loop'], ' ', FILE_APPEND);

        $report = (new AtlasLoopAutopoieticContractVerifier)->verify($manifest);

        $this->assertFalse($report->ok);
        $this->assertContains('MANIFEST_SHA_MISMATCH', $this->codes($report->violations));
    }

    public function test_renamed_method_trips_contract_method_missing(): void
    {
        $primitives = $this->paths();
        // Rewrite the loop stub with the required method RENAMED, then re-seal the manifest sha so only the
        // method violation is isolated.
        file_put_contents($primitives['loop'], $this->loopStub(self::NS, 'nextCandidateRENAMED'));
        $manifest = $this->manifestFor($primitives);

        $report = (new AtlasLoopAutopoieticContractVerifier)->verify($manifest);

        $this->assertFalse($report->ok);
        $this->assertContains('CONTRACT_METHOD_MISSING', $this->codes($report->violations));
        $this->assertNotContains('MANIFEST_SHA_MISMATCH', $this->codes($report->violations), 'sha re-sealed; only the method violation should fire');
    }

    public function test_namespace_drift_trips_contract_namespace_mismatch(): void
    {
        $primitives = $this->paths();
        file_put_contents($primitives['cortex'], $this->cortexStub('Acme\\WrongScope')); // drifted namespace
        $manifest = $this->manifestFor($primitives);

        $report = (new AtlasLoopAutopoieticContractVerifier)->verify($manifest);

        $this->assertFalse($report->ok);
        $this->assertContains('CONTRACT_NAMESPACE_MISMATCH', $this->codes($report->violations));
    }

    public function test_passing_report_exposes_task_fabric_readiness_true_in_to_array(): void
    {
        $manifest = $this->writeScope();

        $arr = (new AtlasLoopAutopoieticContractVerifier)->verify($manifest)->toArray();

        $this->assertArrayHasKey('task_fabric_readiness', $arr);
        $r = $arr['task_fabric_readiness'];
        $this->assertTrue($r['readiness_to_enqueue'], 'clean scope must be ready to enqueue');
        $this->assertSame([], $r['blockers']);
        $this->assertSame('enqueue_ready', $r['safe_next_action']);
        $this->assertNotEmpty($r['required_evidence']);
        $this->assertNotEmpty($r['allowed_file_roots']);
    }

    public function test_missing_primitive_files_yield_readiness_false_with_blockers(): void
    {
        // Build manifest pointing to non-existent files so CONTRACT_FILE_MISSING fires.
        $missing = [
            'loop' => $this->nsDir.'/LoopSubstrateContract.php',
            'cortex' => $this->nsDir.'/CortexComprehensionContract.php',
            'maestro' => $this->nsDir.'/MaestroOrchestrationContract.php',
        ]; // files NOT written
        $manifest = [
            'scope_id' => 'broken-scope',
            'namespace' => self::NS,
            'roots' => [$this->base],
            'primitives' => $missing,
            'manifest_sha256' => '',
        ];

        $arr = (new AtlasLoopAutopoieticContractVerifier)->verify($manifest)->toArray();

        $r = $arr['task_fabric_readiness'];
        $this->assertFalse($r['readiness_to_enqueue'], 'missing files must not be ready');
        $this->assertNotEmpty($r['blockers'], 'blockers must list the violations');
        $this->assertSame('fix_missing_primitive_files', $r['safe_next_action']);
    }

    public function test_verify_is_side_effect_free_and_fast(): void
    {
        $manifest = $this->writeScope();
        $before = $this->snapshotDir();

        $start = microtime(true);
        (new AtlasLoopAutopoieticContractVerifier)->verify($manifest);
        $elapsedMs = (microtime(true) - $start) * 1000;

        $this->assertLessThan(250.0, $elapsedMs, 'verify must run under 250ms');
        $this->assertSame($before, $this->snapshotDir(), 'verify must perform zero file writes');
    }

    /**
     * @return array<string,mixed>
     */
    private function writeScope(): array
    {
        $primitives = $this->paths();
        file_put_contents($primitives['loop'], $this->loopStub(self::NS, 'nextCandidate'));
        file_put_contents($primitives['cortex'], $this->cortexStub(self::NS));
        file_put_contents($primitives['maestro'], $this->maestroStub(self::NS));

        return $this->manifestFor($primitives);
    }

    /**
     * @return array{loop:string,cortex:string,maestro:string}
     */
    private function paths(): array
    {
        return [
            'loop' => $this->nsDir.'/LoopSubstrateContract.php',
            'cortex' => $this->nsDir.'/CortexComprehensionContract.php',
            'maestro' => $this->nsDir.'/MaestroOrchestrationContract.php',
        ];
    }

    /**
     * @param  array{loop:string,cortex:string,maestro:string}  $primitives
     * @return array<string,mixed>
     */
    private function manifestFor(array $primitives): array
    {
        return [
            'scope_id' => 'new-scope',
            'namespace' => self::NS,
            'roots' => [$this->base],
            'primitives' => $primitives,
            'manifest_sha256' => (new AtlasLoopAutopoieticContractVerifier)->recomputeManifestSha($primitives),
        ];
    }

    private function loopStub(string $ns, string $method): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$ns};\n\ninterface LoopSubstrateContract\n{\n    public function {$method}(): ?CandidateRef;\n}\n";
    }

    private function cortexStub(string $ns): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$ns};\n\ninterface CortexComprehensionContract\n{\n    public function comprehend(string \$scopeId): ComprehensionReport;\n}\n";
    }

    private function maestroStub(string $ns): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace {$ns};\n\ninterface MaestroOrchestrationContract\n{\n    public function orchestrate(array \$plan): OrchestrationReceipt;\n}\n";
    }

    /**
     * @param  list<array{code:string,detail:string}>  $violations
     * @return list<string>
     */
    private function codes(array $violations): array
    {
        return array_map(static fn (array $v): string => $v['code'], $violations);
    }

    /**
     * @return array<string,string>
     */
    private function snapshotDir(): array
    {
        $out = [];
        foreach ((array) glob($this->nsDir.'/*') as $f) {
            $f = (string) $f;
            $out[$f] = sha1((string) file_get_contents($f));
        }
        ksort($out);

        return $out;
    }
}
