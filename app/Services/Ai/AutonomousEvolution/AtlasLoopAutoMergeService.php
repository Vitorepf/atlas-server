<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopProposal;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * A travessia merge-livre v2 (decisão do operador, 11-12/06): propostas CERTIFICADAS do
 * Loop (frozen judge + certificação adversarial universal) são MERGEADAS EM MAIN de
 * verdade — o merge não é o evento de risco; o evento de risco é o veredito, e o
 * veredito já aconteceu duas vezes (certificação no grind + re-prova aqui).
 *
 * Pipeline por proposta (fail-closed por proposta; nunca derruba o drain):
 *   1. RE-PROVA — o gate re-roda o frozen judge contra o contrato persistido em
 *      workspace isolado ({@see AtlasLoopProposalPromotionGate::reprove}). Sem re-prova
 *      verde, não merge (skip com razão; fica para retry/fix-forward).
 *   2. APPLY REAL — `git apply --check` e depois `git apply` no repo real. Conflito =
 *      skip (a árvore mudou desde a certificação; o Loop re-descobre o alvo).
 *   3. SANIDADE PRÉ-COMMIT — `php -l` em cada arquivo .php tocado (sintaxe quebrada
 *      nunca entra; tudo o mais é território fix-forward, não bloqueio).
 *   4. COMMIT em main (branch atual) com receipt no Evidence Ledger.
 *   5. CANÁRIO pós-merge (best-effort): roda o teste-irmão por convenção quando existe;
 *      falha NÃO reverte (fix-forward-first) — registra no receipt para a fila.
 *
 * Gated por `atlas.ai.loop.auto_merge_to_main` (default OFF; o operador ligou em 12/06).
 * A marcação merged_to_main=true só é possível dentro do escopo governado do model
 * ({@see AtlasLoopProposal::$governedMergeInProgress}) — nenhum outro caminho marca.
 */
final class AtlasLoopAutoMergeService
{
    public const SCHEMA_VERSION = 'atlas.ai.loop_auto_merge.v1';

    public function __construct(
        private readonly AtlasLoopProposalPromotionGate $gate,
        private readonly AtlasLoopProposalMaterializer $materializer,
        private readonly \App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore $store,
    ) {}

    /**
     * Drena propostas certificadas ainda não-mergeadas para MAIN.
     *
     * @return array<string,mixed>
     */
    public function drain(string $repoRoot, int $limit = 10): array
    {
        if (! (bool) config('atlas.ai.loop.auto_merge_to_main', false)) {
            return $this->summary('disabled', [], 'auto_merge_to_main desligado (decisão do operador necessária)');
        }
        $repoRoot = rtrim($repoRoot, '/');
        if (! is_dir($repoRoot.'/.git')) {
            return $this->summary('blocked', [], 'repo_root_not_a_git_tree');
        }

        // L2-6: guard de saldo líquido — merges continuam livres enquanto a direção
        // líquida MEDIDA for positiva; afogamento em quebra aperta o dial sozinho.
        $net = app(AtlasLoopNetDirectionGuard::class)->verdict();
        if ((bool) ($net['throttled'] ?? false)) {
            return $this->summary('throttled', [], 'saldo líquido negativo medido: '.(string) ($net['reason'] ?? ''));
        }

        $proposals = AtlasLoopProposal::query()
            ->where('merged_to_main', false)
            ->whereNull('reviewed_at')
            ->orderBy('created_at')
            ->limit(max(1, min(50, $limit)))
            ->get();

        $results = [];
        foreach ($proposals as $proposal) {
            $results[] = $this->mergeOne($proposal, $repoRoot);
        }

        $merged = count(array_filter($results, static fn (array $r): bool => $r['merged']));

        return $this->summary($merged > 0 ? 'merged' : 'no_merges', $results, null, $merged);
    }

    /**
     * @return array<string,mixed>
     */
    private function mergeOne(AtlasLoopProposal $proposal, string $repoRoot): array
    {
        $base = [
            'proposal_id' => (string) $proposal->getKey(),
            'target_path' => (string) $proposal->target_path,
            'merged' => false,
            'reason' => null,
            'commit' => null,
            'canary' => null,
        ];

        try {
            // 0. Guardrail do meta-loop NO CONSUMO (defense-in-depth do L3-12): o guard da
            // descoberta pode ser contornado por propostas já certificadas (achado 12/06:
            // o soak certificou edição no PRÓPRIO painel-juiz). Alvo de segurança JAMAIS
            // auto-mergeia — parqueia para revisão do operador (reviewed_at), nunca silencioso.
            if (app(AtlasLoopHarnessGuard::class)->isForbiddenSelfTarget((string) $proposal->target_path)) {
                $proposal->forceFill(['reviewed_at' => now()])->save();

                return array_merge($base, ['reason' => 'forbidden_self_target (parked_for_operator_review)']);
            }

            // 1. Re-prova real contra o contrato congelado persistido.
            $re = $this->gate->reprove($proposal, $repoRoot);
            if ($re['ok'] !== true) {
                $reason = 'reprove:'.(string) $re['reason'];
                // Aposentadoria honesta: uma proposta que NUNCA vai convergir deve sair da
                // fila (reviewed_at) para as propostas FRESCAS do soak não ficarem bloqueadas
                // atrás dela, e para o drain parar de re-clonar o repo nela a cada passe.
                // Dois casos estruturais: (a) diff não aplica mais (árvore moveu desde a
                // certificação); (b) sem contrato de acceptance persistido (legada pré-O-3,
                // impossível re-provar). merged_to_main permanece false; o Loop redescobre o
                // alvo se ainda valer.
                if (str_contains($reason, 'git_apply_failed') || str_contains($reason, 'no_acceptance_contract')) {
                    $proposal->forceFill(['reviewed_at' => now()])->save();
                    $reason .= str_contains($reason, 'no_acceptance_contract') ? ' (retired_contractless)' : ' (retired_stale)';
                }

                return array_merge($base, ['reason' => $reason]);
            }

            // 2. Apply no repo REAL. O diff_text vem com paths workspace-relativos
            // (`src/<basename>`); normaliza para o target_path antes do apply (L3-1) —
            // mesma reescrita que o materializer usa na re-prova, para o apply real casar.
            $patch = $repoRoot.'/.atlas-automerge-'.substr((string) $proposal->proposal_hash, 0, 12).'.patch';
            $normalized = $this->materializer->rewriteDiffToTarget((string) $proposal->diff_text, (string) $proposal->target_path);
            file_put_contents($patch, $normalized);
            try {
                if (! $this->git($repoRoot, ['apply', '--check', '--whitespace=nowarn', basename($patch)])) {
                    return array_merge($base, ['reason' => 'apply_conflict_tree_moved']);
                }
                if (! $this->git($repoRoot, ['apply', '--whitespace=nowarn', basename($patch)])) {
                    return array_merge($base, ['reason' => 'apply_failed']);
                }
            } finally {
                @unlink($patch);
            }

            // 3. Sanidade: sintaxe quebrada nunca entra (o resto é fix-forward).
            $changed = $this->changedPhpFiles($repoRoot);
            foreach ($changed as $file) {
                if (! $this->phpLintOk($repoRoot.'/'.$file)) {
                    $this->git($repoRoot, ['checkout', '--', $file]); // desfaz só o apply inválido

                    return array_merge($base, ['reason' => 'php_lint_failed:'.$file]);
                }
            }

            // 4. Snapshot pré-merge (L2-5): âncora git endereçável do estado ANTES do
            // merge — fix-forward sempre barato (restaurar = checkout da tag). O commit
            // do merge referencia a âncora no receipt.
            $snapTag = 'atlas-snap-'.substr((string) $proposal->proposal_hash, 0, 12);
            $this->git($repoRoot, ['tag', '-f', $snapTag, 'HEAD']);

            // 5. Commit em main + receipt + marcação governada.
            $msg = 'atlas loop auto-merge: '.(string) $proposal->target_path.' ['.substr((string) $proposal->proposal_hash, 0, 12).']';
            $this->git($repoRoot, array_merge(['add', '--'], $changed));
            if (! $this->git($repoRoot, ['-c', 'user.email=loop@atlas', '-c', 'user.name=atlas-loop', 'commit', '-q', '-m', $msg, '--no-gpg-sign'])) {
                return array_merge($base, ['reason' => 'commit_failed']);
            }
            $commit = $this->headSha($repoRoot);

            $this->governedSave(function () use ($proposal): void {
                $proposal->forceFill(['merged_to_main' => true, 'reviewed_at' => now()])->save();
            });

            // 6. Canário best-effort (fix-forward-first: falha registra, não reverte).
            $canary = $this->canary($repoRoot, $changed);

            // L2-6: o resultado do canário persiste na proposta — é o dado que alimenta
            // o guard de saldo líquido (taxa de quebra medida, não narrativa).
            $this->governedSave(function () use ($proposal, $canary): void {
                $quality = is_array($proposal->quality) ? $proposal->quality : [];
                $quality['_canary'] = $canary;
                $proposal->forceFill(['quality' => $quality])->save();
            });

            // L3-4: fix-forward FECHA o ciclo. O canário vermelho pós-merge NUNCA reverte
            // (política v2 do operador) — em vez disso enfileira uma task de CORREÇÃO no
            // próprio Loop, alvejando o mesmo arquivo, com o snapshot pré-merge endereçado
            // para a correção partir de um estado conhecido. Dedup natural (enqueueTask).
            $fixForward = null;
            if (($canary['ran'] ?? false) && ($canary['passed'] ?? null) === false) {
                $fixForward = $this->enqueueFixForward($proposal, $canary, $snapTag);
            }

            $this->receipt($proposal, $commit, $canary, $snapTag);

            // L3-7: cada merge REAL vira learning recallável (accrual de compounding). O
            // contrato no-noise é respeitado por construção — só dispara num merge concreto,
            // com claim SUBSTANTIVO (arquivo, commit, veredito do canário), nunca boilerplate.
            $this->accrueCompounding($proposal, $commit, $canary);

            return array_merge($base, ['merged' => true, 'commit' => $commit, 'canary' => $canary, 'snapshot_tag' => $snapTag, 'fix_forward_task' => $fixForward]);
        } catch (Throwable $e) {
            return array_merge($base, ['reason' => 'error:'.mb_substr($e->getMessage(), 0, 160)]);
        }
    }

    /**
     * L3-7: registra o merge como execução real no runtime de compounding — fecha o laço
     * learn→recall (o merge seguinte recupera o que este ensinou). Substantivo por
     * construção: claim cita o arquivo, o commit e o veredito do canário. Best-effort,
     * gated; nunca afeta o merge (que já aconteceu). Reusa a mesma porta que o conductor
     * de engenharia usa — não fabrica sinal, alimenta um outcome concreto.
     */
    private function accrueCompounding(AtlasLoopProposal $proposal, ?string $commit, array $canary): void
    {
        if (! (bool) config('atlas.ai.loop.compounding_accrual', true)) {
            return;
        }
        try {
            $target = (string) $proposal->target_path;
            $canaryVerdict = ($canary['ran'] ?? false)
                ? (($canary['passed'] ?? false) ? 'canário GREEN' : 'canário RED→fix-forward enfileirado')
                : 'sem canário-irmão';
            app(\App\Services\Ai\Compounding\AtlasCompoundingRuntimeService::class)->recordExecution([
                'outcome_status' => 'passed',
                'flow_id' => 'loop_auto_merge',
                'run_id' => (string) $commit,
                'evidence_refs' => array_values(array_filter([$target, $commit ? 'commit:'.$commit : null])),
                'learning_signal' => [
                    'claim' => sprintf(
                        'Loop auto-merge melhorou %s e mergeou em main (commit %s, %s); reusar este alvo concreto ao priorizar trabalho de evolução similar.',
                        $target,
                        $commit ? substr($commit, 0, 10) : '?',
                        $canaryVerdict,
                    ),
                    'memory_type' => 'loop_merge_memory',
                    'scope' => 'engineering',
                    'confidence' => 0.7,
                    'flow_id' => 'loop_auto_merge',
                    'evidence_refs' => array_values(array_filter([$target, $commit ? 'commit:'.$commit : null])),
                ],
            ]);
        } catch (Throwable) {
            // accrual é best-effort; o merge não depende dele.
        }
    }

    /**
     * L3-4: enfileira a task de correção fix-forward quando o canário fica vermelho
     * pós-merge. Nunca reverte (política v2): o Loop conserta para frente. O dedupe natural
     * do enqueueTask evita enfileirar a mesma correção em passes repetidos.
     *
     * @param  array<string,mixed>  $canary
     * @return array{task_id:?string, enqueued:bool}
     */
    private function enqueueFixForward(AtlasLoopProposal $proposal, array $canary, ?string $snapTag): array
    {
        try {
            $target = (string) $proposal->target_path;
            $objective = 'fix-forward: canário RED após auto-merge de '.$target.' (teste-irmão '
                .(string) ($canary['target'] ?? '?').' falhou). Corrigir para frente — não reverter.';
            $task = $this->store->enqueueTask(
                (string) $proposal->campaign_id,
                $objective,
                [
                    'origin' => 'fix_forward_canary_red',
                    'merged_proposal_hash' => (string) $proposal->proposal_hash,
                    'snapshot_tag' => $snapTag,
                    'canary_target' => $canary['target'] ?? null,
                ],
                'fix_forward',
                $target,
                10, // prioridade alta: regressão recém-introduzida
                false,
            );

            return ['task_id' => $task?->getKey() !== null ? (string) $task->getKey() : null, 'enqueued' => $task !== null];
        } catch (Throwable) {
            // fix-forward é best-effort; o merge já aconteceu e nunca reverte.
            return ['task_id' => null, 'enqueued' => false];
        }
    }

    /**
     * Escreve sob a porta governada do merge-livre v2: abre os DOIS guards de
     * defense-in-depth e só os dois — a flag estática do model (Eloquent) e o setting
     * de sessão do trigger pgsql (`atlas.governed_merge`), este último válido apenas
     * dentro desta transação (SET LOCAL). Qualquer outro caminho continua bloqueado em
     * never-merge. sqlite (testes) ignora o SET LOCAL silenciosamente — o guard do model
     * é o que vale lá.
     */
    private function governedSave(callable $write): void
    {
        AtlasLoopProposal::$governedMergeInProgress = true;
        try {
            DB::transaction(function () use ($write): void {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    DB::statement("SET LOCAL atlas.governed_merge = 'on'");
                }
                $write();
            });
        } finally {
            AtlasLoopProposal::$governedMergeInProgress = false;
        }
    }

    /**
     * Canário por convenção: para app/Services/Foo/Bar.php roda o teste irmão
     * "BarTest.php" (em tests/Unit ou tests/Feature) quando existir. Best-effort,
     * bounded, nunca bloqueia.
     *
     * @param  list<string>  $changed
     * @return array{ran:bool, passed:?bool, target:?string}
     */
    private function canary(string $repoRoot, array $changed): array
    {
        foreach ($changed as $file) {
            $class = pathinfo($file, PATHINFO_FILENAME);
            $hits = glob($repoRoot.'/tests/{Unit,Feature}/**/'.$class.'Test.php', GLOB_BRACE) ?: [];
            if ($hits === []) {
                continue;
            }
            $p = new Process(['php', '-d', 'memory_limit=2048M', 'artisan', 'test', $hits[0]], $repoRoot, null, null, 300.0);
            $p->run();

            return ['ran' => true, 'passed' => $p->isSuccessful(), 'target' => str_replace($repoRoot.'/', '', $hits[0])];
        }

        return ['ran' => false, 'passed' => null, 'target' => null];
    }

    /**
     * @return list<string>
     */
    private function changedPhpFiles(string $repoRoot): array
    {
        $p = new Process(['git', 'diff', '--name-only', '--no-ext-diff'], $repoRoot, null, null, 30.0);
        $p->run();
        $files = [];
        foreach (preg_split('/\R/', trim($p->getOutput())) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $files[] = $line;
            }
        }

        return $files;
    }

    private function phpLintOk(string $absPath): bool
    {
        if (! str_ends_with($absPath, '.php')) {
            return true;
        }
        $p = new Process([PHP_BINARY, '-l', $absPath], null, null, null, 30.0);
        $p->run();

        return $p->isSuccessful();
    }

    private function headSha(string $repoRoot): ?string
    {
        $p = new Process(['git', 'rev-parse', 'HEAD'], $repoRoot, null, null, 15.0);
        $p->run();

        return $p->isSuccessful() ? trim($p->getOutput()) : null;
    }

    /**
     * @param  list<string>  $argv
     */
    private function git(string $cwd, array $argv): bool
    {
        $p = new Process(array_merge(['git'], array_values($argv)), $cwd, null, null, 60.0);
        $p->run();

        return $p->isSuccessful();
    }

    /**
     * @param  array<string,mixed>  $canary
     */
    private function receipt(AtlasLoopProposal $proposal, ?string $commit, array $canary, ?string $snapshotTag = null): void
    {
        try {
            app(AtlasEvidenceLedger::class)->record(
                LedgerEventType::DecisionIssued,
                [
                    'schema_version' => self::SCHEMA_VERSION,
                    'decision' => 'loop_auto_merge_to_main',
                    'proposal_hash' => (string) ($proposal->proposal_hash ?? ''),
                    'target_path' => (string) $proposal->target_path,
                    'commit' => $commit,
                    'canary' => $canary,
                    'snapshot_tag' => $snapshotTag,
                    'policy' => 'merge_livre_v2_operator_2026_06_12',
                ],
                [
                    'operator_id' => 'operator-authorized-merge-livre-v2',
                    'emitter_stage' => 'atlas.ai.loop_auto_merge',
                    'emitter_version' => 'loop-auto-merge-v1',
                ],
            );
        } catch (Throwable) {
            // receipt é best-effort; o merge não depende dele.
        }
    }

    /**
     * @param  list<array<string,mixed>>  $results
     * @return array<string,mixed>
     */
    private function summary(string $status, array $results, ?string $note, int $merged = 0): array
    {
        $out = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'merged_count' => $merged,
            'results' => $results,
        ];
        if ($note !== null) {
            $out['note'] = $note;
        }

        return $out;
    }
}
