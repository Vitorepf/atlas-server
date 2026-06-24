<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComprehensionGraphSerializer;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopComprehensionGraphSerializerTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            (new Process(['rm', '-rf', $path]))->run();
        }

        parent::tearDown();
    }

    public function test_encode_is_byte_identical_for_the_same_snapshot(): void
    {
        $snapshot = $this->snapshot();
        $serializer = new AtlasLoopComprehensionGraphSerializer;

        $first = $serializer->encode($snapshot);
        $second = $serializer->encode($snapshot);

        $this->assertSame($first, $second);
        $this->assertSame(hash('sha256', $first), hash('sha256', $second));
    }

    public function test_round_trip_restores_the_exact_snapshot_structure(): void
    {
        $snapshot = $this->snapshot();
        $serializer = new AtlasLoopComprehensionGraphSerializer;

        $this->assertSame($snapshot, $serializer->decode($serializer->encode($snapshot)));
    }

    public function test_write_snapshot_uses_the_provided_tmp_dir_and_persists_exact_encoded_content(): void
    {
        $dir = $this->tmpDir();
        $snapshot = $this->snapshot();
        $serializer = new AtlasLoopComprehensionGraphSerializer;

        $path = $serializer->writeSnapshot($snapshot, $dir);

        $this->assertFileExists($path);
        $this->assertStringStartsWith($dir, $path);
        $this->assertSame($serializer->encode($snapshot), (string) file_get_contents($path));
        $this->assertSame($snapshot, $serializer->readSnapshot($path));
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(): array
    {
        return [
            'doc_purposes' => [
                'App\\Loop\\Classe' => 'Descricao com acao/ligacao',
            ],
            'inventory' => [
                [
                    'clone_cluster_id' => null,
                    'fqcn' => 'App\\Loop\\Classe',
                    'is_forbidden' => false,
                    'is_orphan' => true,
                    'public_methods' => ['handle'],
                    'rel_path' => 'app/Loop/Classe.php',
                ],
            ],
            'schema_version' => 'atlas.loop.scope_comprehension.v1',
            'snapshot_id' => 'snap-serializer',
        ];
    }

    private function tmpDir(): string
    {
        $dir = sys_get_temp_dir().'/atlas-loop-graph-serializer-'.bin2hex(random_bytes(4));
        mkdir($dir, 0o755, true);
        $this->paths[] = $dir;

        return $dir;
    }
}
