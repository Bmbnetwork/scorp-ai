# SCORP AI — Final report

**Status:** Feature‑complete (Phases 0–8 + UI theme). This is the sign‑off document.
**Date:** 2026‑11‑03
**Audience:** reviewers, judges, and anyone evaluating whether this tool can be trusted with payment evidence.

## One‑line pitch

SCORP AI investigates a PayPal Sandbox order end‑to‑end — retrieve the evidence, evaluate it with deterministic rules, explain it with Gemini — and **refuses to lie about what it knows**: a missing AI layer is labelled as a fallback, a failed required step produces no report at all, and an AI explanation never overrides the payment status PayPal actually returned.

## The problem it addresses

Merchants chasing a payment incident bounce between the PayPal dashboard, ticket threads, and guesses. Generic AI summaries invent transaction IDs, declare "payment failed" when an order was merely *created*, or present model output as the verdict. The cost of a wrong claim here is real (wrong refunds, wrong escalations, lost trust). The hard part of an AI investigation tool is not generating prose — it is **not overclaiming**.

## How it works (one action, four stages)

1. **PayPal retrieval (required).** Server‑side OAuth + Orders API call for the supplied order reference. Normalized to a fixed evidence shape. If this fails, the run stops with an error and **no report is shown**.
2. **Deterministic rules (source of truth for findings).** Confirmed / warning / unknown severities, each carrying its own supporting evidence and a stable code. Rules never reinterpret a status the evidence does not support (e.g. `CREATED` with no capture is reported as *"not evidence that payment failed"*).
3. **Gemini explanation (interpretation only).** Generated strictly from the structured evidence + rule findings, with grounding checks that reject invented identifiers/amounts/currencies. On any failure (key, auth, rate limit, network, blocked, malformed, invalid, unsupported fact) the app substitutes a **clearly labelled deterministic fallback** inside an otherwise complete report.
4. **Local persistence + outputs (optional).** The report can be saved to a local MySQL/MariaDB history, reopened, printed as a standalone document, and exported as lossless JSON or human‑readable CSV. A metadata‑only audit log records *what ran and how it ended* — never the sensitive content.

All four stages run through a single orchestrated workflow, so the web endpoint, the CLI, and the tests exercise the same code path.

## The integrity model (the actual differentiator)

These are enforced in code and covered by automated tests, not asserted in marketing:

- **PayPal is authoritative for returned fields.** The UI, the printable report, and the CSV all keep PayPal evidence, SCORP AI findings, and the explanation visually and structurally separate.
- **Gemini never decides status.** It is labelled as interpretation; the authoritative status pill always comes from PayPal.
- **No fabricated reports.** A failed required step yields an error and a Retry — never a partial or invented "completed" report.
- **No fake AI.** When Gemini is unavailable or its output fails validation, the explanation is labelled *Deterministic fallback (not AI)* everywhere, including in exports. The app never presents fallback as AI.
- **Grounded output only.** Invented transaction identifiers, amounts, or currencies in model output are rejected and trigger the labelled fallback.
- **Metadata‑only audit.** The local audit log allow‑lists scalar fields at write time; amounts, statuses, findings text, AI text, secrets, tokens, request bodies, and IPs cannot reach it (unit‑tested). A failed audit write never invalidates a completed investigation.
- **Honest degradation, audited.** Each external dependency (PayPal required, Gemini optional, DB optional) fails closed and is recorded with a stable code, so an outage is visible rather than masked.

## Scope and limits (stated up front)

- Evaluates **PayPal order references** via the Orders API. An order ID and a transaction ID are **not** treated as interchangeable.
- **Sandbox only**, **local only**, **single‑user only**. No cloud auth, no multi‑tenancy, no production hardening claims.
- **No** automated refunds, reversals, or disputes. It recommends next steps; it takes no financial action.
- **No** fraud prediction. A `DECLINED` capture is reported as declined, with no fraud conclusion.
- Grounding validation is conservative; it cannot prove every natural‑language claim is fully grounded.
- Accessibility is a best‑effort local‑tool pass (focus management, live announcer, accessible names, table semantics, reduced motion) with documented limits — not a certified WCAG audit.
- Audit logging has no automatic retention (dated files bound growth; the operator deletes old files).

## Tech stack

PHP 8.0+ (no framework), vanilla HTML/CSS/JS, PayPal REST (Sandbox), Google Gemini API, MySQL/MariaDB via PDO (prepared statements only). Tests are a dependency‑free harness; the pure mapper/exporter/audit‑logger are unit‑tested without a live database or network.

## Run it (three commands)

```powershell
cd C:\xampp\htdocs\SCORP-AI
& "C:\xampp\php\php.exe" bin\setup.php          # secret-safe readiness check
& "C:\xampp\php\php.exe" tests\run-tests.php    # full suite, expect: 0 failures
& "C:\xampp\php\php.exe" -S localhost:8000 -t public   # then open http://localhost:8000