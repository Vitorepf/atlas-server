# Atlas Cortex Universal Contract (v+infinity)

`AtlasCortexUniversalContract` is the **public, portable** interface of Atlas Cortex. A consumer holding the interface can ask any Cortex backend to comprehend a repository and receive canonical FACTS — without depending on any Atlas-internal class, Laravel container type, Eloquent model, or storage path.

## Methods

```php
interface AtlasCortexUniversalContract
{
    public function comprehend(string $repoRoot, array $config): array;
    public function contractSchemaId(): string;
}
```

- `comprehend(repoRoot, config)` → canonical FACTS (inventory, orphans, clones, forbidden hits, doc-stated gaps, per-unit `level_vector`, named transitions). **Never a score** (pétreo invariant inherited from `AtlasLoopScopeComprehensionModel`).
- `contractSchemaId()` → returns the canonical schema id, currently `atlas.cortex.facts.v1`.

## Default binding

`AppServiceProvider` binds the interface to an inline adapter that delegates to `AtlasLoopScopeComprehensionModelBuilder`. The returned array is **byte-identical** to `AtlasLoopScopeComprehensionModel::toArray()` — this packet only introduces the interface and the wiring, no behavioral change.

## Pétreo invariants

- Parameter and return types are limited to PHP scalar/array primitives (no Laravel facade types, no Eloquent types, no Atlas-internal types leak through the signature).
- The interface declares **exactly two** public methods; backends MUST NOT add scoring methods or aggregate verdicts to satisfy the contract.
- Implementations DELEGATE; there is no duplicate of the comprehension model.

## Why

Today `AtlasLoopScopeComprehensionReadModel` is hard-bound to `storage_path('app/atlas/loop/comprehension')`. The universal contract lets downstream code depend on FACTS rather than that storage detail; future backends (SQLite, remote service, in-memory) can adapt to the contract without rippling through callers.
