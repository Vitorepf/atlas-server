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
}
