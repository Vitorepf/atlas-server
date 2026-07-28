<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityGapTaskChainCompiler;
use App\Services\Ai\Signal\AtlasIdentifierLimitAudit;
use App\Services\Ai\Signal\AtlasSignalInvariantGapAdapter;
use App\Services\Ai\Signal\AtlasTableCensusService;
use App\Services\Ai\Signal\AtlasTableReferenceResolver;
use Illuminate\Console\Command;

/**
 * Série da tese "construído e não provado": quantas tabelas existem, quantas
 * nunca receberam uma linha, e quantas pararam de receber.
 *
 * Read-only.
 */
class AtlasSignalTableCensusCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:signal:table-census
        {--stale-days=14 : dias sem escrita para uma tabela contar como parada}
        {--json : saída canônica (pretty) para leitura}
        {--jsonl : UMA linha por execução, para append em série}
        {--as-gaps : compila as tabelas vazias em cadeia de task (caminho capability-gap)}
        {--limit=0 : com --as-gaps, quantas tabelas vazias compilar (0 = todas)}
        {--identifiers : audita o corte de 63 chars do Postgres (colisão e folga)}';

    protected $description = 'Censo de tabelas: total, vazias, não-vazias e sem escrita desde N dias (só mede).';

    public function handle(AtlasTableCensusService $census): int
    {
        if ((bool) $this->option('identifiers')) {
            return $this->emitIdentifierAudit();
        }

        $report = $census->census((int) $this->option('stale-days'));

        if ((bool) $this->option('as-gaps')) {
            return $this->emitGapChain($report);
        }

        // O `--json` do resto dos comandos atlas:* é pretty por contrato compartilhado
        // (EmitsCanonicalJson), e pretty não empilha: um append diário viraria um
        // arquivo que nenhum leitor de série consegue abrir. Daí o modo de uma linha.
        if ((bool) $this->option('jsonl')) {
            $this->line((string) json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ((bool) $this->option('json')) {
            $this->jsonLine($report);

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d tabelas · %d vazias (%.1f%%) · %d com linhas · %d sem escrita há %d+ dias · %d sem recência conhecida',
            $report['total_tables'],
            $report['empty_count'],
            $report['empty_ratio'] * 100,
            $report['non_empty_count'],
            $report['stale_count'],
            $report['stale_days'],
            $report['unknown_recency_count'],
        ));

        foreach (array_slice($report['stale_tables'], 0, 15) as $row) {
            $this->line(sprintf('  %-56s %7d linhas  última=%s', $row['table'], $row['rows'], $row['last_write']));
        }

        return self::SUCCESS;
    }

    /**
     * O corte de 63 caracteres do Postgres, medido: quantos nomes ele já cortou
     * e quanto falta para dois cortarem IGUAL — que é quando o CREATE falha.
     *
     * Só mede. Renomear 167 índices é obra, e a maioria é inofensiva.
     */
    private function emitIdentifierAudit(): int
    {
        $auditor = app(AtlasIdentifierLimitAudit::class);
        $source = $auditor->fromDatabase();
        $verdict = $auditor->audit($source['declared']);

        $payload = [
            'schema_version' => AtlasIdentifierLimitAudit::SCHEMA,
            'pg_limit' => AtlasIdentifierLimitAudit::PG_LIMIT,
            'available' => $source['available'],
            'truncated_on_disk' => $source['truncated_on_disk'],
            'declared_reconstructed' => $source['reconstructed'],
            'declared_explicit_name' => $source['explicit_name'],
            'over_limit_declared' => count($verdict['truncated']),
            'collision_count' => count($verdict['collisions']),
            'collisions' => $verdict['collisions'],
            'closest_pair' => $verdict['closest_pair'],
            'shared_prefix_len' => $verdict['shared_prefix_len'],
            'margin_chars' => $verdict['margin'],
            'over_limit_names' => $verdict['truncated'],
        ];

        if ((bool) $this->option('jsonl')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } elseif ((bool) $this->option('json')) {
            $this->jsonLine($payload);
        } else {
            $this->info(sprintf(
                '%d identificador(es) cortado(s) no disco · %d nome(s) declarado(s) acima de %d · %d colisão(ões)',
                $payload['truncated_on_disk'],
                $payload['over_limit_declared'],
                $payload['pg_limit'],
                $payload['collision_count'],
            ));
            $this->line(sprintf('  folga até a primeira colisão: %d chars (par mais próximo compartilha %d)',
                $payload['margin_chars'], $payload['shared_prefix_len']));
            foreach ($payload['closest_pair'] as $name) {
                $this->line('    '.$name);
            }
            foreach ($payload['collisions'] as $collision) {
                $this->warn('  COLISÃO em '.$collision['prefix']);
                foreach ($collision['names'] as $name) {
                    $this->line('    <- '.$name);
                }
            }
        }

        return $payload['collision_count'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * A fusão de patamar: medição vira backlog ordenado.
     *
     * Só COMPILA — não semeia, não enfileira, não dispara o cérebro. O que sai
     * daqui é a cadeia que o caminho `capability-gap` consome; a decisão de
     * originar continua fora deste comando.
     *
     * @param  array<string,mixed>  $report
     */
    private function emitGapChain(array $report): int
    {
        $empty = (array) $report['empty_tables'];
        $limit = max(0, (int) $this->option('limit'));
        if ($limit > 0) {
            $empty = array_slice($empty, 0, $limit);
        }

        // O endereço da lacuna. Sem ele todo nó saía not_muscle_ready: o
        // compilador nunca inventa alvo, e uma tabela vazia não diz sozinha quem
        // deveria enchê-la. Quem sabe é o código que já cita aquele nome.
        $refs = app(AtlasTableReferenceResolver::class)->resolve(array_values($empty));

        $findings = [];
        foreach (array_values($empty) as $table) {
            $hint = $refs['references'][$table] ?? [];
            $findings[] = array_filter([
                'class' => 'table_without_owner',
                'subject' => $table,
                'allowed_files_hint' => $hint !== [] ? $hint : null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        $adapted = app(AtlasSignalInvariantGapAdapter::class)->toGaps($findings);
        $compiled = app(AtlasExternalBrainCapabilityGapTaskChainCompiler::class)->compile($adapted);

        $payload = [
            'schema_version' => 'atlas.signal.table_census_gap_chain.v1',
            'brain_path_id' => 'capability-gap',
            'findings_in' => count($findings),
            // Uma tabela vazia não diz quem deveria enchê-la; sem alvo concreto o
            // compilador marca o nó not_muscle_ready, e esse é o número honesto a
            // olhar antes de achar que há backlog pronto para o músculo.
            'not_muscle_ready_nodes' => count(array_filter(
                $compiled['chain'],
                static fn (array $n): bool => (bool) data_get($n, 'muscle_ready_spec_contract.not_muscle_ready', false),
            )),
            'skipped' => $adapted['skipped'],
            'findings_with_hint' => count(array_filter($findings, static fn (array $f): bool => ($f['allowed_files_hint'] ?? []) !== [])),
            'reference_lookup_available' => $refs['available'],
            'reference_lookup_reason' => $refs['reason'],
            // Dois achados que NÃO são falha do censo: a tabela existe só na
            // migration que a criou, ou não é citada em lugar nenhum. Cada uma é
            // uma forma construída e nunca reivindicada.
            'tables_defined_but_never_cited' => count($refs['migration_only']),
            'tables_cited_by_nobody' => count($refs['unreferenced']),
            'chain_nodes' => count($compiled['chain']),
            'chain_value_score' => $compiled['chain_value_score'],
            'gap_chains' => $compiled['gap_chains'],
            'chain' => $compiled['chain'],
        ];

        if ((bool) $this->option('jsonl')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        if ((bool) $this->option('json')) {
            $this->jsonLine($payload);

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d achado(s) (%d com alvo) → %d nó(s) · chain_value_score=%s · %d sem alvo concreto',
            $payload['findings_in'],
            $payload['findings_with_hint'],
            $payload['chain_nodes'],
            $payload['chain_value_score'],
            $payload['not_muscle_ready_nodes'],
        ));
        $this->line(sprintf(
            '  %d tabela(s) só na migration que a criou · %d citada(s) por ninguém',
            $payload['tables_defined_but_never_cited'],
            $payload['tables_cited_by_nobody'],
        ));

        return self::SUCCESS;
    }
}
