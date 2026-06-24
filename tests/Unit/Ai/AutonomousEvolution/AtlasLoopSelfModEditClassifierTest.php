<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\SelfMod\AtlasLoopSelfModEditClassifier;
use Tests\TestCase;

final class AtlasLoopSelfModEditClassifierTest extends TestCase
{
    public function test_local_variable_rename_is_cosmetic_with_empty_evidence(): void
    {
        $before = <<<'PHP'
<?php
namespace App\Services\Ai\AutonomousEvolution\Demo;
final class DemoClass {
    public function run(): int {
        $count = 1;
        return $count;
    }
}
PHP;
        $after = <<<'PHP'
<?php
namespace App\Services\Ai\AutonomousEvolution\Demo;
final class DemoClass {
    public function run(): int {
        $total = 1;
        return $total;
    }
}
PHP;

        $result = (new AtlasLoopSelfModEditClassifier)->classify('app/Services/Ai/AutonomousEvolution/DemoClass.php', $before, $after);

        $this->assertSame('COSMETIC', $result['kind']);
        $this->assertSame([], $result['evidence']['ast_node_kinds_changed']);
    }

    public function test_public_method_signature_change_is_structural_with_class_method_evidence(): void
    {
        $before = <<<'PHP'
<?php
namespace App\Services\Ai\AutonomousEvolution\Demo;
final class DemoClass {
    public function run(): int { return 1; }
}
PHP;
        $after = <<<'PHP'
<?php
namespace App\Services\Ai\AutonomousEvolution\Demo;
final class DemoClass {
    public function run(string $mode): int { return 1; }
}
PHP;

        $result = (new AtlasLoopSelfModEditClassifier)->classify('app/Services/Ai/AutonomousEvolution/DemoClass.php', $before, $after);

        $this->assertSame('STRUCTURAL', $result['kind']);
        $this->assertContains('Stmt_ClassMethod', $result['evidence']['ast_node_kinds_changed']);
    }

    public function test_return_or_branch_change_is_semantic(): void
    {
        $before = <<<'PHP'
<?php
namespace App\Services\Ai\AutonomousEvolution\Demo;
final class DemoClass {
    public function run(): int {
        if (true) {
            return 1;
        }
        return 0;
    }
}
PHP;
        $after = <<<'PHP'
<?php
namespace App\Services\Ai\AutonomousEvolution\Demo;
final class DemoClass {
    public function run(): int {
        if (false) {
            return 1;
        }
        return 0;
    }
}
PHP;

        $result = (new AtlasLoopSelfModEditClassifier)->classify('app/Services/Ai/AutonomousEvolution/DemoClass.php', $before, $after);

        $this->assertSame('SEMANTIC', $result['kind']);
        $this->assertContains('Stmt_If', $result['evidence']['ast_node_kinds_changed']);
    }

    public function test_parse_failure_fails_closed_as_structural(): void
    {
        $before = '<?php final class Broken {';
        $after = <<<'PHP'
<?php
final class Fixed {
    public function run(): int { return 1; }
}
PHP;

        $result = (new AtlasLoopSelfModEditClassifier)->classify('app/Services/Ai/AutonomousEvolution/Broken.php', $before, $after);

        $this->assertSame('STRUCTURAL', $result['kind']);
        $this->assertTrue($result['evidence']['parse_error']);
    }
}
