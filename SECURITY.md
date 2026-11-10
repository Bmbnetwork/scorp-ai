# Security Policy

## Reporting a Vulnerability

Please report security vulnerabilities privately by contacting the repository maintainer or by opening a GitHub issue **without** including secrets, tokens, API keys, or sensitive customer data.

Do not open public issues containing:

- PayPal client secrets;
- Gemini API keys;
- access tokens;
- `.env` contents;
- real customer transaction data.

## Supported Versions

This project is currently provided as a hackathon/demo application. Security updates are best-effort and not guaranteed for production use.

## Scope

The public build is stateless and uses PayPal Sandbox only. It does not support:

- user accounts;
- saved investigation history;
- production multi-tenancy;
- real-money payment operations;
- automated refunds, reversals, or disputes.
