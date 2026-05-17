<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\Programming\AtlasDev\SeniorLoop\SeniorEngineerLoopAuditor;
use Illuminate\Console\Command;

final class AtlasDevSeniorLoopAuditCommand extends Command
{
    protected $signature = 'atlas:dev:senior-loop:audit
        {--workspace= : Existing workspace to audit; defaults to an isolated fixture workspace}
        {--intent= : Intent to audit; defaults to a scoped repair task}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when the senior loop is not fully proven}';

    protected $description = 'Audit the Atlas Dev Senior Engineer Loop capability projection.';

    public function handle(
        AtlasDevFastPathOrchestrator $orchestrator,
        SeniorEngineerLoopAuditor $auditor,
        ReceiptStorage $storage,
    ): int {
        [$workspace, $created] = $this->resolveWorkspace();
        $intent = (string) ($this->option('intent') ?: 'Fix the failing test in src/SmokeSubject.php: greeting returns helo atlas but tests expect hello atlas. Change only src/SmokeSubject.php and run composer test.');

        try {
            $plan = $orchestrator->planOnly(
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
                    'thread_id' => 'senior-engineer-loop-audit',
                ],
            );

            $desktopGoalAudit = $this->readDesktopGoalAudit();
            $audit = $auditor->audit($plan, $desktopGoalAudit);
            $ref = $storage->writeAtomic(
                $plan->envelope->runId,
                ArtifactNames::SENIOR_ENGINEER_LOOP_AUDIT,
                $audit->toCanonicalArray(),
            );

            $payload = $audit->toCanonicalArray();
            $payload['persisted_ref'] = 'receipts/'.$plan->envelope->runId.'/'.ArtifactNames::SENIOR_ENGINEER_LOOP_AUDIT;
            $payload['persisted_path_hash'] = hash('sha256', $ref);
        } finally {
            if ($created) {
                $this->rmrf($workspace);
            }
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        } else {
            $this->components->twoColumnDetail('Atlas Dev Senior Engineer Loop', (string) $payload['status']);
            foreach ((array) $payload['capabilities'] as $capability => $passed) {
                $this->components->twoColumnDetail((string) $capability, $passed ? 'passed' : 'blocked');
            }
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

        $workspace = sys_get_temp_dir().'/atlas-dev-senior-loop-'.bin2hex(random_bytes(4));
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

    /**
     * @return array<string,mixed>|null
     */
    private function readDesktopGoalAudit(): ?array
    {
        $path = rtrim((string) config('atlas_dev.receipts_path', storage_path('atlas-dev/receipts')), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'desktop_efficiency'.DIRECTORY_SEPARATOR.'latest.json';

        if (! is_file($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return null;
        }

        return ['status' => ($decoded['status'] ?? null) === 'passed' ? 'passed' : 'blocked'];
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
