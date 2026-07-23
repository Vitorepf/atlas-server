# Intuito do plano GOD Debulk · atlas-server

> Documento de alinhamento. Se algo faltar aqui, o META prompt e o plano estão errados.

## Para que serve este plano

Tornar o **atlas-server inteiro** (100% do corpus) o ambiente onde **qualquer IA** consegue, de ponta a ponta:

1. **Achar** o ponto certo em ≤3 hops  
2. **Carregar no contexto** o módulo quente sem godfile  
3. **Entender** ownership e invariantes  
4. **Editar** com prova (teste) sem quebrar o cérebro  
5. **Evoluir** sem criar a N-ésima OS layer gêmea  
6. **Provar** com gates  
7. **Navegar docs** como mapa, não arquivo morto  

**Não serve para:** reescrever PHP→Swift, inventar produto/WAVE, “parecer limpo” contando arquivo, vanity `pass++`, deletar keep-list `AtlasLoop*` por prefixo.

**Serve para:** eliminar inchaço estrutural e elevar a **lógica + forma + confiabilidade** ao nível **ultra GOD agent-optimal** — o mesmo espírito do atlas-native, no server Laravel/PHP, no **repo inteiro**.

Nota alvo: **10/10** em todas as capacidades de IA (A–G), com critérios falsificáveis.

---

## Lista completa do que o plano DEVE cobrir

Cada item abaixo é **obrigatório** no plano (e o META deve procurar evidência arquivo a arquivo).

### A. Forma / estrutura (anti-inchaço)

1. **Delete morto** — código sem caller (`rg`=0), stubs, imports mortos, fixtures órfãs  
2. **Rename honesty** — nome = papel real (classe, método, pasta, command)  
3. **SPLIT godfiles** — >2000 any; hot façade/command/http >800  
4. **FUSE / fundição** — peels same-owner, <~80 LOC, 1–2 callers, ↓hops  
5. **Anti-fuse cego** — nunca criar novo godfile; SPLIT antes de fuse em massa  
6. **Um domínio / um owner** — OWNERSHIP sem entrypoint duplo  
7. **Colapso de OS layers gêmeas** — SelfConstruction × AAEOS × AutonomousEvolution × Stewardship × Programming (façades)  
8. **Long-tail peels** — fundir ou justificar  
9. **Config split** — `config/atlas.php` e configs densas ≤800  
10. **Command surface** — thin CLI → façade; famílias ≤12 documentadas  
11. **Http thin** — controllers finos; lógica no service/façade  
12. **Providers/bindings** — wiring claro por owner, sem god-provider  
13. **Jobs/queues** — thin; sem domínio escondido no job  
14. **CODEMAP 100%** — hot paths → `Class::method`  
15. **Vocabulário fechado** — suffixes + famílias `decide*` / `pack*Context` / `rank*` / `certify*` / `project*` / `run*`  

### B. Lógica / qualidade GOD

16. **Simplificação de lógica** — menos branches, menos god-switch, menos batch copy-paste  
17. **Abstração real** — extrair só com 2º consumidor ou invariante clara  
18. **Des-abstração** — matar indirection falsa (wrapper sem ganho)  
19. **Deduplicação** — uma implementação da regra  
20. **Invariantes explícitas** — asserts/policies nomeadas, não magia  
21. **Contratos estáveis** — payloads/JSON shapes com characterization  
22. **Separação policy vs I/O vs projection** — Judgment/Policy puro ≠ Runtime  
23. **Idempotência** de commands/runtimes onde couber  
24. **Failure modes honestos** — erros tipados, sem engolir exceção  
25. **Complexidade ciclomática / nesting** — reduzir hot paths  
26. **Tipagem / static analysis** — PHPStan/Larastan sem regressão no pacote  
27. **Strictness** — tipos, null-safety, enums vs strings mágicas  

### C. Confiabilidade / bugs / prova

28. **Eliminar bugs conhecidos e descobertos na varredura** — com teste que falha→passa  
29. **Characterization tests** de todo entrypoint público tocado  
30. **Split test monsters** — 0 test file >2000  
31. **Orphan tests** — apagar ou religar  
32. **Golden / fixtures** — dono claro; fixtures no CODEMAP dados  
33. **Gates** — test paralelo do pacote + guard densidades + codemap-verify  
34. **Rollback/alias** — façade antiga thin ≤1 ciclo quando renomear  
35. **Race/concurrency** — filas, locks, double-dispatch  
36. **Determinismo** — tirar flake (time, order, entropy) dos testes quentes  

### D. Performance / otimização

37. **Hot path latency** — N+1, loops bobos, reparse, double load  
38. **Query/IO efficiency** — Postgres, files, HTTP externos  
39. **Cache correctness** — invalidação; sem cache mentiroso  
40. **Compaction/context cost** — packs menores sem perder soberania de sinal  
41. **Startup/artisan cost** — commands e providers não carregar o mundo  
42. **Remover trabalho morto em runtime** — scans/full-table no request path  

### E. Segurança / soberania / governança

43. **Provider-safe** — nada sensível em logs/projections/MCP out  
44. **Paths secret/cyber** — nunca vazar da máquina  
45. **AuthZ/policy** — permission engine coerente  
46. **Vocabulário constitucional** — zero Jarvis/Rivals/benchmark/superiority em código/doc novo  
47. **Keep-list Autônomos** — não deletar `AtlasLoop*` vivo por prefixo  
48. **ACDE/`atlas:loop:*`** — fora do operate path; migrar callers com prova  
49. **Evidence honesty** — ledger sem vanity pass  

### F. Docs / descoberta humana+IA

50. **Docs como mapa** — START_HERE → OWNERSHIP → CODEMAP ≤3 hops  
51. **Archive quarantine** — fora da navegação viva  
52. **Split docs canônicos >1200**  
53. **Marcar LEGADO vs VIVO** (loop docs, planos mortos)  
54. **Placement rule** — pasta/serviço novo só com OWNERSHIP + 2º consumidor  

### G. Superfície completa do repo (100%)

55. **app/Services/Ai** (120 buckets A1–A4)  
56. **app/Services non-Ai** + root singles  
57. **app/Console · Http · Models · Jobs · Providers · Support · Enums · …**  
58. **tests/** (Unit/Feature/Fixtures/hooks/…)  
59. **docs/** (ekb, ap, goals, contracts, …)  
60. **database · config · routes · scripts · bin · bootstrap · public · resources**  
61. **Todo path do walk** (php/md/json/sh/css/js/csv/patch/…) no FILESYSTEM-100  

### H. Protocolo de execução do plano (para IAs não estragarem)

62. **SPLIT before fuse**  
63. **Goal até cancelar** / sem Goal Done cedo  
64. **Zero implementação no META** (META = complementar plano)  
65. **EXECUTE** só com ordem explícita do operador  
66. **Commits escopados** `refactor(core)|test(core)|docs(core): GOD-DEBULK…`  
67. **main local only**  
68. **Child plan por bucket/WAVE** na execução  
69. **Scoreboard A–G** só fecha em 10/10 com gates verdes  

---

## O que NÃO entra (anti-intuito)

- Big-bang PHP→Swift  
- Feature/produto novo “já que estamos mexendo”  
- Chase de file-count / LOC cego  
- Fuse cosmético / rename-only como vitória  
- Micro-tipografia mental (server não é casca)  
- Destruir capacidade viva para “parecer simples”  

---

## Confirmação de entendimento

**Intuito:** um plano (e depois execução) que deixa o atlas-server **inteiro** agent-optimal ao nível ultra GOD: sem inchaço, lógica simples e forte, confiável, testada, encontrável, evolúvel — cobrindo **todos** os itens 1–69 acima, com prova 10/10 nas capacidades A–G.

Se o operador concordar com esta lista, o **prompt META** deve obrigar a Sol a vasculhar arquivo a arquivo e **complementar o plano** até cada item ter evidência/ações no plano — sem implementar.
