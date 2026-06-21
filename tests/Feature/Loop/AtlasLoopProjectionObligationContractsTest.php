<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCrossFileConsumerGateService;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionObligationContracts;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * §3 · ARCHITECT PHASE — the projected design contract is a real VETO, not a seal. The grounded
 * consumer_intact obligations are translated into the explicit consumer_contracts the cross-file consumer
 * gate enforces, and the END-TO-END proof runs the REAL gate: a refactor that breaks a real caller's
 * behavior is REFUSED (consumer_contract_failed); an intact refactor passes. Without this bridge the
 * projection produced a contract nobody read.
 */
final class AtlasLoopProjectionObligationContractsTest extends TestCase
{
    public function test_translates_consumer_obligations_into_enforceable_contracts(): void
    {
        $obligations = [
            ['kind' => 'behavior_preserved', 'target_symbol' => 'app/x/hub.php', 'assertion_ref' => 'mutop:return_true'],
            ['kind' => 'consumer_intact', 'target_symbol' => 'app/x/callera.php', 'assertion_ref' => 'consumer:app/x/callera.php'],
        ];
        $contracts = (new AtlasLoopProjectionObligationContracts)->toConsumerContracts(
            $obligations,
            ['app/X/CallerA.php'],
            'Hub',
            static fn (string $c): string => './vendor/bin/phpunit '.escapeshellarg('tests/Unit/CallerATest.php'),
        );

        $this->assertCount(1, $contracts);
        $this->assertSame('Hub', $contracts[0]['changed_symbol']);
        $this->assertSame('app/X/CallerA.php', $contracts[0]['consumer_file']);
        $this->assertStringContainsString('phpunit', $contracts[0]['command']);
        $this->assertSame('tests/Unit/CallerATest.php', $contracts[0]['test_path']);
    }

    public function test_no_consumer_obligation_or_no_changed_symbol_yields_no_contract(): void
    {
        $bridge = new AtlasLoopProjectionObligationContracts;
        $cmd = static fn (string $c): ?string => 'x';
        // no consumer_intact obligation in the set ⇒ nothing to enforce
        $this->assertSame([], $bridge->toConsumerContracts([['kind' => 'behavior_preserved', 'target_symbol' => 't']], ['app/C.php'], 'T', $cmd));
        // an empty changed symbol cannot ground a contract
        $this->assertSame([], $bridge->toConsumerContracts([['kind' => 'consumer_intact', 'target_symbol' => 'c']], ['app/C.php'], '', $cmd));
    }

    public function test_real_gate_refuses_a_refactor_that_breaks_a_real_caller(): void
    {
        $ws = $this->workspace();
        $contracts = (new AtlasLoopProjectionObligationContracts)->toConsumerContracts(
            [['kind' => 'consumer_intact', 'target_symbol' => 'app/caller.php', 'assertion_ref' => 'consumer:app/caller.php']],
            ['app/Caller.php'],
            'Target',
            static fn (string $c): string => escapeshellarg(PHP_BINARY).' tests/caller_test.php',
        );
        $gate = new AtlasLoopCrossFileConsumerGateService;
        $acceptance = ['commands' => [escapeshellarg(PHP_BINARY).' -r "exit(0);"']];
        $options = ['enabled' => true, 'consumer_contracts' => $contracts, 'code_graph_workspace' => $ws];

        // INTACT — the caller's behavior test still passes ⇒ the consumer contracts pass.
        $intact = $gate->evaluate($ws, $acceptance, ['app/Target.php'], $options);
        $this->assertTrue($intact['certified'], json_encode($intact['blockers'] ?? $intact['status']));
        $this->assertSame('consumer_contracts_passed', $intact['status']);

        // BREAK the target so the caller's behavior test goes RED — the SAME projected contract now VETOES.
        file_put_contents($ws.'/app/Target.php', "<?php\nclass Target { public function val(): int { return 999; } }\n");
        $broken = $gate->evaluate($ws, $acceptance, ['app/Target.php'], $options);
        $this->assertFalse($broken['certified'], 'a refactor that breaks a real caller must be refused');
        $this->assertSame('consumer_contract_failed', $broken['status']);

        (new Process(['rm', '-rf', $ws]))->run();
    }

    private function workspace(): string
    {
        $ws = sys_get_temp_dir().'/atlas-obl-contract-'.bin2hex(random_bytes(4));
        @mkdir($ws.'/app', 0o755, true);
        @mkdir($ws.'/tests', 0o755, true);
        file_put_contents($ws.'/app/Target.php', "<?php\nclass Target { public function val(): int { return 10; } }\n");
        file_put_contents($ws.'/app/Caller.php', "<?php\nrequire_once __DIR__.'/Target.php';\nclass Caller { public function total(): int { return (new Target)->val() * 2; } }\n");
        file_put_contents($ws.'/tests/caller_test.php', "<?php\nrequire __DIR__.'/../app/Caller.php';\nif ((new Caller)->total() !== 20) { fwrite(STDERR, 'broken'); exit(1); }\necho 'ok';\n");

        return $ws;
    }
}
