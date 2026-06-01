<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\RuntimeClassConsumptionScanner;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RuntimeClassConsumptionScannerTest extends TestCase
{
    private RuntimeClassConsumptionScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new RuntimeClassConsumptionScanner();
    }

    // ---- PURE detection robustness (the reviewer's core concern) ----

    public function test_new_instantiation_is_a_code_reference(): void
    {
        $this->assertTrue($this->scanner->sourceReferencesClass('<?php class A { function f() { return new OrphanDecider(); } }', 'OrphanDecider'));
    }

    public function test_qualified_use_statement_is_a_code_reference(): void
    {
        $src = "<?php\nnamespace App\\X;\nuse App\\Services\\Foo\\OrphanDecider;\nclass A { public function f(OrphanDecider \$d) {} }";
        $this->assertTrue($this->scanner->sourceReferencesClass($src, 'OrphanDecider'));
    }

    public function test_static_class_const_and_type_hint_are_references(): void
    {
        $this->assertTrue($this->scanner->sourceReferencesClass('<?php class A { function f() { return OrphanDecider::class; } }', 'OrphanDecider'));
        $this->assertTrue($this->scanner->sourceReferencesClass('<?php class A { function f(OrphanDecider $x): OrphanDecider { return $x; } }', 'OrphanDecider'));
    }

    public function test_fully_qualified_reference_matches_last_segment(): void
    {
        $this->assertTrue($this->scanner->sourceReferencesClass('<?php class A { function f() { return new \\App\\Services\\Foo\\OrphanDecider(); } }', 'OrphanDecider'));
    }

    public function test_mention_in_line_comment_is_not_a_reference(): void
    {
        $this->assertFalse($this->scanner->sourceReferencesClass("<?php\nclass A { function f() { /* TODO wire OrphanDecider later */ return 1; } }", 'OrphanDecider'));
    }

    public function test_mention_in_docblock_is_not_a_reference(): void
    {
        $src = "<?php\n/**\n * @see OrphanDecider planned for a future cycle\n */\nclass A {}";
        $this->assertFalse($this->scanner->sourceReferencesClass($src, 'OrphanDecider'));
    }

    public function test_mention_in_string_literal_is_not_a_reference(): void
    {
        $this->assertFalse($this->scanner->sourceReferencesClass('<?php class A { function f() { return "OrphanDecider is great"; } }', 'OrphanDecider'));
        $this->assertFalse($this->scanner->sourceReferencesClass("<?php class A { function f() { return 'OrphanDecider'; } }", 'OrphanDecider'));
    }

    public function test_substring_collision_is_not_a_reference(): void
    {
        // Looking for "Port" must NOT match "Portfolio", "Export", "Report".
        $src = '<?php class A { function f(Portfolio $p) { return Export::report($p); } }';
        $this->assertFalse($this->scanner->sourceReferencesClass($src, 'Port'));
    }

    public function test_absent_class_is_not_a_reference(): void
    {
        $this->assertFalse($this->scanner->sourceReferencesClass('<?php class A { function f() { return new Other(); } }', 'OrphanDecider'));
    }

    public function test_is_product_php_class_path(): void
    {
        $this->assertTrue($this->scanner->isProductPhpClassPath('app/Services/Ai/Foo.php'));
        $this->assertFalse($this->scanner->isProductPhpClassPath('tests/Unit/FooTest.php'));
        $this->assertFalse($this->scanner->isProductPhpClassPath('app/Services/Ai/FooTest.php'));
        $this->assertFalse($this->scanner->isProductPhpClassPath('docs/foo.md'));
    }

    // ---- scan() against a real git fixture (the load-bearing I/O) ----

    public function test_scan_distinguishes_new_consumed_inert_and_edited(): void
    {
        $repo = $this->makeRepo();

        $base = 'app/Services/X/ExistingConsumer.php';
        $wired = 'app/Services/X/WiredThing.php';
        $consumer = 'app/Services/X/ConsumerOfWired.php';
        $orphan = 'app/Services/X/OrphanThing.php';
        $commentOnly = 'app/Services/X/CommentOnlyMentioner.php';

        // Baseline on main: only ExistingConsumer exists.
        $this->put($repo, $base, "<?php\nnamespace App\\Services\\X;\nclass ExistingConsumer { public function a() { return 1; } }\n");
        $this->git($repo, ['add', '-A']);
        $this->git($repo, ['commit', '-q', '-m', 'baseline']);

        // Cycle branch: introduce new classes + edit a pre-existing file.
        $this->git($repo, ['checkout', '-q', '-b', 'cycle']);
        $this->put($repo, $wired, "<?php\nnamespace App\\Services\\X;\nclass WiredThing { public function go(): int { return 7; } }\n");
        $this->put($repo, $consumer, "<?php\nnamespace App\\Services\\X;\nclass ConsumerOfWired { public function run() { return (new WiredThing())->go(); } }\n");
        $this->put($repo, $orphan, "<?php\nnamespace App\\Services\\X;\nclass OrphanThing { public function nope(): int { return 0; } }\n");
        // Mentions OrphanThing ONLY in a comment — must NOT count as consuming it.
        $this->put($repo, $commentOnly, "<?php\nnamespace App\\Services\\X;\n// OrphanThing is planned for a later cycle\nclass CommentOnlyMentioner { public function x(): int { return 1; } }\n");
        $this->put($repo, $base, "<?php\nnamespace App\\Services\\X;\nclass ExistingConsumer { public function a() { return 2; } }\n"); // edit
        $this->git($repo, ['add', '-A']);
        $this->git($repo, ['commit', '-q', '-m', 'cycle']);

        $result = $this->scanner->scan($repo, $repo, [$base, $wired, $consumer, $orphan, $commentOnly], 'main');

        // ExistingConsumer was edited (pre-existing on main) => not a new class.
        $this->assertNotContains('ExistingConsumer', $result['new_classes']);
        $this->assertEqualsCanonicalizing(['WiredThing', 'ConsumerOfWired', 'OrphanThing', 'CommentOnlyMentioner'], $result['new_classes']);

        // WiredThing is consumed by ConsumerOfWired (real `new WiredThing()`).
        $this->assertContains('WiredThing', $result['runtime_consumed_classes']);

        // OrphanThing is only comment-mentioned => inert, not consumed.
        $this->assertNotContains('OrphanThing', $result['runtime_consumed_classes']);
        $this->assertContains('OrphanThing', $result['inert_new_classes']);
        $this->assertContains('CommentOnlyMentioner', $result['inert_new_classes']);

        $this->cleanup($repo);
    }

    private function makeRepo(): string
    {
        $dir = sys_get_temp_dir().'/atlas_rccs_'.bin2hex(random_bytes(6));
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $init = new Process(['git', 'init', '-q', '-b', 'main'], $dir);
        $init->run();
        if (! $init->isSuccessful()) { // older git without -b
            (new Process(['git', 'init', '-q'], $dir))->run();
            (new Process(['git', 'checkout', '-q', '-b', 'main'], $dir))->run();
        }
        $this->git($dir, ['config', 'user.email', 'test@atlas.local']);
        $this->git($dir, ['config', 'user.name', 'atlas-test']);
        $this->git($dir, ['config', 'commit.gpgsign', 'false']);

        return $dir;
    }

    private function put(string $repo, string $rel, string $contents): void
    {
        $path = $repo.'/'.$rel;
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($path, $contents);
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $cwd, array $args): void
    {
        $p = new Process(array_merge(['git'], $args), $cwd);
        $p->run();
    }

    private function cleanup(string $dir): void
    {
        $p = new Process(['rm', '-rf', $dir]);
        $p->run();
    }
}
