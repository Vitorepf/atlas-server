# Cortex v+infinity Portability Proof

`AtlasCortexPortabilityProofTest` is the **existence proof** that Atlas Cortex is portable to a foreign repo. The test:

1. Creates a self-contained fixture repo on a `sys_get_temp_dir()` path
2. Plants three known artefacts inside it:
   - an **orphan**: a defined function or class never called
   - a **clone cluster**: two PHP files with a duplicated method body
   - a **doc-stated gap**: a `docs/` file mentioning a stub class that does not exist in source
3. Writes a `cortex.yaml` at the fixture's repo root with `schema_id: atlas.cortex.facts.v1` plus the required keys
4. Resolves `AtlasCortexUniversalContract` from the container
5. Loads the per-repo config via `AtlasCortexUniversalConfigLoader::load($fixtureRoot)`
6. Calls `$contract->comprehend($fixtureRoot, $config)` — the SAME entry point a foreign consumer would use
7. Validates the returned FACTS against `AtlasCortexUniversalFactsSchema::validate()` — must be `[]`
8. Asserts the planted orphan / clone / doc-gap symbols appear in the FACTS
9. Asserts the fixture is OUTSIDE `base_path()` and rooted under `sys_get_temp_dir()`
10. Cleans up the fixture in `tearDown()` so nothing leaks

## Why this proves portability

The fixture has **no Atlas internals**, lives **outside the atlas-server tree**, and is consumed via the **interface only** (`AtlasCortexUniversalContract`). The pass proves a foreign repo can be cortex-able with no special integration.
