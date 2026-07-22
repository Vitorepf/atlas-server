<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Migration;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationApprovalRequiredException;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationRegistry;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationRunner;
use App\Services\Ai\AutonomousEvolution\Migration\AtlasLoopSchemaMigrationVerificationFailedException;
use PHPUnit\Framework\TestCase;

final class AtlasLoopSchemaMigrationRunnerTest extends TestCase
{
    private string $envPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envPath = sys_get_temp_dir().'/atlas-loop-master-'.bin2hex(random_bytes(6)).'.env';
        AtlasLoopMasterSwitch::$envPathOverride = $this->envPath;
        file_put_contents($this->envPath, "ATLAS_LOOP_MASTER_ENABLED=true\n");
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->envPath);
        parent::tearDown();
    }

    public function test_runner_requires_matching_approval_token_and_leaves_snapshot_unchanged_without_it(): void
    {
        $registry = new AtlasLoopSchemaMigrationRegistry;
        $registry->register('atlas_loop_evidence_ledger', 1, 2, $this->appendVersionTransform(2), $this->versionVerifier(2));

        $fs = new FakeMigrationFilesystem([
            '/virtual/snapshot.json' => '{"artifact_kind":"atlas_loop_evidence_ledger","version":1}',
        ]);
        $runner = new AtlasLoopSchemaMigrationRunner($registry, $fs->ops());
        $original = $fs->files['/virtual/snapshot.json'];

        try {
            $runner->run('atlas_loop_evidence_ledger', 1, 2, '/virtual/snapshot.json', null);
            $this->fail('Expected approval exception.');
        } catch (AtlasLoopSchemaMigrationApprovalRequiredException $e) {
            $this->assertStringContainsString('Approval required', $e->getMessage());
        }

        $this->assertSame($original, $fs->files['/virtual/snapshot.json']);
        $this->assertSame([], array_filter($fs->operations, static fn (array $op): bool => in_array($op['op'], ['read', 'write', 'rename'], true)));
    }

    public function test_runner_aborts_and_preserves_original_snapshot_when_verifier_fails(): void
    {
        $registry = new AtlasLoopSchemaMigrationRegistry;
        $registry->register(
            'atlas_loop_decision_receipts',
            1,
            2,
            static fn (string $bytes): string => str_replace('"version":1', '"version":2', $bytes),
            static fn (string $bytes): bool => str_contains($bytes, '"verified":true'),
        );

        $fs = new FakeMigrationFilesystem([
            '/virtual/failing.json' => '{"artifact_kind":"atlas_loop_decision_receipts","version":1}',
        ]);
        $runner = new AtlasLoopSchemaMigrationRunner($registry, $fs->ops());
        $token = $runner->approvalTokenFor('atlas_loop_decision_receipts', 1, 2);
        $original = $fs->files['/virtual/failing.json'];

        try {
            $runner->run('atlas_loop_decision_receipts', 1, 2, '/virtual/failing.json', $token);
            $this->fail('Expected verifier failure.');
        } catch (AtlasLoopSchemaMigrationVerificationFailedException $e) {
            $this->assertStringContainsString('verification failed', strtolower($e->getMessage()));
        }

        $this->assertSame($original, $fs->files['/virtual/failing.json']);
        $this->assertSame([], array_filter($fs->operations, static fn (array $op): bool => in_array($op['op'], ['write', 'rename'], true)));
    }

    public function test_runner_short_circuits_before_read_or_write_when_master_switch_is_off(): void
    {
        file_put_contents($this->envPath, "ATLAS_LOOP_MASTER_ENABLED=false\n");

        $transformCalls = 0;
        $registry = new AtlasLoopSchemaMigrationRegistry;
        $registry->register(
            'atlas_loop_workspace_blueprints',
            1,
            2,
            function (string $bytes) use (&$transformCalls): string {
                $transformCalls++;

                return str_replace('"version":1', '"version":2', $bytes);
            },
            $this->versionVerifier(2),
        );

        $fs = new FakeMigrationFilesystem([
            '/virtual/master-off.json' => '{"artifact_kind":"atlas_loop_workspace_blueprints","version":1}',
        ]);
        $runner = new AtlasLoopSchemaMigrationRunner($registry, $fs->ops());
        $token = $runner->approvalTokenFor('atlas_loop_workspace_blueprints', 1, 2);

        $result = $runner->run('atlas_loop_workspace_blueprints', 1, 2, '/virtual/master-off.json', $token);

        $this->assertSame('master_off', $result);
        $this->assertSame(0, $transformCalls);
        $this->assertSame([], array_filter($fs->operations, static fn (array $op): bool => in_array($op['op'], ['read', 'write', 'rename'], true)));
    }

    private function appendVersionTransform(int $targetVersion): callable
    {
        return static function (string $snapshotBytes) use ($targetVersion): string {
            $payload = json_decode($snapshotBytes, true, flags: JSON_THROW_ON_ERROR);
            ksort($payload, SORT_STRING);
            $payload['version'] = $targetVersion;

            return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        };
    }

    private function versionVerifier(int $expectedVersion): callable
    {
        return static function (string $snapshotBytes) use ($expectedVersion): bool {
            $payload = json_decode($snapshotBytes, true);

            return is_array($payload) && ($payload['version'] ?? null) === $expectedVersion;
        };
    }
}

final class FakeMigrationFilesystem
{
    /**
     * @param  array<string, string>  $files
     */
    public function __construct(
        public array $files = [],
    ) {}

    /**
     * @var list<array<string, string>>
     */
    public array $operations = [];

    /**
     * @return array{
     *     is_file: callable(string):bool,
     *     read: callable(string):string|false,
     *     write: callable(string,string):int|false,
     *     rename: callable(string,string):bool,
     *     unlink: callable(string):void
     * }
     */
    public function ops(): array
    {
        return [
            'is_file' => function (string $path): bool {
                $this->operations[] = ['op' => 'is_file', 'path' => $path];

                return array_key_exists($path, $this->files);
            },
            'read' => function (string $path): string|false {
                $this->operations[] = ['op' => 'read', 'path' => $path];

                return $this->files[$path] ?? false;
            },
            'write' => function (string $path, string $contents): int|false {
                $this->operations[] = ['op' => 'write', 'path' => $path];
                $this->files[$path] = $contents;

                return strlen($contents);
            },
            'rename' => function (string $from, string $to): bool {
                $this->operations[] = ['op' => 'rename', 'path' => $from, 'target' => $to];
                $this->files[$to] = $this->files[$from];
                unset($this->files[$from]);

                return true;
            },
            'unlink' => function (string $path): void {
                $this->operations[] = ['op' => 'unlink', 'path' => $path];
                unset($this->files[$path]);
            },
        ];
    }
}
