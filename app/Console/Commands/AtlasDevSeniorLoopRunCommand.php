<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\SeniorLoop\SeniorEngineerLoopExecutor;
use Illuminate\Console\Command;

final class AtlasDevSeniorLoopRunCommand extends Command
{
    protected $signature = 'atlas:dev:senior-loop:run
        {--workspace= : Existing workspace to mutate; defaults to an isolated fixture workspace}
        {--intent= : Intent to run; defaults to a scoped repair task}
        {--keep-workspace : Keep the generated fixture workspace}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when the operational loop does not pass}';

    protected $description = 'Run the Atlas Dev Senior Engineer Loop operational path end-to-end.';

    public function handle(SeniorEngineerLoopExecutor $executor): int
    {
        [$workspace, $created] = $this->resolveWorkspace();
        $intent = (string) ($this->option('intent') ?: 'Fix the failing test in src/SmokeSubject.php: greeting returns helo atlas but tests expect hello atlas. Change only src/SmokeSubject.php and run composer test.');

        try {
            $execution = $executor->run(
                surfaceId: 'atlas_desktop_ai',
                workspace: $workspace,
                rawIntent: $intent,
                userConstraints: [
                    'allowed_files=src/SmokeSubject.php',
                    'validation_command=composer test',
                ],
                surfaceHints: [
                    'composer_mode' => 'programming',
                    'composer_task' => 'repair',
                    'thread_id' => 'senior-engineer-loop-run',
                ],
            );
            $payload = $execution->toCanonicalArray();
            $payload['persisted_ref'] = 'receipts/'.$execution->runId.'/senior_engineer_loop_execution.json';
        } finally {
            if ($created && ! (bool) $this->option('keep-workspace')) {
                $this->rmrf($workspace);
            }
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('Atlas Dev Senior Engineer Loop run', (string) ($payload['status'] ?? 'unknown'));
        }

        return (bool) $this->option('strict') && ($payload['status'] ?? null) !== 'passed'
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return array{0:string,1:bool}
     */
    private function resolveWorkspace(): array
    {
        $workspace = (string) ($this->option('workspace') ?: '');
        if ($workspace !== '') {
            return [realpath($workspace) ?: $workspace, false];
        }

        $workspace = sys_get_temp_dir().'/atlas-dev-senior-loop-run-'.bin2hex(random_bytes(4));
        mkdir($workspace.'/src', 0o755, true);
        mkdir($workspace.'/tests', 0o755, true);
        mkdir($workspace.'/.git/refs/heads', 0o755, true);
        file_put_contents($workspace.'/.git/HEAD', 'ref: refs/heads/main');
        file_put_contents($workspace.'/.git/refs/heads/main', '0123456789abcdef0123456789abcdef01234567');
        file_put_contents($workspace.'/composer.json', '{"scripts":{"test":"php tests/SmokeSubjectTest.php"}}'.PHP_EOL);
        file_put_contents($workspace.'/src/SmokeSubject.php', <<<'PHP'
<?php
namespace Smoke;
final class SmokeSubject
{
    public function greeting(): string
    {
        return 'helo atlas';
    }
}
PHP);
        file_put_contents($workspace.'/tests/SmokeSubjectTest.php', <<<'PHP'
<?php
require __DIR__.'/../src/SmokeSubject.php';
$subject = new \Smoke\SmokeSubject();
if ($subject->greeting() !== 'hello atlas') {
    fwrite(STDERR, 'Expected hello atlas, got '.$subject->greeting().PHP_EOL);
    exit(1);
}
echo "ok\n";
PHP);

        return [$workspace, true];
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->rmrf($path);
            } else {
                @chmod($path, 0o600);
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
