<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Gate\DevWeakOutputDetector;
use PHPUnit\Framework\TestCase;

final class DevWeakOutputDetectorTest extends TestCase
{
    private function svc(): DevWeakOutputDetector
    {
        return new DevWeakOutputDetector;
    }

    private function goodDiff(): string
    {
        return <<<'DIFF'
        --- a/app/Services/Foo/FooService.php
        +++ b/app/Services/Foo/FooService.php
        @@ -1,3 +1,4 @@
         <?php
        +// cache hit
         class FooService {}
        DIFF;
    }

    public function test_empty_output_is_weak(): void
    {
        $r = $this->svc()->inspect('');

        $this->assertTrue($r['weak']);
        $this->assertSame(DevWeakOutputDetector::SIGNAL_EMPTY_OR_TRUNCATED_DIFF, $r['signals'][0]['id']);
    }

    public function test_unbalanced_braces_are_flagged_truncated(): void
    {
        $r = $this->svc()->inspect('function foo() { return 1;');

        $this->assertTrue($r['weak']);
        $this->assertSame(DevWeakOutputDetector::SIGNAL_EMPTY_OR_TRUNCATED_DIFF, $r['signals'][0]['id']);
    }

    public function test_clean_diff_within_scope_is_not_weak(): void
    {
        $r = $this->svc()->inspect($this->goodDiff(), [
            'allowed_files' => ['app/Services/Foo/FooService.php'],
        ]);

        $this->assertFalse($r['weak']);
        $this->assertSame([], $r['signals']);
        $this->assertNull($r['repair_hint']);
    }

    public function test_diff_touching_file_outside_allowed_files_is_flagged(): void
    {
        $r = $this->svc()->inspect($this->goodDiff(), [
            'allowed_files' => ['app/Services/Foo/OtherFile.php'],
        ]);

        $this->assertTrue($r['weak']);
        $this->assertSame(DevWeakOutputDetector::SIGNAL_OUT_OF_SCOPE_FILE, $r['signals'][0]['id']);
    }

    public function test_diff_with_no_real_change_lines_is_flagged(): void
    {
        $diff = "--- a/x.php\n+++ b/x.php\n@@ -1,2 +1,2 @@\n context line\n context line\n";
        $r = $this->svc()->inspect($diff);

        $this->assertTrue($r['weak']);
        $this->assertSame(DevWeakOutputDetector::SIGNAL_RESTATES_EXISTING_CODE, $r['signals'][0]['id']);
    }

    public function test_placeholder_marker_is_flagged(): void
    {
        $r = $this->svc()->inspect('+ // TODO: implement this');

        $this->assertTrue($r['weak']);
        $this->assertContains(DevWeakOutputDetector::SIGNAL_PLACEHOLDER_MARKER, array_column($r['signals'], 'id'));
    }

    public function test_missing_verification_command_mention_is_flagged(): void
    {
        $r = $this->svc()->inspect($this->goodDiff(), [
            'verification_command' => 'php artisan test --filter=Unrelated',
        ]);

        $this->assertContains(DevWeakOutputDetector::SIGNAL_IGNORES_VERIFICATION_COMMAND, array_column($r['signals'], 'id'));
    }

    public function test_hallucinated_symbol_not_in_known_symbols_is_flagged(): void
    {
        $diff = "--- a/x.php\n+++ b/x.php\n@@ -1,1 +1,2 @@\n context\n+new UnknownWidgetFactory();\n";
        $r = $this->svc()->inspect($diff, ['known_symbols' => ['FooService']]);

        $this->assertContains(DevWeakOutputDetector::SIGNAL_HALLUCINATED_SYMBOL, array_column($r['signals'], 'id'));
    }

    public function test_known_symbols_empty_skips_hallucination_check(): void
    {
        $diff = "--- a/x.php\n+++ b/x.php\n@@ -1,1 +1,2 @@\n context\n+new UnknownWidgetFactory();\n";
        $r = $this->svc()->inspect($diff, [
            'allowed_files' => ['x.php'],
            'verification_command' => '',
        ]);

        $this->assertNotContains(DevWeakOutputDetector::SIGNAL_HALLUCINATED_SYMBOL, array_column($r['signals'], 'id'));
    }

    // -- inspectAppliedDiff (W1 post-gate probe) -----------------------------

    public function test_applied_diff_with_todo_in_added_line_is_weak(): void
    {
        $diff = "--- a/x.php\n+++ b/x.php\n@@ -1,1 +1,2 @@\n context\n+// TODO: implement this\n";
        $r = $this->svc()->inspectAppliedDiff($diff);

        $this->assertTrue($r['weak']);
        $this->assertSame(DevWeakOutputDetector::SIGNAL_PLACEHOLDER_MARKER, $r['signals'][0]['id']);
    }

    public function test_applied_diff_with_fake_assert_in_added_line_is_weak(): void
    {
        $diff = "--- a/t.php\n+++ b/t.php\n@@ -1,1 +1,2 @@\n context\n+        \$this->assertTrue(true, true);\n";
        $r = $this->svc()->inspectAppliedDiff($diff);

        $this->assertTrue($r['weak']);
    }

    public function test_applied_diff_placeholder_only_in_removed_or_context_lines_is_not_weak(): void
    {
        // Removing a TODO (or having one in unchanged context) is the OPPOSITE
        // of weak output — the probe must scan added lines only.
        $diff = "--- a/x.php\n+++ b/x.php\n@@ -1,3 +1,3 @@\n // TODO: legacy context marker\n-// TODO: implement this\n+return \$this->computeReal();\n";
        $r = $this->svc()->inspectAppliedDiff($diff);

        $this->assertFalse($r['weak']);
        $this->assertSame([], $r['signals']);
    }

    public function test_applied_diff_with_clean_added_lines_is_not_weak(): void
    {
        $r = $this->svc()->inspectAppliedDiff($this->goodDiff());

        $this->assertFalse($r['weak']);
    }

    public function test_applied_diff_with_no_added_lines_is_not_weak(): void
    {
        // No added lines => nothing to scan (E1's intent probe owns the
        // no-change-diff corner).
        $diff = "--- a/x.php\n+++ b/x.php\n@@ -1,2 +1,1 @@\n context\n-old();\n";
        $r = $this->svc()->inspectAppliedDiff($diff);

        $this->assertFalse($r['weak']);
    }

    public function test_applied_diff_portuguese_prose_is_not_a_placeholder(): void
    {
        // Regression: /\bTODO\b/i matched PT-BR prose — 'todo o workspace',
        // 'Todo', and even 'método'/'MÉTODO' (accented char breaks ASCII \b).
        // This repo writes code comments in PT-BR; these are NOT placeholders.
        $diff = "--- a/x.php\n+++ b/x.php\n@@ -1,1 +1,4 @@\n context\n"
            ."+// Este método aplica o gate em todo o workspace.\n"
            ."+// Todo caller passa por aqui.\n"
            ."+const LABEL = 'MÉTODO DE PAGAMENTO';\n";
        $r = $this->svc()->inspectAppliedDiff($diff);

        $this->assertFalse($r['weak'], 'PT-BR prose must never trip the placeholder probe');
    }

    public function test_applied_diff_uppercase_todo_marker_still_trips(): void
    {
        $diff = "--- a/x.php\n+++ b/x.php\n@@ -1,1 +1,2 @@\n context\n+// TODO implementar de verdade\n";
        $r = $this->svc()->inspectAppliedDiff($diff);

        $this->assertTrue($r['weak']);
    }

    public function test_applied_diff_yaml_document_end_is_not_a_placeholder(): void
    {
        // Regression: a literal '...' line is the canonical YAML document
        // end, not an ellipsis placeholder. Non-code files are skipped.
        $diff = "--- a/config/pipeline.yaml\n+++ b/config/pipeline.yaml\n@@ -1,2 +1,3 @@\n key: value\n+extra: value\n+...\n";
        $r = $this->svc()->inspectAppliedDiff($diff);

        $this->assertFalse($r['weak'], 'YAML/docs added lines are out of the placeholder probe scope');
    }

    public function test_applied_diff_single_arg_fake_assert_trips(): void
    {
        // Regression: the 1-arg form assertTrue(true) escaped the 2-arg-only
        // pattern.
        $diff = "--- a/t.php\n+++ b/t.php\n@@ -1,1 +1,2 @@\n context\n+        \$this->assertTrue(true);\n";
        $r = $this->svc()->inspectAppliedDiff($diff);

        $this->assertTrue($r['weak']);
    }

    public function test_repair_hint_prioritizes_first_failing_signal_in_declared_order(): void
    {
        // Empty output alone triggers only SIGNAL_EMPTY_OR_TRUNCATED_DIFF, which is first
        // in CONTEXT_SECTION_BY_SIGNAL priority order.
        $r = $this->svc()->inspect('');

        $this->assertNotNull($r['repair_hint']);
        $this->assertStringStartsWith('signal='.DevWeakOutputDetector::SIGNAL_EMPTY_OR_TRUNCATED_DIFF, $r['repair_hint']);
    }
}
