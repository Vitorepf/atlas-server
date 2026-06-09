<?php

namespace Tests\Unit\Ai\Kernel\Architecture;

use App\Services\Ai\Kernel\Architecture\KernelArchitectureStaticScanner;
use Tests\TestCase;

class KernelArchitectureStaticScannerRuntimeLanguageBoundaryTest extends TestCase
{
    public function test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_laravel_app(): void
    {
        $path = app_path('Services/Ai/Context/ForbiddenFaissRegression.php');

        file_put_contents($path, <<<'PHP'
<?php

namespace App\Services\Ai\Context;

class ForbiddenFaissRegression
{
    public function run(): string|false|null
    {
        return shell_exec('python -c "import faiss"');
    }
}
PHP);

        try {
            $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
            $violations = data_get($report, 'ap201_runtime_language_boundary_contract.violations', []);

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString('ForbiddenFaissRegression.php', implode("\n", $violations));
            $this->assertStringContainsString('python_ai_data', implode("\n", $violations));
        } finally {
            @unlink($path);
        }
    }

    public function test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_semantic_adapter(): void
    {
        $path = app_path('Services/Semantic/ForbiddenChromaRegression.php');

        file_put_contents($path, <<<'PHP'
<?php

namespace App\Services\Semantic;

class ForbiddenChromaRegression
{
    public function run(): string|false|null
    {
        return shell_exec('python -c "import chromadb"');
    }
}
PHP);

        try {
            $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
            $violations = data_get($report, 'ap201_runtime_language_boundary_contract.violations', []);

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString('ForbiddenChromaRegression.php', implode("\n", $violations));
            $this->assertStringContainsString('python_ai_data', implode("\n", $violations));
        } finally {
            @unlink($path);
        }
    }

    public function test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_controller(): void
    {
        $path = app_path('Http/Controllers/ForbiddenLangGraphController.php');

        file_put_contents($path, <<<'PHP'
<?php

namespace App\Http\Controllers;

class ForbiddenLangGraphController
{
    public function __invoke(): string|false|null
    {
        return shell_exec('python -c "import langgraph"');
    }
}
PHP);

        try {
            $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
            $violations = data_get($report, 'ap201_runtime_language_boundary_contract.violations', []);

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString('ForbiddenLangGraphController.php', implode("\n", $violations));
            $this->assertStringContainsString('python_ai_data', implode("\n", $violations));
        } finally {
            @unlink($path);
        }
    }

    public function test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_job(): void
    {
        $path = app_path('Jobs/ForbiddenPandasJob.php');

        file_put_contents($path, <<<'PHP'
<?php

namespace App\Jobs;

class ForbiddenPandasJob
{
    public function handle(): string|false|null
    {
        return shell_exec('python -c "import pandas"');
    }
}
PHP);

        try {
            $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
            $violations = data_get($report, 'ap201_runtime_language_boundary_contract.violations', []);

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString('ForbiddenPandasJob.php', implode("\n", $violations));
            $this->assertStringContainsString('python_ai_data', implode("\n", $violations));
        } finally {
            @unlink($path);
        }
    }

    public function test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_command(): void
    {
        $path = app_path('Console/Commands/ForbiddenNumpyCommand.php');

        file_put_contents($path, <<<'PHP'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ForbiddenNumpyCommand extends Command
{
    protected $signature = 'atlas:forbidden-numpy';

    public function handle(): int
    {
        shell_exec('python -c "import numpy"');

        return self::SUCCESS;
    }
}
PHP);

        try {
            $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
            $violations = data_get($report, 'ap201_runtime_language_boundary_contract.violations', []);

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString('ForbiddenNumpyCommand.php', implode("\n", $violations));
            $this->assertStringContainsString('python_ai_data', implode("\n", $violations));
        } finally {
            @unlink($path);
        }
    }

    public function test_runtime_language_boundary_scan_flags_direct_go_runtime_inside_controller(): void
    {
        $path = app_path('Http/Controllers/ForbiddenGoEdgeController.php');

        file_put_contents($path, <<<'PHP'
<?php

namespace App\Http\Controllers;

class ForbiddenGoEdgeController
{
    public function __invoke(): string|false|null
    {
        return shell_exec('go run ./services/go-edge/postback-ingestor');
    }
}
PHP);

        try {
            $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
            $violations = data_get($report, 'ap201_runtime_language_boundary_contract.violations', []);

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString('ForbiddenGoEdgeController.php', implode("\n", $violations));
            $this->assertStringContainsString('go_edge', implode("\n", $violations));
        } finally {
            @unlink($path);
        }
    }

    public function test_runtime_language_boundary_scan_flags_direct_swift_native_runtime_inside_command(): void
    {
        $path = app_path('Console/Commands/ForbiddenSwiftNativeCommand.php');

        file_put_contents($path, <<<'PHP'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ForbiddenSwiftNativeCommand extends Command
{
    protected $signature = 'atlas:forbidden-swift-native';

    public function handle(): int
    {
        shell_exec('swift run ScreenCaptureKitProbe');

        return self::SUCCESS;
    }
}
PHP);

        try {
            $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
            $violations = data_get($report, 'ap201_runtime_language_boundary_contract.violations', []);

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString('ForbiddenSwiftNativeCommand.php', implode("\n", $violations));
            $this->assertStringContainsString('swift_native_mac', implode("\n", $violations));
        } finally {
            @unlink($path);
        }
    }

    public function test_runtime_language_boundary_scan_flags_laravel_process_facade_runtime_escape(): void
    {
        $path = app_path('Http/Controllers/ForbiddenLaravelProcessRuntimeController.php');

        file_put_contents($path, <<<'PHP'
<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Process;

class ForbiddenLaravelProcessRuntimeController
{
    public function __invoke(): mixed
    {
        return Process::run('python -c "import faiss"');
    }
}
PHP);

        try {
            $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
            $violations = data_get($report, 'ap201_runtime_language_boundary_contract.violations', []);

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString('ForbiddenLaravelProcessRuntimeController.php', implode("\n", $violations));
            $this->assertStringContainsString('laravel_process_heavy_ai_runtime', implode("\n", $violations));
            $this->assertStringContainsString('python_ai_data', implode("\n", $violations));
        } finally {
            @unlink($path);
        }
    }

    public function test_runtime_language_boundary_scan_flags_symfony_process_runtime_escapes(): void
    {
        $goPath = app_path('Http/Controllers/ForbiddenSymfonyProcessGoController.php');
        $swiftPath = app_path('Console/Commands/ForbiddenSymfonyProcessSwiftCommand.php');

        file_put_contents($goPath, <<<'PHP'
<?php

namespace App\Http\Controllers;

use Symfony\Component\Process\Process;

class ForbiddenSymfonyProcessGoController
{
    public function __invoke(): Process
    {
        return Process::fromShellCommandline('go run ./runtimes/go/postback-ingestor');
    }
}
PHP);

        file_put_contents($swiftPath, <<<'PHP'
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class ForbiddenSymfonyProcessSwiftCommand extends Command
{
    protected $signature = 'atlas:forbidden-symfony-swift';

    public function handle(): int
    {
        $process = new Process(['swift', 'run', 'ScreenCaptureKitProbe']);
        $process->run();

        return self::SUCCESS;
    }
}
PHP);

        try {
            $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
            $violations = implode("\n", data_get($report, 'ap201_runtime_language_boundary_contract.violations', []));

            $this->assertStringContainsString('ForbiddenSymfonyProcessGoController.php', $violations);
            $this->assertStringContainsString('laravel_process_go_edge_runtime', $violations);
            $this->assertStringContainsString('go_edge', $violations);
            $this->assertStringContainsString('ForbiddenSymfonyProcessSwiftCommand.php', $violations);
            $this->assertStringContainsString('symfony_process_array_swift_native_runtime', $violations);
            $this->assertStringContainsString('swift_native_mac', $violations);
        } finally {
            @unlink($goPath);
            @unlink($swiftPath);
        }
    }

    public function test_runtime_language_boundary_scan_preserves_embedding_service_as_real_provider_adapter(): void
    {
        $embeddingService = file_get_contents(app_path('Services/Semantic/EmbeddingService.php'));
        $this->assertIsString($embeddingService);
        $this->assertStringContainsString('embedWithSemanticRag', $embeddingService);
        $this->assertStringContainsString('embedWithOpenAi', $embeddingService);
        $this->assertStringContainsString('No real embedding provider available', $embeddingService);
        $this->assertStringNotContainsString('embedWithLocalHash', $embeddingService);

        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
        $violations = implode("\n", data_get($report, 'ap201_runtime_language_boundary_contract.violations', []));

        $this->assertStringNotContainsString('app/Services/Semantic/EmbeddingService.php', $violations);
    }

    public function test_runtime_language_boundary_scan_flags_heavy_ai_runtime_inside_telemetry(): void
    {
        // Telemetry was a scanner blind spot: hand-rolled KS / Mann-Kendall /
        // CUSUM / EWMA / Wilson math lived under Services/Ai/Telemetry undetected
        // until it was moved to runtimes/python/stats_engine behind
        // StatsEngineRuntimeClient. This fixture is the regression: a Telemetry
        // engine reaching for numpy-equivalent stats math directly, bypassing the
        // governed Python boundary. After widening the roots it MUST be flagged.
        $path = app_path('Services/Ai/Telemetry/Engine/Stats/ForbiddenNumpyTelemetryEngine.php');

        file_put_contents($path, <<<'PHP'
<?php

namespace App\Services\Ai\Telemetry\Engine\Stats;

class ForbiddenNumpyTelemetryEngine
{
    // Hand-rolled numpy-equivalent stats math inside a Telemetry engine instead
    // of the governed StatsEngineRuntimeClient boundary.
    public function hardRolledStdDev(): string|false|null
    {
        return shell_exec('python -c "import numpy"');
    }
}
PHP);

        try {
            $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
            $violations = data_get($report, 'ap201_runtime_language_boundary_contract.violations', []);

            $this->assertNotEmpty($violations);
            $this->assertStringContainsString('ForbiddenNumpyTelemetryEngine.php', implode("\n", $violations));
            $this->assertStringContainsString('app/Services/Ai/Telemetry/', implode("\n", $violations));
            $this->assertStringContainsString('python_ai_data', implode("\n", $violations));
        } finally {
            @unlink($path);
        }
    }

    public function test_runtime_language_boundary_scan_preserves_real_telemetry_stats_and_runtime_clients(): void
    {
        // Zero-false-positive proof for the widened root. The real Telemetry stats
        // wrappers delegate to numpy via StatsEngineRuntimeClient and merely
        // MENTION numpy in docblocks ("computed in numpy by ...") — they must not
        // be flagged. The sanctioned RuntimeBoundary clients spawn a generic
        // python entrypoint (no heavy-lib literal in the Process array) and must
        // also pass. No fixture is written here, so any Telemetry-scoped violation
        // would be a genuine false positive introduced by the widening.
        $report = app(KernelArchitectureStaticScanner::class)->complianceReport();
        $allViolations = data_get($report, 'ap201_runtime_language_boundary_contract.violations', []);
        $violations = implode("\n", $allViolations);

        $telemetryFalsePositives = array_values(array_filter(
            $allViolations,
            static fn (string $violation): bool => str_contains($violation, 'app/Services/Ai/Telemetry/'),
        ));
        $this->assertSame([], $telemetryFalsePositives, 'Widening to Telemetry must not flag the real numpy-delegating stats wrappers.');

        $this->assertStringNotContainsString('app/Services/Ai/Telemetry/Engine/Stats/KolmogorovSmirnovTest.php', $violations);
        $this->assertStringNotContainsString('app/Services/Ai/Telemetry/Engine/StatisticalAnalysisService.php', $violations);
        $this->assertStringNotContainsString('app/Services/Ai/RuntimeBoundary/StatsEngineRuntimeClient.php', $violations);
        $this->assertStringNotContainsString('app/Services/Ai/RuntimeBoundary/SemanticRagRuntimeClient.php', $violations);
    }
}
