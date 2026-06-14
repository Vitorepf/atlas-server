<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Obra;

use App\Services\Ai\Obra\AtlasObraExecutor;
use App\Services\Ai\Obra\ObraNodeDelivery;
use App\Services\Ai\Obra\ObraNodeGate;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * OBRA REPAIR LOOP (eixo-3) — frozen proof of the autonomy MULTIPLIER, by construction, ZERO spend.
 * A flaky delivery that fails a node's first K attempts then succeeds proves: with repair ON the node
 * reaches DONE (multiplier), with repair OFF it halts on the first failure (byte-identical to today),
 * the per-node cap bites + fail-closes, a byte-identical re-edit stops early (no-progress), the
 * augmented retry request is LABEL-ONLY (no raw output leaks), and a repaired delivery still faces the
 * SAME gate (repair changes the count of attempts, never the bar).
 */
final class AtlasObraRepairLoopTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.obra.enabled', true);
        config()->set('atlas.aurg.enabled', false);
        $this->repo = sys_get_temp_dir().'/atlas-obra-repair-'.bin2hex(random_bytes(4));
        File::makeDirectory($this->repo.'/app', 0o777, true, true);
        File::put($this->repo.'/app/Hub.php', "<?php\nnamespace App;\nfinal class Hub { public function x(): int { return 1; } }\n");
        foreach ([['init', '-q'], ['add', '-A'], ['-c', 'user.email=t@t', '-c', 'user.name=t', 'commit', '-q', '-m', 'base', '--no-gpg-sign']] as $argv) {
            (new Process(array_merge(['git'], $argv), $this->repo, null, null, 30.0))->run();
        }
    }

    protected function tearDown(): void
    {
        if ($this->repo !== '' && is_dir($this->repo)) {
            (new Process(['rm', '-rf', $this->repo]))->run();
        }
        parent::tearDown();
    }

    /** A single-node in-memory plan editing app/Hub.php. */
    private function plan(string $id = 'obra-repair-1'): array
    {
        return [
            'plan_id' => $id,
            'nodes' => [['id' => 'node-1', 'seq' => 0, 'request' => 'reduce complexity', 'target_area' => 'app/Hub.php']],
        ];
    }

    private function exec(ObraNodeDelivery $delivery, ?ObraNodeGate $gate = null): AtlasObraExecutor
    {
        return new AtlasObraExecutor($delivery, new GovernedBranchMaterializationService(), null, $gate);
    }

    private function runObra(AtlasObraExecutor $executor, array $repair, string $id = 'obra-repair-1'): array
    {
        return $executor->execute($this->plan($id), [
            'repo_dir' => $this->repo,
            'integrated_check' => 'php -r "exit(0);"',
            'no_brain' => true,
            'repair' => $repair,
        ]);
    }

    public function test_repair_off_is_byte_identical_a_failed_node_halts_exactly_once(): void
    {
        $d = new FlakyFixtureDelivery(failFirstK: 2); // would succeed on the 3rd — but OFF never retries
        $env = $this->runObra($this->exec($d), ['enabled' => false, 'maxPerNode' => 1, 'maxPerObra' => 8]);

        $this->assertSame(AtlasObraExecutor::STATUS_FAILED, $env['status']);
        $this->assertSame(1, $d->calls['app/Hub.php'] ?? 0, 'repair OFF delivers exactly once (today behaviour)');
    }

    public function test_repair_on_fixture_fails_K_then_succeeds_node_reaches_done(): void
    {
        $d = new FlakyFixtureDelivery(failFirstK: 2); // fails attempts 1,2; succeeds attempt 3
        $env = $this->runObra($this->exec($d), ['enabled' => true, 'maxPerNode' => 3, 'maxPerObra' => 8]);

        $this->assertSame(AtlasObraExecutor::STATUS_DONE, $env['status'], json_encode($env['reason'] ?? ''));
        $this->assertSame(1, (int) ($env['delivered_nodes'] ?? 0));
        $this->assertSame(3, $d->calls['app/Hub.php'] ?? 0, 'repaired across 3 attempts (the multiplier)');
        $this->assertTrue((bool) ($env['main_untouched'] ?? false));
    }

    public function test_repair_per_node_cap_bites_exhaustion_halts_fail_closed(): void
    {
        $d = new FlakyFixtureDelivery(failFirstK: 99); // never succeeds within the cap
        $env = $this->runObra($this->exec($d), ['enabled' => true, 'maxPerNode' => 3, 'maxPerObra' => 8]);

        $this->assertSame(AtlasObraExecutor::STATUS_FAILED, $env['status']);
        $this->assertSame(3, $d->calls['app/Hub.php'] ?? 0, 'bounded at the per-node cap');
        $this->assertStringContainsString('repair_exhausted:per_node', (string) data_get($env, 'nodes.0.reason', ''));
    }

    public function test_repair_no_progress_fingerprint_stops_before_cap(): void
    {
        // Returns the SAME non-empty (but uncertified) files every attempt — the same wrong edit.
        $d = new FlakyFixtureDelivery(failFirstK: 99, sameWrongFiles: true);
        $env = $this->runObra($this->exec($d), ['enabled' => true, 'maxPerNode' => 5, 'maxPerObra' => 8]);

        $this->assertSame(AtlasObraExecutor::STATUS_FAILED, $env['status']);
        $this->assertSame(2, $d->calls['app/Hub.php'] ?? 0, 'no-progress stops at attempt 2, below the cap of 5');
        $this->assertStringContainsString('repair_exhausted:no_progress', (string) data_get($env, 'nodes.0.reason', ''));
    }

    public function test_repair_augmented_request_is_provider_safe_label_only(): void
    {
        $secret = 'SENTINEL_SECRET_abc123';
        $d = new FlakyFixtureDelivery(failFirstK: 1, outputExcerptSentinel: $secret);
        $this->runObra($this->exec($d), ['enabled' => true, 'maxPerNode' => 3, 'maxPerObra' => 8]);

        $this->assertGreaterThanOrEqual(2, count($d->requests), 'a retry happened');
        $retryRequest = $d->requests[1];
        $this->assertStringContainsString('[repair attempt', $retryRequest, 'retry carries the label-only feedback');
        $this->assertStringContainsString('syntax', $retryRequest, 'the gate label is fed back');
        $this->assertStringNotContainsString($secret, $retryRequest, 'the raw output_excerpt NEVER crosses back into a provider request');
    }

    public function test_repaired_delivery_still_faces_the_same_gate_repair_changes_attempts_not_the_bar(): void
    {
        // The delivery succeeds after one repair, but an injected gate ALWAYS vetoes — the obra still
        // halts. Repair cannot manufacture a pass; the gate bar is untouched.
        $d = new FlakyFixtureDelivery(failFirstK: 1);
        $vetoGate = new class implements ObraNodeGate
        {
            public function certify(array $node, array $context = []): array
            {
                return ['passed' => false, 'reason' => 'injected_veto'];
            }
        };
        $env = $this->runObra($this->exec($d, $vetoGate), ['enabled' => true, 'maxPerNode' => 3, 'maxPerObra' => 8]);

        $this->assertSame(AtlasObraExecutor::STATUS_FAILED, $env['status'], 'a repaired delivery still loses to the gate');
        $this->assertSame(2, $d->calls['app/Hub.php'] ?? 0, 'delivery was repaired (2 calls) — then the gate vetoed');
    }
}

/**
 * A deterministic ObraNodeDelivery that fails a node's first K deliveries then succeeds (or never).
 * NOT a real provider — the executor seals such a run execution_mode='fixture_obra_run'.
 */
final class FlakyFixtureDelivery implements ObraNodeDelivery
{
    /** @var array<string,int> per-target call counter */
    public array $calls = [];

    /** @var list<string> every request string the delivery received (for provider-safety assertions) */
    public array $requests = [];

    public function __construct(
        private readonly int $failFirstK,
        private readonly bool $sameWrongFiles = false,
        private readonly string $outputExcerptSentinel = '',
    ) {}

    public function label(): string
    {
        return 'flaky_fixture';
    }

    public function deliver(string $request, array $context = []): array
    {
        $this->requests[] = $request;
        $target = ltrim((string) ($context['target_area'] ?? 'app/Hub.php'), '/');
        $n = ($this->calls[$target] = ($this->calls[$target] ?? 0) + 1);

        if ($n <= $this->failFirstK) {
            $fail = ['certified' => false, 'reason' => 'provider_returned_not_ok', 'provider' => 'hermes_cli', 'model' => 'gpt-5.5'];
            // A "same wrong edit" returns identical NON-empty files every attempt (drives no-progress).
            if ($this->sameWrongFiles) {
                $fail['files'] = [['path' => $target, 'content' => "<?php\nnamespace App;\nfinal class Hub { public function x(): int { return 1; } }\n"]];
            } else {
                $fail['files'] = [];
            }
            // The raw gate output (which could echo source) lives ONLY here — it must NOT cross back.
            $fail['syntax_check'] = ['ok' => false, 'tool' => 'php -l', 'reason' => 'parse_error', 'exit_code' => 255, 'output_excerpt' => 'Parse error near '.$this->outputExcerptSentinel];

            return $fail;
        }

        // Success: a genuinely simpler version of Hub (lower cyclomatic — trivially, it already is).
        return [
            'certified' => true,
            'files' => [['path' => $target, 'content' => "<?php\nnamespace App;\nfinal class Hub { public function x(): int { return 0; } }\n"]],
            'gate_receipt' => str_repeat('c', 40),
            'provider' => 'hermes_cli',
            'model' => 'gpt-5.5',
        ];
    }
}
