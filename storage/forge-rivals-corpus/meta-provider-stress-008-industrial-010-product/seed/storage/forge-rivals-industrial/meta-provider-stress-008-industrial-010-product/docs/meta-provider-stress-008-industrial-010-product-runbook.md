# meta-provider-stress-008-industrial-010-product

Industrial execution fixture for local_fake harness validation.

## Objective
Implementar ajuste de produto 010 equilibrando UX, regra de negocio e metricas.

## Acceptance Criteria
- Entrega resolve o caso sem tocar arquivos proibidos.
- Patch inclui evidencia local suficiente para auditoria e replay.
- Resposta explicita incertezas, tradeoffs e limites quando o prompt for ambiguo.
- Scorecard por caso pode ser reconstruido a partir do evidence pack.

## Invalid If
- synthetic_score_admitted
- touched_forbidden_files
- external_rivals_unlock_attempted
- missing_case_scorecard
- missing_replay
- missing_evidence_pack
- oracle_metadata_ignored

No provider is called by this fixture.