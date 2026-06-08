# OBRA → Codex: Re-prova out-of-process (a fechadura final de TODA proposta)

> Anexo ao `HANDOFF-CODEX-unified-evolution-loop.md`. Esta é a obra de MENOR risco/maior ROI imediato.
> Comece por ela: ela blinda as **73 propostas já no disco** (candidatas → independentemente-verificadas) e vira a camada final de certificação pra tudo, inclusive a materialização P4.

---

## 0. O problema que ela resolve

O `AtlasEngineeringHonestyGate` é **in-process** — o MESMO processo que rodou o loop certifica a proposta. Self-verification tem ponto-cego correlacionado (eu mesmo vivi isso: minha suíte verde escondeu 6 furos do gate). A defesa real é uma re-prova **INDEPENDENTE, fora do processo**, num **checkout limpo**, idealmente por um **provider/contexto diferente** do que gerou a proposta. Quebra o ponto-cego correlacionado.

Já existe a skill `loop-proposal-adversarial-verify` que faz isso manual. **A obra é torná-la um gate automático** e um novo tier de status: `certified_for_review` (gate in-process) → `independently_verified` (re-prova out-of-process).

---

## 1. O desenho (5 passos, todos out-of-process)

Para cada proposta em `proposals.jsonl`:

1. **Checkout limpo independente.** `git worktree add` do **HEAD atual** (não o workspace poluído do loop) num temp. Aplica o `diff_text` da proposta. Se não aplicar limpo → REFUTADO (`does_not_apply_clean`). (Isto sozinho pega "só funcionou no workspace sujo do loop".)
2. **Re-roda a aceitação frozen, independente.** No checkout limpo, re-roda o MESMO verificador (`atlas:code:deadcode-check --path=` / `atlas:docs:lint-file --path=` / o teste P4). Tem que ir GREEN. Re-prova a métrica fora do processo.
3. **Diff-earned independente.** Stash do candidato no checkout limpo → o verificador tem que voltar a RED. Se ficar GREEN sem o diff → REFUTADO (`change_is_inert`).
4. **Refutação adversarial (N agentes independentes).** N agentes — **idealmente provider/modelo diferente** do gerador — cada um TENTA REFUTAR que a proposta é melhoria real e segura. Procuram: gaming, efeito colateral escondido, mudança cosmética, teste fraco, a remoção apaga algo sutilmente usado. Default refutado=true em dúvida. Maioria refuta → REFUTADO (`adversarial_majority_refute`).
5. **Sobreviveu a tudo → `independently_verified`.** Senão → `refuted` com a razão.

Saída: `independently_verified.jsonl` + `refuted.jsonl` no run dir. O painel passa a mostrar 3 tiers: tentadas → certificadas (in-process) → **independentemente-verificadas** (a que conta de verdade).

---

## 2. Por que é a fechadura universal
- Funciona pra **qualquer** modo (dead-code, docs, e P4 no futuro) — o passo 2 só re-roda o verificador frozen daquele modo.
- Pra dead-code/docs é **LEVE**: não precisa de `vendor/`/DB (o verificador é o analyzer/linter, não a suíte). Por isso é dias, não semanas.
- Pra P4 ela compõe com a materialização (passo 2 = a suíte no workspace materializado). **Materialização abre a porta; esta põe a fechadura boa nela.**
- O passo 4 (refutador de provider diferente) é o que de fato quebra o ponto-cego — é a vantagem out-of-process do Atlas sobre self-verification de provider único.

---

## 3. Onde plugar (arquivos)
- **Novo comando:** `atlas:loop:verify-proposals --run=<run-id> [--proposals=<path>] [--refuters=3] [--refuter-provider=<outro>] --json`. Lê `proposals.jsonl`, escreve `independently_verified.jsonl` + `refuted.jsonl`.
- **Reusa:** `AtlasEvolutionFrozenJudge` (re-rodar aceitação no checkout limpo) — o contrato `acceptance` já está em cada task; persista o `acceptance` junto da proposta se ainda não estiver, pra a re-prova ter o que re-rodar.
- **Novo:** um `CleanCheckoutVerifier` (git worktree de HEAD + aplica diff + roda + diff-earned). Registra o prefixo no `AtlasLoopResourceGate::PREFIXES` (reaper).
- **Refutadores:** via o provider pipe (provider-agnóstico), preferindo um provider DIFERENTE do gerador. Schema de veredito: `{refuted: bool, reason, confidence}`.
- **Painel:** `atlas:loop:unified:report` ganha a linha `independently_verified`.
- **Memória:** `claude-code-dynamic-workflows-review` já registra que self-verification tem ponto-cego correlacionado e que o guard out-of-process do Atlas é superior — esta obra É esse guard, automatizado.

## 4. Testes
- proposta legítima (uma das 37 de código morto) → `independently_verified`.
- proposta que só aplica no workspace sujo (forje uma) → `refuted: does_not_apply_clean`.
- proposta inerte (diff que não muda o verificador) → `refuted: change_is_inert`.
- proposta com efeito colateral escondido (forje) → maioria dos refutadores pega → `refuted`.

## 5. Primeiro uso concreto (faça isto primeiro)
Rode em cima do run que JÁ existe: `storage/atlas/loop/unified/run-20260608-133531-fe7dda/proposals.jsonl` (73 propostas). Saída esperada: a maioria vira `independently_verified` (o gate de 7 camadas já é forte), e qualquer uma que cair vira aprendizado real sobre o gate. **Isso transforma as 73 candidatas em verdades verificadas — o valor que está parado no disco agora.**
