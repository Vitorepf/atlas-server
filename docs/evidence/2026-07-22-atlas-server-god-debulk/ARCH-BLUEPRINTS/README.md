# ARCH-BLUEPRINTS

Lane da ARQUITETURA (Claude). Um arquivo por capability: `<Capability>.md`.

Cada blueprint define, ANTES do split estrutural:

- owners que nascem (classe alvo + sufixo do vocabulário + teto de densidade)
- contratos entre owners (dependência one-way; proibido back-reference `setMother`)
- separação policy vs I/O vs projection
- padrão de projeto aplicado (catálogo declarativo, projetor puro, fail-closed policy, projection context)
- mapa finding-id (A1-SC-…) → owner destino
- status: `draft | approved` (operador aprova)

EXECUTE consome blueprint `approved` para SPLIT/OWNER/EXTRACT de monstros >5k LOC.
Sem blueprint da capability: EXECUTE segue nos itens não-estruturais — nunca inventa arquitetura própria.
