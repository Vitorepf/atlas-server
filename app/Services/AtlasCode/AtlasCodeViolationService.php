<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use InvalidArgumentException;
use Symfony\Component\Process\Process;
use Throwable;
use App\Support\UtcIsoTimestamp;

/** C24 · five-rule scanner with a pure facts-in / violations-out core. */
final class AtlasCodeViolationService
{
    public const SCHEMA_VERSION = 'atlas.code.violations.v1';

    /**
     * Cada regra aponta para o documento que a justifica.
     *
     * O contrato C24 promete `rule_canon_ref` desde o começo, e a
     * implementação nunca o emitiu: a lei acusava sem citar a lei. Numa
     * ferramenta de governança isso é o pior tipo de silêncio — "está errado
     * porque sim". Com a citação, "por que existe essa regra?" tem resposta
     * conferível sem passar por modelo nenhum.
     *
     * Só entra doc que existe em `docs/engineering-knowledge-base/`; citação
     * para arquivo inexistente seria pior que a ausência.
     *
     * @var array<string,string>
     */
    private const CANON = [
        'main_only' => 'atlas-local-main-only-rule.md',
        'obra_return_deadline' => 'atlas-code-programming-obras-operating-system.md',
        'orphan_branch' => 'atlas-local-main-only-rule.md',
        'worktree_allowlist' => 'atlas-code-multi-project-workspace-os.md',
        'mirror_drift' => 'atlas-local-main-only-rule.md',
    ];

    /**
     * @param array<string,mixed> $facts
     * @return array{violations:array<int,array<string,mixed>>,plan:array<int,array<string,mixed>>}
     */
    public function scan(array $facts): array
    {
        $violations = [];
        $plan = [];
        $main = (string) ($facts['main_branch'] ?? 'main');
        $current = (string) ($facts['current_branch'] ?? '');

        if ($current !== '' && $current !== $main) {
            $this->add($violations, $plan, 'main_only', $current, $facts['current_since'] ?? null, 'high', [
                ['action' => 'cite_rule_to_agent', 'label' => 'Citar main_only ao agente'],
                ['action' => 'merge_ff', 'label' => 'Integrar fast-forward na main'],
            ]);
        }

        $allowedRoots = array_values(array_filter((array) ($facts['allowed_worktree_roots'] ?? []), 'is_string'));
        foreach ((array) ($facts['worktrees'] ?? []) as $worktree) {
            if (! is_array($worktree)) {
                continue;
            }
            $path = trim((string) ($worktree['path'] ?? ''));
            if ($path === '' || $this->isAllowedPath($path, $allowedRoots)) {
                continue;
            }
            $this->add($violations, $plan, 'worktree_allowlist', $path, null, 'medium', [
                ['action' => 'stash_quarantine', 'label' => 'Quarentenar alterações do worktree'],
                ['action' => 'cite_rule_to_agent', 'label' => 'Citar worktree_allowlist ao agente'],
            ]);
        }

        $deadlineDays = (int) ($facts['obra_return_deadline_days'] ?? 0);
        $now = strtotime((string) ($facts['now'] ?? ''));
        if ($deadlineDays > 0 && $now !== false) {
            foreach ((array) ($facts['branches'] ?? []) as $branch) {
                if (! is_array($branch) || (string) ($branch['name'] ?? '') === $main) {
                    continue;
                }
                $committedAt = strtotime((string) ($branch['committed_at'] ?? ''));
                if ($committedAt === false || ($now - $committedAt) <= $deadlineDays * 86400) {
                    continue;
                }
                $name = (string) ($branch['name'] ?? '');
                if ($name === '') {
                    continue;
                }
                $this->add($violations, $plan, 'obra_return_deadline', $name, $branch['committed_at'] ?? null, 'medium', [
                    ['action' => 'cherry_pick_to_main', 'label' => 'Levar o commit para main'],
                    ['action' => 'delete_branch', 'label' => 'Remover branch após retorno'],
                ]);
            }
        }

        foreach ((array) ($facts['branches'] ?? []) as $branch) {
            if (! is_array($branch)) {
                continue;
            }
            $name = (string) ($branch['name'] ?? '');
            if ($name === '' || $name === $main || (bool) ($branch['reachable_from_main'] ?? true)) {
                continue;
            }
            $this->add($violations, $plan, 'orphan_branch', $name, $branch['committed_at'] ?? null, 'high', [
                ['action' => 'cite_rule_to_agent', 'label' => 'Citar orphan_branch ao agente'],
                ['action' => 'delete_branch', 'label' => 'Remover branch órfã'],
            ]);
        }

        $mirrorHead = $this->nullableString($facts['mirror_head'] ?? null);
        $mainHead = $this->nullableString($facts['main_head'] ?? null);
        if ($mirrorHead !== null && $mainHead !== null && $mirrorHead !== $mainHead) {
            $this->add($violations, $plan, 'mirror_drift', 'mirror', null, 'medium', [
                ['action' => 'merge_ff', 'label' => 'Sincronizar espelho com main'],
                ['action' => 'cite_rule_to_agent', 'label' => 'Citar mirror_drift ao agente'],
            ]);
        }

        return ['violations' => $violations, 'plan' => $plan];
    }

    /** @return array<string,mixed> */
    public function capture(string $repo): array
    {
        // Mesma frota do radar, do grafo e da folha: a lei vale para todos os
        // repositórios que o app mostra, não só para os registrados.
        $located = (new AtlasCodeRepoLocator())->locate($repo);
        $path = $located['path'];

        $currentBranch = trim($this->run($path, ['git', 'branch', '--show-current'])) ?: 'HEAD';
        // A trunk é fato do repositório, não opinião nossa: fora do Atlas ela
        // se chama production/develop, e comparar tudo contra 'main' acusava
        // trabalho correto ou matava a varredura inteira.
        $mainBranch = (new AtlasCodeTrunkResolver())->resolve($path);
        $mainHead = $mainBranch !== '' ? trim($this->run($path, ['git', 'rev-parse', $mainBranch])) : '';
        $branches = [];
        foreach (preg_split('/\r?\n/', trim($this->run($path, [
            'git', 'for-each-ref', '--format=%(refname:short)|%(objectname)|%(committerdate:iso-strict)|%(upstream:short)', 'refs/heads',
        ]))) ?: [] as $line) {
            $parts = explode('|', trim($line), 4);
            if (count($parts) < 3 || trim($parts[0]) === '') {
                continue;
            }
            $branchHead = trim($parts[1]);
            $branches[] = [
                'name' => trim($parts[0]),
                'head' => $branchHead,
                'committed_at' => trim($parts[2]) ?: null,
                'upstream' => trim($parts[3] ?? '') ?: null,
                'reachable_from_main' => $this->isAncestor($path, $branchHead, $mainHead),
            ];
        }

        $worktrees = (new AtlasCodeGraphService())->parseWorktrees($this->run($path, ['git', 'worktree', 'list', '--porcelain']));
        $facts = [
            'main_branch' => $mainBranch,
            'current_branch' => $currentBranch,
            'main_head' => $mainHead,
            'branches' => $branches,
            'worktrees' => $worktrees,
            'allowed_worktree_roots' => [$path],
            'obra_return_deadline_days' => 3,
            'now' => UtcIsoTimestamp::now(),
        ];
        $result = $this->scan($facts);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'repo' => $located['slug'],
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            // Qual trunk a lei usou para julgar. Sem isto, quem fala com o
            // operador escreve "fora da main" num repositório cuja trunk é
            // production — e a frase vira mentira.
            'trunk' => $mainBranch !== '' ? $mainBranch : null,
            'violations' => $result['violations'],
            'plan' => $result['plan'],
        ];
    }

    /** @param array<int,array<string,mixed>> $violations @param array<int,array<string,mixed>> $plan @param array<int,array<string,mixed>> $steps */
    private function add(array &$violations, array &$plan, string $ruleId, string $target, mixed $since, string $severity, array $steps): void
    {
        $violation = array_filter([
            'rule_id' => $ruleId,
            'rule_canon_ref' => self::CANON[$ruleId] ?? null,
            'target' => $target,
            'since' => is_string($since) && trim($since) !== '' ? $since : null,
            'severity' => $severity,
            'plan' => $steps,
        ], static fn (mixed $value): bool => $value !== null);
        $violations[] = $violation;
        $plan[] = ['rule_id' => $ruleId, 'target' => $target, 'steps' => $steps];
    }

    /** @param array<int,string> $roots */
    private function isAllowedPath(string $path, array $roots): bool
    {
        $path = rtrim($path, DIRECTORY_SEPARATOR);
        foreach ($roots as $root) {
            $root = rtrim(trim($root), DIRECTORY_SEPARATOR);
            if ($root !== '' && ($path === $root || str_starts_with($path, $root.DIRECTORY_SEPARATOR))) {
                return true;
            }
        }

        return false;
    }

    private function isAncestor(string $cwd, string $candidate, string $main): bool
    {
        if ($candidate === $main) {
            return true;
        }
        try {
            $process = new Process(['git', 'merge-base', '--is-ancestor', $candidate, $main], $cwd, null, null, 10);
            $process->run();

            return $process->getExitCode() === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<int,string> $command */
    private function run(string $cwd, array $command): string
    {
        $process = new Process($command, $cwd, null, null, 20);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new InvalidArgumentException('git_command_failed');
        }

        return $process->getOutput();
    }

    private function nullableString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
