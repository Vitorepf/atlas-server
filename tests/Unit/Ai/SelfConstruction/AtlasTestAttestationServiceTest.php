<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasTestAttestationService;
use PHPUnit\Framework\TestCase;

final class AtlasTestAttestationServiceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/atlas-test-attestation-'.bin2hex(random_bytes(4));
        @mkdir($this->root.'/app', 0775, true);
        file_put_contents($this->root.'/app/Foo.php', "<?php\nclass Foo {}\n");
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_valid_attestation_binds_recognized_runner_counts_and_tree_hash(): void
    {
        $service = new AtlasTestAttestationService;
        $paths = ['app/Foo.php'];
        $treeHash = $service->stateHash($this->root, $paths);

        $attestation = $service->attest(
            runner: 'phpunit',
            suite: ['tests/Unit/FooTest.php'],
            nTests: 1,
            nAssertions: 2,
            exitCode: 0,
            treeHash: $treeHash,
        );

        $this->assertSame('valid', $attestation['status']);
        $this->assertSame([], $service->validate($attestation, $treeHash)['blockers']);
    }

    public function test_zero_tests_with_exit_zero_is_vacuous_never_green(): void
    {
        $service = new AtlasTestAttestationService;
        $treeHash = $service->stateHash($this->root, ['app/Foo.php']);

        $attestation = $service->attest('phpunit', ['tests/Unit/FooTest.php'], 0, 0, 0, $treeHash);

        $this->assertSame('vacuous', $attestation['status']);
        $this->assertContains('vacuous', $service->validate($attestation, $treeHash)['blockers']);
    }

    public function test_unrecognized_runner_and_changed_tree_are_blockers(): void
    {
        $service = new AtlasTestAttestationService;
        $treeHash = $service->stateHash($this->root, ['app/Foo.php']);
        $attestation = $service->attest('unknown-runner', ['tests/Unit/FooTest.php'], 1, 1, 0, $treeHash);

        file_put_contents($this->root.'/app/Foo.php', "<?php\nclass Foo { public function changed(): bool { return true; } }\n");
        $current = $service->stateHash($this->root, ['app/Foo.php']);
        $verdict = $service->validate($attestation, $current);

        $this->assertContains('runner_unrecognized', $verdict['blockers']);
        $this->assertContains('attestation_stale', $verdict['blockers']);
    }

    private function deleteDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (array_diff(scandir($dir) ?: [], ['.', '..']) as $entry) {
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            is_dir($path) ? $this->deleteDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
