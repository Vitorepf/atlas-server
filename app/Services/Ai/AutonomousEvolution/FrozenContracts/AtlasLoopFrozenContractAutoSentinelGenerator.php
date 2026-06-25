<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\FrozenContracts;

use RuntimeException;

/**
 * Thrown when {@see AtlasLoopFrozenContractAutoSentinelGenerator::generate()} is asked to overwrite an
 * existing sentinel test file without a valid operator-receipt token. Append-only / no-clobber for the
 * generated test scaffold — silent overwrite would weaken the regression net.
 */
final class AtlasLoopFrozenContractSentinelClobberException extends RuntimeException
{
}

/**
 * Strategy interface for verifying an operator-receipt token before {@see AtlasLoopFrozenContractAutoSentinelGenerator}
 * is allowed to overwrite an existing sentinel test file. The default {@see AtlasLoopFrozenContractRefuseAllReceipts}
 * refuses every token (safe default); a downstream RetirementGate can plug in a real verifier.
 */
interface AtlasLoopFrozenContractOperatorReceiptVerifier
{
    public function isValid(string $token): bool;
}

/**
 * Default verifier — refuses every token. Operator-controlled wiring replaces this with a real verifier.
 */
final class AtlasLoopFrozenContractRefuseAllReceipts implements AtlasLoopFrozenContractOperatorReceiptVerifier
{
    public function isValid(string $token): bool
    {
        return false;
    }
}

/**
 * Given a frozen-contract class FQCN + the FACT array produced by {@see AtlasLoopFrozenContractFactExtractor},
 * deterministically emits a PHPUnit regression-test scaffold that asserts each extracted invariant survives
 * in the live docblock. If a future edit silently weakens a frozen invariant (e.g. removes "never proxy via
 * line count"), the generated sentinel test fails — the regression net is the structural defense.
 *
 * PURE PHP CODE-GEN: no provider calls, no LLM, no templating dependency. Byte-identical output for
 * identical inputs (deterministic test-method names derived from the invariant text).
 *
 * NO-CLOBBER: a generated sentinel test file may not be silently overwritten. The caller MUST supply a
 * valid operator-receipt token; absent or invalid ⇒ {@see AtlasLoopFrozenContractSentinelClobberException}.
 */
final class AtlasLoopFrozenContractAutoSentinelGenerator
{
    private readonly AtlasLoopFrozenContractOperatorReceiptVerifier $receiptVerifier;

    public function __construct(?AtlasLoopFrozenContractOperatorReceiptVerifier $receiptVerifier = null)
    {
        $this->receiptVerifier = $receiptVerifier ?? new AtlasLoopFrozenContractRefuseAllReceipts;
    }

    /**
     * @param  array{schema?:string, class?:string, invariants?:list<string>, forbidden_mutations?:list<string>, anchors?:list<string>, contract_version?:?string}  $facts
     * @return array{class_name:string, code:string}
     */
    public function generate(array $facts, string $targetPath, ?string $operatorReceiptToken = null): array
    {
        if (is_file($targetPath)) {
            if ($operatorReceiptToken === null || ! $this->receiptVerifier->isValid($operatorReceiptToken)) {
                throw new AtlasLoopFrozenContractSentinelClobberException('Refuse to overwrite existing sentinel test: '.$targetPath.' (operator-receipt required)');
            }
        }

        $fqcn = (string) ($facts['class'] ?? '');
        $shortClass = $this->shortClass($fqcn);
        $sentinelClass = $shortClass.'FrozenContractSentinelTest';
        $invariants = array_values(array_unique(array_map('strval', (array) ($facts['invariants'] ?? []))));
        sort($invariants, SORT_STRING);

        $methods = [];
        $usedNames = [];
        foreach ($invariants as $idx => $invariant) {
            $methodName = $this->methodNameFor($invariant, $idx, $usedNames);
            $methods[] = $this->renderMethod($methodName, $invariant, $fqcn);
        }

        $code = $this->renderScaffold($sentinelClass, $fqcn, $methods, $invariants);

        return ['class_name' => $sentinelClass, 'code' => $code];
    }

    /**
     * @param  list<string>  $usedNames
     */
    private function methodNameFor(string $invariant, int $idx, array &$usedNames): string
    {
        $slug = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', $invariant) ?? 'invariant');
        $slug = trim($slug, '_');
        if (strlen($slug) > 80) {
            $slug = substr($slug, 0, 80);
        }
        $base = 'test_invariant_'.($slug === '' ? 'unnamed' : $slug);
        $name = $base;
        $n = 1;
        while (in_array($name, $usedNames, true)) {
            $name = $base.'_'.(++$n);
        }
        $usedNames[] = $name;

        return $name;
    }

    private function renderMethod(string $methodName, string $invariant, string $fqcn): string
    {
        $escaped = var_export($invariant, true);
        $fqcnEscaped = var_export($fqcn, true);

        return <<<PHP
    public function {$methodName}(): void
    {
        \$reflection = new \\ReflectionClass({$fqcnEscaped});
        \$doc = (string) \$reflection->getDocComment();
        \$this->assertStringContainsString({$escaped}, \$doc, 'Frozen invariant lost from docblock: '.{$escaped});
    }
PHP;
    }

    /**
     * @param  list<string>  $methods
     * @param  list<string>  $invariants
     */
    private function renderScaffold(string $sentinelClass, string $fqcn, array $methods, array $invariants): string
    {
        $methodsBlock = implode("\n\n", $methods);
        $count = count($invariants);
        $fqcnEscaped = var_export($fqcn, true);

        return <<<PHP
<?php

declare(strict_types=1);

namespace Tests\\Feature\\Loop;

use PHPUnit\\Framework\\TestCase;

/**
 * AUTO-GENERATED frozen-contract sentinel for {$fqcn}. Each test asserts ONE extracted invariant survives
 * verbatim in the live docblock — a silent weakening of any frozen invariant fails this test.
 *
 * Generated invariants: {$count}. Do NOT edit by hand; rerun the generator instead.
 */
final class {$sentinelClass} extends TestCase
{
{$methodsBlock}

    public function test_target_class_still_exists(): void
    {
        \$this->assertTrue(class_exists({$fqcnEscaped}, false) || class_exists({$fqcnEscaped}), 'Frozen-contract target class vanished: '.{$fqcnEscaped});
    }
}

PHP;
    }

    private function shortClass(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }
}
