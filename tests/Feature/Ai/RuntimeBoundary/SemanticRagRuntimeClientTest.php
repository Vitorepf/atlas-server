<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\RuntimeBoundary;

use App\Services\Ai\RuntimeBoundary\PythonManifestRuntimeClient;
use App\Services\Ai\RuntimeBoundary\SemanticRagRuntimeClient;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves the PHP kernel really invokes the Python semantic/RAG runtime and gets
 * REAL embeddings back through the boundary — not a PHP fake. Gated on the
 * runtime being set up (scripts/setup-semantic-rag-runtime.sh); when absent the
 * e2e test skips honestly and the anti-fallback test asserts an explicit failure.
 */
final class SemanticRagRuntimeClientTest extends TestCase
{
    public function test_resident_daemon_serves_manifest_requests_and_writes_manifest(): void
    {
        [$runtimeRoot, $callsPath, $socketPath, $daemonManifestPath] = $this->fakeRuntimeRoot();

        config()->set('atlas.semantic_memory.embedding_daemon_enabled', true);
        config()->set('atlas.semantic_memory.embedding_daemon_auto_start', true);
        config()->set('atlas.semantic_memory.embedding_daemon_socket_path', $socketPath);
        config()->set('atlas.semantic_memory.embedding_daemon_manifest_path', $daemonManifestPath);
        config()->set('atlas.semantic_memory.embedding_daemon_startup_timeout_ms', 1500);

        $client = new SemanticRagRuntimeClient(new PythonManifestRuntimeClient(
            $runtimeRoot,
            'atlas-semantic-rag-test',
            'missing fake runtime',
            'semantic_rag_fake',
            5,
        ));

        $result = $client->embed(['x']);

        self::assertSame([[0.1, 0.2]], $result['vectors']);
        self::assertSame('daemon-model', $result['boundary']['model']);
        self::assertSame("daemon\n", (string) file_get_contents($callsPath));

        $manifest = json_decode((string) file_get_contents($daemonManifestPath), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('atlas.semantic_rag.daemon.manifest.v1', $manifest['schema_version']);
        self::assertSame('jsonl.unix_socket.v1', $manifest['protocol']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $manifest['manifest_sha256']);
    }

    public function test_daemon_socket_absence_falls_back_to_manifest_spawn(): void
    {
        [$runtimeRoot, $callsPath, $socketPath, $daemonManifestPath] = $this->fakeRuntimeRoot();

        @unlink(base_path($runtimeRoot.'/daemon.py'));
        config()->set('atlas.semantic_memory.embedding_daemon_enabled', true);
        config()->set('atlas.semantic_memory.embedding_daemon_auto_start', false);
        config()->set('atlas.semantic_memory.embedding_daemon_socket_path', $socketPath);
        config()->set('atlas.semantic_memory.embedding_daemon_manifest_path', $daemonManifestPath);

        $client = new SemanticRagRuntimeClient(new PythonManifestRuntimeClient(
            $runtimeRoot,
            'atlas-semantic-rag-test',
            'missing fake runtime',
            'semantic_rag_fake',
            5,
        ));

        $result = $client->embed(['x']);

        self::assertSame([[0.3, 0.4]], $result['vectors']);
        self::assertSame('spawn-model', $result['boundary']['model']);
        self::assertSame("spawn\n", (string) file_get_contents($callsPath));
    }

    public function test_late_interaction_rerank_sends_governed_runtime_operation(): void
    {
        [$runtimeRoot, $callsPath, $socketPath, $daemonManifestPath] = $this->fakeRuntimeRoot();

        config()->set('atlas.semantic_memory.embedding_daemon_enabled', true);
        config()->set('atlas.semantic_memory.embedding_daemon_auto_start', true);
        config()->set('atlas.semantic_memory.embedding_daemon_socket_path', $socketPath);
        config()->set('atlas.semantic_memory.embedding_daemon_manifest_path', $daemonManifestPath);

        $client = new SemanticRagRuntimeClient(new PythonManifestRuntimeClient(
            $runtimeRoot,
            'atlas-semantic-rag-test',
            'missing fake runtime',
            'semantic_rag_fake',
            5,
        ));

        $result = $client->lateInteractionRerank(
            [
                ['id' => 'a', 'text' => 'alpha'],
                ['id' => 'b', 'text' => 'beta'],
            ],
            'query',
            k: 1,
        );

        self::assertSame('late_interaction_rerank', $result['operation']);
        self::assertSame('b', $result['matches'][0]['id']);
        self::assertTrue($result['boundary']['late_interaction']);
        self::assertSame("daemon\n", (string) file_get_contents($callsPath));
    }

    public function test_cross_encoder_rerank_sends_governed_runtime_operation(): void
    {
        [$runtimeRoot, $callsPath, $socketPath, $daemonManifestPath] = $this->fakeRuntimeRoot();

        config()->set('atlas.semantic_memory.embedding_daemon_enabled', true);
        config()->set('atlas.semantic_memory.embedding_daemon_auto_start', true);
        config()->set('atlas.semantic_memory.embedding_daemon_socket_path', $socketPath);
        config()->set('atlas.semantic_memory.embedding_daemon_manifest_path', $daemonManifestPath);
        config()->set('atlas.aobg.cross_encoder_timeout_seconds', 3);

        $client = new SemanticRagRuntimeClient(new PythonManifestRuntimeClient(
            $runtimeRoot,
            'atlas-semantic-rag-test',
            'missing fake runtime',
            'semantic_rag_fake',
            5,
        ));

        $result = $client->crossEncoderRerank(
            [
                ['id' => 'a', 'text' => 'alpha'],
                ['id' => 'b', 'text' => 'beta'],
            ],
            'query',
            k: 1,
        );

        self::assertSame('rerank', $result['operation']);
        self::assertSame('b', $result['matches'][0]['id']);
        self::assertTrue($result['boundary']['cross_encoder']);
        self::assertSame("daemon\n", (string) file_get_contents($callsPath));
    }

    public function test_php_invokes_real_python_embeddings_end_to_end(): void
    {
        $client = new SemanticRagRuntimeClient;
        if (! $client->available()) {
            $this->markTestSkipped('semantic_rag runtime not set up — honest skip (not a fake).');
        }

        $result = $client->retrieve(
            [
                ['id' => 'feline', 'text' => 'A cat is a small domesticated carnivorous mammal that purrs.'],
                ['id' => 'finance', 'text' => 'Quarterly revenue exceeded the analyst earnings forecast.'],
            ],
            'a kitten sleeping on the sofa',
            k: 2,
        );

        // Real embeddings rank the feline doc first despite non-overlapping vocabulary.
        $this->assertSame('feline', $result['matches'][0]['id'], (string) json_encode($result['matches']));
        $this->assertTrue($result['boundary']['real_embeddings']);
        $this->assertFalse($result['boundary']['fabricated_vectors']);
        $this->assertTrue($result['boundary']['embeddings_engine_in_python']);
        $this->assertContains($result['boundary']['provider'], ['fastembed_local', 'openai_api']);
    }

    public function test_runtime_absence_fails_explicitly_never_a_silent_fake(): void
    {
        $client = new SemanticRagRuntimeClient;
        if ($client->available()) {
            // Runtime present: the e2e test covers it; the anti-fake guard is exercised there.
            $this->assertTrue(true);

            return;
        }
        $this->expectException(RuntimeException::class);
        $client->embed(['x']);
    }

    /**
     * @return array{0:string,1:string,2:string,3:string}
     */
    private function fakeRuntimeRoot(): array
    {
        $id = bin2hex(random_bytes(5));
        $runtimeRoot = 'storage/framework/testing/semantic-rag-runtime-'.$id;
        $absoluteRoot = base_path($runtimeRoot);
        $bin = $absoluteRoot.'/.venv/bin';

        @mkdir($bin, 0o775, true);
        file_put_contents($absoluteRoot.'/main.py', '# fake entrypoint');
        file_put_contents($absoluteRoot.'/daemon.py', '# fake daemon entrypoint');

        $callsPath = sys_get_temp_dir().'/atlas-semantic-rag-calls-'.$id.'.log';
        $socketPath = sys_get_temp_dir().'/atlas-semantic-rag-'.$id.'.sock';
        $daemonManifestPath = sys_get_temp_dir().'/atlas-semantic-rag-daemon-'.$id.'.json';

        file_put_contents($bin.'/python', <<<'PHP'
#!/usr/bin/env php
<?php

function fake_result(array $manifest, string $model, array $vector): array
{
    $operation = (string) ($manifest['operation'] ?? 'embed');
    $count = count($manifest['texts'] ?? ($manifest['documents'] ?? []));

    $result = [
        'schema_version' => 'atlas.semantic_rag.python_runtime.receipt.v1',
        'operation' => $operation,
        'count' => $count,
        'vectors' => array_fill(0, $count, $vector),
        'boundary' => [
            'embeddings_engine_in_python' => true,
            'php_adapter_only' => true,
            'real_embeddings' => true,
            'fabricated_vectors' => false,
            'provider' => 'fastembed_local',
            'model' => $model,
            'dim' => count($vector),
        ],
    ];

    if ($operation === 'late_interaction_rerank') {
        $result['matches'] = [
            ['id' => 'b', 'score' => 0.91, 'via' => 'late_interaction'],
            ['id' => 'a', 'score' => 0.12, 'via' => 'late_interaction'],
        ];
        $result['boundary']['late_interaction'] = true;
    }

    if ($operation === 'rerank') {
        $result['matches'] = [
            ['id' => 'b', 'score' => 0.91, 'via' => 'cross_encoder'],
            ['id' => 'a', 'score' => 0.12, 'via' => 'cross_encoder'],
        ];
        $result['boundary']['cross_encoder'] = true;
    }

    return $result;
}

function option_value(array $argv, string $name): ?string
{
    foreach ($argv as $index => $arg) {
        if ($arg === $name) {
            return $argv[$index + 1] ?? null;
        }
    }

    return null;
}

$entrypoint = basename((string) ($argv[1] ?? ''));

if ($entrypoint === 'daemon.py') {
    $socket = option_value($argv, '--socket');
    $manifestPath = option_value($argv, '--manifest-path');
    if (! is_string($socket) || $socket === '' || ! is_string($manifestPath) || $manifestPath === '') {
        fwrite(STDERR, "missing daemon args\n");
        exit(2);
    }

    @unlink($socket);
    $server = stream_socket_server('unix://'.$socket, $errno, $errstr);
    if (! is_resource($server)) {
        fwrite(STDERR, "socket failed: ".$errstr."\n");
        exit(3);
    }

    $daemonManifest = [
        'schema_version' => 'atlas.semantic_rag.daemon.manifest.v1',
        'protocol' => 'jsonl.unix_socket.v1',
        'pid' => getmypid(),
        'socket_path_hash' => hash('sha256', $socket),
    ];
    $daemonManifest['manifest_sha256'] = hash('sha256', json_encode($daemonManifest, JSON_UNESCAPED_SLASHES));
    file_put_contents($manifestPath, json_encode($daemonManifest, JSON_UNESCAPED_SLASHES));

    $conn = stream_socket_accept($server, 10);
    if (! is_resource($conn)) {
        exit(4);
    }

    $manifest = json_decode((string) fgets($conn), true) ?: [];
    file_put_contents((string) getenv('ATLAS_FAKE_RUNTIME_CALLS'), "daemon\n", FILE_APPEND);
    fwrite($conn, json_encode(['ok' => true, 'result' => fake_result($manifest, 'daemon-model', [0.1, 0.2])]).PHP_EOL);
    fclose($conn);
    fclose($server);
    @unlink($socket);
    exit(0);
}

$manifestPath = (string) ($argv[2] ?? '');
$manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
file_put_contents((string) getenv('ATLAS_FAKE_RUNTIME_CALLS'), "spawn\n", FILE_APPEND);
echo json_encode(['ok' => true, 'result' => fake_result($manifest, 'spawn-model', [0.3, 0.4])]).PHP_EOL;
PHP);
        file_put_contents(
            $bin.'/python',
            str_replace(
                "(string) getenv('ATLAS_FAKE_RUNTIME_CALLS')",
                var_export($callsPath, true),
                (string) file_get_contents($bin.'/python'),
            ),
        );
        chmod($bin.'/python', 0o775);

        return [$runtimeRoot, $callsPath, $socketPath, $daemonManifestPath];
    }
}
