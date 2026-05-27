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
        {--allowed-file=* : Workspace-relative file the loop may change}
        {--validation-command=* : Verification command to run inside the workspace}
        {--surface-id=atlas_cli_dev : Atlas Dev surface id for routing}
        {--provider-choice= : Provider choice hint for planning/audit}
        {--composer-model= : Composer/model hint for planning/audit}
        {--create-fixture-workspace : Create the standard senior-loop fixture at --workspace when it does not exist}
        {--keep-workspace : Keep the generated fixture workspace}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when the operational loop does not pass}';

    protected $description = 'Run the Atlas Dev Senior Engineer Loop operational path end-to-end.';

    public function handle(SeniorEngineerLoopExecutor $executor): int
    {
        [$workspace, $created] = $this->resolveWorkspace();
        $intent = (string) ($this->option('intent') ?: 'Fix the failing test in src/SmokeSubject.php: greeting returns helo atlas but tests expect hello atlas. Change only src/SmokeSubject.php and run composer test.');
        $allowedFiles = $this->stringListOption('allowed-file');
        $validationCommands = $this->stringListOption('validation-command');
        $constraints = $this->userConstraints($allowedFiles, $validationCommands);
        $surfaceHints = [
            'composer_mode' => 'programming',
            'composer_task' => 'repair',
            'thread_id' => 'senior-engineer-loop-run',
        ];
        $providerChoice = trim((string) ($this->option('provider-choice') ?: ''));
        if ($providerChoice !== '') {
            $surfaceHints['provider_choice'] = $providerChoice;
        }
        $composerModel = trim((string) ($this->option('composer-model') ?: ''));
        if ($composerModel !== '') {
            $surfaceHints['composer_model'] = $composerModel;
        }

        try {
            $execution = $executor->run(
                surfaceId: trim((string) ($this->option('surface-id') ?: 'atlas_cli_dev')) ?: 'atlas_cli_dev',
                workspace: $workspace,
                rawIntent: $intent,
                userConstraints: $constraints,
                surfaceHints: $surfaceHints,
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
     * @return list<string>
     */
    private function stringListOption(string $name): array
    {
        $values = $this->option($name);
        if (! is_array($values)) {
            $values = $values === null ? [] : [$values];
        }

        return array_values(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            $values,
        ), static fn (string $value): bool => $value !== ''));
    }

    /**
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $validationCommands
     * @return list<string>
     */
    private function userConstraints(array $allowedFiles, array $validationCommands): array
    {
        if ($allowedFiles === []) {
            $allowedFiles = ['src/SmokeSubject.php'];
        }
        if ($validationCommands === []) {
            $validationCommands = ['composer test'];
        }

        $constraints = ['allowed_files='.implode(',', $allowedFiles)];
        foreach ($validationCommands as $command) {
            $constraints[] = 'validation_command='.$command;
        }

        return $constraints;
    }

    /**
     * @return array{0:string,1:bool}
     */
    private function resolveWorkspace(): array
    {
        $workspace = (string) ($this->option('workspace') ?: '');
        if ($workspace !== '') {
            if (! is_dir($workspace) && (bool) $this->option('create-fixture-workspace')) {
                $this->createFixtureWorkspace($workspace);
            }

            return [realpath($workspace) ?: $workspace, false];
        }

        $workspace = sys_get_temp_dir().'/atlas-dev-senior-loop-run-'.bin2hex(random_bytes(4));
        $this->createFixtureWorkspace($workspace);

        return [$workspace, true];
    }

    private function createFixtureWorkspace(string $workspace): void
    {
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
