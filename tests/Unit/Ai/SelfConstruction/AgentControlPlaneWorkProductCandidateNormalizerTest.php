<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AgentControlPlaneWorkProductCandidateNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Proves AgentControlPlaneWorkProductCandidateNormalizer rejects a candidate
 * whose path contains a control character (NUL byte 0x00 or any 0x00-0x1f)
 * with a 'control_char_in_path' violation, while valid paths still normalize.
 */
final class AgentControlPlaneWorkProductCandidateNormalizerTest extends TestCase
{
    private AgentControlPlaneWorkProductCandidateNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new AgentControlPlaneWorkProductCandidateNormalizer;
    }

    private function validCandidate(array $overrides = []): array
    {
        return array_merge([
            'artifact_id' => '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
            'path' => 'app/Services/Foo.php',
            'artifact_hash' => '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
        ], $overrides);
    }

    public function test_valid_path_is_normalized(): void
    {
        $result = $this->normalizer->normalize([$this->validCandidate()]);

        $this->assertSame('normalized', $result['status']);
        $this->assertSame([], $result['violations']);
    }

    public function test_nul_byte_in_path_is_blocked(): void
    {
        $result = $this->normalizer->normalize([
            $this->validCandidate(['path' => "app/Foo\x00.php"]),
        ]);

        $this->assertSame('normalization_blocked', $result['status']);
        $this->assertSame('control_char_in_path', $result['violations'][0]['code']);
    }

    public function test_tab_character_in_path_is_blocked(): void
    {
        $result = $this->normalizer->normalize([
            $this->validCandidate(['path' => "app/Foo\t.php"]),
        ]);

        $this->assertSame('normalization_blocked', $result['status']);
        $this->assertSame('control_char_in_path', $result['violations'][0]['code']);
    }

    public function test_newline_character_in_path_is_blocked(): void
    {
        $result = $this->normalizer->normalize([
            $this->validCandidate(['path' => "app/Foo\n.php"]),
        ]);

        $this->assertSame('normalization_blocked', $result['status']);
        $this->assertSame('control_char_in_path', $result['violations'][0]['code']);
    }

    public function test_missing_path_check_still_fires_before_control_char(): void
    {
        $result = $this->normalizer->normalize([
            $this->validCandidate(['path' => '']),
        ]);

        $this->assertSame('normalization_blocked', $result['status']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('missing_artifact_path', $codes);
    }

    public function test_absolute_path_check_still_fires(): void
    {
        $result = $this->normalizer->normalize([
            $this->validCandidate(['path' => '/etc/passwd']),
        ]);

        $this->assertSame('normalization_blocked', $result['status']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('absolute_path_not_allowed', $codes);
    }
}
