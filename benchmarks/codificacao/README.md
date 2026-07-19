# Codificação

Gerar, editar e raciocinar sobre código — o núcleo do agente de programação.
Todos rodam **nativo no Mac** (venv/toolchain, sem Docker x86). Detalhe e ressalvas
de cada um em [`../LISTA-COMPLETA.md`](../LISTA-COMPLETA.md).

## Integrados (rodando)
- [live_code_bench](live_code_bench/) — programação competitiva contamination-free
- [aider_polyglot](aider_polyglot/) — edição de código multi-linguagem

## Candidatos nativos a integrar
**Geração & raciocínio:** EvalPlus ✅ · CRUXEval ✅ · ClassEval ✅ · BigCodeBench ⚠️
**Repositório & multi-arquivo:** RepoBench ✅ · CrossCodeEval ✅ · DevEval ⚠️ · Long Code Arena ⚠️
**Depuração & localização:** REval ✅ · LocAgent ✅ · debug-gym ⚠️
**Testes:** TestEval ✅

> Codificação de **longo prazo** (resolver issue em repo real — família SWE-bench)
> é onde o agente mais se prova, mas **exige x86** (Docker) → ver
> [`../_estacionados-precisa-x86/`](../_estacionados-precisa-x86/).
