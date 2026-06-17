<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use App\Models\AtlasLoopCampaign;
use App\Models\AtlasLoopConfidenceSample;
use App\Models\AtlasLoopProposal;
use App\Services\Ai\AutonomousEvolution\Contracts\BroaderRegressionGateContract;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopSiblingTestResolver;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopWiredCallerService;
use App\Services\Ai\AutonomousEvolution\Persistence\AtlasLoopStore;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use App\Services\Ai\Governance\AtlasChangeClassTrustLadder;
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
        private readonly AtlasLoopStore $store,
        private readonly AtlasLoopImpactReceiptService $impactReceipts,
        private readonly AtlasLoopMultiRepoMergeAuthority $repoAuthority,
        // ACDE lever #2b — the cross-suite regression gate for the LIVE single-file lane. LAST + nullable +
        // default null so the existing call-sites/tests keep constructing the service; when null and armed,
        // certify() self-resolves it via app(). Laravel zero-config autowiring does NOT inject `?Type $x =
        // null`, hence the in-method app() fallback.
        private readonly ?BroaderRegressionGateContract $broaderGate = null,
        // ACDE DG1 — the calibrated-confidence abstention gate. LAST + nullable (autowiring does not inject
        // `?Type $x = null`, so mergeOne self-resolves it via app() when armed). Default OFF => never consulted.
        private readonly ?AtlasLoopCalibratedConfidenceGate $confidenceGate = null,
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
    ): array {
        $base = [
            'proposal_id' => (string) $proposal->getKey(),
            'target_path' => (string) $proposal->target_path,
            'merged' => false,
            'reason' => null,
            'commit' => null,
            'canary' => null,
        ];

        // Honest-attribution reconciliation state. The moment the real `git commit` (block 5) returns
        // success the change is DURABLE in main — even if a LATER step throws (the attribution save
        // itself, the impact-receipt build, the canary/receipt persistence). We track the landed commit
        // so the catch can reconcile the row to MATCH main instead of leaving a permanent
        // merged_to_main=false lie (the row would say not-merged while main holds the commit, and no
        // later drain pass ever flips it — a stale re-apply only RETIRES the row).
        $commit = null;
        $commitLanded = false;

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

            // ACDE S1 — SELF-EDIT REVIEW GATE (a porta de segurança que destrava o S1): uma proposta marcada
            // is_self_improvement (o loop editando o PRÓPRIO harness, mesmo um alvo NÃO-proibido) JAMAIS
            // auto-mergeia — parqueia para revisão do operador, igual ao forbidden-self-target. Fecha o
            // vazamento que o passe adversarial achou: self-edits certificados auto-mergeavam sem revisão
            // porque is_self_improvement não era lido por NENHUM gate de merge/segurança. Invariante sempre-ON;
            // como nenhuma proposta carrega o marcador hoje (fromManifest o remove), é byte-identical (os 18
            // testes do auto-merge ficam verdes) até o S1 ligar a produção do marcador.
            if ($this->isSelfImprovementProposal($proposal) && ! $operatorApproved) {
                $this->markOperatorReview($proposal, 'parked_for_operator_review', 'self_improvement', 'auto_merge');

                return array_merge($base, ['reason' => 'self_improvement (parked_for_operator_review)']);
            }

            // ACDE DG1 — CALIBRATED-CONFIDENCE ABSTENTION. The honest confidence bar is FIT from real post-merge
            // outcomes (AtlasLoopConfidenceCalibrator over the {predicted, correct} samples the merge feeder
            // writes), not a hand-set default. When armed, a certified proposal whose cert-time
            // delivery_confidence sits BELOW the empirically-precise band PARKS for operator review instead of
            // auto-merging. Default OFF / not-yet-calibrated / no signal => no abstention => byte-identical (the
            // reprove + trust gates below still decide). Fail-open: any calibration/DB error never blocks merge.
            if (! $operatorApproved && (bool) config('atlas.loop.calibrated_confidence_gate_enabled', false)) {
                try {
                    $abstention = ($this->confidenceGate ?? app(AtlasLoopCalibratedConfidenceGate::class))->abstain($proposal->quality);
                    if (($abstention['abstain'] ?? false) === true) {
                        $this->markOperatorReview($proposal, 'parked_for_operator_review', 'below_calibrated_confidence', 'auto_merge');

                        return array_merge($base, ['reason' => 'below_calibrated_confidence (parked_for_operator_review)']);
                    }
                } catch (Throwable) {
                    // fail-open — a calibration/DB hiccup must never block an otherwise-mergeable proposal.
                }
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
            $canary = $precommitGate ? $this->canary($repoRoot, $changed) : ['ran' => false, 'passed' => null, 'target' => null, 'block' => false];
            // ACDE lever #2 — obey the canary's `block` verdict. Under the default (full-coverage OFF) path
            // `block` == the old `ran && passed===false`, so this is byte-identical; with full-coverage ON it
            // also fires on a RED sibling 2..N and (require-coverage ON) on an unprovable source.
            if ($precommitGate && ($canary['block'] ?? false)) {
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

                // ASYMMETRIC TRUST (#10): a precommit canary-red is a DETECTED REGRESSION by this
                // change-class — feed the ladder a revert so the class streak resets, exactly as the
                // success path feeds a clean promotion. Without this, the DEFAULT (precommit-gate-ON)
                // path never reset trust on a regression, so a class could keep earning autonomy
                // despite producing breaking changes. Best-effort; commit is null (nothing merged).
                $this->feedTrustLadder($changed, null, $canary);

                return array_merge($base, [
                    'reason' => 'canary_red_precommit_gate (apply desfeito, main intocado, fix-forward enfileirado)',
                    'canary' => $canary,
                    'fix_forward_task' => $fixForward,
                ]);
            }

            // 4c. BROADER-REGRESSION GATE PRÉ-COMMIT (ACDE lever #2b, default OFF). O canário acima prova o
            // teste-IRMÃO do arquivo mudado; este prova os MÓDULOS afetados (mapeados por subtree) + boot-smoke
            // + php -l, fechando o buraco "regressão em OUTRA suíte" — a classe cross-test por trás dos 12
            // vazamentos. O gate é fail-closed (built + DI-bound, mas até agora só consumido pelo obra path
            // inerte). RED ⇒ mesmo tratamento do canário-red: desfaz o apply (main intocado), aposenta, e
            // enfileira o fix-forward. Flag OFF ⇒ NÃO chamado ⇒ byte-identical. Custo (roda diretórios de
            // suíte) é por isso que vem default-OFF e é armado junto do canary_full_coverage.
            if ((bool) config('atlas.ai.loop.broader_regression_gate_live', false)) {
                $broader = ($this->broaderGate ?? app(BroaderRegressionGateContract::class))->evaluate($repoRoot, $changed);
                if (($broader['passed'] ?? false) !== true) {
                    foreach ($changed as $file) {
                        $this->git($repoRoot, ['checkout', '--', $file]); // pré-commit: desfaz o apply (não é revert)
                    }
                    $verdict = ['ran' => true, 'passed' => false, 'target' => (string) ($broader['reason'] ?? 'broader_regression')];
                    $fixForward = $this->enqueueFixForward($proposal, $verdict, $snapTag);
                    $this->governedSave(function () use ($proposal, $broader): void {
                        $quality = is_array($proposal->quality) ? $proposal->quality : [];
                        $quality['_operator_review'] = [
                            'schema_version' => 'atlas.loop.operator_review.v1',
                            'status' => 'broader_regression_retired',
                            'reason' => 'broader_regression:'.(string) ($broader['reason'] ?? '?'),
                            'reviewed_at' => now()->toIso8601String(),
                            'decision' => 'retire_broader_regression_fix_forward',
                        ];
                        $quality['_broader_regression'] = $broader;
                        $proposal->forceFill(['reviewed_at' => now(), 'quality' => $quality])->save();
                    });
                    // Asymmetric trust: a detected cross-suite regression resets the change-class streak,
                    // exactly as the canary-red path does.
                    $this->feedTrustLadder($changed, null, $verdict);

                    return array_merge($base, [
                        'reason' => 'broader_regression_gate (apply desfeito, main intocado, fix-forward enfileirado)',
                        'broader_regression' => $broader,
                        'fix_forward_task' => $fixForward,
                    ]);
                }
            }

            // 5. Commit em main + receipt + marcação governada.
            $msg = 'atlas loop auto-merge: '.(string) $proposal->target_path.' ['.substr((string) $proposal->proposal_hash, 0, 12).']';
            $this->git($repoRoot, array_merge(['add', '--'], $changed));
            if (! $this->git($repoRoot, ['-c', 'user.email=loop@atlas', '-c', 'user.name=atlas-loop', 'commit', '-q', '-m', $msg, '--no-gpg-sign'])) {
                return array_merge($base, ['reason' => 'commit_failed']);
            }
            // The change is now DURABLY in main. Everything below must end with the row attributed as
            // merged; if any step throws, the catch reconciles from this flag instead of lying.
            $commitLanded = true;
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

            // ITEM10: feed the confidence-calibration flywheel a {predicted,correct} sample. predicted = the
            // cert-time delivery_confidence (threaded into proposal.quality by the grinder); correct = the
            // canary verdict (GREEN, or not-run => treat as correct, the merge gate already passed). Runs
            // AFTER the durable commit + post-merge governedSave, so it never disturbs reconciliation.
            // Flag-gated default-OFF, fail-open: a telemetry write NEVER unwinds a completed merge.
            if ((bool) config('atlas.loop.confidence_calibration.enabled', false)) {
                try {
                    $predicted = (float) data_get($proposal->quality, 'delivery_confidence.confidence', 0.0);
                    if ($predicted > 0.0) {
                        $correct = ($canary['ran'] ?? false) ? (($canary['passed'] ?? null) === true) : true;
                        AtlasLoopConfidenceSample::create([
                            'proposal_id' => $proposal->id,
                            'predicted' => $predicted,
                            'correct' => $correct,
                        ]);
                    }
                } catch (Throwable) {
                    // telemetry never crashes a merge
                }
            }

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

            // ACDE B4b: o merge REAL também grava o CONTRATO DE ENTREGA provado (símbolos públicos alterados +
            // conjunto de consumidores via blast-radius + dimensões D2 resolvidas por máquina) como candidato
            // provider-safe de brain-feedback — o write-end do flywheel que o B3 lê. Mesma banda best-effort
            // pós-commit do trust-ladder: o merge já aconteceu e NUNCA depende disto. Flag default-OFF +
            // self-gated => no-op => byte-identical; embrulhado p/ um throw do recorder nunca desfazer o commit.
            if ((bool) config('atlas.loop.delivery_brain_feedback_enabled', false)) {
                try {
                    $deliveryQuality = is_array($proposal->quality) ? $proposal->quality : [];
                    (new AtlasLoopDeliveryContractRecorder)->record([
                        'target_path' => (string) $proposal->target_path,
                        'changed_symbols' => $this->deliveredSymbols($deliveryQuality),
                        'canary' => ($canary['ran'] ?? false) ? (($canary['passed'] ?? false) ? 'green' : 'red') : 'not_run',
                        'quality' => $deliveryQuality,
                        'commit_sha' => $commit,
                    ]);
                } catch (Throwable) {
                    // best-effort brain feedback — um recorder que falha nunca desfaz o commit durável.
                }
            }

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
            // POST-COMMIT RECONCILIATION (honest attribution). If the real git commit already landed in
            // main but a later step threw — the attribution save itself (a transient DB blip), the
            // impact-receipt build, the canary/receipt persistence — the durable truth is "merged": the
            // change IS in main. Leaving merged_to_main=false here would be a permanent attribution LIE
            // (the row says not-merged while main holds the commit; the next drain pass would only retire
            // it via git_apply_failed, never correcting the flag). Reconcile through the SAME governed
            // scope so the pétreo never-merge default is untouched — only governedSave can flip the flag,
            // so the mere SUCCESS of this flip proves it went through the one sanctioned door. Best-effort:
            // a reconcile that itself fails falls back to the prior cosmetic-mismatch behavior, no worse.
            if ($commitLanded) {
                $sha = $commit ?? $this->headSha($repoRoot);
                if ($this->reconcileMergedAttribution($proposal, $sha, $operatorApproved, $operatorId, $operatorReason)) {
                    return array_merge($base, [
                        'merged' => true,
                        'commit' => $sha,
                        'reason' => 'post_commit_reconciled:'.mb_substr($e->getMessage(), 0, 120),
                        'attribution_reconciled' => true,
                    ]);
                }
            }

            return array_merge($base, ['reason' => 'error:'.mb_substr($e->getMessage(), 0, 160)]);
        }
    }

    /**
     * Reconcile the durable attribution AFTER the real git commit already landed in main but a later
     * step threw before/while persisting it. Without this the row keeps merged_to_main=false forever
     * while main holds the commit — a permanent attribution lie that no later drain pass corrects (a
     * stale re-apply only RETIRES the row, it never flips the flag).
     *
     * Writes through {@see governedSave} ONLY, so the pétreo never-merge default holds: the model's
     * structural guard forces merged_to_main=false on any save outside the governed scope, so the mere
     * SUCCESS of this flip proves it went through the one sanctioned door. Idempotent — when the original
     * attribution actually persisted before the throw (a failure in a LATER step), re-stamping the same
     * values is a harmless no-op and additionally recovers any best-effort enrichment that did not land.
     * Best-effort: returns whether the reconcile write succeeded.
     */
    private function reconcileMergedAttribution(
        AtlasLoopProposal $proposal,
        ?string $commit,
        bool $operatorApproved,
        ?string $operatorId,
        ?string $operatorReason,
    ): bool {
        try {
            $this->governedSave(function () use ($proposal, $commit, $operatorApproved, $operatorId, $operatorReason): void {
                $quality = is_array($proposal->quality) ? $proposal->quality : [];
                $quality['_attribution_reconciled'] = [
                    'schema_version' => 'atlas.loop.attribution_reconcile.v1',
                    'commit' => $commit,
                    'reconciled_at' => now()->toIso8601String(),
                    'reason' => 'post_commit_save_failure',
                ];
                if ($operatorApproved && ! isset($quality['_operator_review'])) {
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

            return true;
        } catch (Throwable) {
            return false;
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
    /**
     * ACDE B4b — the changed public-symbol NAMES the certifier's symbol census persisted into the proposal's
     * quality envelope (lever #3), read defensively from any of the known key shapes. Names only — never a
     * body, never code. Empty when no census ran (B4b then records the contract without the symbol bonus).
     *
     * @param  array<string,mixed>  $quality
     * @return list<string>
     */
    private function deliveredSymbols(array $quality): array
    {
        $candidates = [
            $quality['_changed_symbols'] ?? null,
            $quality['changed_symbols'] ?? null,
            data_get($quality, 'symbol_census.changed_symbols'),
            data_get($quality, '_symbol_census.changed_symbols'),
        ];
        foreach ($candidates as $list) {
            if (is_array($list) && $list !== []) {
                return array_values(array_unique(array_filter(array_map(
                    static fn ($s): string => is_scalar($s) ? trim((string) $s) : '',
                    $list,
                ), static fn (string $s): bool => $s !== '')));
            }
        }

        return [];
    }

    /**
     * ACDE S1 — is this a SELF-IMPROVEMENT proposal (the loop editing its own harness)? Read the marker
     * defensively from the persisted quality envelope (the LossObserver / objective-builder write it). No
     * proposal carries it today (fromManifest strips it), so this returns false everywhere until S1 wires the
     * marker through — keeping the self-edit park gate byte-identical until then.
     */
    private function isSelfImprovementProposal(AtlasLoopProposal $proposal): bool
    {
        $quality = is_array($proposal->quality) ? $proposal->quality : [];

        return ($quality['_is_self_improvement'] ?? $quality['is_self_improvement'] ?? false) === true;
    }

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
            app(AtlasCompoundingRuntimeService::class)->recordExecution([
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
            app(AtlasChangeClassTrustLadder::class)
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
            // Route to a LIVE supervisor, not the proposal's originating campaign. The originating
            // campaign is almost always COMPLETED by merge time, and claimNextTask() is strictly
            // campaign-scoped — so a fix-forward queued there is unclaimable and main stays RED
            // (observed: 12/14 fix-forwards orphaned in dead campaigns; one regression sat ~107min).
            $task = $this->store->enqueueTask(
                $this->resolveFixForwardCampaignId((string) $proposal->campaign_id),
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
     * Resolve which campaign should OWN a fix-forward task. A canary-red regression is global
     * (it sits in main), but the task queue is campaign-scoped, so the fix-forward must land in a
     * campaign a LIVE supervisor will actually claim. Pick the freshest running, non-killed campaign
     * whose heartbeat is within the freshness window (a SIGTERM'd zombie keeps status=running but
     * carries kill_switch=true and a stale heartbeat, so it is excluded). Fall back to the
     * originating campaign only when nothing is alive — best-effort, preserving the prior behavior
     * for the no-supervisor edge case rather than dropping the task.
     */
    private function resolveFixForwardCampaignId(string $originatingCampaignId): string
    {
        try {
            $freshnessSeconds = max(60, (int) config('atlas.ai.loop.fix_forward_live_campaign_freshness_seconds', 1800));
            $floor = now()->subSeconds($freshnessSeconds);
            $live = AtlasLoopCampaign::query()
                ->where('status', AtlasLoopCampaign::STATUS_RUNNING)
                ->where('kill_switch', false)
                ->where('heartbeat_at', '>=', $floor)
                ->orderByDesc('heartbeat_at')
                ->value('id');

            if (is_string($live) && $live !== '') {
                return $live;
            }
        } catch (Throwable) {
            // fall through to the originating id — never let routing failure drop the fix-forward
        }

        return $originatingCampaignId;
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

            return app(AtlasLoopWiredCallerService::class)
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

        // SUBSTANCE FLOOR (flag-gated, default OFF) — block LOW-VALUE work from auto-merging. The
        // operator directive: only enormous refactors / big obras / big features, never tiny vanilla
        // clamps. Uses only objective data already in hand: touched_lines (impact receipt, diff add+del,
        // paddable only with dead lines), real callers (production FQCN grep), and the SYNTHESIZER-
        // stamped refactor discriminator quality._acceptance_contract (complexity_proof+metric_kind,
        // NOT provider-claimed). Refactor contracts are EXEMPT from the size/leverage arm because their
        // AST complexity drop was already enforced fail-closed at certification (complexityProofRequired).
        // Fail-CLOSED on the vanilla substance proof (merge boundary, blocked = re-discoverable, no loss);
        // fail-OPEN on unmeasured callers (mirrors the value-gate seam).
        $substanceFloor = ['enabled' => false];
        if ($passed && (bool) config('atlas.ai.loop.substance_floor_enabled', false)) {
            $touched = (int) data_get($receipt, 'size.touched_lines', 0);
            $quality = is_array($proposal->quality ?? null)
                ? $proposal->quality
                : (array) json_decode((string) ($proposal->quality ?? '{}'), true);
            $isRefactor = data_get($quality, '_acceptance_contract.complexity_proof') === true
                && (string) data_get($quality, '_acceptance_contract.metric_kind') === AtlasEvolutionFrozenJudge::METRIC_MINIMIZE;
            $minTouchedFloor = max(1, (int) config('atlas.ai.loop.substance_floor_min_touched_floor', 10));
            $minTouchedVanilla = max(1, (int) config('atlas.ai.loop.substance_floor_min_touched', 30));
            $minCallersVanilla = max(0, (int) config('atlas.ai.loop.substance_floor_min_callers', 2));

            if ($touched < $minTouchedFloor) {
                $passed = false;
                $reason = 'substance_floor_below_min_touched:'.$touched.'<'.$minTouchedFloor;
            } elseif (! $isRefactor) {
                if ($touched < $minTouchedVanilla) {
                    $passed = false;
                    $reason = 'substance_floor_vanilla_too_small:'.$touched.'<'.$minTouchedVanilla;
                } elseif (! ($unmeasured ? $failOpen : $callers >= $minCallersVanilla)) {
                    $passed = false;
                    $reason = 'substance_floor_vanilla_low_leverage:callers='.($callers ?? 'null');
                }
            }
            $substanceFloor = ['enabled' => true, 'touched_lines' => $touched, 'is_refactor' => $isRefactor];
        }

        return [
            'passed' => $passed,
            'impact_score' => $impactScore,
            'real_callers' => $callers,
            'unmeasured' => $unmeasured,
            'min_impact_score' => $minScore,
            'min_callers' => $minCallers,
            'substance_floor' => $substanceFloor,
            'reason' => $reason,
        ];
    }

    private function canary(string $repoRoot, array $changed): array
    {
        // ACDE lever #2 — default OFF restores TODAY'S behaviour byte-for-byte (first sibling only,
        // `artisan test`, return on the first match). When ON, exercise EVERY changed file's sibling and fail
        // CLOSED — the fix for the 12 canary-RED merges that leaked (files 2..N of a multi-file diff, and
        // sibling-less source, were never proven). The call site obeys the returned `block` flag, which under
        // the OFF path equals exactly the old `ran && passed===false` condition.
        if (! (bool) config('atlas.ai.loop.canary_full_coverage', false)) {
            return $this->canaryFirstSibling($repoRoot, $changed);
        }

        return $this->canaryAllSiblings($repoRoot, $changed);
    }

    /**
     * Today's behaviour (byte-identical to pre-lever-#2): the FIRST changed file with a sibling decides.
     * Resolve the sibling via the SHARED recursive resolver — NOT the old `tests/{Unit,Feature}/**\/X` glob,
     * whose `**` is NOT recursive in PHP and so only matched tests 0-1 dirs deep, MISSING the deep mirror
     * layout (tests/Unit/Ai/.../{Class}Test.php) — the reason canaries "rarely ran".
     */
    private function canaryFirstSibling(string $repoRoot, array $changed): array
    {
        $resolver = new AtlasLoopSiblingTestResolver($repoRoot);
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
            $passed = $p->isSuccessful();

            return ['ran' => true, 'passed' => $passed, 'target' => $siblingRel, 'block' => ! $passed];
        }

        return ['ran' => false, 'passed' => null, 'target' => null, 'block' => false];
    }

    /**
     * ACDE lever #2 — run EVERY changed file's behavioral sibling on ./vendor/bin/phpunit (NOT `artisan
     * test`: its autoloader-redeclare exit-255 hazard would spuriously retire good proposals once N siblings
     * run). Fail CLOSED: ANY sibling RED blocks the merge (the multi-file leak), and — with
     * canary_require_coverage ON — any changed app/**\/*.php source with NO sibling blocks too (an
     * unprovable source must not reach main). Stops at the first RED to bound runtime.
     */
    private function canaryAllSiblings(string $repoRoot, array $changed): array
    {
        $resolver = new AtlasLoopSiblingTestResolver($repoRoot);
        $requireCoverage = (bool) config('atlas.ai.loop.canary_require_coverage', false);
        $ranTargets = [];
        $uncovered = [];
        $redTarget = null;
        foreach ($changed as $file) {
            $sib = $resolver->resolve($file);
            if (! ($sib['has_sibling'] ?? false)) {
                if ($this->isCoverableSource((string) $file)) {
                    $uncovered[] = (string) $file;
                }

                continue;
            }
            $siblingRel = (string) $sib['sibling_path'];
            $p = new Process([PHP_BINARY, '-d', 'memory_limit=2048M', './vendor/bin/phpunit', $siblingRel], $repoRoot, null, null, 300.0);
            $p->run();
            $ranTargets[] = $siblingRel;
            if (! $p->isSuccessful()) {
                $redTarget = $siblingRel;

                break; // first RED is enough to block; stop burning time
            }
        }

        $coverageHole = $requireCoverage && $uncovered !== [];
        $block = $redTarget !== null || $coverageHole;
        $ranAny = $ranTargets !== [];

        return [
            'ran' => $ranAny,
            'passed' => $block ? false : ($ranAny ? true : null),
            'target' => $redTarget ?? ($coverageHole ? 'uncovered:'.implode(',', array_slice($uncovered, 0, 3)) : ($ranTargets[0] ?? null)),
            'block' => $block,
            'ran_targets' => $ranTargets,
            'uncovered' => $uncovered,
        ];
    }

    /** A changed first-party SOURCE file that ought to carry a behavioral sibling (app/**\/*.php, non-test). */
    private function isCoverableSource(string $file): bool
    {
        $f = ltrim($file, '/');

        return str_starts_with($f, 'app/') && str_ends_with($f, '.php') && ! str_ends_with($f, 'Test.php');
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
            .'$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();'
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
    ): void {
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
