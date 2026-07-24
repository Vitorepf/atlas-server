<?php

namespace App\Console\Commands;

use App\Services\Ai\Skills\SkillBundleStore;
use App\Services\Ai\Skills\SkillDiscoveryService;
use App\Services\Ai\Skills\SkillManifest;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasCliSkillsCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:cli:skills
        {action=list : list, show, validate, doctor or trust}
        {name? : Skill name for show or validate}
        {--workspace= : Workspace path. Defaults to current directory}
        {--trust-workspace : Trust local .atlas/skills and .agents/skills for this workspace}
        {--json : Print machine-readable JSON}';

    protected $description = 'List, inspect and validate Atlas agentskills bundles.';

    public function handle(SkillDiscoveryService $discovery, SkillBundleStore $bundles): int
    {
        $workspace = $this->workspace();
        $action = Str::of((string) $this->argument('action'))->lower()->trim()->value();

        if ((bool) $this->option('trust-workspace') || $action === 'trust') {
            $discovery->trustWorkspace($workspace);
            if ($action === 'trust') {
                return $this->printPayload([
                    'ok' => true,
                    'workspace' => $workspace,
                    'trusted' => true,
                    'trusted_projects_file' => $this->trustedProjectsPath(),
                ]);
            }
        }

        $bundles->clear();
        $bundles->registerAll($discovery->discoverAll($workspace));

        return match ($action) {
            'list' => $this->list($bundles, $discovery, $workspace),
            'show' => $this->show($bundles),
            'validate' => $this->validate($bundles, $discovery, $workspace),
            'doctor' => $this->doctor($discovery, $workspace),
            default => $this->invalidAction($action),
        };
    }

    private function list(SkillBundleStore $bundles, SkillDiscoveryService $discovery, string $workspace): int
    {
        $payload = [
            'ok' => true,
            'workspace' => $workspace,
            'trusted_workspace' => $discovery->isWorkspaceTrusted($workspace),
            'skills' => $bundles->catalog(),
            'diagnostics' => $discovery->diagnostics(),
        ];

        if ((bool) $this->option('json')) {
            return $this->printPayload($payload);
        }

        $this->line('Atlas Skills');
        $this->line('workspace: '.$workspace);
        $this->line('local skills: '.($discovery->workspaceHasLocalSkills($workspace) ? ($discovery->isWorkspaceTrusted($workspace) ? 'trusted' : 'not trusted') : 'none'));
        $this->table(
            ['name', 'tier', 'trust', 'compatibility', 'warnings'],
            $bundles->all()->map(fn (SkillManifest $skill): array => [
                $skill->name,
                $skill->sourceTier,
                $skill->trustLevel(),
                $skill->compatibility ?: '-',
                implode(', ', $skill->warnings) ?: '-',
            ])->all(),
        );

        return self::SUCCESS;
    }

    private function show(SkillBundleStore $bundles): int
    {
        $skill = $this->requestedSkill($bundles);
        if (! $skill) {
            return self::FAILURE;
        }

        $payload = [
            'ok' => true,
            'skill' => $skill->catalogEntry() + [
                'sha256' => $skill->contentHash,
                'metadata' => $skill->metadata,
                'allowed_tools' => $skill->allowedTools,
                'resources' => $skill->resourceFiles(),
                'body' => $skill->body,
            ],
        ];

        if ((bool) $this->option('json')) {
            return $this->printPayload($payload);
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>'.$skill->name.'</>', $skill->sourceTier);
        $this->line('description: '.$skill->description);
        $this->line('path: '.$skill->path);
        $this->line('sha256: '.$skill->contentHash);
        if ($skill->resourceFiles() !== []) {
            $this->line('resources:');
            foreach ($skill->resourceFiles() as $resource) {
                $this->line('  - '.$resource);
            }
        }
        $this->newLine();
        $this->line($skill->body);

        return self::SUCCESS;
    }

    private function validate(SkillBundleStore $bundles, SkillDiscoveryService $discovery, string $workspace): int
    {
        $name = $this->argument('name');
        $skills = is_string($name) && trim($name) !== ''
            ? collect([$this->requestedSkill($bundles)])->filter()->values()
            : $bundles->all();

        if ($skills->isEmpty() && is_string($name) && trim($name) !== '') {
            return self::FAILURE;
        }

        $health = $discovery->health($workspace);
        $payload = [
            'ok' => $health['status'] !== 'failed',
            'workspace' => $workspace,
            'health' => $health,
            'skills' => $skills->map(fn (SkillManifest $skill): array => [
                'name' => $skill->name,
                'path' => $skill->path,
                'source_tier' => $skill->sourceTier,
                'trust_level' => $skill->trustLevel(),
                'sha256' => $skill->contentHash,
                'warnings' => $skill->warnings,
                'security_issues' => $skill->securityIssues,
                'resources' => $skill->resourceFiles(),
            ])->values()->all(),
        ];

        if ((bool) $this->option('json')) {
            return $this->printPayload($payload, $payload['ok'] ? self::SUCCESS : self::FAILURE);
        }

        $this->line('Skill validation: '.$health['status']);
        $this->table(
            ['name', 'tier', 'warnings', 'resources'],
            $skills->map(fn (SkillManifest $skill): array => [
                $skill->name,
                $skill->sourceTier,
                implode(', ', $skill->warnings) ?: '-',
                (string) count($skill->resourceFiles()),
            ])->all(),
        );

        foreach ($health['errors'] as $error) {
            $this->error((string) ($error['message'] ?? json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }

        return $health['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function doctor(SkillDiscoveryService $discovery, string $workspace): int
    {
        $health = $discovery->health($workspace);
        $payload = [
            'ok' => $health['status'] !== 'failed',
            'workspace' => $workspace,
            'trusted_workspace' => $discovery->isWorkspaceTrusted($workspace),
            'workspace_has_local_skills' => $discovery->workspaceHasLocalSkills($workspace),
            'health' => $health,
        ];

        if ((bool) $this->option('json')) {
            return $this->printPayload($payload, $payload['ok'] ? self::SUCCESS : self::FAILURE);
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Skills Doctor</>', $health['status']);
        $this->components->twoColumnDetail('Workspace', $workspace);
        $this->components->twoColumnDetail('Skills loaded', (string) $health['count']);
        $this->components->twoColumnDetail('Local skills', $payload['workspace_has_local_skills'] ? ($payload['trusted_workspace'] ? 'trusted' : 'not trusted') : 'none');

        foreach ($health['warnings'] as $warning) {
            $this->warn((string) ($warning['warning'] ?? $warning['message'] ?? json_encode($warning, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }

        foreach ($health['errors'] as $error) {
            $this->error((string) ($error['message'] ?? json_encode($error, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)));
        }

        return $health['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Acao invalida: {$action}. Use list, show, validate, doctor ou trust.");

        return self::FAILURE;
    }

    private function requestedSkill(SkillBundleStore $bundles): ?SkillManifest
    {
        $name = $this->argument('name');
        if (! is_string($name) || trim($name) === '') {
            $this->error('Informe o nome da skill.');

            return null;
        }

        $skill = $bundles->find($name);
        if (! $skill) {
            $this->error("Skill nao encontrada: {$name}");

            return null;
        }

        return $skill;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function printPayload(array $payload, int $status = self::SUCCESS): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } elseif (($payload['trusted'] ?? false) === true) {
            $this->line('Workspace confiavel para skills locais: '.$payload['workspace']);
        }

        return $status;
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: config('atlas.ai.workdir'));
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    private function trustedProjectsPath(): string
    {
        $home = rtrim((string) ($_SERVER['HOME'] ?? getenv('HOME') ?: dirname(base_path())), DIRECTORY_SEPARATOR);

        return $home.'/.atlas/trusted-projects.json';
    }
}

