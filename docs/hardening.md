# SCORP AI — Phase 8 hardening notes

This document records the safety, accessibility, and operability guarantees added in Phase 8. It is the reference for "what is guaranteed" during a demo or review.

## 1. Audit logging (metadata-only, non-fatal, local)

### What is logged

One JSON line per terminal outcome of each request, to `var/audit/audit-YYYYMMDD.log`:

- `ts` — UTC ISO-8601 timestamp.
- `event` — e.g. `investigate.complete`, `investigate.failed`, `investigate.rejected`, `investigate.error`, `history.list`, `history.open`, `history.remove`, `history.unavailable`, `history.error`, `export.complete`, `export.rejected`.
- `request_id` — a random 16-hex correlation id (not derived from any user data).
- `duration_ms` — integer milliseconds for the request.
- Plus, only when present and allow-listed: `reference`, `outcome`, `error_code`, `http_status`, `format`, `id`, `persisted`, `ai_origin`, `ai_available`, `step`, `count`, `limit`, `window`.

### What is never logged

The logger enforces an allow-list at write time. Even if a caller passes them, the following are dropped and cannot reach the file:

- amounts, currencies, order/capture statuses, intents;
- finding titles/details, evidence values, rule next-step text;
- Gemini summaries/facts/uncertainties/limitations, or any AI free text;
- PayPal or Gemini error messages (only their stable `error_code` is kept);
- API keys, client secrets, access tokens, passwords;
- raw request bodies or raw API responses;
- IP addresses or any other network identity.

This is verified by unit tests (`AuditLogger drops disallowed key ...`, `... does not contain a passed secret value / free text / IP / nested data`).

### Non-fatal guarantee

`AuditLogger::log()` never throws. If the directory cannot be created or the write fails, it returns `false` and the investigation/response is unaffected. A failed audit write can never invalidate a completed investigation. Verified by `AuditLogger log returns false (no throw) when the directory cannot be created`.

### Disable + retention

Set `AUDIT_LOG_ENABLED=false` to disable (writes nothing, creates no directory). Files are dated, which bounds growth per day; `var/` is git-ignored; delete old `audit-*.log` files freely. There is no automatic deletion — that is an operator choice, stated honestly.

## 2. Accessibility (app + printable report)

Measures added in Phase 8:

- **Focus management.** On a result, focus moves to the report heading (`#result-title`, `tabindex="-1"`) and the panel scrolls into view; on an error, focus moves to `#error-title`. Screen readers announce the new state without the user hunting for it.
- **Single live announcer.** `#sr-announcer` (`role="status"`, `aria-live="polite"`) carries export and history status messages. The status panel itself is already `aria-live`, so loading/error transitions announce automatically; the result is announced via focus. This avoids double-announcement.
- **Accessible names on repeated controls.** Every history row's Open / Print / JSON / CSV / Remove button gets a unique `aria-label` including the reference or id (e.g. "Open investigation ORDER123456"), so a screen-reader user hears which row a control acts on. The result panel's Print / Export buttons are labelled with the saved id too.
- **Tables.** `scope="col"` on header cells and `aria-label` (plus a visually-hidden `<caption>` on the report's capture table) so relationships are announced.
- **Keyboard.** All controls are native `<button>`/`<a>`/`<input>` and reachable in order; the skip link jumps to `#main`; Escape clears a field error; the Retry button is focusable and labelled.
- **Visible focus.** `:focus-visible` outlines on links, buttons, inputs, summaries, and the programmatically-focused headings.
- **Colour is never the only signal.** Every pill/legend dot is paired with text ("Confirmed", "Warning", "Gemini AI", "Deterministic fallback (not AI)", etc.).
- **Reduced motion.** `prefers-reduced-motion` disables the spinner animation and smooth scrolling in both the app and the report; JS scrolling respects it.
- **Meta.** `lang="en"`, `color-scheme`, sensible heading order, `noindex/nofollow`.

Known limits (stated honestly): this is a local single-user tool, not a certified WCAG audit; contrast was chosen for readability but not measured against every theme; the dynamic findings/explanation lists are announced via focus + reading order rather than per-item live regions. These are acceptable for a local demo and would be revisited before any public deployment.

## 3. Rate limits + error budget

Every local limit is configurable, with safe defaults compiled in:

| Endpoint | Keys | Default |
|---|---|---|
| Investigation | `RATE_LIMIT_RETRIEVE_MAX` / `_WINDOW` | 10 / 60s |
| History | `RATE_LIMIT_HISTORY_MAX` / `_WINDOW` | 60 / 60s |
| Export | `RATE_LIMIT_EXPORT_MAX` / `_WINDOW` | 30 / 60s |

"Error budget" here means bounded, honest degradation rather than a numeric budget: each external dependency fails closed and is *counted* in the audit log, never silently:

- **PayPal (required).** Any failure → error response, no report, audit `investigate.failed` with the stable error code. The app never fabricates a report and never claims payment failed when the evidence does not show it.
- **Gemini (optional explanation).** Any failure (missing key, auth, rate limit, network, blocked, malformed, invalid, unsupported invented facts) → a clearly labelled deterministic fallback inside a complete report, audit `ai_origin`/`ai_available` reflecting the truth. Model/mode retries are bounded by `GEMINI_MAX_MODEL_ATTEMPTS` / `GEMINI_MAX_TOTAL_REQUESTS`.
- **Database (optional history).** Any failure → investigation still completes, `persisted:false` + safe `persistence_error`, audit `outcome:not_saved` with the error code; print/export are honestly disabled until a row exists.

There is deliberately no auto-retry against PayPal for a *not-found* order (a retry would not change the result and would waste quota), and no circuit breaker that could mask a real outage — the audit log plus the honest fallback/not-saved paths are the bound. This is a conscious simplicity choice for a local tool, recorded here so it is not mistaken for an oversight.

## 4. One-command setup

`php bin/setup.php` (add `--gemini` to also list models) verifies, in order, without printing any secret:

1. PHP ≥ 8.0 and required extensions (`curl`, `openssl`, `json`, `mbstring`); `pdo_mysql` warned-if-missing.
2. Bootstrap loads and core classes are loadable.
3. `.env` exists (copied from `.env.example` if missing, with a warning) and required keys are *present* (presence only, never values).
4. Outbound connectivity to PayPal Sandbox + Gemini via the environment check (warn-only).
5. Database reachability + schema; auto-runs `bin/db-migrate.php` if tables are missing (warn-only).
6. The full automated test suite (hard-fail if it reports failures).
7. Optional Gemini model listing.

Hard failures exit 1; optional dependencies only WARN, because a missing database or a Gemini outage must not stop an investigation. Re-running is safe and idempotent.