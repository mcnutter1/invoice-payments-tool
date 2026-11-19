<?php

namespace App\Services\Payments;

use App\Exceptions\DomainException;
use App\Models\Business;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Minimal Stripe REST client (form-encoded API, per-business secret key). */
class StripeGateway
{
    public function __construct(private readonly string $secret) {}

    public static function for(Business $b): self
    {
        $secret = $b->stripeSecret();
        if (! $secret) throw new DomainException('Card payments are not connected for this business.');

        return new self($secret);
    }

    public static function looksLikeSecret(string $key): bool
    {
        return (bool) preg_match('/^(sk|rk)_(live|test)_[A-Za-z0-9]{10,}$/', $key);
    }

    public function get(string $path, array $query = []): array
    {
        return $this->handle($this->client()->get($this->url($path), $query));
    }

    public function post(string $path, array $params, ?string $idempotencyKey = null): array
    {
        $req = $this->client()->asForm();
        if ($idempotencyKey) $req = $req->withHeaders(['Idempotency-Key' => $idempotencyKey]);

        return $this->handle($req->post($this->url($path), $params));
    }

    /** Stripe-Signature: "t=…,v1=…[,v1=…]" over "{t}.{payload}" with the endpoint secret. */
    public static function verifySignature(string $payload, ?string $header, string $secret, int $tolerance = 300): bool
    {
        if (! $header || $secret === '') return false;
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') $t = $v;
            if ($k === 'v1') $sigs[] = $v;
        }
        if (! ctype_digit((string) $t) || ! $sigs || abs(time() - (int) $t) > $tolerance) return false;
        $expected = hash_hmac('sha256', $t.'.'.$payload, $secret);
        foreach ($sigs as $s) {
            if (hash_equals($expected, $s)) return true;
        }

        return false;
    }

    private function client()
    {
        return Http::withToken($this->secret)->acceptJson()->timeout((int) config('finance.stripe.timeout', 20))
            ->withHeaders(['Stripe-Version' => '2024-06-20']);
    }

    private function url(string $path): string
    {
        return rtrim((string) config('finance.stripe.api_base'), '/').'/'.ltrim($path, '/');
    }

    private function handle(Response $res): array
    {
        if ($res->successful()) return (array) $res->json();
        $err = (array) $res->json('error');
        throw new StripeError($err['message'] ?? 'Stripe request failed ('.$res->status().').', $res->status(), $err['code'] ?? null, $err['decline_code'] ?? null);
    }
}
