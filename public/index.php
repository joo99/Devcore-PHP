<?php
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit;
}

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/supabase.php';
require_once __DIR__ . '/../middleware/auth.php';
require_once __DIR__ . '/../controllers/authController.php';
require_once __DIR__ . '/../controllers/patientController.php';

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true) ?? [];

// Basic Router
if ($uri === '/' || $uri === '/index.php') {
    echo json_encode([
        'success' => true,
        'message' => '🏥 Clinic API is running (PHP Version)',
        'version' => '1.0.0',
        'endpoints' => [
            'auth' => '/api/auth',
            'patients' => '/api/patients',
            'public' => '/api/public',
            'chat' => '/api/chat'
        ],
        'test_account' => [
            'email' => 'ahmed@clinic.com',
            'password' => '123456'
        ]
    ]);
} elseif ($uri === '/health') {
    echo json_encode([
        'success' => true,
        'status' => 'healthy',
        'timestamp' => date('c')
    ]);
} 
// Auth Routes
elseif (str_starts_with($uri, '/api/auth/')) {
    $controller = new AuthController($supabase);
    if ($uri === '/api/auth/login' && $method === 'POST') {
        $controller->login($input);
    } elseif ($uri === '/api/auth/register' && $method === 'POST') {
        $controller->register($input);
    } elseif ($uri === '/api/auth/profile' && $method === 'GET') {
        $doctor = authMiddleware($supabase);
        $controller->getProfile($doctor);
    }
}
// Patient Routes
elseif (str_starts_with($uri, '/api/patients')) {
    $controller = new PatientController($supabase);
    if ($uri === '/api/patients' && $method === 'GET') {
        $controller->getPatients($_GET);
    } elseif ($uri === '/api/patients' && $method === 'POST') {
        $controller->addPatient($input);
    } elseif (preg_match('/\/api\/patients\/([^\/]+)\/status/', $uri, $matches) && $method === 'PATCH') {
        $controller->updatePatientStatus($matches[1], $input);
    } elseif (preg_match('/\/api\/patients\/([^\/]+)/', $uri, $matches)) {
        if ($method === 'PUT') {
            $controller->updatePatient($matches[1], $input);
        } elseif ($method === 'DELETE') {
            $controller->deletePatient($matches[1]);
        }
    }
}
// Public Routes
elseif (str_starts_with($uri, '/api/public/')) {
    require_once __DIR__ . '/../controllers/publicController.php';
    $controller = new PublicController($supabase);
    if (preg_match('/\/api\/public\/doctor\/([^\/]+)/', $uri, $matches) && $method === 'GET') {
        $controller->getDoctor($matches[1]);
    } elseif ($uri === '/api/public/book-visit' && $method === 'POST') {
        $controller->bookVisit($input);
    }
}
// Chat Routes
elseif (str_starts_with($uri, '/api/chat/')) {
    require_once __DIR__ . '/../controllers/chatController.php';
    $controller = new ChatController($supabase);
    if ($uri === '/api/chat/rooms' && $method === 'POST') {
        $controller->createChatRoom($input);
    } elseif ($uri === '/api/chat/rooms' && $method === 'GET') {
        $controller->getChatRooms($_GET);
    } elseif (preg_match('/\/api\/chat\/rooms\/([^\/]+)\/status/', $uri, $matches) && $method === 'PUT') {
        $controller->updateChatRoomStatus($matches[1], $input);
    } elseif (preg_match('/\/api\/chat\/rooms\/([^\/]+)/', $uri, $matches)) {
        if ($method === 'GET') {
            $controller->getChatRoom($matches[1]);
        } elseif ($method === 'DELETE') {
            $controller->deleteChatRoom($matches[1], $_GET);
        }
    } elseif ($uri === '/api/chat/messages' && $method === 'POST') {
        $controller->sendMessage($input);
    }
}
else {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Route not found']);
}
