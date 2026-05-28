<?php

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\ProgrammingPythonRuntimeGraphProjector;
use Tests\TestCase;

class ProgrammingPythonRuntimeGraphProjectorTest extends TestCase
{
    public function test_project_returns_empty_fragment_when_receipt_has_no_files(): void
    {
        $fragment = app(ProgrammingPythonRuntimeGraphProjector::class)->project([
            'receipt_hash' => str_repeat('a', 64),
            'result' => ['files' => []],
        ]);

        $this->assertSame('atlas.programming.python_runtime.graph_fragment.v1', $fragment['schema_version']);
        $this->assertSame('empty', $fragment['status']);
        $this->assertSame(0, $fragment['node_count']);
        $this->assertSame(0, $fragment['edge_count']);
        $this->assertSame([], $fragment['nodes']);
        $this->assertSame([], $fragment['edges']);
        $this->assertSame(str_repeat('a', 64), $fragment['source_receipt_hash']);
    }

    public function test_project_skips_non_array_file_entries_and_files_with_empty_path(): void
    {
        $fragment = app(ProgrammingPythonRuntimeGraphProjector::class)->project([
            'result' => [
                'files' => [
                    'not-an-array',
                    ['path' => ''],
                    ['language' => 'python'],
                ],
            ],
        ]);

        $this->assertSame('empty', $fragment['status']);
        $this->assertSame(0, $fragment['node_count']);
    }

    public function test_project_builds_file_symbol_and_import_nodes_and_edges(): void
    {
        $receiptHash = str_repeat('b', 64);

        $fragment = app(ProgrammingPythonRuntimeGraphProjector::class)->project([
            'receipt_hash' => $receiptHash,
            'result' => [
                'files' => [
                    [
                        'path' => 'app/models/user.py',
                        'language' => 'python',
                        'source_hash' => str_repeat('c', 64),
                        'symbols' => [
                            ['name' => 'User', 'kind' => 'class', 'line' => 12],
                            ['name' => '', 'kind' => 'function'],
                            ['kind' => 'function'],
                            'invalid',
                        ],
                        'imports' => ['django.db.models', '', 123],
                    ],
                ],
            ],
        ]);

        $this->assertSame('ready', $fragment['status']);
        $this->assertSame($receiptHash, $fragment['source_receipt_hash']);
        $this->assertSame(2, $fragment['node_count']);
        $this->assertSame(2, $fragment['edge_count']);

        $this->assertSame([
            'kind' => 'file',
            'path' => 'app/models/user.py',
            'language' => 'python',
            'reason' => 'python_runtime_file_analysis',
            'source' => 'programming_python_runtime',
            'hash' => str_repeat('c', 64),
        ], $fragment['nodes'][0]);

        $this->assertSame([
            'kind' => 'symbol',
            'path' => 'app/models/user.py',
            'symbol' => 'User',
            'symbol_kind' => 'class',
            'line' => 12,
            'reason' => 'python_runtime_symbol_analysis',
            'source' => 'programming_python_runtime',
            'id' => 'app/models/user.py::User',
        ], $fragment['nodes'][1]);

        $this->assertSame([
            'type' => 'declares_symbol',
            'from' => 'app/models/user.py',
            'to' => 'app/models/user.py::User',
            'source' => 'programming_python_runtime',
        ], $fragment['edges'][0]);

        $this->assertSame([
            'type' => 'imports',
            'from' => 'app/models/user.py',
            'to' => 'django.db.models',
            'source' => 'programming_python_runtime',
        ], $fragment['edges'][1]);
    }
}
