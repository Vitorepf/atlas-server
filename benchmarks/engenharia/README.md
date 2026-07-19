# ⚙️ Engenharia

Benchmarks que rodam **nativo no seu Mac** (arm64, sem Docker x86, sem gambiarra). Status: **✅ provado** (clonado+rodou) · **🔵 a provar** (clonado, smoke pendente) · ⚠️ ressalva. A lista completa por área em [`../RODA-NO-MAC.md`](../RODA-NO-MAC.md).

## Integrados (já rodando)
- **live_code_bench** ✅ (link na pasta)
- **aider_polyglot** ✅ (link na pasta)
- **bfcl** ✅ (link na pasta)

## A clonar e provar

| benchmark | mede | fonte |
|---|---|---|
| **R2ABench** 🟡 | Especificar produto: transformar requisitos ambíguos em PRD com critérios de ace… | [repo](https://arxiv.org/abs/2604.06683) |
| **ArchBench** 🟡 | Tomar decisão de arquitetura de sistema com trade-offs explícitos: decompor em c… | [repo](https://arxiv.org/abs/2603.17833) |
| **EvalPlus** ✅ | geração | [repo](https://github.com/evalplus/evalplus) |
| **CRUXEval** ✅ | raciocínio de execução | [repo](https://github.com/facebookresearch/cruxeval) |
| **ClassEval** ✅ | geração de classe | [repo](https://github.com/FudanSELab/ClassEval) |
| **RepoBench** ✅ | completação repo-level | [repo](https://github.com/Leolty/repobench) |
| **CrossCodeEval** ✅ | completação cross-file | [repo](https://github.com/amazon-science/cceval) |
| **BigCodeBench** ✅ | geração c/ libs (⚠️) | [repo](https://github.com/bigcode-project/bigcodebench) |
| **DevEval** ✅ | geração repo-level (⚠️) | [repo](https://github.com/seketeam/DevEval) |
| **Long Code Arena** ✅ | contexto longo (⚠️) | [repo](https://github.com/JetBrains-Research/lca-baselines) |
| **REval** ✅ | raciocínio de execução | [repo](https://github.com/r-eval/REval) |
| **LocAgent** ✅ | localização de falha | [repo](https://github.com/gersteinlab/LocAgent) |
| **debug-gym** ✅ | depuração (⚠️) | [repo](https://github.com/microsoft/debug-gym) |
| **TestEval** ✅ | geração de teste | [repo](https://github.com/LLM4SoftwareTesting/TestEval) |
