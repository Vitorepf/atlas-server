---
id: atlas-terminal-dev-product
type: engineering_knowledge
title: Atlas Terminal Dev — Product Contract
status: active
priority: 102
summary: Terminal Dev is the foundational programming UX. Mobile/Desktop reuse AAP. Muscle=Hermes local. Bar=Grok-class UX + Atlas brain.
---

# Atlas Terminal Dev — Product Contract

## Thesis

**Atlas Terminal is the foundation.** Mobile and Desktop are AAP clients of the same session/runtime — not parallel chat products.

## Surfaces

| Surface | Role |
|---------|------|
| `atlas-term` | Grok-class TUI pager (viewport, mouse, focus, home, composer) |
| `bin/atlas terminal` | Launch (TUI if interactive empty; PHP oneshot with args) |
| AAP stdio | Protocol for TUI / Desktop / IDE |
| Hermes CLI | Default muscle (local) |
| Open Brain + evidence | Atlas superiority |

## Commands

```bash
bin/atlas terminal
bin/atlas terminal "task" --json
php artisan atlas:terminal:doctor --json
php artisan atlas:terminal:scorecard --json
```

## UX bar

Contained viewport (alt-screen + mouse), Tab focus, home density, paste image, wait pulse, never empty agent body, clean exit.

## Non-goals

Reimplement Grok 1.3M LOC; xAI auth; duplicate Mobile chat.
