<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopNodeInterfaceVerifier;
use PHPUnit\Framework\TestCase;

/**
 * ACDE Leap 6 — the verifier proves a workspace's REAL AST surface honours the HUMAN-frozen interface
 * contract: required public methods present, required implements/extends satisfied (name-resolved via the
 * file's imports/namespace), and forbidden imports absent (dependency-direction). Any miss is a precise
 * 'interface_contract_violation:<file>:<detail>' reason; a fully-honoured contract returns [].
 */
final class AtlasLoopNodeInterfaceVerifierTest extends TestCase
{
    private function verifier(): AtlasLoopNodeInterfaceVerifier
    {
        return new AtlasLoopNodeInterfaceVerifier;
    }

    /** @param array<string,string> $files */
    private function reader(array $files): callable
    {
        return static fn (string $f): ?string => $files[$f] ?? null;
    }

    public function test_a_file_that_honours_the_contract_returns_no_violations(): void
    {
        $contract = ['app/Support/HubHelper.php' => [
            'fqn' => 'App\\Support\\HubHelper',
            'required_public_methods' => ['stepA', 'stepB'],
            'implements' => ['App\\Contracts\\Helper'],
            'extends' => [],
            'forbidden_imports' => ['App\\Services\\Hub'],
        ]];
        $src = "<?php\nnamespace App\\Support;\nuse App\\Contracts\\Helper;\n"
            ."final class HubHelper implements Helper {\n  public function stepA(): void {}\n  public function stepB(): int { return 1; }\n}\n";

        $this->assertSame([], $this->verifier()->verify($contract, $this->reader(['app/Support/HubHelper.php' => $src])));
    }

    public function test_missing_public_method_is_a_violation(): void
    {
        $contract = ['app/Support/HubHelper.php' => ['fqn' => null, 'required_public_methods' => ['stepA', 'stepB'], 'implements' => [], 'extends' => [], 'forbidden_imports' => []]];
        $src = "<?php\nnamespace App\\Support;\nclass HubHelper { public function stepA(): void {} }\n";

        $out = $this->verifier()->verify($contract, $this->reader(['app/Support/HubHelper.php' => $src]));

        $this->assertContains('interface_contract_violation:app/Support/HubHelper.php:missing_public_method:stepB', $out);
        $this->assertNotContains('interface_contract_violation:app/Support/HubHelper.php:missing_public_method:stepA', $out);
    }

    public function test_forbidden_import_is_a_violation_dependency_direction(): void
    {
        // The helper MUST NOT depend on the Hub (anti-inversion) — but it imports it.
        $contract = ['app/Support/HubHelper.php' => ['fqn' => null, 'required_public_methods' => [], 'implements' => [], 'extends' => [], 'forbidden_imports' => ['App\\Services\\Hub']]];
        $src = "<?php\nnamespace App\\Support;\nuse App\\Services\\Hub;\nclass HubHelper { public function go(Hub \$h): void {} }\n";

        $out = $this->verifier()->verify($contract, $this->reader(['app/Support/HubHelper.php' => $src]));

        $this->assertContains('interface_contract_violation:app/Support/HubHelper.php:forbidden_import:App\\Services\\Hub', $out);
    }

    public function test_missing_required_implements_is_a_violation(): void
    {
        $contract = ['app/Foo.php' => ['fqn' => null, 'required_public_methods' => [], 'implements' => ['App\\Contracts\\Helper'], 'extends' => [], 'forbidden_imports' => []]];
        $src = "<?php\nnamespace App;\nclass Foo {}\n";

        $out = $this->verifier()->verify($contract, $this->reader(['app/Foo.php' => $src]));

        $this->assertContains('interface_contract_violation:app/Foo.php:missing_implements:App\\Contracts\\Helper', $out);
    }

    public function test_implements_is_name_resolved_via_imports(): void
    {
        // The contract names the FQN; the code writes the short name + imports it => must MATCH (no violation).
        $contract = ['app/Foo.php' => ['fqn' => 'App\\Foo', 'required_public_methods' => [], 'implements' => ['App\\Contracts\\Helper'], 'extends' => [], 'forbidden_imports' => []]];
        $src = "<?php\nnamespace App;\nuse App\\Contracts\\Helper;\nclass Foo implements Helper {}\n";

        $this->assertSame([], $this->verifier()->verify($contract, $this->reader(['app/Foo.php' => $src])));
    }

    public function test_forbidden_import_prefix_denylist(): void
    {
        // A trailing-backslash forbidden entry forbids the whole namespace; a sibling namespace is fine.
        $contract = ['app/Foo.php' => ['fqn' => null, 'required_public_methods' => [], 'implements' => [], 'extends' => [], 'forbidden_imports' => ['App\\Services\\']]];

        $bad = "<?php\nnamespace App;\nuse App\\Services\\Hub;\nclass Foo { public function go(Hub \$h): void {} }\n";
        $this->assertContains('interface_contract_violation:app/Foo.php:forbidden_import:App\\Services\\', $this->verifier()->verify($contract, $this->reader(['app/Foo.php' => $bad])));

        $ok = "<?php\nnamespace App;\nuse App\\ServicesSupport\\Helper;\nclass Foo { public function go(Helper \$h): void {} }\n";
        $this->assertSame([], $this->verifier()->verify($contract, $this->reader(['app/Foo.php' => $ok])), 'a sibling namespace must NOT trip the prefix');
    }

    public function test_forbidden_dependency_reached_via_service_locator_string(): void
    {
        // No use-import of the Hub — but it is resolved through the container. The import-only census would
        // miss this; the service-ref census catches the inverted edge.
        $contract = ['app/Foo.php' => ['fqn' => null, 'required_public_methods' => [], 'implements' => [], 'extends' => [], 'forbidden_imports' => ['App\\Services\\Hub']]];
        $src = "<?php\nnamespace App;\nclass Foo { public function go() { return app(\\App\\Services\\Hub::class); } }\n";

        $out = $this->verifier()->verify($contract, $this->reader(['app/Foo.php' => $src]));

        $this->assertContains('interface_contract_violation:app/Foo.php:forbidden_service_string:App\\Services\\Hub', $out);
    }

    public function test_a_missing_file_or_wrong_type_is_a_violation(): void
    {
        $contract = ['app/Gone.php' => ['fqn' => 'App\\Gone', 'required_public_methods' => [], 'implements' => [], 'extends' => [], 'forbidden_imports' => []]];

        $missing = $this->verifier()->verify($contract, $this->reader([]));
        $this->assertContains('interface_contract_violation:app/Gone.php:file_missing', $missing);

        $wrongType = $this->verifier()->verify($contract, $this->reader(['app/Gone.php' => "<?php\nnamespace App;\nclass Other {}\n"]));
        $this->assertContains('interface_contract_violation:app/Gone.php:missing_type:App\\Gone', $wrongType);
    }
}
