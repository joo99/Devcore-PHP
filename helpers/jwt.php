<?php
/**
 * ===== Minimal JWT (HS256) implementation, no external libraries =====
 * Enough to sign and verify tokens, matching the original code (jsonwebtoken)
 */

function base64UrlEncode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string
{
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * @param array $payload  token data (id, email, role...)
 * @param string $secret  signing secret
 * @param int $expiresInSeconds  lifetime in seconds (default 7 days, as in the original)
 */
function jwtSign(array $payload, string $secret, int $expiresInSeconds = 7 * 24 * 60 * 60): string
{
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];

    $payload['iat'] = time();
    $payload['exp'] = time() + $expiresInSeconds;

    $segments = [
        base64UrlEncode(json_encode($header)),
        base64UrlEncode(json_encode($payload)),
    ];

    $signature = hash_hmac('sha256', implode('.', $segments), $secret, true);
    $segments[] = base64UrlEncode($signature);

    return implode('.', $segments);
}

/**
 * Verifies the token and returns its payload, or null if invalid/expired
 */
function jwtVerify(string $token, string $secret): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }

    [$headerB64, $payloadB64, $signatureB64] = $parts;

    $expectedSignature = hash_hmac('sha256', "$headerB64.$payloadB64", $secret, true);
    $actualSignature = base64UrlDecode($signatureB64);

    if (!hash_equals($expectedSignature, $actualSignature)) {
        return null;
    }

    $payload = json_decode(base64UrlDecode($payloadB64), true);
    if (!is_array($payload)) {
        return null;
    }

    if (isset($payload['exp']) && time() > $payload['exp']) {
        return null; // expired
    }

    return $payload;
}

/**
 * Extracts the token from the "Authorization: Bearer xxx" header and verifies it.
 * Used as an optional middleware on protected routes.
 */
function requireAdminAuth(): array
{
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
        jsonError('غير مصرح - التوكن مطلوب', 401);
    }

    $token = substr($authHeader, 7);
    $payload = jwtVerify($token, JWT_SECRET);

    if (!$payload) {
        jsonError('التوكن غير صالح أو منتهي الصلاحية', 401);
    }

    return $payload;
}
