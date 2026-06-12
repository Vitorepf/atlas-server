# T001 — Obra alvo e slices iniciais

## Leitura executada

Arquivos re-lidos no início do `/goal`:

- `docs/goals/fable-campanha-obra-atual/goal.md`
- `docs/goals/fable-campanha-obra-atual/state.yaml`
- `docs/fable-campanha-execution-prompt.md`
- `docs/fable-campanha-11-dias-nxm.md`

## Obra alvo confirmada

Primeira obra `em andamento` ou `pendente` no ledger canônico atual:

- **O-1 — Certification Sweep da espinha de engenharia + Marco Zero**
- Status no ledger: **em andamento** `(iniciada 11/06)`
- Prova já registrada para Marco Zero: `storage/app/atlas/evidence/marco-zero-fable-2026-06-11.json`, ledger `01KTWCPTNZQDQ2NGMD8G8NP83K` `(waste 94%, 72 propostas em quarentena, custo 0% medido, scorecard 0/11)`

Conclusão: a sessão **não pode avançar para O-2** até O-1 estar completa por DoD, captura e auditoria.

## DoD O-1 extraído do plano/prompt

O-1 só pode fechar quando todos os itens abaixo tiverem prova concreta:

1. Auditoria adversarial dos 4 pisos:
   - Dev pipe real: `AiProviderManager` + conductor + `WorkspaceMutatingProviders`.
   - Gates Forge.
   - Stack do Loop e drivers.
   - Compounding flywheel + capture quality gate.
2. Cada achado corrigido com regressão congelada **ou** triado como `[OPERADOR]` com evidência.
3. Marco Zero registrado no Evidence Ledger.
4. Re-prova independente verde.
5. Backlog com evidência fresca.
6. Ritual de captura quando aplicável:
   - testes de regressão verdes;
   - canon doc em `docs/engineering-knowledge-base` com frontmatter de cartografia válido quando houver doc nova/alterada;
   - `bin/atlas engineering knowledge sync --prune`;
   - `bin/atlas engineering knowledge index-code --prune --workspace "$(pwd)"`;
   - memória Atlas provider-safe registrada quando aplicável;
   - ledger atualizado em `docs/fable-campanha-11-dias-nxm.md`;
   - checklist DoD item por item.

## Slices iniciais propostos para O-1

### O1-S0 — Bootstrap/AOBG e mapa de evidência

Objetivo: rodar o bootstrap e contexto do cérebro para O-1 antes de qualquer implementação.

Comandos obrigatórios no workspace `/Users/vitorepf/develop/Atlas/atlas-server`:

```bash
/opt/homebrew/bin/php artisan atlas:ai:session-bootstrap --task="O-1 Certification Sweep da espinha de engenharia + Marco Zero" --json
bin/atlas open-brain context "O-1 Certification Sweep da espinha de engenharia + Marco Zero" --json
```

Prova: outputs JSON preservados em receipt/nota, com paths/docs/símbolos relevantes listados e gaps explícitos.

Stop if:

- bootstrap ou AOBG falha de forma bloqueante sem fallback CLI razoável;
- contexto aponta decisão `[OPERADOR]` necessária antes de auditar.

### O1-S1 — Integridade do Marco Zero

Objetivo: verificar se o Marco Zero já registrado é real, auditável e suficiente para o DoD.

Evidências mínimas:

- arquivo `storage/app/atlas/evidence/marco-zero-fable-2026-06-11.json` existe e contém dados coerentes;
- ledger/evento `01KTWCPTNZQDQ2NGMD8G8NP83K` rastreável;
- métricas citadas no ledger batem com o artefato ou com fonte resolvida;
- qualquer lacuna vira achado com correção/regressão ou triagem `[OPERADOR]`.

### O1-S2 — Sweep Dev pipe real

Objetivo: auditar `AiProviderManager`, conductor e `WorkspaceMutatingProviders` com mandato de refutar.

Prova esperada:

- paths/classes/tests envolvidos;
- contratos críticos identificados;
- regressões congeladas para achados corrigidos;
- achados grandes fora de escopo triados no backlog com evidência.

### O1-S3 — Sweep gates Forge

Objetivo: auditar os gates Forge que protegem execução pesada/prolongada.

Prova esperada:

- paths/classes/tests envolvidos;
- gates reais vs proposta/documentação;
- falhas corrigidas com teste ou triadas.

### O1-S4 — Sweep Loop stack e drivers

Objetivo: auditar runtime do Loop, drivers e pontos que alimentam a campanha de engenharia.

Prova esperada:

- commands/routes/jobs/services envolvidos;
- testes ou harnesses existentes;
- lacunas que afetam O-2 explicitadas.

### O1-S5 — Sweep compounding flywheel + capture quality gate

Objetivo: auditar se o flywheel/capture quality gate consegue converter execução em melhoria capturada, sem noise self-declared.

Prova esperada:

- caminho runtime da proposta → evidência → captura → memória/docs/index;
- testes/gates que impedem regressão;
- backlog com evidência para lacunas grandes.

### O1-S6 — Re-prova independente e backlog `[OPERADOR]`

Objetivo: quem mediu não pode ser quem criou. Reprovar independentemente correções/achados e separar riscos aceitos que exigem operador.

Prova esperada:

- comandos de teste reais;
- leitura direta de diffs/artefatos;
- lista de decisões `[OPERADOR]`, especialmente destino de riscos triados como aceitos em O-1.

### O1-S7 — Captura final O-1

Objetivo: converter N em M e fechar O-1 no ledger.

Prova esperada:

- suites do cluster tocado verdes;
- docs canônicos atualizados quando aplicável;
- KB sync e Code Intelligence index-code executados;
- memória Atlas provider-safe quando aplicável;
- ledger `docs/fable-campanha-11-dias-nxm.md` atualizado com prova;
- final Judge/PM audit com `full_outcome_complete: true`.

## Próxima tarefa escolhida

Ativar **T002 — Judge/Fable** para validar adversarialmente esta decomposição antes de implementação. A razão é que O-1 é load-bearing para toda a campanha e inclui medição/gates/imune; a execução precisa de uma decisão de risco antes de abrir Worker/Codex.
