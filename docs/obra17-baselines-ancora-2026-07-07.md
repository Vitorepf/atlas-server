# Obra #17 — Baselines-âncora (linha de partida, T0.4)

Data: 2026-07-07 · Autoridade: Carta de Autonomia (Regra 4 — reversibilidade; **proibido fabricar**) + plano-mestre §T0.4.

> **Regra anti-fabricação (Carta R4 / plano §T0.4):** o Atlas mede sozinho a partir de eventos reais. Onde não há instrumento, o valor fica **VAZIO** — nunca inventado. Um número de vitrine ("≤3 turns" sem baseline) é pior que um vazio honesto: mascara a falta de medição.

## A tabela de partida

| Baseline-âncora | Valor | Fonte / status | Quem fecha o gate |
|---|---|---|---|
| **Colisões tardias** (retrabalho por decisão/refutação que devia ter aparecido na spec) | **10** | REAL — na obra #8, 10 refutações só apareceram NA execução, não na spec (plano-mestre §"Por que 3×"; `docs/obra-linha-acos-plano-de-execucao-2026-07-06.md`). | T2 (crítica adversarial de spec pega ≥1 antes da implementação) |
| **TPE de retomada** (turns até a 1ª edição correta ao retomar a obra ativa) | **VAZIO (não instrumentado)** | Proibido fabricar. Não existe instrumento hoje: a #8 não foi medida em turns de retomada. O contador nasce no T1 (pack de retomada + gate ≤3 turns). | T1 |
| **Perguntas evitáveis** (resposta já estava no registry → falha do ACOS) | **VAZIO (não instrumentado)** | Proibido fabricar. Precisa casar transcript × registry; sem instrumento hoje. Nasce no T2 (gate −≥50%). | T2 |
| **Eventos de evolução autônoma** (desde 2026-07-01) | **15** | REAL — `atlas:evolucao listar --desde=2026-07-01` (Diário append-only, hash-chained). | — (medida viva; sobe com a autonomia) |

## Por que dois VAZIOs, e por que isso é correto

O plano-mestre é explícito: *"Baseline ANTES do código: toda fase mede o estado atual real antes de prometer gate ... sem baseline, '≤3 turns' é vitrine."* Os dois contadores (TPE, evitáveis) **dependem de instrumentos que ainda não existem** — T1 traz o pack de retomada (que torna TPE mensurável) e T2 traz o casamento transcript×registry (que torna evitáveis mensurável). Publicar um número inventado agora violaria a Carta e a régua do operador. O VAZIO é o resultado honesto da medição: *"medi, e o instrumento não existe ainda"*.

O que **é** real e batido hoje: colisões tardias = **10** (histórico #8) e eventos de evolução autônoma = **15** na semana (Diário vivo). São essas as âncoras contra as quais T1/T2 vão provar melhora.

## Como cada gate consome esta linha

- **T1** mede TPE de retomada real (obra ativa) e prova ≤3 turns vs. este VAZIO virar número.
- **T2** mede perguntas evitáveis e prova −≥50%; e pega ≥1 das ~10 colisões tardias ANTES da implementação.
- A régua do operador (`atlas valor semana`) lê TPE | perguntas evitáveis | colisões — os três contadores rodando a partir do T1.
