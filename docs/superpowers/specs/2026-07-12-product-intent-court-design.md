# ProductIntentCourt — desenho

## Objetivo

Adicionar uma única court de Product Intent à família Product. Ela transforma a saída já existente de `AtlasProductTruthCompilerService` em um veredito tipado, determinístico e fail-closed para a cadeia Dev → Forge → Autônomos. Não haverá segundo compiler, tabela ou ledger.

## Contrato

`ProductIntentCourt::adjudicate(ProductIntentCase $case): ProductIntentVerdict`.

`ProductIntentCase` será imutável e conterá o pedido, modo, snapshot de Product Truth, referências de fonte, hash do world snapshot, autoridade e política de risco. O modo será apenas contexto de proveniência: não poderá alterar a decisão para o mesmo conteúdo factual.

`ProductIntentVerdict` será imutável e conterá `schema_version`, `status` (`admitted|revise|refused|held`), problema, usuário, valor, métrica, janela de observação, fontes, restrições, não-objetivos, hipóteses, incertezas, alternativas, falsificadores, efeitos colaterais, aceitação, políticas de release/outcome, world snapshot, hash canônico e blockers.

## Regras de adjudicação

- Pedido vazio ou sem problema/usuário/valor → `revise`.
- Métrica ou janela ausente → `revise`.
- Fonte/proveniência ou world snapshot ausente/stale quando exigido pelo risco → `held`.
- Aceitação contraditória com restrição, efeito colateral sem contenção ou ausência de falsificador → `refused`/`revise` conforme a falha.
- Só `admitted` pode emitir o evento `unit.frozen`; a court não emite claim, release ou outcome.
- Hash é derivado do payload canônico completo e replay idêntico produz o mesmo resultado.

## Composição e integração

`ProductIntentCourt` chama o compiler existente, normaliza seus campos para o contrato e aplica apenas validações deterministicamente verificáveis. O compiler continua sendo a fonte de extração. A integração inicial será por adapter explícito; consumers existentes podem continuar lendo o payload legado enquanto novos orders consomem o hash do veredito.

## Verificação

Testes cobrirão casos válidos, ausência de cada requisito, stale world, contradições, side effects, falsificadores, hash determinístico, replay e paridade de modo. A suíte focada incluirá Product Truth, IntentRouter e a court. Nenhuma persistência nova será criada; receipts/eventos usarão a infraestrutura canônica existente em uma etapa posterior.

## Rollback

O enforcement pode ser colocado em observe/hold sem alterar o compiler. Um caso sem veredito `admitted` nunca poderá gerar `ExecutionOrder` executável.
