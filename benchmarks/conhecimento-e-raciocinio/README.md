# Conhecimento e Raciocínio

Benchmarks de **conhecimento puro, raciocínio e recusa apropriada** — não são
tarefas de engenharia. Ficam num intuito próprio (não em Engenharia de Software)
justamente porque medem outra coisa.

> **Estado do braço "com Atlas" aqui:** em aberto. O Atlas é executor de
> engenharia; o braço `with_atlas` honesto para uma Q&A exige um **runtime de
> resposta governado** (cérebro Atlas com Hermes por dentro, respondendo — não o
> `atlas:cli:dev` de código, que erra o enquadramento). Enquanto esse runtime não
> existir, estas suítes aparecem **"não medido"** para o Atlas — nunca um número
> de Hermes cru disfarçado. Ver `docs/rivals-warroom.md` §6/§7.

## Benchmarks

| benchmark | mede | repo oficial |
|---|---|---|
| [inspect_evals](inspect_evals/) | Q&A de conhecimento (MMLU), raciocínio (MUSR), recusa (coconot), seguir instruções (ifeval) | UKGovernmentBEIS/inspect_evals |
