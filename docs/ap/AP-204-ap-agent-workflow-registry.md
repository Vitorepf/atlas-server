---
title: AP-204 AP Agent Workflow Registry
status: foundation-registry-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowRegistry.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowRegistryTest.php
depends_on:
  - AP-200
  - AP-201
  - AP-202
  - AP-205
  - AP-206..AP-239
---

# AP-204 - AP Agent Workflow Registry
## Proposito
AP-204 declara a ordem oficial do workflow de um agente trabalhando em APs.
Ele nao executa contratos; registra sequencia para humanos e IAs nao criarem fluxo paralelo.
## Posicao
Pertence ao Documentation Operating System e deve ser lido antes de abrir, validar ou fechar sessao documentada.
## Sequencia
### Trace Primario Do Agente
1. AP-200 monta handoff, intake, contexto alvo e checklist inicial
2. AP-201 traduz problemas documentais em propostas humanas de reparo
3. AP-202 decide se a sessao pode comecar
4. AP-205 valida o shape da evidencia final
5. AP-203 fecha a sessao com gate, shape valido e checklist

AP-203 continua terminal no trace primario para impedir revisao humana ou
integracao manual fingida dentro do mesmo trace.

### Cadeia Pos-Completion
Depois do completion, a revisao enterprise fica em cadeia separada:

1. AP-206 valida transicoes do trace primario
2. AP-207 audita o trace completo
3. AP-208 cria receipt de execucao
4. AP-209 prepara pacote de revisao humana
5. AP-210 normaliza decisao humana
6. AP-211 entrega para revisao de integrador
7. AP-212 exige evidencia de prontidao do integrador
8. AP-213 registra receipt de integracao manual
9. AP-214 prepara auditoria final
10. AP-215 normaliza closeout humano
11. AP-216 emite receipt read-only de aceite final
12. AP-217 valida preflight para futura camada release/evidence
13. AP-218 entrega pacote read-only ao futuro owner release/evidence
14. AP-219 molda candidate release/evidence para revisao humana
15. AP-220 registra decisao humana sobre o candidate
16. AP-221 registra receipt read-only da decisao do candidate
17. AP-222 valida readiness para futura execucao release/ledger
18. AP-223 planeja dry-run sem executar nem mutar estado
19. AP-224 revisa plano de dry-run sem executar
20. AP-225 sela resultado declarado de dry-run sem ledger write
21. AP-226 revisa resultado de dry-run sem executar release
22. AP-227 entrega handoff pos-dry-run sem criar job
23. AP-228 valida readiness do futuro consumidor
24. AP-229 normaliza decisao humana da readiness
25. AP-230 emite receipt read-only da decisao da readiness
26. AP-231 faz preflight de autorizacao de execucao
27. AP-232 normaliza decisao humana de autorizacao
28. AP-233 emite receipt read-only da autorizacao
29. AP-234 entrega handoff de autorizacao para futuro AP de execucao
30. AP-235 valida preflight de implementacao de execucao
31. AP-236 normaliza decisao humana da implementacao
32. AP-237 emite receipt read-only da implementacao
33. AP-238 valida preflight de ativacao sem ativar
34. AP-239 normaliza decisao humana de ativacao

## Saida
Schema: `atlas.ap_agent_workflow_registry.v1`  
Modo: `read_only_workflow_registry`  
Autoridade: `ap_agent_workflow_registry_only_no_execution`

## Campos Principais
- `workflow_id`
- `steps`
- `terminal_statuses`
- `required_validation_commands`
- `handoff_summary`
- `guardrails`

## Comandos Declarados
- focused tests
- docs-health
- architecture-validate
- architecture-readiness
- knowledge sync
- code intelligence index
- diff check

Os comandos sao apenas declarados. O registry nao executa nada.

## Guardrails
- nao escreve arquivos
- nao executa comandos
- nao avalia runtime
- nao cria fluxo paralelo
- nao substitui handoff
- nao substitui session gate
- nao substitui validation evidence contract
- nao substitui completion report

## Beneficio
Um Codex novo carrega a ordem correta antes de tocar no codigo, reduzindo
duplicacao de contrato, AP fora de hora e conclusao sem evidencia.

## Criterios de Aceite
- registry lista AP-200, AP-201, AP-202, AP-205 e AP-203 na ordem correta
- registry lista AP-206 ate AP-239 como cadeia pos-completion separada
- cada step declara componente, schema, proposito e bloqueios
- comandos de validacao aparecem como contrato declarativo
- guardrails impedem execucao ou escrita
