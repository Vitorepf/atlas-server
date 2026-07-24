<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Brain\AtlasEvolutionDiary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * DIARIO-2 (Carta Regra 3) — `atlas:evolucao`, the operator's window into the
 * autonomy. The operator does not approve beforehand; they navigate the Diary
 * here and revert the rare evolution they dislike.
 *
 *   atlas:evolucao hoje                         — today's evolutions by tipo
 *   atlas:evolucao listar --tipo=merge --desde=2026-07-01
 *   atlas:evolucao ver <id>                     — one evolution's detail
 *   atlas:evolucao reverter <id> [--apply]      — undo it (git revert OR
 *                                                 replay-sem-a-entrada da memória)
 *
 * reverter defaults to printing the reversal PLAN; --apply executes it.
 */
class AtlasEvolucaoCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:evolucao
        {acao=hoje : hoje|listar|ver|reverter}
        {id? : evolution id (ver/reverter)}
        {--tipo= : filter by tipo (listar)}
        {--desde= : filter from date YYYY-MM-DD (listar)}
        {--apply : actually execute the reversal (reverter)}
        {--diary= : diary path override (defaults to config)}
        {--json : machine-readable output}';

    protected $description = 'Navigate and revert the Evolution Diary (Carta Regra 3).';

    public function handle(): int
    {
        $diary = new AtlasEvolutionDiary($this->option('diary') ?: null);

        return match ((string) $this->argument('acao')) {
            'hoje' => $this->hoje($diary),
            'listar' => $this->listar($diary),
            'ver' => $this->ver($diary),
            'reverter' => $this->reverter($diary),
            default => $this->bail('Ação desconhecida. Use: hoje | listar | ver | reverter'),
        };
    }

    private function hoje(AtlasEvolutionDiary $diary): int
    {
        $grouped = $diary->today();

        if ($this->option('json')) {
            $this->line($this->encode($grouped));

            return self::SUCCESS;
        }

        if ($grouped === []) {
            $this->info('Nenhuma evolução hoje.');

            return self::SUCCESS;
        }

        foreach ($grouped as $tipo => $entries) {
            $this->line("<comment>{$tipo}</comment> (".count($entries).')');
            foreach ($entries as $entry) {
                $this->line(sprintf('  %s  %s', $entry['id'] ?? '?', $entry['o_que'] ?? ''));
            }
        }

        return self::SUCCESS;
    }

    private function listar(AtlasEvolutionDiary $diary): int
    {
        $entries = $diary->filter(
            $this->option('tipo') ?: null,
            $this->option('desde') ?: null,
        );

        if ($this->option('json')) {
            $this->line($this->encode($entries));

            return self::SUCCESS;
        }

        if ($entries === []) {
            $this->info('Nenhuma evolução no filtro.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'data', 'tipo', 'o_que'],
            array_map(static fn (array $e): array => [
                $e['id'] ?? '', substr((string) ($e['data'] ?? ''), 0, 19), $e['tipo'] ?? '', Str::limit((string) ($e['o_que'] ?? ''), 60),
            ], $entries),
        );

        return self::SUCCESS;
    }

    private function ver(AtlasEvolutionDiary $diary): int
    {
        $entry = $this->requireEntry($diary);
        if ($entry === null) {
            return self::FAILURE;
        }

        if ($this->option('json')) {
            $this->line($this->encode($entry));

            return self::SUCCESS;
        }

        foreach (['id', 'data', 'tipo', 'o_que', 'por_que', 'evidencia', 'id_reversao'] as $field) {
            $this->line(sprintf('  %-12s %s', $field, var_export($entry[$field] ?? null, true)));
        }

        return self::SUCCESS;
    }

    private function reverter(AtlasEvolutionDiary $diary): int
    {
        $entry = $this->requireEntry($diary);
        if ($entry === null) {
            return self::FAILURE;
        }

        $rev = trim((string) ($entry['id_reversao'] ?? ''));
        if ($rev === '') {
            return $this->bail("Evolução {$entry['id']} não tem id_reversao — nada a reverter.");
        }

        $apply = (bool) $this->option('apply');

        if (str_starts_with($rev, 'memory:')) {
            $seq = (int) substr($rev, strlen('memory:'));
            $plan = "atlas:brain:replay --exclude-seq={$seq}";
            if (! $apply) {
                return $this->plan($plan, 'replay-sem-a-entrada da memória');
            }
            $this->info("Revertendo memória via replay (exclui seq {$seq})…");
            Artisan::call('atlas:brain:replay', ['--exclude-seq' => [$seq]], $this->output);

            return self::SUCCESS;
        }

        // Otherwise: a git commit sha → git revert.
        $plan = "git revert --no-edit {$rev}";
        if (! $apply) {
            return $this->plan($plan, 'git revert do commit');
        }
        $this->info("Revertendo commit {$rev}…");
        $result = Process::run($plan);
        $this->line($result->output().$result->errorOutput());
        if (! $result->successful()) {
            return $this->bail('git revert falhou. Resolva ou rode: git revert --abort');
        }

        return self::SUCCESS;
    }

    private function plan(string $command, string $tipo): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['plan' => $command, 'reversal_type' => $tipo, 'apply' => false], JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->line("<info>Plano de reversão</info> ({$tipo}):");
        $this->line("  {$command}");
        $this->line('Rode de novo com --apply para executar.');

        return self::SUCCESS;
    }

    private function requireEntry(AtlasEvolutionDiary $diary): ?array
    {
        $id = (string) ($this->argument('id') ?? '');
        if ($id === '') {
            $this->error('Informe o id da evolução.');

            return null;
        }
        $entry = $diary->find($id);
        if ($entry === null) {
            $this->error("Evolução '{$id}' não encontrada.");

            return null;
        }

        return $entry;
    }

    private function bail(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
