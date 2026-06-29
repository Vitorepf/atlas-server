<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the post-edit coherence scanner is live at the operator surface: a file with a PHP parse error is
 * flagged, while a clean file yields no issues. Explicit --file paths drive the scan deterministically.
 */
final class AtlasLoopPostEditCoherenceCommandTest extends TestCase
{
    public function test_parse_error_file_is_flagged(): void
    {
        $bad = tempnam(sys_get_temp_dir(), 'atlas_coh_bad_').'.php';
        file_put_contents($bad, "<?php\nclass Broken {\n"); // unclosed class ⇒ parse error

        try {
            $exit = Artisan::call('atlas:loop:post-edit-coherence', ['--file' => [$bad], '--json' => true]);
            $decoded = json_decode(trim(Artisan::output()), true);

            $this->assertSame(0, $exit);
            $this->assertSame('atlas.loop.post_edit_coherence.v1', $decoded['schema_version']);
            $this->assertGreaterThan(0, $decoded['issues_count']);
            $this->assertContains('parse_error', array_column($decoded['issues'], 'reason'));
        } finally {
            @unlink($bad);
        }
    }

    public function test_clean_file_has_no_issues(): void
    {
        $good = tempnam(sys_get_temp_dir(), 'atlas_coh_good_').'.php';
        file_put_contents($good, "<?php\n\nnamespace AtlasCohTest;\n\nfinal class Clean\n{\n    public function ping(): string\n    {\n        return 'pong';\n    }\n}\n");

        try {
            $exit = Artisan::call('atlas:loop:post-edit-coherence', ['--file' => [$good], '--json' => true]);
            $decoded = json_decode(trim(Artisan::output()), true);

            $this->assertSame(0, $exit);
            $this->assertSame(0, $decoded['issues_count'], 'a clean file has no coherence issues');
        } finally {
            @unlink($good);
        }
    }
}
