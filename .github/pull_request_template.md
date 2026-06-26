<!--
Atlas Server Pull Request Template
Fill the sections below. Be honest; reviewers can tell.
-->

## Summary
<!-- 1-3 sentences: what does this PR change and why? -->

## Type
<!-- check one by replacing [ ] with [x] -->
- [ ] feat — new capability
- [ ] fix — bug fix
- [ ] refactor — behavior-preserving restructure
- [ ] test — test-only change
- [ ] docs — documentation only
- [ ] chore — build / CI / deps

## Evidence
<!-- What proves this works? Reference a test name, a green CI job, a command output, or a reproduced trace. -->
- Test(s) added or updated: 
- Manual verification: 

## Risk & Rollback
<!-- What could break? How do we roll back if it does? -->
- Blast radius: 
- Rollback path: 

## Checklist
- [ ] `composer test` green locally
- [ ] `phpstan` / `pint` clean
- [ ] No secrets / PII in diff
- [ ] Affected docs / AGENTS.md updated if behaviour changed
- [ ] Feature-flagged if risky (`ATLAS_*_ENABLED`)
