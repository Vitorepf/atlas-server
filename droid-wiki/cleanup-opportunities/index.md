# Cleanup opportunities

This page collects observations about codebase structure, complexity, and dead ends. These are not priorities. The autonomous loop handles structural evolution 24/7, and the anti-Goodhart doctrine says behavior-preserving refactor is zero improvement. Cleanup for its own sake is not something a human contributor should pursue in this codebase.

These pages exist because a wiki should document what a reader will encounter when exploring the repo: large files, probe scripts, legacy directories, and TODOs. Knowing they are there and why they are there is more useful than pretending they are not.

## Pages

- [Complexity hotspots](complexity-hotspots.md) — the largest files, the 330 migrations, the 1,227 commands
- [Dead ends and TODOs](dead-ends-and-todos.md) — TODO/FIXME/HACK comments, root probe scripts, legacy self-improvement subsystems

## The autonomous loop handles this

The [Autonomous Evolution Loop](../systems/evolution-loop/index.md) exists to evolve scopes exponentially. Structural cleanup that improves real capability falls within its scope. A human contributor who sees a 52K-line file and wants to split it should first check whether the loop is already working on that scope, and whether splitting the file would improve real capability or just reduce line count. See [anti-Goodhart and no-proxy](../concepts/anti-goodhart.md).

## Related pages

- [Complexity hotspots](complexity-hotspots.md)
- [Dead ends and TODOs](dead-ends-and-todos.md)
- [By the numbers](../by-the-numbers.md) — codebase statistics
- [Anti-Goodhart and no-proxy](../concepts/anti-goodhart.md) — why cleanup is not a priority
