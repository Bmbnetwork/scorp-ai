# SCORP AI — Phase 4 Gemini integration

Gemini is used only as an explanation layer.

## Authority model

- PayPal is the source of truth for returned transaction fields.
- SCORP AI deterministic rules are the source of truth for rule findings.
- Gemini is not authoritative for payment status.
- Gemini must not invent transaction IDs, statuses, amounts, currencies, dates, causes, or outcomes.
- Gemini must not authorize refunds, reversals, disputes, or other financial actions.

## Input minimization

The Gemini request includes only:

- normalized PayPal order evidence;
- SCORP AI deterministic findings;
- rule-generated next steps.

It does not include:

- PayPal client secret;
- PayPal access token;
- Gemini API key;
- raw PayPal API responses;
- full HTTP headers;
- unnecessary personal data.

## Output validation

Gemini must return JSON with:

- `summary`
- `facts`
- `uncertainties`
- `next_steps`
- `limitations`

The backend validates:

- JSON shape;
- string types;
- maximum lengths;
- maximum list sizes;
- absence of unsupported long transaction identifiers;
- absence of unsupported monetary claims adjacent to currency codes or `$`.

If validation fails, the backend does not display the Gemini output. It returns a clearly labelled deterministic fallback.

## Fallback behaviour

If Gemini is unavailable, misconfigured, rate-limited, blocked, malformed, or fails validation, SCORP AI returns a deterministic fallback summary.

The fallback is labelled:

```text
Deterministic fallback