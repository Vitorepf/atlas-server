<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMutationAdequacyGateService;
use Illuminate\Console\Command;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;

final class AtlasLoopMutationAdequacyGateCommand extends Command
{
    protected $signature = 'atlas:loop:mutation-gate
        {--workspace= : Git workspace containing the approved candidate}
        {--acceptance= : Acceptance JSON object}
        {--acceptance-file= : Path to acceptance JSON object}
        {--changed-file=* : Changed file to mutate; omitted means discover from git}
        {--property-command=* : Optional property runner; receives ATLAS_MUTATION_PROPERTY_CASES}
        {--fixture= : Built-in fixture to run: strong or weak}
        {--receipt= : Optional path to write receipt JSON}
        {--write-receipt : Write to configured receipt path when --receipt is omitted}
        {--strict : Exit non-zero unless the mutation adequacy gate certifies}
        {--json : Print canonical JSON}';

    protected $description = 'L6-5 mutation adequacy gate: adversarial numeric cases plus a temporary mutant that tests must kill.';

    public function handle(AtlasLoopMutationAdequacyGateService $gate): int
    {
        try {
            [$workspace, $acceptance, $changedFiles, $cleanup] = $this->inputs();
            $receipt = $gate->evaluate($workspace, $acceptance, $changedFiles, [
                'enabled' => true,
                'property_commands' => $this->stringOptionList('property-command'),
                'timeout_seconds' => (int) config('atlas.loop.mutation_adequacy_gate.timeout_seconds', 120),
                'max_mutants' => (int) config('atlas.loop.mutation_adequacy_gate.max_mutants', 1),
            ]);
        } catch (JsonException | RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            if (isset($cleanup)) {
                $cleanup();
            }
        }

        $receiptPath = $this->receiptPath();
        if ($receiptPath !== '') {
            $this->writeJson($receiptPath, $receipt);
            $receipt['receipt_path'] = $receiptPath;
        }

        if ((bool) $this->option('json')) {
            $this->line($this->json($receipt, pretty: true));
        } else {
            $this->components->twoColumnDetail('Mutation adequacy gate', (string) ($receipt['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Certified', (bool) ($receipt['certified'] ?? false) ? 'yes' : 'no');
            $this->components->twoColumnDetail('Mutants', (string) ($receipt['mutants_killed'] ?? 0).'/'.(string) ($receipt['mutants_sampled'] ?? 0).' killed');
        }

        return (bool) ($this->option('strict') ?? false) && ! (bool) ($receipt['certified'] ?? false)
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return array{0:string,1:array<string,mixed>,2:list<string>,3:callable():void}
     *
     * @throws JsonException
     */
    private function inputs(): array
    {
        $fixture = trim((string) ($this->option('fixture') ?: ''));
        if ($fixture !== '') {
            return $this->fixture($fixture);
        }

        $workspace = rtrim(trim((string) ($this->option('workspace') ?: '')), '/');
        if ($workspace === '' || ! is_dir($workspace)) {
            throw new RuntimeException('Missing or invalid --workspace.');
        }

        return [
            $workspace,
            $this->acceptance(),
            $this->stringOptionList('changed-file'),
            static function (): void {},
        ];
    }

    /**
     * @return array<string,mixed>
     *
     * @throws JsonException
     */
    private function acceptance(): array
    {
        $json = trim((string) ($this->option('acceptance') ?: ''));
        $file = trim((string) ($this->option('acceptance-file') ?: ''));
        if ($json === '' && $file !== '' && is_file($file)) {
            $json = (string) file_get_contents($file);
        }
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array{0:string,1:array<string,mixed>,2:list<string>,3:callable():void}
     */
    private function fixture(string $kind): array
    {
        if (! in_array($kind, ['strong', 'weak'], true)) {
            throw new RuntimeException('--fixture must be "strong" or "weak".');
        }

        $dir = sys_get_temp_dir().'/atlas-loop-mutation-gate-'.bin2hex(random_bytes(5));
        @mkdir($dir.'/src', 0o755, true);
        @mkdir($dir.'/tests', 0o755, true);
        file_put_contents($dir.'/src/NumericKernel.php', <<<'PHP'
<?php

final class NumericKernel
{
    public function label(float $value): string
    {
        return 'red';
    }
}
PHP);
        file_put_contents($dir.'/tests/NumericKernelTest.php', $kind === 'strong' ? $this->strongFixtureTest() : $this->weakFixtureTest());
        $this->runProcess(['git', 'init', '-q'], $dir);
        $this->runProcess(['git', 'config', 'user.email', 'atlas-loop@local'], $dir);
        $this->runProcess(['git', 'config', 'user.name', 'Atlas Loop'], $dir);
        $this->runProcess(['git', 'add', '-A'], $dir);
        $this->runProcess(['git', 'commit', '-q', '-m', 'baseline'], $dir);
        file_put_contents($dir.'/src/NumericKernel.php', <<<'PHP'
<?php

final class NumericKernel
{
    public function label(float $value): string
    {
        return 'green';
    }
}
PHP);

        return [
            $dir,
            [
                'commands' => ['php tests/NumericKernelTest.php'],
                'allowed_globs' => ['src/**'],
                'frozen_globs' => ['tests/**'],
                'metric_kind' => 'gate',
                'timeout_seconds' => 30,
            ],
            ['src/NumericKernel.php'],
            static function () use ($dir): void {
                (new Process(['rm', '-rf', $dir], null, null, null, 30.0))->run();
            },
        ];
    }

    private function strongFixtureTest(): string
    {
        return <<<'PHP'
<?php

require __DIR__.'/../src/NumericKernel.php';

$cases = json_decode(getenv('ATLAS_MUTATION_PROPERTY_CASES') ?: '[]', true);
$families = array_column(is_array($cases) ? $cases : [], 'family');
foreach (['nan', 'positive_infinity', 'float_overflow', 'integer_bounds'] as $family) {
    if (! in_array($family, $families, true)) {
        fwrite(STDERR, 'missing adversarial family '.$family);
        exit(1);
    }
}

$kernel = new NumericKernel();
if ($kernel->label(1.0) !== 'green') {
    fwrite(STDERR, 'expected exact green label');
    exit(1);
}
PHP;
    }

    private function weakFixtureTest(): string
    {
        return <<<'PHP'
<?php

require __DIR__.'/../src/NumericKernel.php';

$cases = json_decode(getenv('ATLAS_MUTATION_PROPERTY_CASES') ?: '[]', true);
if (count(is_array($cases) ? $cases : []) < 4) {
    fwrite(STDERR, 'missing adversarial cases');
    exit(1);
}

$kernel = new NumericKernel();
if ($kernel->label(1.0) === 'red') {
    fwrite(STDERR, 'still red');
    exit(1);
}
PHP;
    }

    /**
     * @param  list<string>  $argv
     */
    private function runProcess(array $argv, string $cwd): void
    {
        $process = new Process($argv, $cwd, null, null, 30.0);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException($process->getErrorOutput() ?: $process->getOutput());
        }
    }

    /**
     * @return list<string>
     */
    private function stringOptionList(string $key): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $value): string => is_string($value) ? trim($value) : '',
            (array) $this->option($key),
        ), static fn (string $value): bool => $value !== ''));
    }

    private function receiptPath(): string
    {
        $path = trim((string) ($this->option('receipt') ?: ''));
        if ($path !== '') {
            return $path;
        }

        return (bool) $this->option('write-receipt')
            ? (string) config('atlas.loop.mutation_adequacy_gate.receipt_path', storage_path('app/atlas/evidence/mutation-adequacy-gate.json'))
            : '';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException('Cannot create receipt directory '.$dir);
        }
        file_put_contents($path, $this->json($payload, pretty: true)."\n");
    }

    private function json(mixed $payload, bool $pretty = false): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0) | JSON_THROW_ON_ERROR,
        );
    }
}
