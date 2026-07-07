<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\ParatestPathIsolation;
use Tests\TestCase;

/**
 * P4 (Obra #19) — per-worker path isolation: a file keeps its extension with the
 * token suffixed, a directory gets a per-token subdir, and a serial run (empty
 * token) is a no-op. This is what stops N paratest workers colliding on the 3 shared
 * fixed file paths.
 */
final class ParatestPathIsolationTest extends TestCase
{
    public function test_file_path_keeps_extension_with_token_suffix(): void
    {
        $this->assertSame(
            '/tmp/atlas-learning-transfer-admission-t3.jsonl',
            ParatestPathIsolation::isolate('/tmp/atlas-learning-transfer-admission.jsonl', '3'),
        );
    }

    public function test_directory_path_gets_a_per_token_subdir(): void
    {
        $this->assertSame(
            'storage/testing/rivals/t7',
            ParatestPathIsolation::isolate('storage/testing/rivals', '7'),
        );
    }

    public function test_two_workers_never_collide_on_the_same_path(): void
    {
        $base = 'storage/framework/testing/atlas-trust-ladder-testenv.jsonl';
        $this->assertNotSame(
            ParatestPathIsolation::isolate($base, '1'),
            ParatestPathIsolation::isolate($base, '2'),
        );
    }

    public function test_empty_token_is_a_noop_for_serial_runs(): void
    {
        $this->assertSame('storage/testing/rivals', ParatestPathIsolation::isolate('storage/testing/rivals', ''));
    }
}
