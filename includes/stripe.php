<?php
/**
 * Minimal Stripe client (plain cURL, no libraries). Test mode only.
 * TrustTrade never sees card details: the buyer pays on Stripe's own page.
 */
class StripeClient
{
    private static function config(): array
    {
        $file = __DIR__ . '/../config/stripe.php';
        $cfg  = is_file($file) ? include $file : [];
        return is_array($cfg) ? $cfg : [];
    }

    /**
     * True only when a test key (sk_test_...) is configured.
     * The automated test (run_all_tests.php) can ask for the simulated payment for its own requests: it writes a
     * random token to storage/sim_payment.token and sends it in the X-TT-Sim header. Nobody else knows the token.
     */
    public static function enabled(): bool
    {
        $tokenFile = __DIR__ . '/../storage/sim_payment.token';
        $sent      = $_SERVER['HTTP_X_TT_SIM'] ?? '';
        if ($sent !== '' && is_file($tokenFile) && time() - (int) filemtime($tokenFile) < 900
            && hash_equals((string) file_get_contents($tokenFile), $sent)) {
            return false;
        }
        $key = (string) (self::config()['secret_key'] ?? '');
        return strpos($key, 'sk_test_') === 0 && strlen($key) > 20;
    }

    /** @return array decoded JSON; ['error' => ...] on failure */
    public static function request(string $method, string $path, array $params = []): array
    {
        $cfg  = self::config();
        $base = rtrim((string) ($cfg['api_base'] ?? 'https://api.stripe.com'), '/');
        $ch   = curl_init($base . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_USERPWD        => ((string) ($cfg['secret_key'] ?? '')) . ':',
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['error' => ['message' => 'Could not reach Stripe: ' . $err]];
        }
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : ['error' => ['message' => 'Unexpected answer from Stripe.']];
    }

    /** Starts a hosted checkout for one order. Amounts are in øre (DKK x 100). */
    public static function createCheckout(int $orderId, int $buyerId, string $title, float $priceDkk, string $successUrl, string $cancelUrl): array
    {
        return self::request('POST', '/v1/checkout/sessions', [
            'mode'        => 'payment',
            'success_url' => $successUrl,
            'cancel_url'  => $cancelUrl,
            'line_items'  => [[
                'quantity'   => 1,
                'price_data' => [
                    'currency'     => 'dkk',
                    'unit_amount'  => (int) round($priceDkk * 100),
                    'product_data' => ['name' => $title],
                ],
            ]],
            'metadata'    => ['order_id' => (string) $orderId, 'buyer_id' => (string) $buyerId],
        ]);
    }

    public static function retrieve(string $sessionId): array
    {
        return self::request('GET', '/v1/checkout/sessions/' . rawurlencode($sessionId));
    }
}