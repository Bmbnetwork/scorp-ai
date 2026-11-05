
# SCORP AI

SCORP AI is an AI-powered PayPal payment incident investigation tool for online merchants.

It takes one PayPal Sandbox order ID, retrieves the real order evidence from PayPal, evaluates that evidence using deterministic investigation rules, and generates a plain-language explanation with Gemini. The application is designed to be useful without overclaiming: PayPal remains authoritative for transaction status, deterministic rules are authoritative for findings, and Gemini is only an explanation layer.

**Live application:** https://scorp-ai.onrender.com  
**Health check:** https://scorp-ai.onrender.com/health.php  
**Source repository:** https://github.com/Bmbnetwork/scorp-ai

## Live demo

1. Open https://scorp-ai.onrender.com
2. Enter a valid PayPal Sandbox order ID.
3. Click **Start investigation**.
4. Review the report:
   - PayPal evidence
   - deterministic findings
   - supporting evidence
   - Gemini explanation or clearly labelled deterministic fallback
   - recommended next steps

The first request after inactivity may take 30–60 seconds because the application is hosted on a free tier that spins down idle instances. That delay is expected and is not an application error.

## Core principles

- PayPal API responses are authoritative for returned transaction fields.
- Payment status is never accepted from the browser form.
- Deterministic rules produce the investigation findings.
- Gemini explains evidence; it does not invent evidence.
- Gemini never decides the authoritative payment status.
- If Gemini is unavailable or invalid, the app uses a deterministic fallback and labels it as not AI.
- If a required step fails, the app shows an error and does not fabricate a report.
- The public build is stateless: no database, no user accounts, no saved history, no print/export endpoints.

## Workflow

```text
User enters PayPal Sandbox order ID
        ↓
Backend validates the reference
        ↓
Backend retrieves order evidence from PayPal Sandbox
        ↓
Deterministic investigation engine produces findings
        ↓
Gemini explains the structured evidence, or fallback is used
        ↓
Browser renders a separated report:
PayPal evidence / SCORP AI findings / explanation / next steps


## Sandbox test order IDs

Use one of these on the live site:

- PASTE_ORDER_ID_1: 7AP856189S292592F
- PASTE_ORDER_ID_2: 4E798323DM779034V
- PASTE_ORDER_ID_3: 6R6412534D096942V
- PASTE_ORDER_ID_4: 2137241420534622H
- PASTE_ORDER_ID_5: 90Y0769070984242A
- PASTE_ORDER_ID_6: 0AY64465A60713739
- PASTE_ORDER_ID_7: 9R463514TY2757347
- PASTE_ORDER_ID_8: 2NS044149D7145304
- PASTE_ORDER_ID_9: 2NS044149D7145304
- PASTE_ORDER_ID_10: 4TW70456PU585833P

Open: https://scorp-ai.onrender.com
THESE ARE SANDBOX ORDER ID
Paste an order ID into the field and click **Start investigation**.
