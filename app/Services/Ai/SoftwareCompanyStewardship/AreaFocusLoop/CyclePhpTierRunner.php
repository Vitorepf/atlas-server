<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * PHP-tier tool runner seam for the language-quality gate. The gate depends on
 * this interface; production gets {@see ShellCyclePhpTierRunner}, tests inject a
 * fake so unit tests NEVER touch a shell.
 *
 * @see CycleLanguageQualityGateService
 */
interface CyclePhpTierRunner
{
    /**
     * Run one static tool against the cycle's changed PHP files.
     *
     * @param  list<string>  $files  repo-relative changed PHP paths (in the worktree)
     * @return array{tool:string,command:string,status:string,exit_code:int,output:string,analyzed:int}
     *         status: passed | failed | timeout | crashed | nothing_to_analyze
     */
    public function run(string $tool, string $repoRoot, string $worktree, array $files): array;
}
