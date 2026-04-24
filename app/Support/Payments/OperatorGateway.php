<?php

namespace App\Support\Payments;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class OperatorGateway
{
    public function isSupported(string $operator): bool
    {
        return in_array($operator, ['airtel_money', 'moov_money', 'visa_mastercard'], true);
    }

    public function initiate(Payment $payment, string $operator, array $extraPayload = []): array
    {
        if (!$this->isSupported($operator)) {
            return ['ok' => false, 'message' => 'Unsupported payment operator'];
        }

        $cfg = (array) config("payments.gateways.{$operator}", []);
        $baseUrl = rtrim((string) ($cfg['base_url'] ?? ''), '/');
        $apiKey = (string) ($cfg['api_key'] ?? '');
        $apiSecret = (string) ($cfg['api_secret'] ?? '');

        if ($baseUrl === '' || $apiKey === '' || $apiSecret === '') {
            return [
                'ok' => false,
                'message' => "Operator {$operator} is not configured on server",
            ];
        }

        $payload = array_merge([
            'internal_payment_id' => (int) $payment->id,
            'amount' => (float) $payment->total_amount,
            'currency' => 'XAF',
            'reference' => $payment->partner_reference ?: ('NOYA-PAY-' . $payment->id . '-' . now()->timestamp),
            'description' => 'NOYA payment #' . $payment->id,
        ], $extraPayload);

        $response = Http::timeout(20)
            ->acceptJson()
            ->withHeaders([
                'X-API-KEY' => $apiKey,
                'X-API-SECRET' => $apiSecret,
            ])
            ->post("{$baseUrl}/payments/initiate", $payload);

        if (!$response->successful()) {
            return [
                'ok' => false,
                'message' => 'Operator request failed',
                'status' => $response->status(),
                'raw' => $response->json(),
            ];
        }

        $json = (array) $response->json();
        $externalRef = (string) ($json['reference'] ?? $json['transaction_id'] ?? $payload['reference']);
        $redirectUrl = (string) ($json['redirect_url'] ?? '');

        return [
            'ok' => true,
            'external_reference' => $externalRef,
            'redirect_url' => $redirectUrl,
            'raw' => $json,
        ];
    }

    public function verifyWebhookSignature(Request $request, string $operator): bool
    {
        $secret = (string) config("payments.gateways.{$operator}.webhook_secret", '');
        if ($secret === '') {
            return true;
        }

        $provided = (string) $request->header('X-Signature', '');
        if ($provided === '') {
            return false;
        }

        $computed = hash_hmac('sha256', (string) $request->getContent(), $secret);
        return hash_equals($computed, $provided);
    }

    public function normalizeWebhookStatus(array $payload): string
    {
        $raw = strtoupper((string) ($payload['status'] ?? $payload['payment_status'] ?? ''));
        if (in_array($raw, ['PAID', 'SUCCESS', 'COMPLETED', 'APPROVED'], true)) {
            return 'PAID';
        }
        return 'INIT';
    }

    public function extractReference(array $payload): ?string
    {
        $value = (string) ($payload['reference'] ?? $payload['transaction_id'] ?? $payload['partner_reference'] ?? '');
        return trim($value) !== '' ? trim($value) : null;
    }
}
