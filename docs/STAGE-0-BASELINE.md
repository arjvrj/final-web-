# Stage 0 — Safety checkpoint

Recorded before any integration work. Nothing in `app/` has been modified.

## Backups

| What | Where | Verified how |
|---|---|---|
| Full app archive | `backups/reviewgrow-php-STAGE0-20261110-064224.zip` (1.4 MB) | extracted to `/tmp/restore-test` and diffed against the working copy — **0 differences** |
| Archive SHA256 | `c1310d3555dd880cc8c5929c2d5c7d90f01e0fd874181829927680cd3b840fce` | — |
| Schema snapshot | `backups/db-schema-snapshot.sql` (4,650 B, 8 tables) | extracted verbatim from `install.php` |
| Original purchase | `purchased-source/PURCHASED-ORIGINAL-feedback_delete.zip` | SHA256 `efd5ba16…f88388`, byte-identical to delivery |

**Database:** this sandbox has no MySQL server, so there is no live data to dump here. The schema
snapshot records the 8 tables exactly as the installer creates them. **Before you deploy, take a
real dump:** hPanel → Databases → phpMyAdmin → Export → Quick → SQL → Go, and store the file
outside the hosting account.

## Git

- Working tree: **clean**
- HEAD: `f1e40f7`
- Tag: `pre-integration` (rollback target for the whole integration)

## Baseline test results (must not regress)

| Suite | Result |
|---|---|
| Payment assertions (`tests/php/test_payments.php`) | **89 passed, 0 failed** |
| PHP syntax sweep (`tools/php-lint.mjs app`) | **29/29 files parse cleanly** |

Environment: Node v20.20.2, Python 3.13.16, php-wasm 0.2.0 (PHP 8.4.1 in WASM).

## Gate

Stage 0 passes: backup verified by restore, checkpoint tagged, tree clean, tests recorded.
Safe to proceed to Stage 1.
