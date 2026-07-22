<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Services\Ai\Cognition\AcosProgram\AtlasLocalModelIntegrityService;
use App\Services\Ai\Cognition\Watchdog\Checks\LocalModelIntegrityWatchdogCheck;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtlasLocalModelIntegrityTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas-elev19-'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        $this->cleanup($this->tmp);
        parent::tearDown();
    }

    #[Test]
    public function pinned_artifact_matching_disk_hash_is_verified(): void
    {
        $path = $this->tmp.'/minilm.onnx';
        file_put_contents($path, 'canonical-artifact-bytes');
        $sha = hash('sha256', 'canonical-artifact-bytes');

        $service = new AtlasLocalModelIntegrityService([
            'schema_version' => 'atlas.model_integrity_manifest.v1',
            'artifacts' => [
                [
                    'model_id' => 'test/minilm',
                    'function' => 'dense_embed',
                    'path' => $path,
                    'sha256_pin' => $sha,
                    'license' => 'apache-2.0',
                ],
            ],
        ]);

        $report = $service->verifyAll();

        $this->assertSame(1, $report['verified']);
        $this->assertSame(0, $report['mismatched']);
        $this->assertSame('verified', $report['artifacts'][0]['status']);
        $this->assertTrue($report['artifacts'][0]['model_verified']);
    }

    #[Test]
    public function tampered_artifact_is_refused_by_watchdog_with_named_reason(): void
    {
        $path = $this->tmp.'/tampered.onnx';
        file_put_contents($path, 'evil-bytes');
        $legitimatePin = hash('sha256', 'canonical-artifact-bytes');

        $service = new AtlasLocalModelIntegrityService([
            'schema_version' => 'atlas.model_integrity_manifest.v1',
            'artifacts' => [
                [
                    'model_id' => 'test/tampered',
                    'function' => 'dense_embed',
                    'path' => $path,
                    'sha256_pin' => $legitimatePin,
                    'license' => 'apache-2.0',
                ],
            ],
        ]);

        $check = new LocalModelIntegrityWatchdogCheck($service);
        $result = $check->run()->toArray();

        $this->assertSame('alert', $result['status']);
        $this->assertSame('local_model_hash_mismatch', $result['alert']['code']);
        $this->assertSame('mismatched', $result['evidence']['artifacts'][0]['status']);
        $this->assertSame('sha256_mismatch', $result['evidence']['artifacts'][0]['reason']);
        $this->assertFalse($result['evidence']['artifacts'][0]['model_verified']);
    }

    #[Test]
    public function missing_artifact_alerts_and_never_silently_loads(): void
    {
        $service = new AtlasLocalModelIntegrityService([
            'schema_version' => 'atlas.model_integrity_manifest.v1',
            'artifacts' => [
                [
                    'model_id' => 'test/missing',
                    'function' => 'dense_embed',
                    'path' => $this->tmp.'/does-not-exist.onnx',
                    'sha256_pin' => str_repeat('a', 64),
                    'license' => 'apache-2.0',
                ],
            ],
        ]);

        $check = new LocalModelIntegrityWatchdogCheck($service);
        $result = $check->run()->toArray();

        $this->assertSame('alert', $result['status']);
        $this->assertSame('missing', $result['evidence']['artifacts'][0]['status']);
        $this->assertFalse($result['evidence']['artifacts'][0]['model_verified']);
    }

    #[Test]
    public function unpinned_artifact_is_advisory_not_alert(): void
    {
        $path = $this->tmp.'/unpinned.onnx';
        file_put_contents($path, 'anything');

        $service = new AtlasLocalModelIntegrityService([
            'schema_version' => 'atlas.model_integrity_manifest.v1',
            'artifacts' => [
                [
                    'model_id' => 'test/unpinned',
                    'function' => 'dense_embed',
                    'path' => $path,
                    'sha256_pin' => '',
                    'license' => 'apache-2.0',
                ],
            ],
        ]);

        $check = new LocalModelIntegrityWatchdogCheck($service);
        $result = $check->run()->toArray();

        $this->assertSame('ok', $result['status']);
        $this->assertSame('unpinned', $result['evidence']['artifacts'][0]['status']);
    }

    #[Test]
    public function default_manifest_covers_at_least_the_default_semantic_rag_model(): void
    {
        $service = new AtlasLocalModelIntegrityService();
        $report = $service->verifyAll();

        $ids = array_map(static fn (array $row): string => (string) $row['model_id'], $report['artifacts']);
        $this->assertContains(
            'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2',
            $ids,
            'ELEV-19 manifest must cover the default semantic_rag_model.'
        );
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob(rtrim($dir, '/').'/*') as $entry) {
            if (is_dir($entry)) {
                $this->cleanup($entry);
            } else {
                @unlink($entry);
            }
        }
        @rmdir($dir);
    }
}
