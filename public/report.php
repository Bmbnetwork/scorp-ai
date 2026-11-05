<?php

declare(strict_types=1);

use App\Database\Database;
use App\Database\DatabaseException;
use App\Services\InvestigationMapper;
use App\Services\InvestigationRepository;
use App\Services\ReportExporter;

$env = require dirname(__DIR__) . '/app/config/bootstrap.php';

function e(mixed $value): string
{
    if ($value === null) {
        return '';
    }

    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }

    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }

    if (!is_string($value)) {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $value = $json === false ? '' : $json;
    }

    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function display(mixed $value): string
{
    $text = e($value);

    return $text === '' ? 'Not provided' : $text;
}

$idRaw = $_GET['id'] ?? null;
$id = null;

if (is_string($idRaw) && preg_match('/\A\d+\z/', $idRaw) === 1) {
    $id = (int) $idRaw;
}

$state = 'ok';
$stateTitle = '';
$stateMessage = '';
$report = null;

if ($id === null || $id <= 0) {
    $state = 'invalid';
    $stateTitle = 'Invalid report id';
    $stateMessage = 'A printable report requires a positive numeric id.';
} else {
    try {
        $database = Database::fromEnv($env);
        $repository = new InvestigationRepository($database);
        $record = $repository->find($id);
    } catch (DatabaseException $exception) {
        $state = 'unavailable';
        $stateTitle = 'History not available';
        $stateMessage = $exception->getErrorCode() === 'database_not_configured'
            ? 'History is not configured on this machine. Set the DB_* values in .env and create the scorp_ai tables, then save an investigation.'
            : 'The local history database could not be reached right now.';
        $record = null;
    } catch (Throwable $exception) {
        $state = 'unavailable';
        $stateTitle = 'History not available';
        $stateMessage = 'The saved investigation could not be read right now.';
        $record = null;
    }

    if ($state === 'ok' && $record === null) {
        $state = 'notfound';
        $stateTitle = 'Report not found';
        $stateMessage = 'No saved investigation matches id #' . $id . '. It may have been removed.';
    } elseif ($state === 'ok' && is_array($record)) {
        $report = InvestigationMapper::toReport($record['investigation'], $record['findings']);
        $report['investigation_id'] = $id;
        $report['saved_at'] = $record['investigation']['created_timestamp'] ?? null;
    }
}

$ai = is_array($report['ai'] ?? null) ? $report['ai'] : null;
$aiAvailable = $ai !== null && ($ai['available'] ?? null) === true;
$aiOrigin = is_string($ai['origin'] ?? null) ? (string) $ai['origin'] : null;
$aiLabel = ReportExporter::explanationLabel($aiOrigin, $aiAvailable);

$reportState = is_string($report['report_state'] ?? null) ? (string) $report['report_state'] : null;
$reportComplete = $reportState === 'complete';

$amount = is_array($report['amount'] ?? null) ? $report['amount'] : null;
$amountText = '';

if ($amount !== null) {
    $amountText = trim(((string) ($amount['value'] ?? '')) . ' ' . ((string) ($amount['currency_code'] ?? '')));
}

$captures = is_array($report['captures'] ?? null) ? array_values($report['captures']) : [];
$findings = is_array($report['findings'] ?? null) ? array_values($report['findings']) : [];
$ruleSteps = is_array($report['next_steps'] ?? null) ? array_values($report['next_steps']) : [];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="color-scheme" content="light">
    <title>SCORP AI — Report <?= $id !== null ? '#' . (int) $id : '' ?></title>
    <style>
                :root {
            --ink: #071a33;
            --muted: #5a6b82;
            --line: #d9e4f0;
            --soft: #f8fbff;
            --accent: #0070ba;
            --accent-dark: #003087;
            --accent-light: #e8f3fb;
            --pass: #003087;
            --warn: #8a4b08;
            --fail: #9f1c17;
            --focus: #003087;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 2rem 1.25rem 3rem;
            font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
            color: var(--ink);
            background: #ffffff;
        }

        .doc {
            max-width: 880px;
            margin: 0 auto;
        }

        h1 {
            font-size: 1.4rem;
            margin: 0 0 .25rem;
            color: var(--accent-dark);
        }

        h2 {
            font-size: 1.05rem;
            margin: 1.6rem 0 .6rem;
            padding-bottom: .35rem;
            border-bottom: 1px solid var(--line);
            color: var(--ink);
        }

        h3 {
            font-size: .8rem;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: var(--muted);
            margin: 1.1rem 0 .4rem;
        }

        p {
            margin: .35rem 0;
        }

        .sub {
            color: var(--muted);
            font-size: .9rem;
        }

        .sr-only {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            clip-path: inset(50%);
            white-space: nowrap;
            border: 0;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 1rem;
            flex-wrap: wrap;
        }

        .pills {
            display: flex;
            gap: .4rem;
            flex-wrap: wrap;
        }

        .pill {
            font-size: .72rem;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            padding: .25rem .55rem;
            border-radius: 999px;
            border: 1px solid var(--line);
            background: var(--soft);
            color: var(--muted);
        }

        .pill.pass {
            color: var(--pass);
            border-color: #a8d1ee;
            background: var(--accent-light);
        }

        .pill.warn {
            color: var(--warn);
            border-color: #f3d19a;
            background: #fff6e8;
        }

        .pill.fail {
            color: var(--fail);
            border-color: #f4b7b4;
            background: #fff1f1;
        }

        .pill.ai {
            color: #005a96;
            border-color: #8fd6f7;
            background: #e6f7ff;
        }

        .banner {
            margin: 1rem 0 0;
            padding: .7rem .9rem;
            border: 1px solid var(--line);
            border-left: 4px solid var(--accent);
            border-radius: 8px;
            background: var(--soft);
            font-size: .85rem;
            color: #334155;
        }

        .banner.fallback {
            border-left-color: var(--warn);
        }

        dl.grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: .5rem 1.5rem;
            margin: .8rem 0 0;
        }

        dl.grid > div {
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            border-bottom: 1px dotted var(--line);
            padding: .25rem 0;
        }

        dt {
            color: var(--muted);
            font-size: .82rem;
        }

        dd {
            margin: 0;
            font-weight: 600;
            text-align: right;
            overflow-wrap: anywhere;
            color: var(--ink);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            margin: .4rem 0 0;
            font-size: .88rem;
            background: #ffffff;
        }

        caption {
            text-align: left;
            color: var(--muted);
            font-size: .8rem;
            padding-bottom: .3rem;
        }

        th,
        td {
            text-align: left;
            padding: .45rem .55rem;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
            overflow-wrap: anywhere;
        }

        th {
            font-size: .72rem;
            letter-spacing: .05em;
            text-transform: uppercase;
            color: var(--muted);
            background: var(--soft);
        }

        ul,
        ol {
            margin: .3rem 0 0;
            padding-left: 1.2rem;
        }

        li {
            margin: .2rem 0;
        }

        .finding {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: .6rem .75rem;
            margin: .5rem 0;
            background: #ffffff;
        }

        .finding .head {
            display: flex;
            align-items: center;
            gap: .5rem;
            flex-wrap: wrap;
        }

        .finding .title {
            font-weight: 700;
            color: var(--ink);
        }

        .finding .detail {
            color: var(--muted);
            font-size: .9rem;
            margin-top: .25rem;
        }

        details {
            margin-top: .4rem;
        }

        summary {
            cursor: pointer;
            font-size: .82rem;
            font-weight: 650;
            color: var(--muted);
        }

        .empty {
            color: var(--muted);
            font-style: italic;
        }

        .actions {
            margin: 1.5rem 0 0;
            display: flex;
            gap: .5rem;
            flex-wrap: wrap;
        }

        .actions a,
        .actions button {
            font: inherit;
            font-size: .85rem;
            font-weight: 600;
            padding: .5rem .8rem;
            border-radius: 8px;
            border: 1px solid var(--line);
            background: var(--soft);
            color: var(--accent-dark);
            text-decoration: none;
            cursor: pointer;
        }

        .actions a:hover,
        .actions button:hover {
            background: #f2f8fd;
            border-color: #8bb9dc;
        }

        .actions a.primary,
        .actions button.primary {
            background: var(--accent);
            border-color: var(--accent);
            color: #ffffff;
        }

        .actions a.primary:hover,
        .actions button.primary:hover {
            background: var(--accent-dark);
            border-color: var(--accent-dark);
        }

        .notice {
            margin: 2rem auto;
            max-width: 640px;
            padding: 1.5rem;
            border: 1px solid var(--line);
            border-radius: 12px;
            background: var(--soft);
        }

        .notice h1 {
            margin-top: 0;
        }

        .foot {
            margin-top: 2rem;
            padding-top: 1rem;
            border-top: 1px solid var(--line);
            color: var(--muted);
            font-size: .8rem;
        }

        a:focus-visible,
        button:focus-visible,
        summary:focus-visible {
            outline: 3px solid var(--focus);
            outline-offset: 2px;
            border-radius: 6px;
        }

        @media (max-width: 640px) {
            dl.grid {
                grid-template-columns: 1fr;
            }

            dl.grid > div {
                border-bottom: none;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            * {
                animation-duration: .01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: .01ms !important;
                scroll-behavior: auto !important;
            }
        }

        @media print {
            body {
                padding: 0;
                background: #ffffff;
            }

            .no-print {
                display: none !important;
            }

            .doc {
                max-width: none;
            }

            .banner,
            .finding,
            table th {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }

            a {
                color: inherit;
                text-decoration: none;
            }

            @page {
                margin: 16mm;
            }
        }
    </style>
</head>
<body>
<?php if ($state !== 'ok'): ?>
    <div class="notice" role="alert">
        <h1><?= e($stateTitle) ?></h1>
        <p class="sub"><?= e($stateMessage) ?></p>
        <div class="actions no-print">
            <a class="primary" href="index.php">Back to investigation</a>
        </div>
    </div>
<?php else: ?>
    <div class="doc">
        <div class="topbar">
            <div>
                <h1>SCORP AI — Investigation report</h1>
                <p class="sub">Saved investigation #<?= (int) $id ?> · <?= e(display($report['reference'] ?? null)) ?></p>
            </div>
            <div class="pills">
                <span class="pill <?= $reportComplete ? 'pass' : 'fail' ?>">
                    <?= $reportComplete ? 'Report complete' : 'Report incomplete' ?>
                </span>
                <span class="pill <?= strtoupper((string) ($report['status'] ?? '')) === 'COMPLETED' ? 'pass' : (in_array(strtoupper((string) ($report['status'] ?? '')), ['VOIDED', 'CANCELLED', 'CANCELED'], true) ? 'fail' : 'warn') ?>">
                    <?= e(display($report['status'] ?? null)) ?>
                </span>
            </div>
        </div>

        <div class="banner <?= $aiAvailable ? '' : 'fallback' ?>">
            <strong>Sources.</strong> PayPal evidence was retrieved server-side from the PayPal Sandbox Orders API.
            Findings are calculated by SCORP AI deterministic rules.
            <?php if ($aiAvailable): ?>
                The explanation was generated by Gemini from structured evidence and does <em>not</em> decide the authoritative payment status.
            <?php elseif ($aiOrigin === 'deterministic_fallback'): ?>
                The explanation below is a <strong>deterministic fallback (not AI)</strong> because Gemini was unavailable or invalid. It is not an AI-generated explanation.
            <?php else: ?>
                No explanation was stored for this investigation.
            <?php endif; ?>
        </div>

        <h2>PayPal evidence</h2>
        <dl class="grid">
            <div><dt>Order reference</dt><dd><?= e(display($report['reference'] ?? null)) ?></dd></div>
            <div><dt>Observed order status</dt><dd><?= e(display($report['status'] ?? null)) ?></dd></div>
            <div><dt>Intent</dt><dd><?= e(display($report['intent'] ?? null)) ?></dd></div>
            <div><dt>Amount</dt><dd><?= e($amountText === '' ? 'Not provided' : $amountText) ?></dd></div>
            <div><dt>Purchase units</dt><dd><?= (int) ($report['purchase_unit_count'] ?? 0) ?></dd></div>
            <div><dt>Captures</dt><dd><?= (int) ($report['capture_count'] ?? 0) ?></dd></div>
            <div><dt>Environment</dt><dd><?= e(display($report['environment'] ?? null)) ?></dd></div>
            <div><dt>Endpoint</dt><dd><?= e(display($report['endpoint'] ?? null)) ?></dd></div>
            <div><dt>Created</dt><dd><?= e(display($report['created_at'] ?? null)) ?></dd></div>
            <div><dt>Updated</dt><dd><?= e(display($report['updated_at'] ?? null)) ?></dd></div>
            <div><dt>Retrieved at</dt><dd><?= e(display($report['retrieved_at'] ?? null)) ?></dd></div>
            <div><dt>Investigated at</dt><dd><?= e(display($report['investigated_at'] ?? null)) ?></dd></div>
            <div><dt>Report generated at</dt><dd><?= e(display($report['report_generated_at'] ?? null)) ?></dd></div>
            <div><dt>Saved at (history)</dt><dd><?= e(display($report['saved_at'] ?? null)) ?></dd></div>
        </dl>

        <h3>Capture details</h3>
        <?php if ($captures === []): ?>
            <p class="empty">No capture details were present in the PayPal response.</p>
        <?php else: ?>
            <table aria-label="Capture details for investigation <?= (int) $id ?>">
                <caption class="sr-only">Capture details returned by PayPal for this order.</caption>
                <thead>
                    <tr>
                        <th scope="col">Capture ID</th><th scope="col">Status</th><th scope="col">Amount</th><th scope="col">Currency</th>
                        <th scope="col">Created</th><th scope="col">Updated</th><th scope="col">Seller protection</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($captures as $capture): $cAmount = is_array($capture['amount'] ?? null) ? $capture['amount'] : []; ?>
                        <tr>
                            <td><?= e(display($capture['id'] ?? null)) ?></td>
                            <td><?= e(display($capture['status'] ?? null)) ?></td>
                            <td><?= e(display($cAmount['value'] ?? null)) ?></td>
                            <td><?= e(display($cAmount['currency_code'] ?? null)) ?></td>
                            <td><?= e(display($capture['created_at'] ?? null)) ?></td>
                            <td><?= e(display($capture['updated_at'] ?? null)) ?></td>
                            <td><?= e(display($capture['seller_protection_status'] ?? null)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2>SCORP AI deterministic findings</h2>
        <?php if ($findings === []): ?>
            <p class="empty">No deterministic findings were returned.</p>
        <?php else: ?>
            <?php foreach ($findings as $finding):
                $sev = strtolower(trim((string) ($finding['severity'] ?? '')));
                $sevClass = $sev === 'confirmed' ? 'pass' : ($sev === 'warning' ? 'warn' : ($sev === 'critical' ? 'fail' : ''));
                $sevLabel = $sev === 'confirmed' ? 'Confirmed' : ($sev === 'warning' ? 'Warning' : ($sev === 'critical' ? 'Critical' : 'Unknown'));
                $evidence = is_array($finding['evidence'] ?? null) ? array_values($finding['evidence']) : [];
            ?>
                <div class="finding">
                    <div class="head">
                        <span class="pill <?= $sevClass ?>"><?= e($sevLabel) ?></span>
                        <span class="title"><?= e(display($finding['title'] ?? $finding['code'] ?? null)) ?></span>
                    </div>
                    <?php if (($finding['detail'] ?? null) !== null && trim((string) $finding['detail']) !== ''): ?>
                        <div class="detail"><?= e((string) $finding['detail']) ?></div>
                    <?php endif; ?>
                    <?php if ($evidence !== []): ?>
                        <details>
                            <summary>Supporting evidence</summary>
                            <ul>
                                <?php foreach ($evidence as $entry):
                                    $entry = is_array($entry) ? $entry : [];
                                    $val = ($entry['value'] ?? null) === null || $entry['value'] === '' ? 'Not provided' : (string) $entry['value'];
                                ?>
                                    <li><?= e((string) ($entry['label'] ?? 'Evidence')) ?>: <?= e($val) ?><?= isset($entry['source']) && $entry['source'] !== '' ? ' · Source: ' . e((string) $entry['source']) : '' ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <h2>Explanation</h2>
        <?php if ($ai === null): ?>
            <p class="empty">No explanation was stored for this investigation.</p>
        <?php else: ?>
            <p class="pills" style="margin-bottom:.4rem;">
                <span class="pill <?= $aiAvailable ? 'ai' : 'warn' ?>"><?= e($aiLabel) ?></span>
                <?php if ($aiAvailable && ($ai['model'] ?? null)): ?>
                    <span class="pill">Model: <?= e((string) $ai['model']) ?></span>
                <?php endif; ?>
            </p>
            <?php if (($ai['summary'] ?? null) !== null && trim((string) $ai['summary']) !== ''): ?>
                <p><?= e((string) $ai['summary']) ?></p>
            <?php endif; ?>
            <?php if (!$aiAvailable && ($ai['error_message'] ?? null)): ?>
                <p class="sub">Gemini was not used. Reason: <?= e((string) $ai['error_message']) ?></p>
            <?php endif; ?>

            <?php
            $blocks = [
                'Facts supported by evidence' => is_array($ai['facts'] ?? null) ? $ai['facts'] : [],
                'Uncertainties' => is_array($ai['uncertainties'] ?? null) ? $ai['uncertainties'] : [],
                ($aiAvailable ? 'Gemini suggested next steps' : 'Fallback suggested next steps') => is_array($ai['next_steps'] ?? null) ? $ai['next_steps'] : [],
                'Limitations' => is_array($ai['limitations'] ?? null) ? $ai['limitations'] : [],
            ];
            ?>
            <?php foreach ($blocks as $title => $items): $items = array_values(array_filter(array_map(static fn ($i) => is_string($i) ? trim($i) : (string) $i, (array) $items), static fn ($i) => $i !== '')); if ($items === []) { continue; } ?>
                <h3><?= e((string) $title) ?></h3>
                <ul>
                    <?php foreach ($items as $item): ?>
                        <li><?= e((string) $item) ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        <?php endif; ?>

        <h2>Recommended next steps from deterministic rules</h2>
        <?php if ($ruleSteps === []): ?>
            <p class="empty">No rule-generated next steps were stored.</p>
        <?php else: ?>
            <ol>
                <?php foreach ($ruleSteps as $step): ?>
                    <li><?= e(is_string($step) ? $step : (string) $step) ?></li>
                <?php endforeach; ?>
            </ol>
        <?php endif; ?>

        <div class="actions no-print">
            <button class="primary" type="button" onclick="window.print()" aria-label="Print or save this report as PDF">Print / Save as PDF</button>
            <a href="api/export.php?id=<?= (int) $id ?>&format=json" download aria-label="Export investigation <?= (int) $id ?> as JSON">Export JSON</a>
            <a href="api/export.php?id=<?= (int) $id ?>&format=csv" download aria-label="Export investigation <?= (int) $id ?> as CSV">Export CSV</a>
            <a href="index.php" aria-label="Open SCORP AI app">Open in app</a>
        </div>

        <p class="foot">
            Generated by SCORP AI (local development). PayPal values are returned by the PayPal Sandbox Orders API;
            findings are deterministic rules; the explanation is Gemini or a labelled deterministic fallback.
            This document reflects a saved investigation and does not authorize refunds, reversals, or disputes.
        </p>
    </div>
<?php endif; ?>
</body>
</html>