<?php

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionPromotionExecutorService;
use Illuminate\Console\Command;

/**
 * G4 — promove um scaffold STAGED de self-construction para um BRANCH novo do
 * repo (worktree isolado; nunca main, nunca a working tree). Requer flag
 * `atlas.ai.self_construction.promote_to_source_enabled` + aprovação explícita.
 * O merge branch→main continua sendo um ato humano de git/PR.
 */
class AtlasSelfConstructionPromoteCommand extends Command
{
    protected $signature = 'atlas:self-construction:promote
        {proposal : Proposal id (staged)}
        {hash : Proposal hash}
        {--operator= : Operator id approving the promotion}
        {--approve : Explicit approval (required; absence denies)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Govern-promote a staged self-construction scaffold to a new branch (flag + operator approval; never merges to main).';

    public function handle(AtlasSelfConstructionPromotionExecutorService $executor): int
    {
        $result = $executor->promote(
            (string) $this->argument('proposal'),
            (string) $this->argument('hash'),
            [
                'operator_id' => (string) $this->option('operator'),
                'approved' => (bool) $this->option('approve'),
            ],
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $result['promoted'] ? self::SUCCESS : self::FAILURE;
        }

        if ($result['promoted']) {
            $this->info('Promovido para branch (nunca main): '.(string) $result['branch']);
            $this->line('Arquivos: '.implode(', ', (array) $result['files']));
            $this->line('Revise + mescle o branch você mesmo — Atlas nunca escreveu main.');

            return self::SUCCESS;
        }

        $this->warn('Não promovido: '.(string) $result['reason']);

        return self::FAILURE;
    }
}
