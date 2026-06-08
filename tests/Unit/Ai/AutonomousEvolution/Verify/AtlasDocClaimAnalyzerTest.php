<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Verify;

use App\Services\Ai\AutonomousEvolution\Verify\AtlasDocClaimAnalyzer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Soundness tests for the fake-implemented oracle: it must flag only DECISIVE phantom
 * claims (App\ class with no file/namespace, command with no registered prefix) and never
 * false-flag a real symbol — the property that collapsed 252 false phantoms to 0 once the
 * authoritative command registry replaced the static grep.
 */
final class AtlasDocClaimAnalyzerTest extends TestCase
{
    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-claim-test-'.bin2hex(random_bytes(4));
        mkdir($this->repo.'/app/Services/Real', 0o755, true);
        file_put_contents($this->repo.'/app/Services/Real/RealService.php', "<?php\nnamespace App\\Services\\Real;\nclass RealService {}\n");
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->repo]))->run();
        parent::tearDown();
    }

    private function analyze(string $md, array $registeredCommands = []): array
    {
        $doc = $this->repo.'/doc.md';
        file_put_contents($doc, $md);
        $analyzer = new AtlasDocClaimAnalyzer;
        $analyzer->useRegisteredCommands($registeredCommands);

        return $analyzer->analyzeFile($doc, $this->repo);
    }

    /**
     * @return list<string>
     */
    private function phantomSymbols(array $report): array
    {
        return array_map(static fn (array $p): string => $p['symbol'], $report['phantoms']);
    }

    public function test_real_class_is_not_flagged(): void
    {
        $report = $this->analyze('See `App\Services\Real\RealService` for details.');
        $this->assertSame([], $report['phantoms']);
    }

    public function test_phantom_class_is_flagged(): void
    {
        $report = $this->analyze('Uses App\Services\Totally\Fake\PhantomService heavily.');
        $this->assertSame(['App\Services\Totally\Fake\PhantomService'], $this->phantomSymbols($report));
    }

    public function test_namespace_reference_is_not_a_phantom(): void
    {
        // a directory exists at app/Services/Real → this is a namespace, not a missing class
        $report = $this->analyze('Everything under App\Services\Real lives here.');
        $this->assertSame([], $report['phantoms']);
    }

    public function test_secondary_type_in_a_sibling_file_is_not_flagged(): void
    {
        // PSR-4 names a file after its PRIMARY type; a secondary public type lives in the same
        // namespace dir under a differently-named file. The oracle must find it. (Audit hole #3.)
        file_put_contents(
            $this->repo.'/app/Services/Real/PrimaryThing.php',
            "<?php\nnamespace App\\Services\\Real;\nclass PrimaryThing {}\nfinal class SecondaryHelper {}\n",
        );
        $report = $this->analyze('We rely on `App\Services\Real\SecondaryHelper` here.');
        $this->assertSame([], $report['phantoms']);
    }

    public function test_genuinely_missing_secondary_type_is_still_flagged(): void
    {
        $report = $this->analyze('We rely on App\Services\Real\NoSuchHelperAnywhere here.');
        $this->assertSame(['App\Services\Real\NoSuchHelperAnywhere'], $this->phantomSymbols($report));
    }

    public function test_registered_command_is_not_flagged(): void
    {
        $report = $this->analyze('Run `php artisan atlas:thing:do` now.', ['atlas:thing:do']);
        $this->assertSame([], $report['phantoms']);
    }

    public function test_colon_shorthand_of_a_registered_parent_is_not_flagged(): void
    {
        // parent command exists; `:brief` is the action argument shorthand → not a phantom
        $report = $this->analyze('Run `php artisan atlas:thing:do:brief`.', ['atlas:thing:do']);
        $this->assertSame([], $report['phantoms']);
    }

    public function test_unregistered_command_is_flagged(): void
    {
        $report = $this->analyze('Run `php artisan atlas:nope:missing`.', ['atlas:thing:do']);
        $this->assertSame(['atlas:nope:missing'], $this->phantomSymbols($report));
    }
}
