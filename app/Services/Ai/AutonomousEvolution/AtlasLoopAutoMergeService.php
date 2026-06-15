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
        private readonly AtlasLoopMultiRepoMergeAuthority $repoAuthority,
    ) {}

    /**
     * Drena propostas certificadas ainda não-mergeadas para MAIN.
     *
     * @return array<string,mixed>
     */
    public function drain(string $repoRoot, int $limit = 10): array
    {
        // L5-9: a PORTA É POR-REPO. O home repo segue governado por `auto_merge_to_main`;
        // qualquer repo ESTRANGEIRO é never-merge default e só atravessa com a feature
        // multi-repo ON + o caminho na allow-list do operador (fail-closed por construção).
        $authority = $this->repoAuthority->authorize($repoRoot);
        if (($authority['allowed'] ?? false) !== true) {
            $status = $authority['scope'] === AtlasLoopMultiRepoMergeAuthority::SCOPE_HOME ? 'disabled' : 'blocked';
            $note = $authority['scope'] === AtlasLoopMultiRepoMergeAuthority::SCOPE_HOME
                ? 'auto_merge_to_main desligado (decisão do operador necessária)'
                : 'repo estrangeiro sem porta governada: '.(string) ($authority['reason'] ?? 'foreign_repo_not_authorized');

            return array_merge(
                $this->summary($status, [], $note),
                ['repo_authority' => $authority],
            );
        }
        $repoRoot = rtrim($repoRoot, '/');
        if (! is_dir($repoRoot.'/.git')) {
            return $this->summary('blocked', [], 'repo_root_not_a_git_tree');
        }

        // L2-6: guard de saldo líquido — merges continuam livres enquanto a direção
        // líquida MEDIDA for positiva; afogamento em quebra aperta o dial sozinho.
        $net = app(AtlasLoopNetDirectionGuard::class)->verdict();
        if ((bool) ($net['throttled'] ?? false)) {
            return array_merge(
                $this->summary('throttled', [], 'saldo líquido negativo medido: '.(string) ($net['reason'] ?? '')),
                ['repo_authority' => $authority],
            );
        }

        $proposals = AtlasLoopProposal::query()
            ->where('status', AtlasLoopProposal::STATUS_CERTIFIED)
            ->where('merged_to_main', false)
            ->whereNull('reviewed_at')
            ->orderBy('created_at')
            ->limit(max(1, min(50, $limit)))
            ->get();

        // L5-9 TOCTOU: captura a identidade canônica AUTORIZADA. O `mergeOne()` re-resolve
        // o caminho imediatamente antes das git ops e aborta se ela mudou (symlink/montagem
        // trocada entre authorize() e o apply) — a autorização não é um cheque em branco
        // para qualquer alvo que `$repoRoot` venha a apontar depois.
        $authorizedCanonical = (string) ($authority['resolved_repo'] ?? '') !== ''
            ? (string) $authority['resolved_repo']
            : $this->repoAuthority->canonicalPath($repoRoot);

        $results = [];
        foreach ($proposals as $proposal) {
            $results[] = $this->mergeOne($proposal, $repoRoot, false, null, null, $authorizedCanonical);
        }

        $merged = count(array_filter($results, static fn (array $r): bool => $r['merged']));

        return array_merge(
            $this->summary($merged > 0 ? 'merged' : 'no_merges', $results, null, $merged),
            ['repo_authority' => $authority],
        );
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

        // L5-9: a porta por-repo vale TAMBÉM no override do operador. Aprovar uma proposta
        // parqueada é uma decisão humana explícita, mas um repo ESTRANGEIRO só atravessa se
        // estiver REGISTRADO (multi-repo ON + na allow-list) — a aprovação de UMA proposta
        // não vira autorização estrutural do repo. O home repo segue livre p/ override
        // (comportamento existente do parked-review). Fail-closed para foreign não-registrado.
        $authority = $this->repoAuthority->authorize($repoRoot);
        if (
            ($authority['scope'] ?? null) === AtlasLoopMultiRepoMergeAuthority::SCOPE_FOREIGN
            && ($authority['allowed'] ?? false) !== true
        ) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked',
                'reason' => 'foreign_repo_not_registered:'.(string) ($authority['reason'] ?? 'foreign_repo_not_authorized'),
                'result' => null,
                'repo_authority' => $authority,
            ];
        }

        // L5-9 TOCTOU: também no override do operador a identidade autorizada é re-resolvida
        // antes das git ops (o `mergeOne` aborta se o caminho mudou desde aqui).
        $authorizedCanonical = (string) ($authority['resolved_repo'] ?? '') !== ''
            ? (string) $authority['resolved_repo']
            : $this->repoAuthority->canonicalPath($repoRoot);

        $result = $this->mergeOne(
            $proposal,
            $repoRoot,
            true,
            $operator,
            trim((string) ($approval['reason'] ?? 'operator approved parked proposal')),
            $authorizedCanonical,
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
        ?string $authorizedCanonical = null,
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
            // L5-9 TOCTOU (agora ENFORCED — antes o 6º arg era silenciosamente descartado e
            // a re-resolução documentada NUNCA rodava). A identidade canônica AUTORIZADA foi
            // capturada pelo chamador (drain/operator-approved) logo após authorize(). Re-resolve
            // o caminho canônico de $repoRoot AGORA, imediatamente antes de QUALQUER git op, e
            // ABORTA se mudou (symlink/montagem trocada entre authorize() e aqui). Fail-closed:
            // um canônico autorizado não-nulo que não resolve mais idêntico bloqueia o merge.
            // null/'' = chamador legado sem captura ⇒ guarda inerte (comportamento inalterado).
            if ($authorizedCanonical !== null && $authorizedCanonical !== ''
                && ! $this->repoAuthority->stillResolvesTo($repoRoot, $authorizedCanonical)) {
                return array_merge($base, ['reason' => 'canonical_path_changed_toctou (merge abortado; identidade do repo mudou desde a autorização)']);
            }

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
                    $contractless = str_contains($reason, 'no_acceptance_contract');
                    // ATTRIBUTABLE RETIRE: persist WHY on the row. This used to be a bare reviewed_at
                    // stamp, so "retired_stale" was an unattributed black hole — the funnel could not
                    // tell a RECOVERABLE base-drift casualty (contract present, only the base moved)
                    // from a genuinely DEAD contractless legacy one. Now every retire is auditable.
                    $this->retireStale(
                        $proposal,
                        $contractless ? 'retired_contractless' : 'retired_stale_diff',
                        $reason,
                    );
                    $reason .= $contractless ? ' (retired_contractless)' : ' (retired_stale)';
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
                    // ATTRIBUTABLE: reproved GREEN but the diff no longer applies (tree moved) — the
                    // MOST recoverable retire class (the change is still good; only its base is stale).
                    $this->retireStale($proposal, 'retired_stale_tree_moved', 'apply_conflict_tree_moved');

                    return array_merge($base, ['reason' => 'apply_conflict_tree_moved (retired_stale)']);
                }
                if (! $this->git($repoRoot, ['apply', '--whitespace=nowarn', basename($patch)])) {
                    return array_merge($base, ['reason' => 'apply_failed']);
                }
            } finally {
                @unlink($patch);
            }

            // 3. Sanidade: sintaxe quebrada nunca entra (o resto é fix-forward).
            // SCOPE TO THE PROPOSAL'S OWN PATCH: the drain runs in base_path, which the concurrently
            // grinding supervisor leaves dirty with UNRELATED tracked edits. A whole-working-tree
            // `git diff` let those foreign files (a) ride into the merge commit via `git add` and
            // (b) mis-aim the canary at an unrelated sibling test — measured 22.4% of merges canaried
            // the WRONG test, so a real behavioural regression could merge GREEN. Intersect the tree
            // diff with the patch's own files so EVERY step below (lint, value-gate, canary, git add,
            // attribution, trust-ladder) sees ONLY this proposal's change.
            $changed = $this->scopeToPatch($this->changedPhpFiles($repoRoot), $normalized, (string) $proposal->target_path, $repoRoot);
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

            // 3c. VALUE GATE (default OFF) + medição WIRED. Resolve UMA vez quantos callers
            // de produção REAIS o alvo tem; isso (a) alimenta o real_callers do receipt (o
            // sinal WIRED que a nota re-resolve) e (b) dirige o value-gate opcional. Todos os
            // gates de SEGURANÇA acima (harness-guard, re-prova frozen, escopo, php -l,
            // boot-smoke) rodaram PRIMEIRO e estão intactos — este gate só recusa merge de
            // BAIXO IMPACTO (órfão/dead-scaffolding), nunca relaxa segurança.
            $callers = $this->targetCallerCount($proposal, $changed);
            if ((bool) config('atlas.ai.loop.value_gate_enabled', false)) {
                $verdict = $this->valueGateVerdict($proposal, $changed, $callers);
                if (! ($verdict['passed'] ?? false)) {
                    foreach ($changed as $file) {
                        $this->git($repoRoot, ['checkout', '--', $file]); // pré-commit: desfaz o apply (não é revert)
                    }
                    // RETIRE (não deixa re-drenável): deixar a proposta unreviewed CLOGAVA a fila
                    // oldest-first do drain (--limit=10) — propostas WIRED ficavam presas ATRÁS de
                    // alvos órfãos/baixo-impacto que nunca mergeiam (incidente medido: 13 wired
                    // presas atrás de 8 órfãs, ~7h sem merge). Aposenta com status auditável; a
                    // re-descoberta cria uma proposta NOVA se o alvo ganhar callers/evidência
                    // depois (não re-processa ESTA proposta velha). Fail-open intacto: callers
                    // null (não-medido) já passou no value-gate; só MEDIDO órfão chega aqui.
                    $this->governedSave(function () use ($proposal, $verdict): void {
                        $quality = is_array($proposal->quality) ? $proposal->quality : [];
                        $quality['_operator_review'] = [
                            'schema_version' => 'atlas.loop.operator_review.v1',
                            'status' => 'value_gate_retired',
                            'reason' => (string) ($verdict['reason'] ?? 'low_impact'),
                            'reviewed_at' => now()->toIso8601String(),
                            'decision' => 'retire_low_impact',
                        ];
                        $proposal->forceFill(['reviewed_at' => now(), 'quality' => $quality])->save();
                    });

                    return array_merge($base, ['reason' => 'value_gate_blocked:'.($verdict['reason'] ?? 'low_impact'), 'value_gate' => $verdict]);
                }
            }

            // 4. Snapshot pré-merge (L2-5): âncora git endereçável do estado ANTES do
            // merge — fix-forward sempre barato (restaurar = checkout da tag). O commit
            // do merge referencia a âncora no receipt.
            $snapTag = 'atlas-snap-'.substr((string) $proposal->proposal_hash, 0, 12);
            $this->git($repoRoot, ['tag', '-f', $snapTag, 'HEAD']);

            // 4b. CANÁRIO PRÉ-COMMIT (default ON, flag `precommit_canary_gate`) — fecha o buraco
            // do "fix-forward commitava ANTES do veredito e nunca revertia": uma regressão de
            // COMPORTAMENTO (passa php -l + boot-smoke mas quebra o teste-irmão) chegava em main
            // no caminho single-file dominante. Agora o canário roda contra a árvore APLICADA-
            // mas-não-commitada; RED ⇒ DESFAZ o apply (NÃO é revert de main — nada commitado,
            // igual php -l/boot-smoke/value-gate), APOSENTA a proposta (senão re-drena + re-falha
            // p/ sempre = clog) e enfileira a CORREÇÃO fix-forward. A semântica fix-forward é
            // PRESERVADA (o Loop conserta pra frente), mas a partir de um MAIN LIMPO — a regressão
            // nunca transita por main. GREEN/sem-irmão ⇒ segue p/ commit. Flag OFF restaura a
            // política v2 pura (canário pós-commit, nunca reverte) — bloco 6 abaixo.
            $precommitGate = (bool) config('atlas.ai.loop.precommit_canary_gate', true);
            $canary = $precommitGate ? $this->canary($repoRoot, $changed) : ['ran' => false, 'passed' => null, 'target' => null];
            if ($precommitGate && ($canary['ran'] ?? false) && ($canary['passed'] ?? null) === false) {
                foreach ($changed as $file) {
                    $this->git($repoRoot, ['checkout', '--', $file]); // pré-commit: desfaz o apply (não é revert)
                }
                $fixForward = $this->enqueueFixForward($proposal, $canary, $snapTag);
                $this->governedSave(function () use ($proposal, $canary): void {
                    $quality = is_array($proposal->quality) ? $proposal->quality : [];
                    $quality['_operator_review'] = [
                        'schema_version' => 'atlas.loop.operator_review.v1',
                        'status' => 'canary_red_retired',
                        'reason' => 'precommit_canary_red:'.(string) ($canary['target'] ?? '?'),
                        'reviewed_at' => now()->toIso8601String(),
                        'decision' => 'retire_canary_red_fix_forward',
                    ];
                    $quality['_canary'] = $canary;
                    $proposal->forceFill(['reviewed_at' => now(), 'quality' => $quality])->save();
                });

                return array_merge($base, [
                    'reason' => 'canary_red_precommit_gate (apply desfeito, main intocado, fix-forward enfileirado)',
                    'canary' => $canary,
                    'fix_forward_task' => $fixForward,
                ]);
            }

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

            // 6. Canário. Com o gate ON o GREEN já rodou (pré-commit, acima). Com o gate OFF
            // (política v2 pura, opt-in) roda agora, pós-commit; um RED enfileira fix-forward
            // SEM reverter — a regressão fica em main até o conserto (comportamento legado).
            if (! $precommitGate) {
                $canary = $this->canary($repoRoot, $changed);
            }
            $impactReceipt = (bool) config('atlas.ai.loop.impact_receipts_enabled', true)
                ? $this->impactReceipts->build($proposal, $changed, $commit, $canary, $callers)
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

            // L3-4 (legado, só com o gate OFF): fix-forward pós-commit sem reverter. Com o gate
            // ON o RED já foi tratado pré-commit (revert+retire+fix-forward) e nunca chega aqui.
            $fixForward = null;
            if (! $precommitGate && ($canary['ran'] ?? false) && ($canary['passed'] ?? null) === false) {
                $fixForward = $this->enqueueFixForward($proposal, $canary, $snapTag);
            }

            $this->receipt($proposal, $commit, $canary, $snapTag, $impactReceipt);

            // L3-7: cada merge REAL vira learning recallável (accrual de compounding). O
            // contrato no-noise é respeitado por construção — só dispara num merge concreto,
            // com claim SUBSTANTIVO (arquivo, commit, veredito do canário), nunca boilerplate.
            $this->accrueCompounding($proposal, $commit, $canary);

            // L6-14: o merge REAL também alimenta o trust-ladder por-classe — é o feed de
            // HISTÓRICO REAL que faltava para uma classe poder auto-ganhar confiança (ou
            // perdê-la num canário vermelho). Gated (default OFF), evidence-only (chave = o
            // commit SHA real), assimétrico (canário RED → revert) e best-effort: o merge já
            // aconteceu e NUNCA depende disso. Não toca never-merge nem a porta de merge.
            $this->feedTrustLadder($changed, $commit, $canary);

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
     * Retire a certified proposal TERMINALLY (out of the drain queue) with an AUDITABLE, attributable
     * reason persisted on the row. Mirrors the value_gate_retired stamp (decision=retire, so it never
     * surfaces in the operator-review queue), and records had_acceptance_contract so the funnel/digest
     * can split RECOVERABLE base-drift casualties (tree moved, contract present → re-discoverable, and
     * the future rebase-salvage candidate) from genuinely DEAD ones (no contract, irreprovable).
     * reviewed_at terminates drainability (drainable requires reviewed_at IS NULL); merged_to_main
     * stays false (this only annotates a retire that already happened — it never marks a merge).
     */
    private function retireStale(AtlasLoopProposal $proposal, string $status, string $reason): void
    {
        $quality = is_array($proposal->quality) ? $proposal->quality : [];
        $quality['_operator_review'] = [
            'schema_version' => 'atlas.loop.operator_review.v1',
            'status' => $status,
            'reason' => $reason,
            'reviewed_at' => now()->toIso8601String(),
            'decision' => 'retire',
            'had_acceptance_contract' => (bool) data_get($quality, '_acceptance_contract'),
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
            $canaryRan = (bool) ($canary['ran'] ?? false);
            $canaryGreen = $canaryRan && (bool) ($canary['passed'] ?? false);
            $canaryVerdict = $canaryRan
                ? ($canaryGreen ? 'canário GREEN' : 'canário RED→fix-forward enfileirado')
                : 'sem canário-irmão';
            app(\App\Services\Ai\Compounding\AtlasCompoundingRuntimeService::class)->recordExecution([
                'outcome_status' => 'passed',
                'flow_id' => 'loop_auto_merge',
                'run_id' => (string) $commit,
                'evidence_refs' => array_values(array_filter([$target, $commit ? 'commit:'.$commit : null])),
                'learning_signal' => [
                    // STABLE per target+verdict — the volatile commit SHA lives in evidence_refs, NOT
                    // the claim: the memory content-dedup (AtlasCompoundingMemoryService) keys on the
                    // EXACT claim, so a per-merge SHA in the claim would flood a fresh active memory for
                    // every merge to the same target (the 137→N noise flood the no-noise contract bars).
                    'claim' => sprintf(
                        'Loop auto-merge endureceu %s em main (%s); reusar este alvo concreto ao priorizar trabalho de evolução similar.',
                        $target,
                        $canaryVerdict,
                    ),
                    'memory_type' => 'loop_merge_memory',
                    'scope' => 'engineering',
                    // 0–100 scale (NOT a 0–1 fraction): the distiller's score() floors a fraction to 1,
                    // which is below the >=70 promote gate, so EVERY loop merge was held forever (203
                    // merges → 0 recallable memory — the learn→recall flywheel silently dead). Canary-
                    // gated for honesty: a red-canary merge (fix-forward enqueued) stays HELD (<70).
                    'confidence' => $canaryGreen ? 75 : ($canaryRan ? 60 : 70),
                    'flow_id' => 'loop_auto_merge',
                    'evidence_refs' => array_values(array_filter([$target, $commit ? 'commit:'.$commit : null])),
                ],
            ]);
        } catch (Throwable) {
            // accrual é best-effort; o merge não depende dele.
        }
    }

    /**
     * L6-14: feed the per-change-class trust ladder from this REAL merge outcome. This is
     * the production HISTORY feed the capstone needed — without it the ladder log sits at
     * entry_count=0 forever and no class can ever auto-green. Gated (default OFF) and
     * best-effort: the merge already happened and never depends on this. The ladder method
     * is structurally evidence-only (real commit SHA = the distinct ref) and asymmetric (a
     * red canary records a revert that resets the class streak). Never touches never-merge
     * or the merge gate; the class is derived from the real diff, never operator-claimed.
     *
     * @param  list<string>  $changed
     * @param  array<string,mixed>  $canary
     */
    private function feedTrustLadder(array $changed, ?string $commit, array $canary): void
    {
        try {
            app(\App\Services\Ai\Governance\AtlasChangeClassTrustLadder::class)
                ->recordMergeOutcome($changed, $commit, $canary);
        } catch (Throwable) {
            // best-effort: the merge is already committed and never depends on the ladder feed.
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
    /**
     * Real production caller count of the merge's primary target. ?int: null means
     * the wired-caller service was unavailable (fail-open — never blocks on missing
     * data); 0 = orphan; >0 = wired. Resolved lazily (no constructor change).
     *
     * @param  list<string>  $changed
     */
    private function targetCallerCount(AtlasLoopProposal $proposal, array $changed): ?int
    {
        try {
            $target = trim(str_replace('\\', '/', (string) $proposal->target_path));
            if ($target === '') {
                foreach ($changed as $file) {
                    $file = trim(str_replace('\\', '/', $file));
                    if (str_ends_with($file, '.php') && ! str_contains($file, '/tests/') && ! str_ends_with($file, 'Test.php')) {
                        $target = $file;
                        break;
                    }
                }
            }
            if ($target === '') {
                return null;
            }

            return app(\App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService::class)
                ->callerCount($target);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Value-gate verdict (pre-commit). Refuses near-zero-impact merges so the loop's
     * provider budget hardens code that RUNS, not orphan scaffolding. The impact_score
     * already embeds the caller penalty (orphan -0.20), so the score floor alone
     * separates orphan (~0.4) from hub (~0.7+); the caller check is belt-and-suspenders.
     * Fail-open on unmeasured callers.
     *
     * @param  list<string>  $changed
     * @return array<string,mixed>
     */
    private function valueGateVerdict(AtlasLoopProposal $proposal, array $changed, ?int $callers): array
    {
        $receipt = $this->impactReceipts->build($proposal, $changed, null, ['ran' => false], $callers);
        $impactScore = (float) ($receipt['impact_score'] ?? 0.0);
        $minScore = (float) config('atlas.ai.loop.value_gate_min_impact_score', 0.45);
        $minCallers = max(0, (int) config('atlas.ai.loop.value_gate_min_callers', 1));

        // callers===null = the wired-caller infra was unavailable (grep error / stale world
        // model). Default fail-OPEN (don't block a real fix on a transient infra blip), but
        // the operator can flip value_gate_fail_open=false to fail-CLOSED, and we ALWAYS
        // stamp `unmeasured` so the digest can surface how many merges rode the seam.
        $unmeasured = $callers === null;
        $failOpen = (bool) config('atlas.ai.loop.value_gate_fail_open', true);
        $scoreOk = $impactScore >= $minScore;
        $callersOk = $unmeasured ? $failOpen : $callers >= $minCallers;
        $passed = $scoreOk && $callersOk;

        $reason = 'ok';
        if (! $scoreOk) {
            $reason = 'impact_score_below_floor:'.$impactScore.'<'.$minScore;
        } elseif (! $callersOk) {
            $reason = $unmeasured ? 'unmeasured_callers_fail_closed' : 'orphan_below_min_callers:'.$callers;
        }

        return [
            'passed' => $passed,
            'impact_score' => $impactScore,
            'real_callers' => $callers,
            'unmeasured' => $unmeasured,
            'min_impact_score' => $minScore,
            'min_callers' => $minCallers,
            'reason' => $reason,
        ];
    }

    private function canary(string $repoRoot, array $changed): array
    {
        // Resolve the sibling via the SHARED recursive resolver — NOT the old
        // `tests/{Unit,Feature}/**/X` glob, whose `**` is NOT recursive in PHP and so
        // only matched tests 0-1 dirs deep, MISSING the deep mirror layout
        // (tests/Unit/Ai/.../{Class}Test.php) — the reason canaries "rarely ran". Now the
        // canary finds + runs the real deep sibling, the same one discovery/grade resolve,
        // so canary-green is a real fact for far more merges (and feeds NON_TRIVIAL credit).
        $resolver = new \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSiblingTestResolver($repoRoot);
        foreach ($changed as $file) {
            $sib = $resolver->resolve($file);
            if (! ($sib['has_sibling'] ?? false)) {
                continue;
            }
            $siblingRel = (string) $sib['sibling_path'];
            // PHP_BINARY, not bare 'php': the canary runs as a direct child of the
            // launchd-spawned drain (outside the frozen judge's process tree). Under
            // launchd's minimal PATH a bare 'php' argv[0] would not resolve (exit-127).
            $p = new Process([PHP_BINARY, '-d', 'memory_limit=2048M', 'artisan', 'test', $siblingRel], $repoRoot, null, null, 300.0);
            $p->run();

            return ['ran' => true, 'passed' => $p->isSuccessful(), 'target' => $siblingRel];
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

    /**
     * Restrict the working-tree changed set to the files THIS proposal's patch actually touches.
     * Never widens to the whole tree; if the intersection is empty (a parse miss), falls back to the
     * patch's own existing files — a parse miss must NEVER re-admit foreign dirty work.
     *
     * @param  list<string>  $treeChanged
     * @return list<string>
     */
    private function scopeToPatch(array $treeChanged, string $normalizedDiff, string $targetPath, string $repoRoot): array
    {
        $patchFiles = $this->patchTargetFiles($normalizedDiff, $targetPath);
        $scoped = array_values(array_intersect($treeChanged, $patchFiles));
        if ($scoped !== []) {
            return $scoped;
        }

        return array_values(array_filter($patchFiles, static fn (string $f): bool => is_file($repoRoot.'/'.$f)));
    }

    /**
     * Repo-relative paths a unified diff touches (its `+++ b/<path>` headers, plus the `--- a/<path>`
     * side for deletes). Falls back to the proposal's declared target_path so the scope is never empty.
     *
     * @return list<string>
     */
    private function patchTargetFiles(string $diff, string $targetPath): array
    {
        $files = [];
        foreach (preg_split('/\R/', $diff) ?: [] as $line) {
            if (preg_match('#^\+\+\+\s+b/(.+)$#', $line, $m) || preg_match('#^---\s+a/(.+)$#', $line, $m)) {
                $path = trim($m[1]);
                if ($path !== '' && $path !== '/dev/null') {
                    $files[$path] = true;
                }
            }
        }
        if ($files === []) {
            $t = trim($targetPath);
            if ($t !== '') {
                $files[$t] = true;
            }
        }

        return array_keys($files);
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
