<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\DevRunControlSnapshotService;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\RunIndex\AtlasDevRunIndexRepository;
use PHPUnit\Framework\TestCase;

final class DevRunControlSnapshotServiceTest extends TestCase
{
    private string $baseDir;
    private DevRunControlSnapshotService $svc;

    protected function setUp(): void
    {
        $this->baseDir = sys_get_temp_dir().'/dev-snapshot-test-'.bin2hex(random_bytes(4));
        mkdir($this->baseDir, 0777, true);
        $this->svc = new DevRunControlSnapshotService(
            new ReceiptStorage($this->baseDir),
            new AtlasDevRunIndexRepository,
        );
    }

    protected function tearDown(): void
    {
        $this->rmdirRecursive($this->baseDir);
    }

    public function test_complete_run_yields_snapshot_with_all_fields(): void
    {
        $runId = 'run-complete';
        $this->writeArtifact($runId, ArtifactNames::OPERATION_ENVELOPE, [
            'request' => 'Add login page',
            'workspace' => 'atlas-server',
            'allowed_files' => ['app/Http/Controllers/LoginController.php'],
            'model' => 'claude-sonnet-4',
        ]);
        $this->writeArtifact($runId, ArtifactNames::COMPACT_SDD, [
            'risk_level' => 'medium',
            'confidence' => 0.7,
            'budget' => ['strategy' => 'balanced'],
        ]);
        $this->writeArtifact($runId, ArtifactNames::MINI_PROGRAMMING_SPEC, [
            'design_path' => 'add_controller_and_view',
            'workcells' => [['step' => 'impl'], ['step' => 'test']],
            'instruction_char_count' => 1200,
        ]);
        $this->writeArtifact($runId, ArtifactNames::VERIFICATION_RECEIPT, [
            'verdict' => 'passed',
            'passed' => true,
        ]);
        $this->writeArtifact($runId, ArtifactNames::SCOPE_GUARD_RECEIPT, [
            'passed' => true,
        ]);

        $result = $this->svc->snapshot($runId);

        $this->assertSame($runId, $result['run_id']);
        $this->assertSame('Add login page', $result['request']);
        $this->assertSame('claude-sonnet-4', $result['model_tier']);
        $this->assertSame('medium', $result['risk']['level']);
        $this->assertSame('add_controller_and_view', $result['plan']['design_path']);
        $this->assertSame(2, $result['plan']['workcell_count']);
        $this->assertSame([], $result['gaps']);
        $this->assertSame('done', $result['next_action']);
        $this->assertTrue($result['read_model']);
    }

    public function test_run_missing_verification_receipt_shows_gap_and_run_verification(): void
    {
        $runId = 'run-no-verification';
        $this->writeArtifact($runId, ArtifactNames::OPERATION_ENVELOPE, [
            'request' => 'Fix bug',
            'workspace' => 'atlas-server',
            'model' => 'gpt-4',
        ]);
        $this->writeArtifact($runId, ArtifactNames::COMPACT_SDD, ['risk_level' => 'low']);
        $this->writeArtifact($runId, ArtifactNames::MINI_PROGRAMMING_SPEC, [
            'design_path' => 'fix_bug',
            'workcells' => [],
        ]);

        $result = $this->svc->snapshot($runId);

        $this->assertSame([], $result['gaps']); // required artifacts present
        $this->assertFalse($result['proof']['verification_present']);
        $this->assertSame('run_verification', $result['next_action']);
    }

    public function test_blocked_quality_gate_yields_repair(): void
    {
        $runId = 'run-blocked';
        $this->writeArtifact($runId, ArtifactNames::OPERATION_ENVELOPE, [
            'request' => 'Bad change',
            'workspace' => 'atlas-server',
        ]);
        $this->writeArtifact($runId, ArtifactNames::COMPACT_SDD, ['risk_level' => 'high']);
        $this->writeArtifact($runId, ArtifactNames::MINI_PROGRAMMING_SPEC, [
            'design_path' => 'bad_change',
        ]);
        $this->writeArtifact($runId, ArtifactNames::SCOPE_GUARD_RECEIPT, [
            'passed' => false,
            'verdict' => 'blocked',
        ]);

        $result = $this->svc->snapshot($runId);

        $this->assertSame('blocked', $result['proof']['gate_verdict']);
        $this->assertSame('repair', $result['next_action']);
    }

    public function test_unknown_run_id_returns_not_found(): void
    {
        $result = $this->svc->snapshot('nonexistent-run');

        $this->assertSame('run_not_found', $result['error']);
        $this->assertArrayHasKey('recent_run_ids', $result);
        $this->assertTrue($result['read_model']);
    }

    private function writeArtifact(string $runId, string $name, array $data): void
    {
        $dir = $this->baseDir.'/'.$runId;
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir.'/'.$name, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function rmdirRecursive(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->rmdirRecursive($path) : unlink($path);
        }
        rmdir($dir);
    }
}
