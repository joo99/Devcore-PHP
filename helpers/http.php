<?php
/**
 * ===== General HTTP helper utilities =====
 */

function jsonResponse($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function jsonError(string $message, int $status = 500): void
{
    jsonResponse(['error' => $message], $status);
}

function getJsonBody(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function nowIso(): string
{
    return (new DateTime('now', new DateTimeZone('UTC')))->format(DateTime::ATOM);
}

/**
 * Trims a time value to its first 5 characters, e.g. "14:30:00" -> "14:30"
 */
function timeShort(?string $time): ?string
{
    if ($time === null) {
        return null;
    }
    return substr($time, 0, 5);
}
