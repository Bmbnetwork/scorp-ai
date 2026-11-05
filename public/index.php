<?php

declare(strict_types=1);

$env = require dirname(__DIR__) . '/app/config/bootstrap.php';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <title>SCORP AI — Payment incident investigation</title>
    <meta name="description" content="AI-powered payment incident investigation for online merchants.">
    <link rel="stylesheet" href="assets/css/styles.css">
    <script src="assets/js/app.js" defer></script>
</head>
<body>
    <a class="skip-link" href="#main">Skip to investigation</a>
    <p id="sr-announcer" class="sr-only" role="status" aria-live="polite" aria-atomic="true"></p>

    <header class="app-header">
        <div class="container header-inner">
            <div class="brand">
                <span class="brand-mark" aria-hidden="true">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6l8-4z"></path>
                        <path d="M9 12l2 2 4-4"></path>
                    </svg>
                </span>
                <div class="brand-text">
                    <p class="brand-name">SCORP AI</p>
                    <p class="brand-subtitle">Payment incident investigation</p>
                </div>
            </div>

            <div class="header-meta">
                <span class="badge" data-state="info">Phase 8 — hardening &amp; accessibility</span>
            </div>
        </div>
    </header>

    <main id="main" class="container layout">
        <h1 class="sr-only">SCORP AI payment incident investigation</h1>

        <section class="panel" aria-labelledby="form-title">
            <div class="panel-header">
                <div>
                    <h2 id="form-title">Investigate a PayPal Sandbox order</h2>
                    <p class="muted">
                        One action retrieves PayPal evidence, applies deterministic rules, generates a Gemini
                        explanation, saves to local history when configured, and lets you print or export the report.
                    </p>
                </div>
            </div>

            <form id="investigation-form" class="form" novalidate>
                <div class="field">
                    <label for="reference">PayPal Sandbox order ID</label>

                    <div class="input-group">
                        <span class="input-icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="11" cy="11" r="7"></circle>
                                <path d="M20 20l-3.5-3.5"></path>
                            </svg>
                        </span>

                        <input
                            id="reference"
                            name="reference"
                            type="text"
                            inputmode="text"
                            autocomplete="off"
                            spellcheck="false"
                            maxlength="64"
                            placeholder="Enter PayPal Sandbox order ID"
                            aria-describedby="reference-help reference-error"
                            required
                        >
                    </div>

                    <p id="reference-help" class="help">
                        Only the order reference is sent to the backend. Payment status is never accepted from this form.
                    </p>

                    <p id="reference-error" class="field-error" role="alert" hidden></p>
                </div>

                <div class="form-actions">
                    <button id="investigate-button" class="primary-button" type="submit">
                        <span class="button-icon" aria-hidden="true">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M12 3v3"></path>
                                <path d="M12 18v3"></path>
                                <path d="M3 12h3"></path>
                                <path d="M18 12h3"></path>
                                <circle cx="12" cy="12" r="4"></circle>
                            </svg>
                        </span>
                        <span id="investigate-label">Start investigation</span>
                    </button>

                    <button id="clear-button" class="secondary-button" type="button" disabled>
                        Clear
                    </button>
                </div>
            </form>
        </section>

        <section id="status-area" class="panel status-panel" aria-live="polite" aria-atomic="true">
            <div id="empty-state" class="state">
                <span class="state-icon" aria-hidden="true">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="3" y="4" width="18" height="16" rx="2"></rect>
                        <path d="M7 9h10"></path>
                        <path d="M7 13h6"></path>
                    </svg>
                </span>

                <h2>No investigation yet</h2>

                <p class="muted">
                    Submit a PayPal Sandbox order ID to run the full workflow. Saved results can be reopened from
                    the history below, printed as a standalone report, or exported as JSON / CSV.
                </p>

                <ul class="state-list">
                    <li>Empty state: shown before a request.</li>
                    <li>Loading state: shown while PayPal, rules, Gemini, and the local save run.</li>
                    <li>Error state: shown when the required PayPal step fails. No report is produced, and you can retry.</li>
                    <li>Result state: shows the complete report with provenance, plus print / export actions when saved.</li>
                </ul>
            </div>

            <div id="loading-state" class="state" hidden>
                <span class="spinner" aria-hidden="true"></span>

                <h2>Investigating…</h2>

                <p class="muted">
                    1. Retrieving order evidence from PayPal Sandbox.<br>
                    2. Applying deterministic investigation rules.<br>
                    3. Generating a Gemini explanation, or a labelled fallback if unavailable.<br>
                    4. Saving to local history when configured.
                </p>
                <p class="muted loading-hint">This usually takes a few seconds. Please don’t submit again while it runs.</p>
            </div>

            <div id="error-state" class="state state-error" hidden>
                <span class="state-icon error-icon" aria-hidden="true">
                    <svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 3l9 16H3L12 3z"></path>
                        <path d="M12 9v4"></path>
                        <path d="M12 17h.01"></path>
                    </svg>
                </span>

                <h2 id="error-title" tabindex="-1">Investigation could not be completed</h2>

                <p id="error-message" class="muted"></p>

                <p class="muted error-note">
                    No investigation report is shown because a required step failed.
                </p>

                <ul id="error-details" class="error-details" hidden></ul>

                <div class="form-actions error-actions">
                    <button id="error-retry" class="secondary-button" type="button" aria-label="Retry investigation" hidden>Retry</button>
                </div>
            </div>
        </section>

        <section id="result-state" class="panel result-panel" hidden aria-labelledby="result-title">
            <div class="panel-header">
                <div>
                    <h2 id="result-title" tabindex="-1">Investigation report</h2>
                    <p class="muted">
                        Sources are kept separate: PayPal evidence, SCORP AI findings, and the explanation layer.
                    </p>
                </div>

                <div class="header-pills">
                    <span id="result-report-state" class="status-pill" data-state="info">
                        Report state
                    </span>
                    <span id="result-status-pill" class="status-pill" data-state="info">
                        Unknown status
                    </span>
                </div>
            </div>

            <div class="provenance-legend" aria-label="Information sources used in this report">
                <span class="legend-item">
                    <span class="legend-dot" data-source="paypal" aria-hidden="true"></span>
                    PayPal evidence (retrieved server-side)
                </span>
                <span class="legend-item">
                    <span class="legend-dot" data-source="rule" aria-hidden="true"></span>
                    SCORP AI findings (deterministic rules)
                </span>
                <span id="legend-explanation" class="legend-item">
                    <span class="legend-dot" data-source="ai" aria-hidden="true"></span>
                    <span id="legend-explanation-text">Explanation</span>
                </span>
            </div>

            <dl class="definition-grid">
                <div><dt>Order reference</dt><dd id="result-reference">—</dd></div>
                <div><dt>Observed order status</dt><dd id="result-payment-status">—</dd></div>
                <div><dt>Intent</dt><dd id="result-intent">—</dd></div>
                <div><dt>Amount</dt><dd id="result-amount">—</dd></div>
                <div><dt>Currency</dt><dd id="result-currency">—</dd></div>
                <div><dt>Created</dt><dd id="result-created">—</dd></div>
                <div><dt>Updated</dt><dd id="result-updated">—</dd></div>
                <div><dt>Environment</dt><dd id="result-environment">—</dd></div>
                <div><dt>Retrieved at</dt><dd id="result-retrieved-at">—</dd></div>
                <div><dt>Investigated at</dt><dd id="result-investigated-at">—</dd></div>
                <div><dt>Report generated at</dt><dd id="result-report-generated-at">—</dd></div>
                <div><dt>PayPal endpoint</dt><dd id="result-endpoint">—</dd></div>
            </dl>

            <div class="result-section">
                <h3>PayPal evidence — capture details</h3>

                <div id="result-captures-wrap" class="table-wrap" hidden>
                    <table aria-label="Capture details">
                        <thead>
                            <tr>
                                <th scope="col">Capture ID</th>
                                <th scope="col">Status</th>
                                <th scope="col">Amount</th>
                                <th scope="col">Currency</th>
                                <th scope="col">Created</th>
                                <th scope="col">Updated</th>
                                <th scope="col">Seller protection</th>
                            </tr>
                        </thead>
                        <tbody id="result-captures-body"></tbody>
                    </table>
                </div>

                <p id="result-captures-empty" class="muted">
                    No capture details were present in the PayPal response.
                </p>
            </div>

            <div class="result-section">
                <h3>SCORP AI deterministic findings</h3>

                <p id="result-findings-placeholder" class="muted">
                    No deterministic findings were returned.
                </p>

                <ul id="result-findings" class="result-list" hidden></ul>
            </div>

            <div class="result-section">
                <div class="section-head">
                    <h3>Explanation</h3>
                    <span id="ai-origin-pill" class="status-pill" data-state="info">
                        Not generated
                    </span>
                </div>

                <p id="ai-status-message" class="ai-status muted" hidden></p>

                <div id="result-ai" class="ai-copy"></div>
            </div>

            <div class="result-section">
                <h3>Recommended next steps from deterministic rules</h3>

                <ol id="result-next-steps" class="result-list"></ol>
            </div>

            <p id="result-saved-note" class="saved-note muted" hidden></p>

            <div id="result-actions" class="result-actions" hidden>
                <span id="result-actions-label" class="actions-label"></span>
                <button id="action-print" type="button" class="secondary-button small">Printable report</button>
                <button id="action-json" type="button" class="secondary-button small">Export JSON</button>
                <button id="action-csv" type="button" class="secondary-button small">Export CSV</button>
            </div>
            <p id="result-actions-status" class="actions-status muted" hidden></p>

            <p id="result-provenance" class="provenance muted"></p>
        </section>

        <section id="history-panel" class="panel history-panel" aria-labelledby="history-title">
            <div class="panel-header">
                <div>
                    <h2 id="history-title">Recent investigations</h2>
                    <p class="muted">
                        Saved locally. Open to review, print as a standalone report, export as JSON / CSV, or remove.
                    </p>
                </div>
                <div class="header-pills">
                    <button id="history-refresh" class="secondary-button" type="button">Refresh</button>
                </div>
            </div>

            <p id="history-status" class="history-status muted" hidden></p>

            <div id="history-table-wrap" class="table-wrap" hidden>
                <table aria-label="Recent investigations">
                    <thead>
                        <tr>
                            <th scope="col">Reference</th>
                            <th scope="col">Status</th>
                            <th scope="col">Amount</th>
                            <th scope="col">Explanation</th>
                            <th scope="col">Saved</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="history-body"></tbody>
                </table>
            </div>

            <p id="history-empty" class="muted history-empty" hidden>
                No saved investigations yet.
            </p>
        </section>
    </main>

    <footer class="container footer">
        <p>
            <a href="env-check.php">Environment check</a>
            · Local development interface. PayPal evidence, Gemini calls, history, audit log, and exports are server-side.
        </p>
    </footer>
</body>
</html>