# ceiling-360-114-industrial-170-ambiguous_bug-v3

Industrial execution fixture for local_fake harness validation.

## Objective
Triar e corrigir bug ambiguo industrial 170, separando fatos, hipoteses e fix minimo.

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