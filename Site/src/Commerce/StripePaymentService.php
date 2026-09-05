<?php

declare(strict_types=1);

namespace Pericles\Commerce;

use Pericles\Http\ApiException;
use Stripe\Exception\SignatureVerificationException;
use Stripe\StripeClient;
use Stripe\Webhook;
use UnexpectedValueException;

final class StripePaymentService
{
    /** @var null|callable */
    private $sessionCreator;

    /** @var null|callable */
    private $eventVerifier;

    public function __construct(
        private CheckoutService $checkout,
        private string $publishableKey,
        private string $secretKey,
        private string $webhookSecret,
        private string $appUrl,
        ?callable $sessionCreator = null,
        ?callable $eventVerifier = null
    ) {
        $this->sessionCreator = $sessionCreator;
        $this->eventVerifier = $eventVerifier;
    }

    public function isConfigured(): bool
    {
        return str_starts_with($this->publishableKey, 'pk_')
            && (str_starts_with($this->secretKey, 'sk_') || str_starts_with($this->secretKey, 'rk_'))
            && str_starts_with($this->webhookSecret, 'whsec_')
            && filter_var($this->appUrl, FILTER_VALIDATE_URL) !== false;
    }

    public function publishableKey(): string
    {
        return $this->publishableKey;
    }

    public function createEmbeddedSession(array $order, string $checkoutToken): array
    {
        if (!$this->isConfigured()) {
            throw new ApiException('payment_not_configured', 503, 'Stripe payment is not configured. No charge was attempted.');
        }
        if ((string) ($order['status'] ?? '') !== 'pending') {
            throw new ApiException('order_not_payable', 409, 'This order is not awaiting payment.');
        }

        $amount = (int) ($order['amount_cents'] ?? 0);
        $currency = strtolower((string) ($order['currency'] ?? ''));
        $orderNumber = trim((string) ($order['order_number'] ?? ''));
        $email = trim((string) ($order['customer_email'] ?? ''));
        if ($amount < 1 || !preg_match('/^[a-z]{3}$/', $currency) || $orderNumber === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException('invalid_order', 409, 'This order cannot be sent to Stripe.');
        }
        if (!preg_match('/^[A-Za-z0-9_-]{40,60}$/', $checkoutToken)) {
            throw new ApiException('checkout_not_found', 404, 'Checkout not found.');
        }

        $productName = trim((string) ($order['product_name_snapshot'] ?? 'Pericles Enhancement'));
        $planName = trim((string) ($order['plan_name_snapshot'] ?? 'Access'));
        $metadata = [
            'pericles_order_number' => $orderNumber,
            'pericles_order_id' => (string) (int) ($order['id'] ?? 0),
            'pericles_product_id' => (string) (int) ($order['product_id'] ?? 0),
            'pericles_plan_id' => (string) (int) ($order['plan_id'] ?? 0),
        ];
        $params = [
            'ui_mode' => 'elements',
            'mode' => 'payment',
            'client_reference_id' => $orderNumber,
            'customer_email' => $email,
            'billing_address_collection' => 'auto',
            'locale' => 'auto',
            'line_items' => [[
                'price_data' => [
                    'currency' => $currency,
                    'unit_amount' => $amount,
                    'product_data' => [
                        'name' => $productName,
                        'description' => $planName . ' Access',
                        'metadata' => $metadata,
                    ],
                ],
                'quantity' => 1,
            ]],
            'metadata' => $metadata,
            'payment_intent_data' => [
                'description' => $productName . ' - ' . $planName . ' Access',
                'metadata' => $metadata,
            ],
            'return_url' => rtrim($this->appUrl, '/') . '/checkout/' . rawurlencode($checkoutToken)
                . '?stripe_return=1&session_id={CHECKOUT_SESSION_ID}',
        ];
        // Stripe only permits reusing an idempotency key when every request
        // parameter is identical. Include a request fingerprint so a future
        // checkout configuration change can safely create a replacement
        // session while identical retries remain idempotent.
        $requestFingerprint = hash(
            'sha256',
            json_encode($params, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
        );
        $options = [
            'idempotency_key' => 'pericles-checkout-' . $orderNumber . '-' . $requestFingerprint,
        ];

        $session = $this->sessionCreator !== null
            ? ($this->sessionCreator)($params, $options)
            : (new StripeClient([
                'api_key' => $this->secretKey,
                'stripe_version' => '2026-08-26.dahlia',
            ]))->checkout->sessions->create($params, $options);

        $clientSecret = $this->field($session, 'client_secret');
        $sessionId = $this->field($session, 'id');
        if (!is_string($clientSecret) || $clientSecret === '' || !is_string($sessionId) || $sessionId === '') {
            throw new ApiException('stripe_session_invalid', 502, 'Stripe did not return a usable payment session.');
        }

        return ['client_secret' => $clientSecret, 'session_id' => $sessionId];
    }

    public function handleWebhook(string $rawBody, string $signature): array
    {
        if ($this->webhookSecret === '') {
            throw new ApiException('payment_not_configured', 503, 'Stripe webhook is not configured.');
        }
        if ($rawBody === '' || $signature === '') {
            throw new ApiException('invalid_webhook_signature', 400, 'Invalid Stripe webhook signature.');
        }

        try {
            $event = $this->eventVerifier !== null
                ? ($this->eventVerifier)($rawBody, $signature, $this->webhookSecret)
                : Webhook::constructEvent($rawBody, $signature, $this->webhookSecret);
        } catch (UnexpectedValueException | SignatureVerificationException) {
            throw new ApiException('invalid_webhook_signature', 400, 'Invalid Stripe webhook signature.');
        }

        $eventId = $this->field($event, 'id');
        $eventType = $this->field($event, 'type');
        if (!is_string($eventId) || $eventId === '' || !is_string($eventType) || $eventType === '') {
            throw new ApiException('invalid_webhook', 400, 'Invalid Stripe webhook event.');
        }
        if (!in_array($eventType, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return ['received' => true, 'processed' => false, 'type' => $eventType];
        }

        $data = $this->field($event, 'data');
        $session = $this->field($data, 'object');
        $paymentStatus = $this->field($session, 'payment_status');
        if ($paymentStatus !== 'paid') {
            return ['received' => true, 'processed' => false, 'type' => $eventType];
        }

        $orderNumber = $this->field($session, 'client_reference_id');
        $metadata = $this->field($session, 'metadata');
        $metadataOrder = $this->field($metadata, 'pericles_order_number');
        $sessionId = $this->field($session, 'id');
        $paymentIntent = $this->field($session, 'payment_intent');
        $reference = is_string($paymentIntent) ? $paymentIntent : $this->field($paymentIntent, 'id');
        if (!is_string($reference) || $reference === '') {
            $reference = is_string($sessionId) ? $sessionId : '';
        }
        $amount = $this->field($session, 'amount_total');
        $currency = $this->field($session, 'currency');

        if (!is_string($orderNumber) || $orderNumber === '' || $metadataOrder !== $orderNumber
            || (!is_int($amount) && !is_numeric($amount)) || !is_string($currency)) {
            throw new ApiException('invalid_webhook', 400, 'Stripe payment data is incomplete.');
        }

        $result = $this->checkout->confirmPayment(
            'stripe',
            $eventId,
            $orderNumber,
            $reference,
            (int) $amount,
            strtoupper($currency)
        );
        $result['received'] = true;
        $result['processed'] = true;
        return $result;
    }

    private function field(mixed $value, string $name): mixed
    {
        if (is_array($value)) {
            return $value[$name] ?? null;
        }
        if (is_object($value)) {
            return $value->{$name} ?? null;
        }
        return null;
    }
}
