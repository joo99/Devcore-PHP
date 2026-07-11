<?php
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

function authMiddleware($supabase) {
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (empty($authHeader) || !str_starts_with($authHeader, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Please authenticate - No token provided']);
        exit;
    }

    $token = str_replace('Bearer ', '', $authHeader);
    $jwtSecret = $_ENV['JWT_SECRET'] ?? 'your-secret-key';

    try {
        $decoded = JWT::decode($token, new Key($jwtSecret, 'HS256'));
        $doctorId = $decoded->id;

        $result = $supabase->from('doctors')
            ->select('id, name, email, specialty, phone, clinic_address')
            ->eq('id', $doctorId)
            ->single();

        if ($result->error || !$result->data) {
            http_response_code(401);
            echo json_encode(['success' => false, 'error' => 'Please authenticate - Invalid token']);
            exit;
        }

        return $result->data;
    } catch (\Exception $e) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Please authenticate']);
        exit;
    }
}
