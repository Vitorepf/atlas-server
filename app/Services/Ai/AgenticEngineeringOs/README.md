# AgenticEngineeringOs (AEOS)

## Layout
- **Gates/** — Universal gates façade + GateObserveSection01–09 (ACOS floor peels, not byte-clones)
- **Maturity/** — docs-as-law / department maturity (re-homed from Aaeos)
- **Scoring/** — pure scorers
- **Support/** — normalizers

## Naming
- Prefer **Aeos** (one a) for AEOS types; avoid implying AAEOS control plane.
- AAEOS control plane lives in `app/Services/Ai/Aaeos/{Control,Spine}` only.

## Density note
GateObserve sections share large import surfaces; further DRY = GateObserveCollaborators (planned). Do not change observe *semantics* without golden tests green.
