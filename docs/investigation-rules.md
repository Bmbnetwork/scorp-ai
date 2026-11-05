# SCORP AI — Phase 3 investigation rules

These rules evaluate normalized PayPal order evidence only.

They do not contact PayPal. They do not use Gemini. They do not infer facts that are absent from the retrieved response.

## Severity model

- `confirmed`: The finding is directly supported by a field returned by PayPal.
- `warning`: The evidence contains a condition that requires merchant verification.
- `unknown`: Required evidence is missing or PayPal returned a condition not covered by the rules.

A warning is not a fraud determination.

## Rules

| Code | Severity | Purpose |
|---|---|---|
| `paypal_evidence_retrieved` | confirmed | Confirms the backend retrieved PayPal Sandbox order evidence. |
| `order_status_missing` | unknown | The response did not include an order status. |
| `order_status_completed` | confirmed | PayPal returned `COMPLETED`. |
| `completed_without_capture_details` | warning | Order status is `COMPLETED`, but no capture details were returned. |
| `order_status_approved` | warning | PayPal returned `APPROVED`; capture is not evidenced by the response. |
| `order_payer_action_required` | warning | PayPal returned `PAYER_ACTION_REQUIRED`. |
| `order_created_without_capture` | warning | PayPal returned `CREATED` and no capture details were returned. This is not evidence that payment failed. |
| `order_created_with_capture` | warning | PayPal returned `CREATED`, but capture details are present. |
| `order_status_saved` | warning | PayPal returned `SAVED`. |
| `order_status_voided` | warning | PayPal returned `VOIDED`. |
| `order_status_unrecognized` | unknown | PayPal returned an order status not covered by the rules. |
| `status_with_capture_details` | warning | Non-completed order status includes capture details. |
| `no_purchase_units` | unknown | No purchase unit details were returned. |
| `multiple_purchase_units` | warning | Multiple purchase units were returned; displayed amount is first purchase unit only. |
| `order_amount_missing` | unknown | No order amount was returned. |
| `order_amount_value_missing` | unknown | Order amount object missing value. |
| `order_currency_missing` | unknown | Order amount object missing currency. |
| `multiple_captures_returned` | warning | Multiple captures were returned. |
| `more_captures_than_purchase_units` | warning | Capture count exceeds purchase unit count. |
| `capture_id_missing` | unknown | Capture returned without ID. |
| `capture_status_missing` | unknown | Capture returned without status. |
| `capture_completed` | confirmed | Capture status is `COMPLETED`. |
| `capture_declined` | warning | Capture status is `DECLINED`. Does not identify a cause. |
| `capture_pending` | warning | Capture status is `PENDING`. |
| `capture_suspended` | warning | Capture status is `SUSPENDED`. |
| `capture_status_unrecognized` | unknown | Capture status not covered by rules. |
| `capture_amount_missing` | unknown | Capture returned without amount. |
| `capture_amount_value_missing` | unknown | Capture amount missing value. |
| `capture_currency_missing` | unknown | Capture amount missing currency. |
| `amount_currency_mismatch` | warning | Single order amount currency differs from single capture currency. |
| `amount_value_mismatch` | warning | Single order amount value differs from single capture value. |