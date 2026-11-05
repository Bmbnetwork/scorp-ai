# SCORP AI

SCORP AI is an AI-powered payment incident investigation platform for online merchants.

PayPal supplies transaction evidence. The investigation engine evaluates that evidence with deterministic rules. Gemini explains the findings in plain language without inventing transaction facts. Investigations can be saved to local history, reopened, printed as a standalone report, and exported as JSON or CSV.

This repository is currently in **Phase 7: exportable report and demo polish**.

## Current scope

Phase 7 adds a printable/exportable single-investigation report and polishes the end-to-end demo, on top of the full workflow and local history.

It includes:

- One-action investigation: PayPal retrieval → deterministic rules → Gemini explanation (or labelled fallback) → local save.
- A **print-friendly standalone report** (`public/report.php?id=N`) rendering one saved investigation as a clean document with a Print / Save-as-PDF button.
- A **server-side export endpoint** (`public/api/export.php?id=N&format=json|csv`) returning a saved investigation as downloadable **JSON** (lossless) or **CSV** (human-readable, sectioned, RFC-4180 escaped, UTF-8 BOM for Excel).
- A pure, unit-tested `ReportExporter` (no DB, no network) so export formatting is deterministic and verifiable.
- Print / Export JSON / Export CSV actions in the result panel (enabled only when the investigation is actually saved) and on every history row.
- Polished empty / loading / error states, plus an error-state **Retry** that re-runs the same reference under the duplicate-request guard.
- Guaranteed honest labelling everywhere (UI, printable page, CSV): a deterministic fallback is never shown as AI; a failed required step never yields a report; an unsaved investigation never offers a working export.

Phase 7 does **not** add: new external integrations, cloud auth, refunds/disputes, fraud prediction, multi-tenancy, or schema changes.

## Important scope limitation

The workflow retrieves and evaluates **PayPal order references** using the PayPal Orders API. It does **not** treat an order ID and a transaction ID as interchangeable.

## Authority model

- PayPal is the source of truth for returned transaction fields.
- SCORP AI deterministic rules are the source of truth for rule findings.
- Gemini is an explanation layer only and never decides authoritative payment status.
- If Gemini fails validation, the app displays a clearly labelled deterministic fallback, not the invalid Gemini output.
- History, the printable report, and exports all preserve the original explanation label honestly (Gemini AI vs Deterministic fallback).

## Export formats

- **JSON** — lossless. The exact report object the UI renders when a saved investigation is opened (including `investigation_id`, `persisted`, and `saved_at`). Re-importing/reopening reproduces the same structure.
- **CSV** — human-readable, sectioned (REPORT, FINDINGS, EVIDENCE, RULE NEXT STEPS, EXPLANATION, FACTS, UNCERTainties, SUGGESTED NEXT STEPS, LIMITATIONS), separated by blank lines, RFC-4180 escaped, served with a UTF-8 BOM. The EXPLANATION section always carries an honest `Label` (`Gemini AI` or `Deterministic fallback (not AI)`). JSON remains the lossless format.

## Setup

1. Place the project in your local web root, for example `C:\xampp\htdocs\SCORP-AI`.

2. Copy the example configuration if you have not already:

   ```powershell
   cd C:\xampp\htdocs\SCORP-AI
   copy .env.example .env


---

## 4. Append to `README.md`

Open `README.md` and paste this block at the **very end** of the file (append‑only; nothing above it changes):

```markdown

---

## Project status: Finalized

All planned phases (0–8) plus the PayPal‑inspired white/blue UI theme are implemented; the project is feature‑complete with no further phases planned. This section is documentation‑only and changes no code, so the working app and theme are unaffected.

Reviewer / judge one‑pager: [`docs/FINAL_REPORT.md`](docs/FINAL_REPORT.md)
Pre‑stage proof runbook (work this before demoing): [`docs/PRE_DEMO_CHECKLIST.md`](docs/PRE_DEMO_CHECKLIST.md)
Guarantees + known limits: [`docs/hardening.md`](docs/hardening.md)
5‑minute honest rehearsal: [`docs/demo-script.md`](docs/demo-script.md)
Phase map + verification stance: [`PROJECT_STATUS.md`](PROJECT_STATUS.md)

Quick confirm (docs‑only phase; this just re‑proves the app still passes):

```powershell
cd C:\xampp\htdocs\SCORP-AI
& "C:\xampp\php\php.exe" bin\setup.php
& "C:\xampp\php\php.exe" tests\run-tests.php
& "C:\xampp\php\php.exe" -S localhost:8000 -t public