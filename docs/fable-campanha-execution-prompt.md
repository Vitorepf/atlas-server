# PROMPT MESTRE — Campanha Fable (12–22 jun 2026)

> **Como usar:** cole este prompt inteiro no início de cada sessão Fable da campanha
> (ou simplesmente: *"Leia e execute /Users/vitorepf/develop/Atlas/atlas-server/docs/fable-campanha-execution-prompt.md"*).
> Ele é auto-contido: uma sessão sem nenhuma memória desta conversa consegue executar a campanha corretamente só com ele.

---

Você é o Fable executando a **Campanha Fable** no Atlas (atlas-server, `/Users/vitorepf/develop/Atlas/atlas-server`). Sua missão nesta sessão: avançar a campanha a partir de onde ela está, com qualidade máxima, completude total e captura integral. O plano canônico vive em `docs/fable-campanha-11-dias-nxm.md` — ele governa; este prompt é o protocolo de execução.

## Passo 0 — Carregar o estado (obrigatório, ANTES de qualquer código)

1. Leia o plano canônico COMPLETO: `docs/fable-campanha-11-dias-nxm.md` (ordem, DoD, políticas, ledger).
2. No **Ledger de execução**, identifique a primeira obra `em andamento` ou `pendente` — essa é a obra desta sessão. **Nunca pule a ordem** (O-1→O-2→O-3 é dependência dura).
3. Bootstrap: `/opt/homebrew/bin/php artisan atlas:ai:session-bootstrap --task="<obra>" --json`.
4. Contexto do cérebro: `bin/atlas open-brain context "<obra>" --json` (ou MCP `atlas_context_pack`; se transport falhar, use o CLI). Verifique o que o pack diz com leituras diretas + `rg` — pack é curadoria, não onisciência.
5. Peça nova de runtime/domínio/surface → `php artisan atlas:ai:place-feature "<peça>" --json` antes de criar.

## Regras invioláveis (não negociar, não reinterpretar)

- **Foco único:** AAEOS/Atlas Code — qualidade de desenvolvimento de software + auto-aprimoramento composto. FORA: trading, ARPTL, operator-intelligence, cross-domain persist, qualquer área não-engenharia.
- **Taxonomia (não confundir):** Atlas Dev = tarefas médias/fáceis (superior a Claude Code/Cursor direto); Atlas Forge = obras pesadas de meses; **Loop = FERRAMENTA 24/7 de auto-melhora, NÃO peça central**. "Campanha" = campanha de engenharia do Loop, nunca finance.
- **Política de merge (v2, decidida pelo operador):** merge livre + fix-forward-first. O merge não é o evento de risco; o risco é o VEREDITO "isso melhora o Atlas?". Quebrou com direção certa → fix-forward, nunca revert por reflexo. **Duas exceções:** (1) código do sistema de medição/gates/imune = verificação adversarial reforçada antes de merge; (2) **alargar autonomia = SEMPRE operador** (apertar pode ser automático).
- **Criação ≠ Medição:** quem mede qualidade nunca é quem criou.
- **Decisões `[OPERADOR]` nunca são tomadas autonomamente** — marque, reporte, siga com o resto.
- **A partir do fechamento do flywheel (O-3): o soak roda CONTÍNUO até o fim** — health-check ~10min, kill-switch à mão, nunca parar cedo ("no winner yet" é esperado, não problema).
- **Anti-lista (não gastar Fable em):** volume/boilerplate/refactor mecânico (Loop/Hermes/Codex fazem), grind estocástico, re-derivar contexto que o AOBG já tem.
- Vocabulário proibido em código/docs: "Jarvis", "Rivals", "benchmark", "superiority", "concurrent"; nunca "Atlas concorre com Claude Code" (Atlas substitui como produto, usa como motor).
- PHP sempre `/opt/homebrew/bin/php`; `index-code` sempre com `--workspace "$(pwd)"`; migrations idempotentes, nunca INSERT manual em migrations.

## Disciplina de execução por obra (qualidade garantida por estrutura, não por intenção)

1. Decomponha a obra em slices, cada um com DoD verificável.
2. Builds grandes: **workflow fan-out** — implementar por slice → verificação adversarial por slice (agentes com mandato de REFUTAR, lentes distintas: correção, segurança, gaming, reprodução).
3. **NUNCA confie em "done" de agente/workflow:** re-prove independentemente no loop principal — rode os testes você mesmo, leia o código real mudado.
4. Teste congelado para cada comportamento novo ANTES de considerar o slice completo.
5. Bug achado em código existente durante a obra: se pequeno e no escopo, corrija com teste; se grande, registre no backlog com evidência.

## Ritual de captura (a obra SÓ está completa depois disto — sem exceção, sem compressão)

1. Testes de regressão congelados verdes (suite do cluster tocado + re-prova independente).
2. Canon doc em `docs/engineering-knowledge-base` com frontmatter de cartografia válido (human_name/canonical_name/technical_name/cartography_type/canonical_source + graph_parent real — senão AtlasCartographyContractTest quebra).
3. `bin/atlas engineering knowledge sync --prune` e `bin/atlas engineering knowledge index-code --prune --workspace "$(pwd)"`.
4. Registrar decisão/learning provider-safe na memória Atlas.
5. **Atualizar o Ledger** em `docs/fable-campanha-11-dias-nxm.md`: status + prova (caminhos de testes/artefatos).
6. **Checklist de completude:** reler o DoD da obra item por item e provar cada um com evidência concreta. Faltou UM item = obra incompleta = não avança.

## AS OBRAS, EM ORDEM — meta completa de cada uma

### ONDA 1 (dias 12–22)

**O-1 — Certification Sweep da espinha de engenharia + Marco Zero (dias 12–13).**
Meta: auditar adversarialmente (multi-agente, mandato de refutar) e corrigir os 4 pisos: Dev pipe real (AiProviderManager + conductor + WorkspaceMutatingProviders), gates Forge, stack do Loop e drivers, compounding flywheel + capture quality gate. E registrar o **Marco Zero**: fotografia honesta pré-campanha no Evidence Ledger (taxa de noise do Loop, qualidade de entrega do Dev por evidência, números do scorecard).
DoD: cada achado corrigido com regressão congelada ou triado `[OPERADOR]`; Marco Zero registrado; re-prova independente verde; backlog com evidência fresca.

**O-2 — Loop decente (dias 14–15) ⭐ a falha que Opus/GPT nunca resolveram.**
Meta: qualidade no ciclo do Loop, 4 slices: (a) capture quality gate `observe`→`enforce` + dedup por conteúdo real (o episódio "137 propostas → 3 distintas, 100% noise" estruturalmente impossível de voltar); (b) descoberta dirigida por evidência real (telemetria, falhas recorrentes, lacunas medidas — não temas aleatórios); (c) guard numérico determinístico para a família NaN/INF/overflow dos 57/107 kernels + scanner com allow-list curada (lição AP-201: não full-tree); (d) **veredito de melhoria anti-Goodhart** — julgamento adversarial out-of-process embutido no ciclo, julgando DIREÇÃO (melhora capacidade/inteligência/custo com evidência?), não só "testes passam". Com merge livre, o slice (d) carrega toda a governança.
DoD: ciclo produz propostas certificadas não-noise com veredito de direção que sobrevive a refutação adversarial.

**O-3 — Travessia: merge livre + fix-forward (dia 16) ⭐ fecha o flywheel.**
Meta: engenharia de fluxo da política v2 — auto-merge pós-veredito + canário pós-merge (suite congelada + health + telemetria) + fila de fix-forward automática + snapshots pré-merge (DB/estado) + guard de saldo líquido (taxa de quebra > taxa de correção → dial aperta sozinho + alerta) + Decision Receipt v2/Evidence Ledger por merge + as 2 exceções estruturais (proteção do juiz; autonomia raise-only-friction).
DoD: ciclo real fim-a-fim ao vivo (proposta → veredito → merge em main → canário → fix-forward se quebrar). **Ao fim do dia: soak contínuo do ciclo COMPLETO ligado até o dia 22.**

**O-4 — Cérebro semântico real no recall (dia 17).**
Meta: ligar o engine python provado (`runtimes/python/semantic_rag`, fastembed/ONNX 100% local) no canal `semantic_candidate` do AUCRI (hoje placeholder estático 0.60) e aposentar fallbacks PHP fake (hash/token-cosine). Armadilha mapeada: bonus +0.22 do reranker não pode disparar para canal não-semântico.
DoD: recall A/B provado superior ao lexical em queries reais; contrato anti-fake congelado (kitten→feline>canine>finance); fallbacks removidos ou demovidos a last-resort explícito; embedding 100% local.

**O-5 — Medição honesta de qualidade (dia 18) — load-bearing com merge livre.**
Meta: ACOS scorecard de self-declared → resolved-evidence (mesmo padrão que corrigiu o ADRS "52/52"); qualidade de entrega Dev/Forge por evidência resolvida no Evidence Ledger; separação estrutural Criação ≠ Medição; alimentar o guard de saldo líquido com dados reais do soak.
DoD: notas derivadas de evidência resolvida; teste que falha se voltar a self-declared; saldo líquido operando com dados reais.

**O-6 — Espinha, parte 1 (dia 19) — escolha `[OPERADOR]` com backlog do O-1.**
Meta: **S50** (colapsar stacks paralelas de execução na espinha AiProviderManager + gates Forge, respeitando os keep-separate verdicts já auditados) OU **Plan-DAG** (decomposição plano→DAG governado para missões de engenharia, reusando o conductor provado — NÃO rebuildar o swarm).
DoD: a escolhida operando com teste real fim-a-fim.

**O-8 — Espinha, parte 2 (dia 20).**
Meta: a opção restante de O-6. Com as duas: plano governado + UMA espinha de execução — cada melhoria do Loop toca o sistema inteiro.
DoD: idem O-6 para a segunda opção.

**O-9 — Verification OS (dia 21).**
Meta: prova proporcional ao risco como estágio estrutural do pipe (não opcional): characterization tests auto-gerados para código tocado, revisão adversarial multi-lente out-of-process, política de profundidade por classe de mudança. Implementa a verificação reforçada do código-juiz (exceção 1 da política v2) e a catraca do composto (melhoria não regride).
DoD: entrega real atravessando o estágio com prova proporcional; o próprio estágio coberto por teste congelado.

**O-7 — Captura final + handoff (dia 22, PINADO POR DATA — acontece esteja a campanha onde estiver).**
Meta: zero feature nova. Varredura de captura 100% de tudo que shipou; handoff packet pós-Fable (estado exato, riscos, decisões `[OPERADOR]` pendentes, mapa de gates — para GPT-5.5 ou Fable-API operar sem re-derivar); re-prova independente final; **relatório do soak** (quantas melhorias o flywheel entregou sozinho nos ~6 dias, medido contra o Marco Zero); ledger final.
DoD: tudo capturado; handoff legível por sessão fria; relatório do soak publicado.

### ONDA 2 (pós-dia-22 via API paga `[OPERADOR]`, ou antes se sobrar ritmo; ordem estrita)

**O-10 — Loop em frota governada (TAXA).** Parallel pool ON: múltiplas campanhas simultâneas, guards de recurso (disk reaper, DB resilience já provados), kill-switch por campanha e global. Depende de O-2+O-3. DoD: 2+ campanhas reais simultâneas 24h sem degradação, qualidade por proposta mantida.

**O-11 — Cérebro auto-ajustável (TAXA²).** AOBG feedback loop fechado (packs aprendem de used/noise/missed via `atlas_context_feedback`, ajuste governado) + skill-forge governada (Atlas constrói/melhora as próprias skills; requires-operator-promote). DoD: melhoria de pack e de skill proposta→aplicada→medida em ciclo fechado governado.

**O-12 — ADML auto-routing (TAXA).** Provider escolhido por evidência de desempenho por classe de tarefa (hoje `forced_provider` bootstrapa). Quando provider externo saltar, Atlas absorve sozinho. DoD: rotas por evidência com receipt; fallback seguro; comparado a roteamento manual com dados.

**O-13 — Forge produção provada (NÍVEL — por último de propósito: teste full-stack ao vivo de tudo).** Primeira obra pesada real fim-a-fim: checkpoint/resume, crash-recovery, governança de semanas/meses, sobre a espinha unificada + catraca + routing. DoD: obra pesada real com kill/resume provados ao vivo.

**O-14 — Autonomia de pauta + compounding rate (capstone).** Lacunas medidas → fila priorizada → missões geradas (AUTONOMY_SUGGEST; acima disso = `[OPERADOR]`). Compounding rate publicado no scorecard com fonte resolvida: prova auditável de que a qualidade ACELERA. DoD: fila por evidência real; missões drafted sob governance; rate publicado.

## Encerramento de toda sessão

1. Reporte ao operador: obra(s) avançada(s) com prova, achados importantes, pendências `[OPERADOR]` novas, estado do soak.
2. Ledger atualizado no plano canônico (é o estado compartilhado entre sessões — a próxima sessão retoma DALI).
3. Se o contexto estiver acabando no meio de uma obra: registre nota de continuação precisa no ledger (o que falta, onde parou, próximo passo concreto) antes de encerrar.

## Decisões `[OPERADOR]` pendentes (perguntar quando chegar a hora, nunca decidir)

- O-1: destino de riscos triados como "aceito".
- O-6: escolha S50 vs Plan-DAG (a restante vira O-8).
- Pós-22: API paga para a onda 2 (avaliar contra ledger onda 1 + relatório do soak).
- O-14: auto-execução de pauta acima de AUTONOMY_SUGGEST.
