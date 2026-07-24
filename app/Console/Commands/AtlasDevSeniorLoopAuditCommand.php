<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasDevSeniorLoopAuditCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:dev:senior-loop:audit
        {--workspace= : Existing workspace to audit; defaults to an isolated fixture workspace}
        {--intent= : Intent to audit; defaults to a scoped repair task}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when the senior loop is not fully proven}';

    protected $description = 'Audit the Atlas Dev Senior Engineer Loop capability projection.';

    public function handle(
        AtlasDevFastPathOrchestrator $orchestrator,
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

            $audit = $plan->seniorLoopAudit;
            if ($audit === null) {
                throw new \RuntimeException('Senior Engineer Loop audit was not produced by the plan orchestrator.');
            }

            $payload = $audit->toCanonicalArray();
            $payload['persisted_ref'] = $plan->persistedArtifactRefs()[ArtifactNames::SENIOR_ENGINEER_LOOP_AUDIT]
                ?? 'receipts/'.$plan->envelope->runId.'/'.ArtifactNames::SENIOR_ENGINEER_LOOP_AUDIT;
        } finally {
            if ($created) {
                File::deleteDirectory($workspace);
            }
        }

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);
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
        File::ensureDirectoryExists($workspace.'/src');
        File::ensureDirectoryExists($workspace.'/tests');
        File::ensureDirectoryExists($workspace.'/.git/refs/heads');
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

}
