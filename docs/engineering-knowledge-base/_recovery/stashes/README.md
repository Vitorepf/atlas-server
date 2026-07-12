# Recovered stash queue (local-main-only)

Auto-stash / cloud-agent stashes that still conflicted with current `main` after
the 2026-07-12 recovery. Patches are parked here so work is not lost; they are
**not** applied blindly (conflicts). Re-land as scoped commits on `main` only.

Critical ACOS Max auto-stash (ESP-06 / MAXI-05 / MAXI-07 + rivals --fast) was
already reapplied and committed on `main` — do not re-apply those.
