<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Provider;

use App\Services\Ai\Programming\AtlasDev\Provider\DiffParser;
use App\Services\Ai\Programming\AtlasDev\Provider\DiffParseResult;
use PHPUnit\Framework\TestCase;

final class DiffParserTest extends TestCase
{
    private function parser(): DiffParser
    {
        return new DiffParser;
    }

    public function test_parses_fenced_unified_diff_and_extracts_changed_files(): void
    {
        $payload = <<<'MD'
Here is the patch you asked for.

```diff
--- a/app/Services/Foo.php
+++ b/app/Services/Foo.php
@@ -1,3 +1,3 @@
-old line
+new line
 unchanged
```
MD;

        $result = $this->parser()->parse($payload);

        $this->assertTrue($result->hasPatch());
        $this->assertSame(['app/Services/Foo.php'], $result->changedFiles);
        $this->assertNotNull($result->diff);
        $this->assertNotNull($result->diffHash());
    }

    public function test_ignores_non_unified_fence_and_stops_at_unified_diff_closing_fence(): void
    {
        $payload = <<<'MD'
The direct edit was blocked, but here is the change:

```diff
-        return 41;
+        return 42;
```

**Diff (unified):**
```diff
--- app/Services/Foo/FooService.php
+++ app/Services/Foo/FooService.php
@@ -4,6 +4,6 @@
     public function answer(): int
     {
-        return 41;
+        return 42;
     }
 }
```

**changed_files:** `app/Services/Foo/FooService.php`
**blocked:** `true`
MD;

        $result = $this->parser()->parse($payload);

        $this->assertTrue($result->hasPatch());
        $this->assertSame(['app/Services/Foo/FooService.php'], $result->changedFiles);
        $this->assertNotNull($result->diff);
        $this->assertStringContainsString('+        return 42;', $result->diff);
        $this->assertStringNotContainsString('```', $result->diff);
        $this->assertStringNotContainsString('changed_files', $result->diff);
        $this->assertStringNotContainsString('blocked', $result->diff);
    }

    public function test_parses_raw_unified_diff_without_fence(): void
    {
        $diff = <<<'DIFF'
diff --git a/x.php b/x.php
--- a/x.php
+++ b/x.php
@@ -10,7 +10,7 @@
-old
+new
 keep
DIFF;

        $result = $this->parser()->parse($diff);

        $this->assertTrue($result->hasPatch());
        $this->assertSame(['x.php'], $result->changedFiles);
    }

    public function test_parses_multi_file_diff(): void
    {
        $payload = <<<'DIFF'
```diff
--- a/a.php
+++ b/a.php
@@ -1,1 +1,1 @@
-old a
+new a
--- a/b.php
+++ b/b.php
@@ -1,1 +1,1 @@
-old b
+new b
```
DIFF;
        $result = $this->parser()->parse($payload);

        $this->assertTrue($result->hasPatch());
        $this->assertSame(['a.php', 'b.php'], $result->changedFiles);
    }

    public function test_parses_no_patch_needed_marker_with_reason(): void
    {
        $payload = "no_patch_needed: true\nreason: target already passes test suite";

        $result = $this->parser()->parse($payload);

        $this->assertTrue($result->isNoPatchNeeded());
        $this->assertSame('target already passes test suite', $result->reason);
        $this->assertSame([], $result->changedFiles);
    }

    public function test_parses_markdown_bold_no_patch_needed_marker_with_reason(): void
    {
        $payload = <<<'MD'
**Analysis complete.**

**no_patch_needed: true**

**Reason:** The verification command already exits with code 0.
MD;

        $result = $this->parser()->parse($payload);

        $this->assertTrue($result->isNoPatchNeeded());
        $this->assertSame('The verification command already exits with code 0.', $result->reason);
    }

    public function test_parses_blocked_marker_with_question(): void
    {
        $payload = "blocked: true\nquestion: which adapter should we modify?";

        $result = $this->parser()->parse($payload);

        $this->assertTrue($result->isBlocked());
        $this->assertSame('which adapter should we modify?', $result->question);
    }

    public function test_parses_markdown_bold_blocked_marker_with_question(): void
    {
        $payload = "**blocked: true**\n\n**Question:** Which adapter should we modify?";

        $result = $this->parser()->parse($payload);

        $this->assertTrue($result->isBlocked());
        $this->assertSame('Which adapter should we modify?', $result->question);
    }

    public function test_returns_invalid_when_no_known_format_present(): void
    {
        $result = $this->parser()->parse("ok, here is what I did:\n- changed something\n");

        $this->assertTrue($result->isInvalid());
        $this->assertNotEmpty($result->errors);
        $this->assertNull($result->diff);
        $this->assertSame([], $result->changedFiles);
    }

    public function test_returns_invalid_when_diff_present_but_no_changed_files(): void
    {
        $payload = <<<'DIFF'
```diff
@@ -1,3 +1,3 @@
-old
+new
 keep
```
DIFF;
        // Missing --- / +++ headers, so looksLikeUnifiedDiff returns false.
        $result = $this->parser()->parse($payload);

        $this->assertTrue($result->isInvalid());
    }

    public function test_strips_a_b_prefixes_from_paths(): void
    {
        $payload = <<<'DIFF'
```diff
--- a/path/with/prefix.php
+++ b/path/with/prefix.php
@@ -1,1 +1,1 @@
-old
+new
```
DIFF;
        $result = $this->parser()->parse($payload);

        $this->assertSame(['path/with/prefix.php'], $result->changedFiles);
    }

    public function test_treats_dev_null_as_deletion_using_old_path(): void
    {
        $payload = <<<'DIFF'
```diff
--- a/old/file.php
+++ /dev/null
@@ -1,3 +0,0 @@
-old
-old2
-old3
```
DIFF;
        $result = $this->parser()->parse($payload);

        $this->assertTrue($result->hasPatch());
        $this->assertSame(['old/file.php'], $result->changedFiles);
    }

    public function test_factory_helpers_construct_expected_modes(): void
    {
        $patch = DiffParseResult::patch("--- a/x\n+++ b/x\n@@\n+x", ['x']);
        $noPatch = DiffParseResult::noPatchNeeded('done');
        $blocked = DiffParseResult::blocked('question?');
        $invalid = DiffParseResult::invalid(['e1']);

        $this->assertTrue($patch->hasPatch());
        $this->assertTrue($noPatch->isNoPatchNeeded());
        $this->assertTrue($blocked->isBlocked());
        $this->assertTrue($invalid->isInvalid());
    }

    public function test_diff_parse_result_summary_omits_raw_diff_but_keeps_hash_and_errors(): void
    {
        $diff = "--- a/x.php\n+++ b/x.php\n@@ -1,1 +1,1 @@\n-old\n+new\n";
        $patch = DiffParseResult::patch($diff, ['x.php']);

        $summary = $patch->toSummaryArray();
        $canonical = $patch->toCanonicalArray();

        $this->assertArrayNotHasKey('diff', $summary);
        $this->assertSame(hash('sha256', $diff), $summary['diff_hash']);
        $this->assertSame($diff, $canonical['diff']);
        $this->assertSame('atlas.dev.diff_parse_result.v1', $summary['schema_version']);
    }
}
