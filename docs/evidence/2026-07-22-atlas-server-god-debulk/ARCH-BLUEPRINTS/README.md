# ARCH-BLUEPRINTS

Lane da ARQUITETURA (Claude). Um arquivo por capability: `<Capability>.md`.

**Norte-star de forma final (Núcleo GOD/SOTA):**  
`docs/evidence/2026-07-22-atlas-server-god-debulk/ATLAS-NUCLEUS-GOD-SOTA.md`  
→ 12 órgãos · três anéis (Núcleo/Coroa/Quarentena) · cobertura 100% do corpus.  
Blueprints abaixo são o **como mover monstro sem quebrar**; o Nucleus doc é o **para onde converge**.

Cada blueprint define, ANTES do split estrutural:

- owners que nascem (classe alvo + sufixo do vocabulário + teto de densidade)
- contratos entre owners (dependência one-way; proibido back-reference `setMother`)
- separação policy vs I/O vs projection
- padrão de projeto aplicado (catálogo declarativo, projetor puro, fail-closed policy, projection context)
- mapa finding-id (A1-SC-…) → owner destino
- status: `draft | approved` (operador aprova)
- órgão-alvo N1–N12 do ATLAS-NUCLEUS-GOD-SOTA (quando aplicável)

EXECUTE consome blueprint `approved` para SPLIT/OWNER/EXTRACT de monstros >5k LOC.
Sem blueprint da capability: EXECUTE segue nos itens não-estruturais — nunca inventa arquitetura própria.
