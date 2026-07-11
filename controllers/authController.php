<?php
use Firebase\JWT\JWT;

class AuthController {
    private $supabase;

    public function __construct($supabase) {
        $this->supabase = $supabase;
    }

    public function login($data) {
        $email = $data['email'] ?? '';
        $password = $data['password'] ?? '';

        if (empty($email) || empty($password)) {
            http_response_code(400);
            echo json_encode(['error' => 'Email and password are required']);
            return;
        }

        $result = $this->supabase->from('doctors')
            ->select('*')
            ->eq('email', $email)
            ->single();

        if ($result->error || !$result->data) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid email or password']);
            return;
        }

        $doctor = $result->data;
        // In the original JS, it compares plaintext password
        if ($password !== $doctor['password']) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid email or password']);
            return;
        }

        $jwtSecret = $_ENV['JWT_SECRET'] ?? 'your-secret-key';
        $payload = [
            'id' => $doctor['id'],
            'email' => $doctor['email'],
            'iat' => time(),
            'exp' => time() + (7 * 24 * 60 * 60) // 7 days
        ];

        $token = JWT::encode($payload, $jwtSecret, 'HS256');

        echo json_encode([
            'success' => true,
            'token' => $token,
            'doctor' => [
                'id' => $doctor['id'],
                'name' => $doctor['name'],
                'email' => $doctor['email'],
                'specialty' => $doctor['specialty']
            ]
        ]);
    }

    public function register($data) {
        $result = $this->supabase->from('doctors')->insert($data);

        if ($result->error) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $result->error]);
            return;
        }

        http_response_code(201);
        echo json_encode([
            'success' => true,
            'message' => 'Doctor registered',
            'doctor' => $result->data
        ]);
    }

    public function getProfile($doctor) {
        echo json_encode([
            'success' => true,
            'doctor' => $doctor
        ]);
    }
}
