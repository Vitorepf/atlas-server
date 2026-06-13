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
        private readonly AtlasLoopImpactReceiptService $impactReceipts,
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
            ->where('status', AtlasLoopProposal::STATUS_CERTIFIED)
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
     * Merge a proposal that was explicitly reviewed by the operator after the normal
     * auto-merge guard parked it. This is the only path that can override the
     * forbidden-self-target park, and it still runs the full re-proof/apply/lint/canary
     * pipeline.
     *
     * @param  array{operator_id?:string,approved?:bool,reason?:string}  $approval
     * @return array<string,mixed>
     */
    public function mergeOperatorApproved(AtlasLoopProposal $proposal, string $repoRoot, array $approval): array
    {
        $operator = trim((string) ($approval['operator_id'] ?? ''));
        if ($operator === '' || ($approval['approved'] ?? false) !== true) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'denied',
                'reason' => 'operator_approval_required',
                'result' => null,
            ];
        }
        $repoRoot = rtrim($repoRoot, '/');
        if (! is_dir($repoRoot.'/.git')) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked',
                'reason' => 'repo_root_not_a_git_tree',
                'result' => null,
            ];
        }

        $result = $this->mergeOne(
            $proposal,
            $repoRoot,
            true,
            $operator,
            trim((string) ($approval['reason'] ?? 'operator approved parked proposal')),
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => ($result['merged'] ?? false) === true ? 'merged' : 'blocked',
            'reason' => $result['reason'] ?? null,
            'result' => $result,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mergeOne(
        AtlasLoopProposal $proposal,
        string $repoRoot,
        bool $operatorApproved = false,
        ?string $operatorId = null,
        ?string $operatorReason = null,
    ): array
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
            if (app(AtlasLoopHarnessGuard::class)->isForbiddenSelfTarget((string) $proposal->target_path) && ! $operatorApproved) {
                $this->markOperatorReview($proposal, 'parked_for_operator_review', 'forbidden_self_target', 'auto_merge');

                return array_merge($base, ['reason' => 'forbidden_self_target (parked_for_operator_review)']);
            }

            // 1. Re-prova real contra o contrato congelado persistido. A re-prova é FLAKY sob
            // contenção (o drain roda junto do grind do supervisor + outros comandos; o test
            // runner no clone pode dar timeout sob carga e voltar reproof_failed mesmo p/ uma
            // proposta sã — provado: a mesma proposta re-prova ok=true isolada). reproof_failed
            // é TRANSIENTE (≠ estrutural): re-tenta até 3× antes de pular, então uma janela de
            // carga não mata os merges. Falhas ESTRUTURAIS (git_apply_failed/no_acceptance_
            // contract) NÃO re-tentam — saem na 1ª e aposentam.
            $re = $this->gate->reprove($proposal, $repoRoot);
            for ($attempt = 2; $attempt <= 3 && $re['ok'] !== true && (string) ($re['reason'] ?? '') === 'reproof_failed'; $attempt++) {
                $re = $this->gate->reprove($proposal, $repoRoot);
            }
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
                    // INDEPENDÊNCIA 24h+: a proposta passou a re-prova mas o diff não aplica
                    // mais no repo real (outro merge moveu a árvore). A árvore só anda PRA
                    // FRENTE — esse diff específico nunca mais casa; sem aposentar, fica
                    // drenável p/ sempre, re-clonando o repo a cada passe (lixo que infla a
                    // fila e gasta ciclos). Retira (reviewed_at); o loop re-descobre o alvo se
                    // ainda tiver superfície melhorável. Mesma filosofia do git_apply_failed.
                    $proposal->forceFill(['reviewed_at' => now()])->save();

                    return array_merge($base, ['reason' => 'apply_conflict_tree_moved (retired_stale)']);
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

            // 3b. INDEPENDÊNCIA (crítico p/ 24h+ sem intervenção): `php -l` só pega SINTAXE.
            // Um merge com erro de LÓGICA que quebra o BOOT do app (um provider/singleton que
            // lança ao bootar) passaria — e quando o supervisor reiniciasse por drift,
            // carregaria o código quebrado e entraria em CRASH-LOOP, derrubando o loop inteiro.
            // Estende o piso "sintaxe quebrada nunca entra" para "BOOT quebrado nunca entra":
            // boota o app com a mudança aplicada na working tree; se falhar, DESFAZ o apply e
            // rejeita (NÃO é revert — nada foi commitado ainda, igual ao php -l). Gated.
            if ((bool) config('atlas.ai.loop.boot_smoke_guard', true) && ! $this->bootSmokeOk($repoRoot)) {
                foreach ($changed as $file) {
                    $this->git($repoRoot, ['checkout', '--', $file]);
                }

                return array_merge($base, ['reason' => 'boot_smoke_failed (rejeitado pré-commit, boot do app quebraria)']);
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

            $this->governedSave(function () use ($proposal, $operatorApproved, $operatorId, $operatorReason): void {
                $quality = is_array($proposal->quality) ? $proposal->quality : [];
                if ($operatorApproved) {
                    $quality['_operator_review'] = [
                        'schema_version' => 'atlas.loop.operator_review.v1',
                        'status' => 'merged',
                        'reason' => $operatorReason,
                        'operator_id' => $operatorId,
                        'reviewed_at' => now()->toIso8601String(),
                        'decision' => 'approve_and_merge',
                    ];
                }
                $proposal->forceFill(['merged_to_main' => true, 'reviewed_at' => now(), 'quality' => $quality])->save();
            });

            // 6. Canário best-effort (fix-forward-first: falha registra, não reverte).
            $canary = $this->canary($repoRoot, $changed);
            $impactReceipt = (bool) config('atlas.ai.loop.impact_receipts_enabled', true)
                ? $this->impactReceipts->build($proposal, $changed, $commit, $canary)
                : null;

            // L2-6/L4-3: canário mede quebra; impact receipt mede valor. Ambos persistem
            // no mesmo registro de qualidade para alimentar guard + digest sem narrativa.
            $this->governedSave(function () use ($proposal, $canary, $impactReceipt): void {
                $quality = is_array($proposal->quality) ? $proposal->quality : [];
                $quality['_canary'] = $canary;
                if (is_array($impactReceipt)) {
                    $quality['_impact_receipt'] = $impactReceipt;
                }
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

            $this->receipt($proposal, $commit, $canary, $snapTag, $impactReceipt);

            // L3-7: cada merge REAL vira learning recallável (accrual de compounding). O
            // contrato no-noise é respeitado por construção — só dispara num merge concreto,
            // com claim SUBSTANTIVO (arquivo, commit, veredito do canário), nunca boilerplate.
            $this->accrueCompounding($proposal, $commit, $canary);

            return array_merge($base, [
                'merged' => true,
                'commit' => $commit,
                'canary' => $canary,
                'impact_receipt' => $impactReceipt,
                'snapshot_tag' => $snapTag,
                'fix_forward_task' => $fixForward,
                'operator_approved' => $operatorApproved,
            ]);
        } catch (Throwable $e) {
            return array_merge($base, ['reason' => 'error:'.mb_substr($e->getMessage(), 0, 160)]);
        }
    }

    private function markOperatorReview(AtlasLoopProposal $proposal, string $status, string $reason, string $operatorId): void
    {
        $quality = is_array($proposal->quality) ? $proposal->quality : [];
        $quality['_operator_review'] = [
            'schema_version' => 'atlas.loop.operator_review.v1',
            'status' => $status,
            'reason' => $reason,
            'operator_id' => $operatorId,
            'reviewed_at' => now()->toIso8601String(),
            'decision' => 'park',
        ];
        $proposal->forceFill(['reviewed_at' => now(), 'quality' => $quality])->save();
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
            // PHP_BINARY, not bare 'php': the canary runs as a direct child of the
            // launchd-spawned drain (outside the frozen judge's process tree, so it does
            // not inherit the judge's PATH fix). Under launchd's minimal PATH a bare
            // 'php' argv[0] would not resolve — same exit-127 class that broke reprove.
            $p = new Process([PHP_BINARY, '-d', 'memory_limit=2048M', 'artisan', 'test', $hits[0]], $repoRoot, null, null, 300.0);
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

    /**
     * Boot-smoke: o app inteiro consegue BOOTAR com a mudança aplicada? Carrega o autoloader
     * + bootstrap/app.php + bootstrap do kernel (que registra TODOS os providers). Se um
     * provider/singleton resolvido no boot lançar por causa da mudança, falha aqui — e o
     * merge é rejeitado ANTES de entrar em main (evita o crash-loop do supervisor). Bounded
     * + degrade-safe: sem vendor/bootstrap (ex.: workspace incompleto) ⇒ true (não bloqueia
     * por ambiente; o `php -l` já cobriu a sintaxe).
     */
    private function bootSmokeOk(string $repoRoot): bool
    {
        $repoRoot = rtrim($repoRoot, '/');
        if (! is_file($repoRoot.'/vendor/autoload.php') || ! is_file($repoRoot.'/bootstrap/app.php')) {
            return true;
        }
        $script = "require 'vendor/autoload.php';"
            ."\$app = require 'bootstrap/app.php';"
            ."\$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();"
            ."echo 'atlas-boot-ok';";
        $p = new Process([PHP_BINARY, '-d', 'memory_limit=512M', '-r', $script], $repoRoot, null, null, 60.0);
        $p->run();

        return $p->isSuccessful() && str_contains($p->getOutput(), 'atlas-boot-ok');
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
    private function receipt(
        AtlasLoopProposal $proposal,
        ?string $commit,
        array $canary,
        ?string $snapshotTag = null,
        ?array $impactReceipt = null,
    ): void
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
                    'impact_receipt' => $impactReceipt,
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
