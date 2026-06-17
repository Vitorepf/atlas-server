<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderEditApplier;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE engine-independence keystone. Proves the primitive that turns a TEXT/HTTP engine
 * (MiniMax M3) into an agent that edits a repo: take the model's unified-diff completion
 * and APPLY it to the scenario workspace. End-to-end against a real git workspace and the
 * real DiffParser + PatchApplier (no mocks) — if this passes, the loop can certify work
 * produced by a provider that never touches the filesystem itself.
 */
final class AtlasLoopProviderEditApplierTest extends TestCase
{
    private string $workspace = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/atlas-edit-applier-'.bin2hex(random_bytes(5));
        mkdir($this->workspace, 0o755, true);
        $this->git(['git', 'init', '-q']);
        $this->git(['git', 'config', 'user.email', 't@local']);
        $this->git(['git', 'config', 'user.name', 'T']);
    }

    protected function tearDown(): void
    {
        if ($this->workspace !== '' && is_dir($this->workspace)) {
            (new Process(['rm', '-rf', $this->workspace]))->run();
        }
        parent::tearDown();
    }

    /** @param list<string> $argv */
    private function git(array $argv): void
    {
        (new Process($argv, $this->workspace, null, null, 30.0))->run();
    }

    private function seedFile(string $relpath, string $contents): void
    {
        $full = $this->workspace.'/'.$relpath;
        @mkdir(dirname($full), 0o755, true);
        file_put_contents($full, $contents);
        $this->git(['git', 'add', '-A']);
        $this->git(['git', 'commit', '-q', '-m', 'seed']);
    }

    /** Capture a real unified diff for a desired change, then revert so it can be re-applied. */
    private function captureDiffFor(string $relpath, string $newContents): string
    {
        file_put_contents($this->workspace.'/'.$relpath, $newContents);
        $diff = new Process(['git', 'diff', '--no-ext-diff'], $this->workspace, null, null, 30.0);
        $diff->run();
        $this->git(['git', 'checkout', '--', $relpath]);

        return (string) $diff->getOutput();
    }

    public function test_applies_a_fenced_unified_diff_to_an_existing_file(): void
    {
        $this->seedFile('src/Calc.php', "<?php\n\nreturn 1;\n");
        $diff = $this->captureDiffFor('src/Calc.php', "<?php\n\nreturn 42;\n");
        // Wrap in a ```diff fence + surrounding prose — the realistic shape of a chat-model reply.
        $reply = "Here is the change:\n\n```diff\n".$diff."\n```\n";

        $result = (new AtlasLoopProviderEditApplier)->applyFromText($reply, $this->workspace);

        $this->assertTrue($result['applied'], 'a real fenced diff must apply');
        $this->assertSame(['src/Calc.php'], $result['changed_files']);
        $this->assertStringContainsString('return 42;', (string) file_get_contents($this->workspace.'/src/Calc.php'));
    }

    public function test_creates_a_new_file_from_a_dev_null_diff(): void
    {
        $this->seedFile('src/Existing.php', "<?php\n\nclass Existing {}\n");
        $diff = "diff --git a/src/New.php b/src/New.php\n"
            ."new file mode 100644\n"
            ."--- /dev/null\n"
            ."+++ b/src/New.php\n"
            ."@@ -0,0 +1,3 @@\n"
            ."+<?php\n"
            ."+\n"
            ."+class NewlyCreated {}\n";

        $result = (new AtlasLoopProviderEditApplier)->applyFromText($diff, $this->workspace);

        $this->assertTrue($result['applied'], 'a /dev/null new-file diff must apply');
        $this->assertContains('src/New.php', $result['changed_files']);
        $this->assertFileExists($this->workspace.'/src/New.php');
        $this->assertStringContainsString('class NewlyCreated', (string) file_get_contents($this->workspace.'/src/New.php'));
    }

    public function test_prose_with_no_diff_applies_nothing(): void
    {
        $this->seedFile('src/Calc.php', "<?php\n\nreturn 1;\n");

        $result = (new AtlasLoopProviderEditApplier)->applyFromText(
            'I considered the change but the code already looks correct, so no patch is needed.',
            $this->workspace,
        );

        $this->assertFalse($result['applied']);
        $this->assertSame([], $result['changed_files']);
        $this->assertStringContainsString('return 1;', (string) file_get_contents($this->workspace.'/src/Calc.php'));
    }

    public function test_malformed_diff_fails_safe_and_never_throws(): void
    {
        $this->seedFile('src/Calc.php', "<?php\n\nreturn 1;\n");
        $garbage = "```diff\n--- a/src/Calc.php\n+++ b/src/Calc.php\n@@ -9999,1 +9999,1 @@\n-this context does not exist\n+nonsense\n```";

        $result = (new AtlasLoopProviderEditApplier)->applyFromText($garbage, $this->workspace);

        $this->assertFalse($result['applied'], 'a diff that cannot apply must not report success');
        $this->assertSame([], $result['changed_files']);
        $this->assertStringContainsString('apply_', (string) $result['status']);
    }

    public function test_empty_text_or_missing_workspace_is_a_clean_noop(): void
    {
        $applier = new AtlasLoopProviderEditApplier;
        $this->assertFalse($applier->applyFromText('', $this->workspace)['applied']);
        $this->assertFalse($applier->applyFromText('```diff\n--- a/x\n+++ b/x\n```', $this->workspace.'/does-not-exist')['applied']);
    }

    /** A full-file delivery block ({@see AtlasLoopProviderEditApplier} primary path for a weak engine). */
    private function fullFileBlock(string $rel, string $body): string
    {
        return "*** ATLAS_FILE: {$rel} ***\n{$body}\n*** ATLAS_END ***";
    }

    private const BROKEN_PHP = "<?php\n\nclass Broken\n{\n    public function a()\n    {\n        return 1\n    }\n"; // missing ; + unclosed class brace

    private const VALID_PHP = "<?php\n\nclass Ok\n{\n    public function a(): int\n    {\n        return 42;\n    }\n}\n";

    public function test_parse_gate_OFF_writes_unparseable_php_verbatim_byte_identical(): void
    {
        config(['atlas.loop.parse_gate_enabled' => false]); // pin OFF (env-independent)
        // OFF => today's behavior exactly: a broken full-file rewrite is written as-is.
        $reply = $this->fullFileBlock('src/Broken.php', self::BROKEN_PHP);

        $result = (new AtlasLoopProviderEditApplier)->applyFromText($reply, $this->workspace);

        $this->assertTrue($result['applied'], 'with the gate OFF the broken body is written (byte-identical to before X2)');
        $this->assertSame(['src/Broken.php'], $result['changed_files']);
        $this->assertStringContainsString('return 1', (string) file_get_contents($this->workspace.'/src/Broken.php'));
    }

    public function test_parse_gate_ON_rejects_unparseable_php_and_never_writes_it(): void
    {
        config(['atlas.loop.parse_gate_enabled' => true]);
        $reply = $this->fullFileBlock('src/Broken.php', self::BROKEN_PHP);

        $result = (new AtlasLoopProviderEditApplier)->applyFromText($reply, $this->workspace);

        $this->assertFalse($result['applied'], 'an armed gate must reject a body that does not parse');
        $this->assertSame([], $result['changed_files']);
        $this->assertSame('parse_gate_rejected', $result['reason']);
        $this->assertFileDoesNotExist($this->workspace.'/src/Broken.php', 'the poison file must never reach the workspace');
    }

    public function test_parse_gate_ON_still_applies_valid_php(): void
    {
        config(['atlas.loop.parse_gate_enabled' => true]);
        $reply = $this->fullFileBlock('src/Ok.php', self::VALID_PHP);

        $result = (new AtlasLoopProviderEditApplier)->applyFromText($reply, $this->workspace);

        $this->assertTrue($result['applied'], 'a parseable body passes the gate');
        $this->assertSame(['src/Ok.php'], $result['changed_files']);
        $this->assertStringContainsString('return 42;', (string) file_get_contents($this->workspace.'/src/Ok.php'));
    }

    public function test_parse_gate_ON_only_guards_php_targets(): void
    {
        config(['atlas.loop.parse_gate_enabled' => true]);
        // A .json file with "broken PHP" content is not PHP — the gate must not touch it.
        $reply = $this->fullFileBlock('config/data.json', self::BROKEN_PHP);

        $result = (new AtlasLoopProviderEditApplier)->applyFromText($reply, $this->workspace);

        $this->assertTrue($result['applied'], 'the gate only applies to .php targets');
        $this->assertSame(['config/data.json'], $result['changed_files']);
    }

    public function test_parse_gate_ON_writes_the_valid_file_and_rejects_only_the_broken_one(): void
    {
        config(['atlas.loop.parse_gate_enabled' => true]);
        $reply = $this->fullFileBlock('src/Ok.php', self::VALID_PHP)."\n".$this->fullFileBlock('src/Broken.php', self::BROKEN_PHP);

        $result = (new AtlasLoopProviderEditApplier)->applyFromText($reply, $this->workspace);

        $this->assertTrue($result['applied'], 'the parseable file still lands');
        $this->assertSame(['src/Ok.php'], $result['changed_files']);
        $this->assertSame('applied_full_file_partial', $result['status']);
        $this->assertFileExists($this->workspace.'/src/Ok.php');
        $this->assertFileDoesNotExist($this->workspace.'/src/Broken.php');
    }
}
