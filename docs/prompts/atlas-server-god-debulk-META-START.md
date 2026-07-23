# GPT 5.6 Sol · META — complementar plano GOD Debulk (atlas-server)

> Cole no Codex/Sol com **Acesso completo** · cwd `atlas-server` · branch `main` · esforço **extra alto**.  
> Missão: **só complementar o plano** até 10/10 da *experiência de IA* no server.  
> **Não** implementar refactors em massa nesta missão (exceto se o operador mandar `EXECUTE` explícito).

---

## `/goal` (cole literal)

```
ATLAS-SERVER · META PLAN COMPLEMENT · GPT 5.6 Sol · até cancelar.

Você está em atlas-server (Laravel/PHP 8.4), branch main, acesso completo local.
Missão ÚNICA: complementar e endurecer o PLANO até ficar 10/10 para IAs gerenciarem o server inteiro
(achar · caber no contexto · entender · editar seguro · evoluir · provar · docs-mapa).

NÃO é reescrever em Swift. NÃO é feature nova. NÃO é WAVE de produto. NÃO é “residual pass” docs vazio.

BASE OBRIGATÓRIA (leia nesta ordem):
1) docs/superpowers/plans/2026-07-22-atlas-server-god-debulk-COMPLETE.md
2) docs/superpowers/plans/2026-07-22-atlas-server-god-debulk-FILESYSTEM-100.md
3) docs/engineering-knowledge-base/atlas-cognition-operating-system.md (ownership mental)
4) docs/engineering-knowledge-base/atlas-autonomos-live-system.md (keep-list Loop)
5) docs/engineering-knowledge-base/atlas-ai-self-construction-os.md

ALVO 10/10 (falsificável — COMPLETE §1):
A Achar · B Contexto · C Entender · D Editar · E Evoluir · F Provar · G Docs-mapa
Done do META = plano + apêndices de achados cobrem 100% dos buckets do FILESYSTEM-100
com evidência por arquivo (não só nomes de pasta).

PROTOCOLO DE VARREDURA (pétreo):
- Trabalhe bucket a bucket na ordem do FILESYSTEM-100 (A1→A2→A3→A4→B*→T→D→I).
- Dentro do bucket: abra CADA arquivo do bucket (php/md/json/sh/… listados no walk).
- Leia o arquivo INTEIRO (todas as linhas). Se >2000 LOC, leia em fatias sequenciais sem pular.
- Para CADA arquivo, registre achados no ledger de complemento (schema abaixo).
- Depois do bucket: atualize o plano (COMPLETE e/ou FILESYSTEM-100 e/ou apêndice COMPLEMENT)
  com ações concretas novas que faltavam (bugs, duplicação, abstração, fusão, split, deletes,
  contratos, testes faltando, hops de CODEMAP, ownership conflict).
- PROIBIDO marcar Goal Done. PROIBIDO parar porque “já tem 478 buckets”.
- Só avance de bucket quando o apêndice do bucket tiver 1 linha de evidência por arquivo.
- Commits só docs do plano/evidence: docs(core): GOD-DEBULK-META <bucket>
  Zero diff em app/Tests de implementação nesta missão.

O QUE PROCURAR EM CADA ARQUIVO (checklist):
1) Densidade / godfile / peel inútil
2) Duplicação de lógica / façade gêmea / OS overlap (SelfConstruction×AAEOS×Evolution×Stewardship)
3) API pública desonesta (nome ≠ comportamento)
4) Abstração faltando OU abstração falsa (indirection sem ganho)
5) Candidato a FUSE (same-owner, <80 LOC, 1 caller) vs SPLIT (>800 hot / >2000 any)
6) Bug / invariante quebrada / TODO morto / dead code (rg callers=0)
7) Confiabilidade: falta characterization test do entrypoint
8) Complexidade acidental (nesting, god switch, copy-paste batches)
9) Seguro/soberania: vazamento provider-unsafe, paths sensíveis
10) Docs mentindo / archive no caminho de navegação

SAÍDA POR ARQUIVO (obrigatório no ledger):
path | loc | kind | findings[] | actions[] | 10/10 caps impacted (A-G) | evidence (trecho/símbolo)

SAÍDA POR BUCKET:
- gaps no FILESYSTEM-100 (ações que faltavam)
- ownership proposta
- ordered_worklist (SPLIT→…→CODEMAP)
- risk_notes
- test_gaps

ANTI-PADRÕES (halt):
- Implementar refactor “já que li”
- Fuse criando arquivo >800 hot / >2000
- Delete AtlasLoop* por prefixo
- PHP→Swift
- Commit docs(evidence) só com pass++ / vanity
- Declarar 10/10 sem evidência arquivo-a-arquivo do bucket
- Pular arquivos “porque são json/fixture” — fixtures também entram

Não peça permissão. Não pare. Continue no próximo arquivo/bucket até o operador cancelar.
```

---

## `/loop` (cole literal — rede 15–20m)

```
/loop 15m Leia docs/evidence/2026-07-22-atlas-server-god-debulk/META-LEDGER.md (crie se faltar) + FILESYSTEM-100.
Se bucket_in_progress incompleto → continue lendo o PRÓXIMO arquivo do bucket (linha a linha) e registre achados.
Se bucket completo sem apêndice → escreva apêndice + atualize plano + commit docs(core): GOD-DEBULK-META <bucket>.
Senão pegue o próximo bucket A1…I ainda sem meta_complete=true.
PROIBIDO implementar app/Tests. PROIBIDO Goal Done. PROIBIDO pular arquivo.
Não peça permissão. Não pare. Continue a varredura META.
```

---

## Anexos `@` sugeridos

```
@docs/superpowers/plans/2026-07-22-atlas-server-god-debulk-COMPLETE.md
@docs/superpowers/plans/2026-07-22-atlas-server-god-debulk-FILESYSTEM-100.md
@docs/prompts/atlas-server-god-debulk-META-START.md
```

(Crie `docs/evidence/2026-07-22-atlas-server-god-debulk/META-LEDGER.md` no primeiro ciclo se não existir.)

---

## Schema META-LEDGER (criar no 1º ciclo)

```yaml
mission: god-debulk-meta-complement
phase: audit
bucket: null
file_cursor: null
buckets_done: []
files_scanned: 0
lines_scanned: 0
meta_complete: false
notes: |
  planning-only
```

---

## Mensagem curta se a UI só tiver um campo (“Faça o que quiser”)

Cole isto como primeira mensagem (equivale a goal+loop):

```
META ONLY · atlas-server · complementar plano GOD Debulk até 10/10 IA (COMPLETE + FILESYSTEM-100).
Varra bucket a bucket, arquivo a arquivo, linha a linha; registre achados; atualize o plano; commits só docs.
Zero implementar app/Tests até eu dizer EXECUTE.
Leia @docs/superpowers/plans/2026-07-22-atlas-server-god-debulk-COMPLETE.md e @docs/superpowers/plans/2026-07-22-atlas-server-god-debulk-FILESYSTEM-100.md
Obedeça docs/prompts/atlas-server-god-debulk-META-START.md
Não pare. Não Goal Done. Próximo arquivo agora (comece WAVE A1 · maior godfile do primeiro bucket).
```
