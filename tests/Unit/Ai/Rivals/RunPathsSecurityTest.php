<?php

namespace Tests\Unit\Ai\Rivals;

use App\Services\Ai\Rivals\Support\RunPaths;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Tests\TestCase;

class RunPathsSecurityTest extends TestCase
{
    private string $root;

    private string $outside;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/rivals_path_root_'.uniqid();
        $this->outside = sys_get_temp_dir().'/rivals_path_outside_'.uniqid();
        File::ensureDirectoryExists($this->root);
        File::ensureDirectoryExists($this->outside);
        config()->set('atlas_rivals.storage_root', $this->root);
        config()->set('atlas_rivals.import_roots', [$this->root]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        File::deleteDirectory($this->outside);
        parent::tearDown();
    }

    public function test_run_and_case_identifiers_reject_traversal(): void
    {
        foreach (['../escape', '/absolute', "bad\0id", 'a/b'] as $invalid) {
            try {
                RunPaths::runDir($invalid);
                $this->fail("run id should fail: {$invalid}");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
            try {
                RunPaths::assertCaseId($invalid);
                $this->fail("case id should fail: {$invalid}");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_containment_rejects_parent_and_symlink_escape(): void
    {
        file_put_contents($this->outside.'/secret.txt', 'secret');
        symlink($this->outside.'/secret.txt', $this->root.'/escape.txt');

        try {
            RunPaths::resolveContained($this->root, '../outside/secret.txt');
            $this->fail('parent traversal should fail');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }

        $this->expectException(InvalidArgumentException::class);
        RunPaths::resolveContained($this->root, 'escape.txt');
    }

    public function test_import_source_must_be_inside_allowlisted_root(): void
    {
        file_put_contents($this->outside.'/results.json', '{}');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('outside_allowed_roots');
        RunPaths::assertImportSource($this->outside.'/results.json');
    }
}
