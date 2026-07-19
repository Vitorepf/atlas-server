# ⛔ Estacionados — precisam de x86 (ou GPU)

Estes benchmarks **não rodam confiável no Mac arm64**. As imagens de avaliação são
**x86/amd64** e rodam por **emulação** — que corrompe o resultado. **Provado:** o
patch GOLD (a resposta CORRETA) do SWE-bench Verified conta como *falha* aqui,
porque 5 testes sem relação com a tarefa quebram sozinhos na emulação. Rodar um
modelo/Atlas contra isto daria número mentiroso.

**Não estão descartados** — ficam prontos para quando houver x86 (uma máquina x86
ou nuvem só para a execução dos testes; o Atlas continua gerando o patch local).

## Suítes que já estavam integradas (agora estacionadas)
- [terminal_bench](terminal_bench/) — docker
- [swe_bench_live](swe_bench_live/) — docker x86
- [senior_swe_bench](senior_swe_bench/) — docker x86 (harbor)
- [swe_marathon](swe_marathon/) — docker x86 (harbor)
- [hal_harness](hal_harness/) — docker

## Candidatos que também precisam de x86/GPU
Toda a família SWE-bench (Verified, Pro, Multimodal, Multi-SWE, SWE-Lancer,
SWE-rebench, Commit0), terminal/DevOps (SetupBench, EnvBench, Repo2Run, AIOpsLab,
ITBench, InterCode), testes SWE-based (SWT-Bench, TDD-Bench, TestGenEval, UTBoost),
segurança/CTF (Cybench, CyberGym, CVE-Bench, BountyBench, SeCodePLT), ML pesado
(MLE-bench, RE-Bench, MLGym), multi-linguagem compilada (McEval), TheAgentCompany,
DA-Code. Online-judge (CodeElo, DebugBench) e nuvem (MCP-Universe) também.

Lista completa com o motivo de cada um em [`../LISTA-COMPLETA.md`](../LISTA-COMPLETA.md).
Ver memória `swe-bench-arm64-emulacao`.
