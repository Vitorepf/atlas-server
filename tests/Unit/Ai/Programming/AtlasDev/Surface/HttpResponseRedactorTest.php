<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Surface;

use App\Services\Ai\Programming\AtlasDev\Surface\HttpResponseRedactor;
use PHPUnit\Framework\TestCase;

final class HttpResponseRedactorTest extends TestCase
{
    public function test_artifact_ref_is_relative_with_receipts_prefix(): void
    {
        $redactor = new HttpResponseRedactor;

        $ref = $redactor->artifactRef('dev-1234-abcd', 'operation_envelope.json');

        $this->assertSame('receipts/dev-1234-abcd/operation_envelope.json', $ref);
        $this->assertDoesNotMatchRegularExpression('@^/@', $ref);
    }

    public function test_artifact_refs_handles_absolute_filenames_via_basename(): void
    {
        $redactor = new HttpResponseRedactor;

        $refs = $redactor->artifactRefs('dev-1', [
            'envelope' => '/var/lib/atlas/dev/receipts/dev-1/envelope.json',
            'task_contract' => '/var/lib/atlas/dev/receipts/dev-1/task_contract.json',
        ]);

        $this->assertSame([
            'envelope' => 'receipts/dev-1/envelope.json',
            'task_contract' => 'receipts/dev-1/task_contract.json',
        ], $refs);
    }

    public function test_workspace_label_returns_basename(): void
    {
        $redactor = new HttpResponseRedactor;

        $this->assertSame('atlas-server', $redactor->workspaceLabel('/Users/op/code/atlas-server'));
        $this->assertSame('atlas-server', $redactor->workspaceLabel('/Users/op/code/atlas-server/'));
        $this->assertSame('', $redactor->workspaceLabel(''));
        $this->assertSame('', $redactor->workspaceLabel('/'));
    }

    public function test_redact_workspace_strips_absolute_prefix_recursively(): void
    {
        $redactor = new HttpResponseRedactor;

        $payload = [
            'workspace' => '/Users/op/code/atlas-server',
            'expected_files' => [
                '/Users/op/code/atlas-server/app/Foo.php',
                '/Users/op/code/atlas-server/tests/FooTest.php',
                'relative/already.php',
            ],
            'nested' => [
                'ref' => '/Users/op/code/atlas-server/docs/atlas.md',
                'hash' => 'abc123',
                'count' => 7,
            ],
        ];

        $out = $redactor->redactWorkspaceIn($payload, '/Users/op/code/atlas-server');

        $this->assertSame('atlas-server', $out['workspace']);
        $this->assertSame([
            'atlas-server/app/Foo.php',
            'atlas-server/tests/FooTest.php',
            'relative/already.php',
        ], $out['expected_files']);
        $this->assertSame('atlas-server/docs/atlas.md', $out['nested']['ref']);
        $this->assertSame('abc123', $out['nested']['hash']);
        $this->assertSame(7, $out['nested']['count']);
    }

    public function test_redact_workspace_is_noop_when_workspace_blank(): void
    {
        $redactor = new HttpResponseRedactor;

        $payload = ['path' => '/Users/op/code/atlas-server/foo.php'];
        $this->assertSame($payload, $redactor->redactWorkspaceIn($payload, ''));
    }

    public function test_redact_workspace_does_not_touch_strings_outside_workspace(): void
    {
        $redactor = new HttpResponseRedactor;

        $payload = [
            'a' => '/etc/passwd',
            'b' => '/Users/op/other-repo/foo.php',
        ];
        $out = $redactor->redactWorkspaceIn($payload, '/Users/op/code/atlas-server');

        $this->assertSame($payload, $out);
    }

    public function test_redact_storage_converts_absolute_storage_paths_to_refs(): void
    {
        $redactor = new HttpResponseRedactor;

        $payload = [
            'paths' => [
                'a' => '/var/lib/atlas-dev/receipts/dev-9/envelope.json',
                'b' => '/var/lib/atlas-dev/receipts/dev-9/task_contract.json',
            ],
            'untouched' => '/Users/op/elsewhere.txt',
        ];

        // storage base is the receipts root — paths under it become `receipts/<rest>`.
        $out = $redactor->redactStorageIn($payload, '/var/lib/atlas-dev/receipts');

        $this->assertSame('receipts/dev-9/envelope.json', $out['paths']['a']);
        $this->assertSame('receipts/dev-9/task_contract.json', $out['paths']['b']);
        $this->assertSame('/Users/op/elsewhere.txt', $out['untouched']);
    }

    public function test_no_absolute_path_survives_combined_redaction(): void
    {
        $redactor = new HttpResponseRedactor;

        $payload = [
            'workspace' => '/Users/op/code/atlas-server',
            'persisted' => '/var/lib/atlas-dev/receipts/dev-x/receipt.json',
            'file' => '/Users/op/code/atlas-server/app/Foo.php',
        ];

        $out = $redactor->redactWorkspaceIn(
            $redactor->redactStorageIn($payload, '/var/lib/atlas-dev/receipts'),
            '/Users/op/code/atlas-server',
        );

        $serialised = json_encode($out, JSON_UNESCAPED_SLASHES) ?: '';
        $this->assertStringNotContainsString('/Users/op', $serialised);
        $this->assertStringNotContainsString('/var/lib/atlas-dev', $serialised);
    }
}
