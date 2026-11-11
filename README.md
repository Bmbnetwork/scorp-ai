
# SCORP AI

[![MIT License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![Live App](https://img.shields.io/badge/live-scorp--ai.onrender.com-green.svg)](https://scorp-ai.onrender.com)

SCORP AI is an AI-powered PayPal payment incident investigation tool for online merchants.

It takes one PayPal Sandbox order ID, retrieves the real order evidence from PayPal, evaluates that evidence using deterministic investigation rules, and generates a plain-language explanation with Gemini. The application is designed to be useful without overclaiming: PayPal remains authoritative for transaction status, deterministic rules are authoritative for findings, and Gemini is only an explanation layer.

**Live application:** https://scorp-ai.onrender.com  
**Health check:** https://scorp-ai.onrender.com/health.php  
**Source repository:** https://github.com/Bmbnetwork/scorp-ai

## Live demo

1. Open https://scorp-ai.onrender.com
2. Enter a valid PayPal Sandbox order ID or Click "Get a live test Order".
3. Click **Start investigation**.
4. Review the report:
   - PayPal evidence
   - deterministic findings
   - supporting evidence
   - Gemini explanation or clearly labelled deterministic fallback
   - recommended next steps

The first request after inactivity may take 30â€“60 seconds because the application is hosted on a free tier that spins down idle instances. That delay is expected and is not an application error.

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
        â†“
Backend validates the reference
        â†“
Backend retrieves order evidence from PayPal Sandbox
        â†“
Deterministic investigation engine produces findings
        â†“
Gemini explains the structured evidence, or fallback is used
        â†“
Browser renders a separated report:
PayPal evidence / SCORP AI findings / explanation / next steps
