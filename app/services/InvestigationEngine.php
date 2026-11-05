<?php

declare(strict_types=1);

namespace App\Services;

final class InvestigationEngine
{
    /**
     * Evaluates normalized PayPal order evidence using deterministic rules.
     *
     * The engine does not contact PayPal. It only evaluates data already
     * returned by the PayPal service and normalized by OrderNormalizer.
     *
     * @param array<string, mixed> $data
     *
     * @return array{findings: array<int, array<string, mixed>>, next_steps: string[]}
     */
    public function investigate(array $data): array
    {
        $findings = [];
        $nextSteps = [];

        $this->addRetrievalFinding($data, $findings, $nextSteps);

        $status = $this->nullableString($data['status'] ?? null);
        $amount = is_array($data['amount'] ?? null) ? $data['amount'] : null;
        $purchaseUnitCount = (int) ($data['purchase_unit_count'] ?? 0);
        $captures = is_array($data['captures'] ?? null) ? array_values($data['captures']) : [];
        $captureCount = count($captures);

        $this->evaluateStatus($status, $captureCount, $findings, $nextSteps);
        $this->evaluatePurchaseUnits($purchaseUnitCount, $findings, $nextSteps);
        $this->evaluateOrderAmount($amount, $findings, $nextSteps);
        $this->evaluateCaptures($captures, $purchaseUnitCount, $amount, $findings, $nextSteps);

        return [
            'findings' => $findings,
            'next_steps' => array_values(array_unique($nextSteps)),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param array<int, array<string, mixed>> $findings
     * @param string[] $nextSteps
     */
    private function addRetrievalFinding(array $data, array &$findings, array &$nextSteps): void
    {
        $this->addFinding(
            $findings,
            $nextSteps,
            'paypal_evidence_retrieved',
            'confirmed',
            'PayPal Sandbox evidence retrieved',
            'The backend successfully retrieved order data from the PayPal Sandbox Orders API.',
            [
                $this->makeEvidence('Order reference', $data['reference'] ?? null),
                $this->makeEvidence('Endpoint', $data['endpoint'] ?? null),
                $this->makeEvidence('Environment', $data['environment'] ?? null),
                $this->makeEvidence('Retrieved at', $data['retrieved_at'] ?? null),
            ]
        );
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     * @param string[] $nextSteps
     */
    private function evaluateStatus(?string $status, int $captureCount, array &$findings, array &$nextSteps): void
    {
        if ($status === null) {
            $this->addFinding(
                $findings,
                $nextSteps,
                'order_status_missing',
                'unknown',
                'Order status was not returned',
                'The PayPal response did not include an order status. SCORP AI cannot determine the transaction state from the available evidence.',
                [
                    $this->makeEvidence('Order status', null),
                ],
                [
                    'Check the order in PayPal Sandbox.',
                    'Retrieve the order again after verifying the reference.',
                ]
            );

            return;
        }

        $upper = strtoupper($status);

        switch ($upper) {
            case 'COMPLETED':
                $this->addFinding(
                    $findings,
                    $nextSteps,
                    'order_status_completed',
                    'confirmed',
                    'Order status is COMPLETED',
                    'PayPal returned COMPLETED for this order. This is a PayPal-reported status, not a SCORP AI inference.',
                    [
                        $this->makeEvidence('Order status', $status),
                        $this->makeEvidence('Capture count', $captureCount),
                    ],
                    [
                        'Verify capture details and amounts below before treating the incident as resolved.',
                    ]
                );

                if ($captureCount === 0) {
                    $this->addFinding(
                        $findings,
                        $nextSteps,
                        'completed_without_capture_details',
                        'warning',
                        'Completed order has no capture details',
                        'The order status is COMPLETED, but the retrieved response did not include capture details. This requires verification.',
                        [
                            $this->makeEvidence('Order status', $status),
                            $this->makeEvidence('Capture count', 0),
                        ],
                        [
                            'Check the order in PayPal Sandbox for capture records.',
                            'Do not rely only on the order status when capture evidence is missing.',
                        ]
                    );
                }

                break;

            case 'APPROVED':
                $this->addFinding(
                    $findings,
                    $nextSteps,
                    'order_status_approved',
                    'warning',
                    'Order approved but not captured',
                    'PayPal returned APPROVED. The response does not show that funds have been captured.',
                    [
                        $this->makeEvidence('Order status', $status),
                        $this->makeEvidence('Capture count', $captureCount),
                    ],
                    [
                        'Check whether a capture was attempted in PayPal.',
                        'Do not treat this status as a completed payment.',
                    ]
                );

                break;

            case 'PAYER_ACTION_REQUIRED':
                $this->addFinding(
                    $findings,
                    $nextSteps,
                    'order_payer_action_required',
                    'warning',
                    'Payer action required',
                    'PayPal returned PAYER_ACTION_REQUIRED. The order cannot be treated as completed from the available evidence.',
                    [
                        $this->makeEvidence('Order status', $status),
                        $this->makeEvidence('Capture count', $captureCount),
                    ],
                    [
                        'Ask the buyer to complete the required PayPal action.',
                        'Review the order in PayPal Sandbox.',
                    ]
                );

                break;

            case 'CREATED':
                if ($captureCount === 0) {
                    $this->addFinding(
                        $findings,
                        $nextSteps,
                        'order_created_without_capture',
                        'warning',
                        'Order created without capture evidence',
                        'The order was created, but no capture details were returned. This is not evidence that payment failed.',
                        [
                            $this->makeEvidence('Order status', $status),
                            $this->makeEvidence('Capture count', 0),
                        ],
                        [
                            'Confirm whether the buyer completed checkout.',
                            'Retrieve the order again after buyer action.',
                        ]
                    );
                } else {
                    $this->addFinding(
                        $findings,
                        $nextSteps,
                        'order_created_with_capture',
                        'warning',
                        'Created order contains capture details',
                        'The order status is CREATED, but capture details are present. Verify consistency.',
                        [
                            $this->makeEvidence('Order status', $status),
                            $this->makeEvidence('Capture count', $captureCount),
                        ],
                        [
                            'Review the captures in PayPal Sandbox.',
                            'Do not assume the captures belong to this order status without verification.',
                        ]
                    );
                }

                break;

            case 'SAVED':
                $this->addFinding(
                    $findings,
                    $nextSteps,
                    'order_status_saved',
                    'warning',
                    'Order saved but not completed',
                    'PayPal returned SAVED. The order is not completed according to the retrieved status.',
                    [
                        $this->makeEvidence('Order status', $status),
                        $this->makeEvidence('Capture count', $captureCount),
                    ],
                    [
                        'Check whether the order was intended to be saved.',
                        'Verify the latest order status in PayPal.',
                    ]
                );

                break;

            case 'VOIDED':
                $this->addFinding(
                    $findings,
                    $nextSteps,
                    'order_status_voided',
                    'warning',
                    'Order voided',
                    'PayPal returned VOIDED. This order was voided according to the retrieved response.',
                    [
                        $this->makeEvidence('Order status', $status),
                        $this->makeEvidence('Capture count', $captureCount),
                    ],
                    [
                        'Check why the order was voided.',
                        'Do not treat this order as a captured payment.',
                    ]
                );

                break;

            default:
                $this->addFinding(
                    $findings,
                    $nextSteps,
                    'order_status_unrecognized',
                    'unknown',
                    'Unrecognized order status',
                    'PayPal returned a status that is not covered by the deterministic rules: ' . $status . '.',
                    [
                        $this->makeEvidence('Order status', $status),
                        $this->makeEvidence('Capture count', $captureCount),
                    ],
                    [
                        'Consult PayPal documentation or the Sandbox dashboard for this status.',
                    ]
                );

                break;
        }

        if (
            $captureCount > 0
            && in_array($upper, ['APPROVED', 'PAYER_ACTION_REQUIRED', 'SAVED'], true)
        ) {
            $this->addFinding(
                $findings,
                $nextSteps,
                'status_with_capture_details',
                'warning',
                'Order status and capture details require verification',
                'The order status indicates the order is not completed, but capture details are present. Verify consistency.',
                [
                    $this->makeEvidence('Order status', $status),
                    $this->makeEvidence('Capture count', $captureCount),
                ],
                [
                    'Review the captures in PayPal Sandbox.',
                    'Do not assume the captures belong to this order status without verification.',
                ]
            );
        }
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     * @param string[] $nextSteps
     */
    private function evaluatePurchaseUnits(int $purchaseUnitCount, array &$findings, array &$nextSteps): void
    {
        if ($purchaseUnitCount === 0) {
            $this->addFinding(
                $findings,
                $nextSteps,
                'no_purchase_units',
                'unknown',
                'No purchase unit details returned',
                'The retrieved response did not include purchase unit details. Amount and line-item evidence may be incomplete.',
                [
                    $this->makeEvidence('Purchase unit count', 0),
                ],
                [
                    'Check the order in PayPal Sandbox for purchase unit details.',
                ]
            );

            return;
        }

        if ($purchaseUnitCount > 1) {
            $this->addFinding(
                $findings,
                $nextSteps,
                'multiple_purchase_units',
                'warning',
                'Multiple purchase units returned',
                'The response contains multiple purchase units. The displayed amount is taken from the first purchase unit only.',
                [
                    $this->makeEvidence('Purchase unit count', $purchaseUnitCount),
                ],
                [
                    'Verify totals across all purchase units before making a decision.',
                ]
            );
        }
    }

    /**
     * @param array<string, mixed>|null $amount
     * @param array<int, array<string, mixed>> $findings
     * @param string[] $nextSteps
     */
    private function evaluateOrderAmount(?array $amount, array &$findings, array &$nextSteps): void
    {
        if ($amount === null) {
            $this->addFinding(
                $findings,
                $nextSteps,
                'order_amount_missing',
                'unknown',
                'Order amount was not returned',
                'The retrieved response did not include an order amount.',
                [
                    $this->makeEvidence('Order amount', null),
                ],
                [
                    'Check the order amount in PayPal Sandbox.',
                ]
            );

            return;
        }

        $value = $this->nullableString($amount['value'] ?? null);
        $currency = $this->nullableString($amount['currency_code'] ?? null);

        if ($value === null) {
            $this->addFinding(
                $findings,
                $nextSteps,
                'order_amount_value_missing',
                'unknown',
                'Order amount value was not returned',
                'The retrieved response included an amount object but no amount value.',
                [
                    $this->makeEvidence('Order amount', $this->amountText($amount)),
                ],
                [
                    'Check the order amount in PayPal Sandbox.',
                ]
            );
        }

        if ($currency === null) {
            $this->addFinding(
                $findings,
                $nextSteps,
                'order_currency_missing',
                'unknown',
                'Order currency was not returned',
                'The retrieved response included an amount object but no currency code.',
                [
                    $this->makeEvidence('Order amount', $this->amountText($amount)),
                ],
                [
                    'Check the order currency in PayPal Sandbox.',
                ]
            );
        }
    }

    /**
     * @param array<int, mixed> $captures
     * @param array<string, mixed>|null $orderAmount
     * @param array<int, array<string, mixed>> $findings
     * @param string[] $nextSteps
     */
    private function evaluateCaptures(
        array $captures,
        int $purchaseUnitCount,
        ?array $orderAmount,
        array &$findings,
        array &$nextSteps
    ): void {
        $captureCount = count($captures);

        if ($captureCount === 0) {
            return;
        }

        if ($captureCount > 1) {
            $this->addFinding(
                $findings,
                $nextSteps,
                'multiple_captures_returned',
                'warning',
                'Multiple captures returned',
                'The response contains multiple captures. Verify which capture relates to the merchant incident.',
                [
                    $this->makeEvidence('Capture count', $captureCount),
                ],
                [
                    'Compare each capture ID and status with your records.',
                ]
            );
        }

        if ($purchaseUnitCount > 0 && $captureCount > $purchaseUnitCount) {
            $this->addFinding(
                $findings,
                $nextSteps,
                'more_captures_than_purchase_units',
                'warning',
                'More captures than purchase units',
                'The response contains more captures than purchase units. This may be valid in some flows but requires verification.',
                [
                    $this->makeEvidence('Capture count', $captureCount),
                    $this->makeEvidence('Purchase unit count', $purchaseUnitCount),
                ],
                [
                    'Review capture allocation in PayPal Sandbox.',
                ]
            );
        }

        $index = 0;

        foreach ($captures as $capture) {
            $index++;

            if (!is_array($capture)) {
                $this->addFinding(
                    $findings,
                    $nextSteps,
                    'capture_entry_invalid',
                    'unknown',
                    'Invalid capture entry',
                    'A capture entry was not in the expected format.',
                    [
                        $this->makeEvidence('Capture index', $index),
                    ],
                    [
                        'Check the order in PayPal Sandbox for capture details.',
                    ]
                );

                continue;
            }

            $captureId = $this->nullableString($capture['id'] ?? null);
            $captureStatus = $this->nullableString($capture['status'] ?? null);
            $captureAmount = is_array($capture['amount'] ?? null) ? $capture['amount'] : null;
            $captureLabel = $captureId ?? ('capture #' . $index);

            if ($captureId === null) {
                $this->addFinding(
                    $findings,
                    $nextSteps,
                    'capture_id_missing',
                    'unknown',
                    'Capture ID was not returned',
                    'A capture was returned without an ID.',
                    [
                        $this->makeEvidence('Capture index', $index),
                    ],
                    [
                        'Check the order in PayPal Sandbox for capture identifiers.',
                    ]
                );
            }

            if ($captureStatus === null) {
                $this->addFinding(
                    $findings,
                    $nextSteps,
                    'capture_status_missing',
                    'unknown',
                    'Capture status was not returned',
                    'A capture was returned without a status.',
                    [
                        $this->makeEvidence('Capture ID', $captureId),
                    ],
                    [
                        'Check the capture status in PayPal Sandbox.',
                    ]
                );
            } else {
                $upperStatus = strtoupper($captureStatus);

                switch ($upperStatus) {
                    case 'COMPLETED':
                        $this->addFinding(
                            $findings,
                            $nextSteps,
                            'capture_completed',
                            'confirmed',
                            'Capture status is COMPLETED',
                            'PayPal returned COMPLETED for ' . $captureLabel . '.',
                            [
                                $this->makeEvidence('Capture ID', $captureId),
                                $this->makeEvidence('Capture status', $captureStatus),
                                $this->makeEvidence('Capture amount', $this->amountText($captureAmount)),
                            ],
                            [
                                'Compare the capture amount with the order amount.',
                            ]
                        );

                        break;

                    case 'DECLINED':
                        $this->addFinding(
                            $findings,
                            $nextSteps,
                            'capture_declined',
                            'warning',
                            'Capture was declined',
                            'PayPal returned DECLINED for ' . $captureLabel . '. This confirms a declined capture in the retrieved response, but does not identify a cause.',
                            [
                                $this->makeEvidence('Capture ID', $captureId),
                                $this->makeEvidence('Capture status', $captureStatus),
                                $this->makeEvidence('Capture amount', $this->amountText($captureAmount)),
                            ],
                            [
                                'Review the capture in PayPal Sandbox for decline details.',
                                'If appropriate, ask the customer to try an alternate payment method.',
                            ]
                        );

                        break;

                    case 'PENDING':
                        $this->addFinding(
                            $findings,
                            $nextSteps,
                            'capture_pending',
                            'warning',
                            'Capture is pending',
                            'PayPal returned PENDING for ' . $captureLabel . '. The outcome is not final.',
                            [
                                $this->makeEvidence('Capture ID', $captureId),
                                $this->makeEvidence('Capture status', $captureStatus),
                                $this->makeEvidence('Capture amount', $this->amountText($captureAmount)),
                            ],
                            [
                                'Monitor the capture status.',
                                'Check PayPal for pending review reasons.',
                            ]
                        );

                        break;

                    case 'SUSPENDED':
                        $this->addFinding(
                            $findings,
                            $nextSteps,
                            'capture_suspended',
                            'warning',
                            'Capture is suspended',
                            'PayPal returned SUSPENDED for ' . $captureLabel . '. The capture requires review.',
                            [
                                $this->makeEvidence('Capture ID', $captureId),
                                $this->makeEvidence('Capture status', $captureStatus),
                                $this->makeEvidence('Capture amount', $this->amountText($captureAmount)),
                            ],
                            [
                                'Review the suspended capture in PayPal Sandbox.',
                                'Do not treat funds as available until PayPal resolves it.',
                            ]
                        );

                        break;

                    default:
                        $this->addFinding(
                            $findings,
                            $nextSteps,
                            'capture_status_unrecognized',
                            'unknown',
                            'Unrecognized capture status',
                            'PayPal returned an unrecognized capture status: ' . $captureStatus . '.',
                            [
                                $this->makeEvidence('Capture ID', $captureId),
                                $this->makeEvidence('Capture status', $captureStatus),
                            ],
                            [
                                'Consult PayPal documentation for this capture status.',
                            ]
                        );

                        break;
                }
            }

            if ($captureAmount === null) {
                $this->addFinding(
                    $findings,
                    $nextSteps,
                    'capture_amount_missing',
                    'unknown',
                    'Capture amount was not returned',
                    'A capture was returned without amount details.',
                    [
                        $this->makeEvidence('Capture ID', $captureId),
                    ],
                    [
                        'Check the capture amount in PayPal Sandbox.',
                    ]
                );
            } else {
                $value = $this->nullableString($captureAmount['value'] ?? null);
                $currency = $this->nullableString($captureAmount['currency_code'] ?? null);

                if ($value === null) {
                    $this->addFinding(
                        $findings,
                        $nextSteps,
                        'capture_amount_value_missing',
                        'unknown',
                        'Capture amount value was not returned',
                        'A capture amount object was returned without a value.',
                        [
                            $this->makeEvidence('Capture ID', $captureId),
                            $this->makeEvidence('Capture amount', $this->amountText($captureAmount)),
                        ],
                        [
                            'Check the capture amount in PayPal Sandbox.',
                        ]
                    );
                }

                if ($currency === null) {
                    $this->addFinding(
                        $findings,
                        $nextSteps,
                        'capture_currency_missing',
                        'unknown',
                        'Capture currency was not returned',
                        'A capture amount object was returned without a currency code.',
                        [
                            $this->makeEvidence('Capture ID', $captureId),
                            $this->makeEvidence('Capture amount', $this->amountText($captureAmount)),
                        ],
                        [
                            'Check the capture currency in PayPal Sandbox.',
                        ]
                    );
                }
            }
        }

        if (
            $purchaseUnitCount === 1
            && $captureCount === 1
            && $orderAmount !== null
            && is_array($captures[0]['amount'] ?? null)
        ) {
            $captureAmount = $captures[0]['amount'];

            $orderValue = $this->nullableString($orderAmount['value'] ?? null);
            $captureValue = $this->nullableString($captureAmount['value'] ?? null);
            $orderCurrency = $this->nullableString($orderAmount['currency_code'] ?? null);
            $captureCurrency = $this->nullableString($captureAmount['currency_code'] ?? null);

            if (
                $orderCurrency !== null
                && $captureCurrency !== null
                && strtoupper($orderCurrency) !== strtoupper($captureCurrency)
            ) {
                $this->addFinding(
                    $findings,
                    $nextSteps,
                    'amount_currency_mismatch',
                    'warning',
                    'Order and capture currencies differ',
                    'The first capture currency differs from the order amount currency. Verify which amount is correct.',
                    [
                        $this->makeEvidence('Order amount', $this->amountText($orderAmount)),
                        $this->makeEvidence('Capture amount', $this->amountText($captureAmount)),
                    ],
                    [
                        'Compare the order and capture amounts in PayPal Sandbox.',
                    ]
                );
            }

            if (
                $orderValue !== null
                && $captureValue !== null
                && !$this->amountsMatch($orderValue, $captureValue)
            ) {
                $this->addFinding(
                    $findings,
                    $nextSteps,
                    'amount_value_mismatch',
                    'warning',
                    'Order and capture amounts differ',
                    'The first capture amount differs from the order amount. Verify whether this is expected.',
                    [
                        $this->makeEvidence('Order amount', $this->amountText($orderAmount)),
                        $this->makeEvidence('Capture amount', $this->amountText($captureAmount)),
                    ],
                    [
                        'Compare the order and capture amounts in PayPal Sandbox.',
                    ]
                );
            }
        }
    }

    /**
     * @param array<int, array<string, mixed>> $findings
     * @param string[] $nextSteps
     * @param array<int, array<string, string>> $evidence
     * @param string[] $steps
     */
    private function addFinding(
        array &$findings,
        array &$nextSteps,
        string $code,
        string $severity,
        string $title,
        string $detail,
        array $evidence = [],
        array $steps = []
    ): void {
        $findings[] = [
            'code' => $code,
            'severity' => $severity,
            'title' => $title,
            'detail' => $detail,
            'origin' => 'scorp_ai_rule',
            'evidence' => array_values($evidence),
        ];

        foreach ($steps as $step) {
            if (is_string($step) && trim($step) !== '') {
                $nextSteps[] = trim($step);
            }
        }
    }

    /**
     * @return array{label: string, value: string, source: string}
     */
    private function makeEvidence(string $label, mixed $value, string $source = 'PayPal Sandbox response'): array
    {
        return [
            'label' => $label,
            'value' => $this->displayValue($value),
            'source' => $source,
        ];
    }

    private function displayValue(mixed $value): string
    {
        if ($value === null) {
            return 'Not returned';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? 'Not returned' : $value;
        }

        if (is_array($value)) {
            $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

            return $json === false ? 'Unsupported value' : $json;
        }

        return 'Unsupported value';
    }

    /**
     * @param array<string, mixed>|null $amount
     */
    private function amountText(?array $amount): string
    {
        if ($amount === null) {
            return 'Not returned';
        }

        $value = $this->nullableString($amount['value'] ?? null);
        $currency = $this->nullableString($amount['currency_code'] ?? null);

        if ($value === null && $currency === null) {
            return 'Not returned';
        }

        return trim(($value ?? '—') . ' ' . ($currency ?? ''));
    }

    private function nullableString(mixed $value): ?string
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? null : $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return null;
    }

    private function amountsMatch(?string $left, ?string $right): bool
    {
        if ($left === null || $right === null) {
            return true;
        }

        if ($left === $right) {
            return true;
        }

        $leftNumeric = is_numeric($left) ? (float) $left : null;
        $rightNumeric = is_numeric($right) ? (float) $right : null;

        if ($leftNumeric === null || $rightNumeric === null) {
            return false;
        }

        return abs($leftNumeric - $rightNumeric) <= 0.000001;
    }
}