<?php
/**
 * Clinic API - PHP version
 * Direct port from Express.js + Database JS SDK to PHP + PDO (now MySQL)
 * Version: 3.0.1
 */

declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // errors are logged only, never printed in the response

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../helpers/http.php';
require_once __DIR__ . '/../helpers/jwt.php';
require_once __DIR__ . '/../helpers/slots.php';
require_once __DIR__ . '/../helpers/chatHelper.php';

// ===== CORS (equivalent to app.use(cors())) =====
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = rtrim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/');
if ($uri === '') {
    $uri = '/';
}

// ===== Simple router =====
// each entry: [method, regex pattern, handler]
$routes = [];

function route(array &$routes, string $method, string $pattern, callable $handler): void
{
    $routes[] = [$method, $pattern, $handler];
}

function dispatch(array $routes, string $method, string $uri): void
{
    foreach ($routes as [$routeMethod, $pattern, $handler]) {
        if ($routeMethod !== $method) {
            continue;
        }
        if (preg_match($pattern, $uri, $matches)) {
            $handler($matches);
            return;
        }
    }
    jsonError('المسار غير موجود', 404);
}

// ============================
// Auth helpers (RBAC)
// ============================

/**
 * Extracts the Bearer token from the Authorization header (robust across servers).
 */
function bearerToken(): ?string
{
    $auth = '';
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strcasecmp($k, 'Authorization') === 0) {
                $auth = $v;
                break;
            }
        }
    }
    if ($auth === '') {
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    }
    return stripos($auth, 'Bearer ') === 0 ? substr($auth, 7) : null;
}

/**
 * Verifies the token and loads the current account from the DB (so role changes
 * and deletions take effect immediately). Sends 401 and exits on failure.
 * Returns [id, email, role].
 */
function currentAccount(PDO $db): array
{
    $token = bearerToken();
    if (!$token) {
        jsonError('غير مصرح - التوكن مطلوب', 401);
    }
    $payload = jwtVerify($token, JWT_SECRET);
    if (!$payload || !isset($payload['id'])) {
        jsonError('التوكن غير صالح أو منتهي الصلاحية', 401);
    }
    $stmt = $db->prepare('SELECT id, email, role FROM admins WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $payload['id']]);
    $account = $stmt->fetch();
    if (!$account) {
        jsonError('الحساب غير موجود', 401);
    }
    return $account;
}

/**
 * Ensures the account has one of the allowed roles; sends 403 otherwise.
 */
function requireRole(array $account, array $roles): void
{
    if (!in_array($account['role'], $roles, true)) {
        jsonError('غير مصرح - صلاحيات غير كافية', 403);
    }
}

// ============================
// Test / Health routes
// ============================

route($routes, 'GET', '#^/$#', function () {
    jsonResponse([
        'message' => '🚀 Clinic API is running!',
        'database_connected' => isDbConfigured(),
        'version' => '3.0.1',
    ]);
});

route($routes, 'GET', '#^/api/health$#', function () {
    jsonResponse([
        'status' => 'OK',
        'timestamp' => nowIso(),
        'database' => isDbConfigured() ? 'Configured ✅' : 'Missing ❌',
    ]);
});

// ============================
// 1. Login (Admin Login) with Token
// ============================

route($routes, 'POST', '#^/api/admin/login$#', function () {
    $body = getJsonBody();
    $email = $body['email'] ?? null;
    $password = $body['password'] ?? null;

    if (!$email || !$password) {
        jsonError('البريد الإلكتروني وكلمة المرور مطلوبان', 400);
    }

    $db = getDb();
    $stmt = $db->prepare('SELECT * FROM admins WHERE email = :email AND password = :password LIMIT 1');
    $stmt->execute(['email' => $email, 'password' => $password]);
    $admin = $stmt->fetch();

    if (!$admin) {
        jsonError('بيانات الدخول غير صحيحة', 401);
    }

    $role = $admin['role'] ?? 'admin';

    $token = jwtSign([
        'id' => $admin['id'],
        'email' => $admin['email'],
        'role' => $role,
    ], JWT_SECRET);

    // The response key follows the role: user -> "user"; admin/superadmin -> "admin".
    // (admin/user responses stay unchanged; superadmin returns under "admin" with role="superadmin")
    $accountKey = $role === 'user' ? 'user' : 'admin';

    jsonResponse([
        'success' => true,
        'message' => 'تم تسجيل الدخول بنجاح',
        'token' => $token,
        $accountKey => [
            'id' => $admin['id'],
            'email' => $admin['email'],
            'role' => $role,
        ],
    ]);
});

// ============================
// 2. Departments
// ============================

route($routes, 'GET', '#^/api/departments$#', function () {
    $db = getDb();

    $departments = $db->query('SELECT * FROM departments ORDER BY `order` ASC')->fetchAll();

    $typesStmt = $db->prepare('SELECT * FROM doctor_types WHERE enabled = TRUE');
    $typesStmt->execute();
    $doctorTypes = $typesStmt->fetchAll();

    $result = [];
    foreach ($departments as $dept) {
        $types = array_values(array_filter($doctorTypes, fn ($dt) => $dt['department_id'] === $dept['id']));

        $formattedTypes = [];
        foreach ($types as $dt) {
            $dt['enabled'] = (bool) $dt['enabled'];
            $slots = fetchCustomSlots($db, $dt['id']);
            $dt['custom_slots'] = array_map(fn ($slot) => formatSlot($db, $slot), $slots);
            $formattedTypes[] = $dt;
        }

        $dept['doctor_types'] = $formattedTypes;
        $result[] = $dept;
    }

    jsonResponse($result);
});

route($routes, 'GET', '#^/api/departments/([^/]+)$#', function (array $p) {
    $db = getDb();
    $id = $p[1];

    $stmt = $db->prepare('SELECT * FROM departments WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $department = $stmt->fetch();

    if (!$department) {
        jsonError('القسم غير موجود', 404);
    }

    $typesStmt = $db->prepare('SELECT * FROM doctor_types WHERE department_id = :id ORDER BY type ASC');
    $typesStmt->execute(['id' => $id]);
    $doctorTypes = $typesStmt->fetchAll();

    $formattedTypes = [];
    foreach ($doctorTypes as $dt) {
        $dt['enabled'] = (bool) $dt['enabled'];
        $slotsStmt = $db->prepare(
            'SELECT id, date, capacity, from_time, to_time FROM custom_slots WHERE doctor_type_id = :id'
        );
        $slotsStmt->execute(['id' => $dt['id']]);
        $slots = $slotsStmt->fetchAll();

        $dt['custom_slots'] = array_map(fn ($slot) => formatSlot($db, $slot), $slots);
        $formattedTypes[] = $dt;
    }

    $department['doctor_types'] = $formattedTypes;

    jsonResponse($department);
});

route($routes, 'POST', '#^/api/departments$#', function () {
    $db = getDb();
    $body = getJsonBody();

    $name = $body['name'] ?? null;
    $iconUrl = $body['icon_url'] ?? null;
    $doctorTypes = $body['doctor_types'] ?? null;

    if (!$name) {
        jsonError('اسم القسم مطلوب', 400);
    }

    $maxOrderStmt = $db->query('SELECT `order` FROM departments ORDER BY `order` DESC LIMIT 1');
    $maxOrderRow = $maxOrderStmt->fetch();
    $nextOrder = $maxOrderRow ? ((int) $maxOrderRow['order'] + 1) : 1;

    try {
        $db->beginTransaction();

        $insertDept = $db->prepare(
            'INSERT INTO departments (name, icon_url, `order`, created_at, updated_at)
             VALUES (:name, :icon_url, :order, NOW(6), NOW(6))'
        );
        $insertDept->execute([
            'name' => $name,
            'icon_url' => $iconUrl,
            'order' => $nextOrder,
        ]);
        $department = fetchRowById($db, 'departments', (int) $db->lastInsertId());

        $addedTypes = [];
        if (is_array($doctorTypes) && count($doctorTypes) > 0) {
            $insertType = $db->prepare(
                'INSERT INTO doctor_types (department_id, type, label, enabled, created_at, updated_at)
                 VALUES (:department_id, :type, :label, :enabled, NOW(6), NOW(6))'
            );

            foreach ($doctorTypes as $type) {
                $label = $type['label'] ?? (($type['type'] ?? '') === 'male' ? 'دكتور' : 'دكتورة');
                $enabled = $type['enabled'] ?? true;

                $insertType->execute([
                    'department_id' => $department['id'],
                    'type' => $type['type'] ?? null,
                    'label' => $label,
                    'enabled' => $enabled ? 1 : 0,
                ]);
                $newType = fetchRowById($db, 'doctor_types', (int) $db->lastInsertId());
                $newType['enabled'] = (bool) $newType['enabled'];
                $addedTypes[] = $newType;
            }
        }

        $db->commit();

        $department['doctor_types'] = $addedTypes;
        jsonResponse($department, 201);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('❌ Server error: ' . $e->getMessage());
        jsonError('Server error: ' . $e->getMessage(), 500);
    }
});

route($routes, 'PUT', '#^/api/departments/reorder$#', function () {
    $db = getDb();
    $body = getJsonBody();
    $orderedIds = $body['ordered_ids'] ?? null;

    if (!is_array($orderedIds)) {
        jsonError('ordered_ids مطلوب كمصفوفة', 400);
    }

    try {
        $stmt = $db->prepare('UPDATE departments SET `order` = :order, updated_at = NOW(6) WHERE id = :id');
        foreach ($orderedIds as $index => $id) {
            $stmt->execute(['order' => $index + 1, 'id' => $id]);
        }
        jsonResponse(['message' => 'تم إعادة ترتيب الأقسام بنجاح']);
    } catch (Throwable $e) {
        error_log('❌ Server error: ' . $e->getMessage());
        jsonError('Server error: ' . $e->getMessage(), 500);
    }
});

route($routes, 'PUT', '#^/api/departments/([^/]+)/doctor-types$#', function (array $p) {
    $db = getDb();
    $id = $p[1];
    $body = getJsonBody();
    $doctorTypes = $body['doctor_types'] ?? null;

    if (!is_array($doctorTypes)) {
        jsonError('doctor_types مطلوب كمصفوفة', 400);
    }

    try {
        $upsert = $db->prepare(
            'INSERT INTO doctor_types (department_id, type, label, enabled, updated_at)
             VALUES (:department_id, :type, :label, :enabled, NOW(6))
             ON DUPLICATE KEY UPDATE label = VALUES(label), enabled = VALUES(enabled), updated_at = NOW(6)'
        );

        foreach ($doctorTypes as $type) {
            $label = $type['label'] ?? (($type['type'] ?? '') === 'male' ? 'دكتور' : 'دكتورة');
            $enabled = $type['enabled'] ?? true;

            $upsert->execute([
                'department_id' => $id,
                'type' => $type['type'] ?? null,
                'label' => $label,
                'enabled' => $enabled ? 1 : 0,
            ]);
        }

        $select = $db->prepare('SELECT * FROM doctor_types WHERE department_id = :id');
        $select->execute(['id' => $id]);
        $doctorTypesRows = $select->fetchAll();
        foreach ($doctorTypesRows as &$dtRow) {
            $dtRow['enabled'] = (bool) $dtRow['enabled'];
        }
        unset($dtRow);

        jsonResponse([
            'department_id' => $id,
            'doctor_types' => $doctorTypesRows,
        ]);
    } catch (Throwable $e) {
        error_log('❌ Server error: ' . $e->getMessage());
        jsonError('Server error: ' . $e->getMessage(), 500);
    }
});

route($routes, 'PUT', '#^/api/departments/([^/]+)$#', function (array $p) {
    $db = getDb();
    $id = $p[1];
    $body = getJsonBody();

    $fields = [];
    $params = ['id' => $id];

    if (!empty($body['name'])) {
        $fields[] = 'name = :name';
        $params['name'] = $body['name'];
    }
    if (array_key_exists('icon_url', $body)) {
        $fields[] = 'icon_url = :icon_url';
        $params['icon_url'] = $body['icon_url'];
    }
    $fields[] = 'updated_at = NOW(6)';

    $sql = 'UPDATE departments SET ' . implode(', ', $fields) . ' WHERE id = :id';

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $data = fetchRowById($db, 'departments', $id);

        if (!$data) {
            jsonError('القسم غير موجود', 404);
        }

        jsonResponse($data);
    } catch (Throwable $e) {
        error_log('❌ Server error: ' . $e->getMessage());
        jsonError('Server error: ' . $e->getMessage(), 500);
    }
});

route($routes, 'DELETE', '#^/api/departments/([^/]+)$#', function (array $p) {
    $db = getDb();
    $id = $p[1];

    try {
        $stmt = $db->prepare('DELETE FROM departments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        jsonResponse(['message' => 'تم حذف القسم بنجاح']);
    } catch (Throwable $e) {
        error_log('❌ Server error: ' . $e->getMessage());
        jsonError('Server error: ' . $e->getMessage(), 500);
    }
});

// ============================
// 4. Custom Slots
// ============================

route($routes, 'GET', '#^/api/departments/([^/]+)/doctor-types/([^/]+)/custom-slots$#', function (array $p) {
    $db = getDb();
    $departmentId = $p[1];
    $type = $p[2];
    $date = $_GET['date'] ?? null;

    if (!$date) {
        jsonError('التاريخ مطلوب', 400);
    }

    $typeStmt = $db->prepare('SELECT id FROM doctor_types WHERE department_id = :dept AND type = :type LIMIT 1');
    $typeStmt->execute(['dept' => $departmentId, 'type' => $type]);
    $doctorType = $typeStmt->fetch();

    if (!$doctorType) {
        jsonError('نوع الطبيب غير موجود', 404);
    }

    $slotsStmt = $db->prepare(
        'SELECT * FROM custom_slots WHERE doctor_type_id = :dt_id AND date = :date ORDER BY from_time ASC'
    );
    $slotsStmt->execute(['dt_id' => $doctorType['id'], 'date' => $date]);
    $slots = $slotsStmt->fetchAll();

    $formattedSlots = array_map(fn ($slot) => formatSlot($db, $slot), $slots);

    jsonResponse([
        'doctor_type' => $type,
        'date' => $date,
        'custom_slots' => $formattedSlots,
    ]);
});

route($routes, 'GET', '#^/api/departments/([^/]+)/doctor-types/([^/]+)/slots$#', function (array $p) {
    $db = getDb();
    $departmentId = $p[1];
    $type = $p[2];
    $date = $_GET['date'] ?? null;

    if (!$date) {
        jsonError('التاريخ مطلوب', 400);
    }

    $typeStmt = $db->prepare(
        'SELECT id, type, label FROM doctor_types WHERE department_id = :dept AND type = :type LIMIT 1'
    );
    $typeStmt->execute(['dept' => $departmentId, 'type' => $type]);
    $doctorType = $typeStmt->fetch();

    if (!$doctorType) {
        jsonError('نوع الطبيب غير موجود', 404);
    }

    $slotsStmt = $db->prepare(
        'SELECT * FROM custom_slots WHERE doctor_type_id = :dt_id AND date = :date ORDER BY from_time ASC'
    );
    $slotsStmt->execute(['dt_id' => $doctorType['id'], 'date' => $date]);
    $slots = $slotsStmt->fetchAll();

    $formattedSlots = array_map(fn ($slot) => formatSlot($db, $slot), $slots);

    jsonResponse([
        'doctor_type' => $type,
        'doctor_label' => $doctorType['label'],
        'date' => $date,
        'slots' => $formattedSlots,
    ]);
});

route($routes, 'POST', '#^/api/departments/([^/]+)/doctor-types/([^/]+)/custom-slots$#', function (array $p) {
    $db = getDb();
    $departmentId = $p[1];
    $type = $p[2];
    $body = getJsonBody();

    $date = $body['date'] ?? null;
    $capacity = $body['capacity'] ?? null;
    $fromTime = $body['from_time'] ?? null;
    $toTime = $body['to_time'] ?? null;

    if (!$date || !$capacity || !$fromTime || !$toTime) {
        jsonError('جميع الحقول مطلوبة', 400);
    }

    $typeStmt = $db->prepare('SELECT id FROM doctor_types WHERE department_id = :dept AND type = :type LIMIT 1');
    $typeStmt->execute(['dept' => $departmentId, 'type' => $type]);
    $doctorType = $typeStmt->fetch();

    if (!$doctorType) {
        jsonError('نوع الطبيب غير موجود', 404);
    }

    try {
        $insert = $db->prepare(
            'INSERT INTO custom_slots (doctor_type_id, date, capacity, from_time, to_time, created_at, updated_at)
             VALUES (:dt_id, :date, :capacity, :from_time, :to_time, NOW(6), NOW(6))'
        );
        $insert->execute([
            'dt_id' => $doctorType['id'],
            'date' => $date,
            'capacity' => $capacity,
            'from_time' => $fromTime,
            'to_time' => $toTime,
        ]);

        jsonResponse(fetchRowById($db, 'custom_slots', (int) $db->lastInsertId()), 201);
    } catch (Throwable $e) {
        error_log('❌ Server error: ' . $e->getMessage());
        jsonError('Server error: ' . $e->getMessage(), 500);
    }
});

route(
    $routes,
    'PUT',
    '#^/api/departments/([^/]+)/doctor-types/([^/]+)/custom-slots/([^/]+)$#',
    function (array $p) {
        $db = getDb();
        $slotId = $p[3];
        $body = getJsonBody();

        $fields = [];
        $params = ['id' => $slotId];

        if (array_key_exists('capacity', $body)) {
            $fields[] = 'capacity = :capacity';
            $params['capacity'] = $body['capacity'];
        }
        if (!empty($body['from_time'])) {
            $fields[] = 'from_time = :from_time';
            $params['from_time'] = $body['from_time'];
        }
        if (!empty($body['to_time'])) {
            $fields[] = 'to_time = :to_time';
            $params['to_time'] = $body['to_time'];
        }
        $fields[] = 'updated_at = NOW(6)';

        try {
            $sql = 'UPDATE custom_slots SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $data = fetchRowById($db, 'custom_slots', $slotId);

            if (!$data) {
                jsonError('الفترة غير موجودة', 404);
            }

            $currentBookings = countBookingsForSlot($db, $data['id']);

            jsonResponse([
                'message' => 'تم تحديث الفترة بنجاح',
                'slot' => [
                    'id' => $data['id'],
                    'date' => $data['date'],
                    'from_time' => $data['from_time'],
                    'to_time' => $data['to_time'],
                    'capacity' => $data['capacity'],
                    'current_bookings' => $currentBookings,
                    'remaining' => $data['capacity'] - $currentBookings,
                    'available' => $currentBookings < $data['capacity'],
                ],
            ]);
        } catch (Throwable $e) {
            error_log('❌ Server error: ' . $e->getMessage());
            jsonError('Server error: ' . $e->getMessage(), 500);
        }
    }
);

route(
    $routes,
    'PATCH',
    '#^/api/departments/([^/]+)/doctor-types/([^/]+)/custom-slots/([^/]+)/capacity$#',
    function (array $p) {
        $db = getDb();
        $slotId = $p[3];
        $body = getJsonBody();
        $capacity = $body['capacity'] ?? null;

        if ($capacity === null || $capacity < 0) {
            jsonError('السعة مطلوبة ويجب أن تكون أكبر من أو تساوي 0', 400);
        }

        $fetchStmt = $db->prepare(
            'SELECT id, capacity, date, from_time, to_time FROM custom_slots WHERE id = :id LIMIT 1'
        );
        $fetchStmt->execute(['id' => $slotId]);
        $currentSlot = $fetchStmt->fetch();

        if (!$currentSlot) {
            jsonError('الفترة غير موجودة', 404);
        }

        $currentBookings = countBookingsForSlot($db, $slotId);

        if ($capacity < $currentBookings) {
            jsonResponse([
                'error' => "لا يمكن تقليل السعة إلى أقل من عدد الحجوزات الحالية ($currentBookings)",
                'current_bookings' => $currentBookings,
                'requested_capacity' => $capacity,
            ], 400);
        }

        try {
            $update = $db->prepare(
                'UPDATE custom_slots SET capacity = :capacity, updated_at = NOW(6) WHERE id = :id'
            );
            $update->execute(['capacity' => $capacity, 'id' => $slotId]);
            $data = fetchRowById($db, 'custom_slots', $slotId);

            jsonResponse([
                'message' => 'تم تحديث السعة بنجاح',
                'slot' => [
                    'id' => $data['id'],
                    'date' => $data['date'],
                    'from_time' => $data['from_time'],
                    'to_time' => $data['to_time'],
                    'capacity' => $data['capacity'],
                    'current_bookings' => $currentBookings,
                    'remaining' => $data['capacity'] - $currentBookings,
                    'available' => $currentBookings < $data['capacity'],
                ],
            ]);
        } catch (Throwable $e) {
            error_log('❌ Server error: ' . $e->getMessage());
            jsonError('Server error: ' . $e->getMessage(), 500);
        }
    }
);

route(
    $routes,
    'DELETE',
    '#^/api/departments/([^/]+)/doctor-types/([^/]+)/custom-slots/([^/]+)$#',
    function (array $p) {
        $db = getDb();
        $slotId = $p[3];

        try {
            $stmt = $db->prepare('DELETE FROM custom_slots WHERE id = :id');
            $stmt->execute(['id' => $slotId]);
            jsonResponse(['message' => 'تم حذف الفترة المخصصة بنجاح']);
        } catch (Throwable $e) {
            error_log('❌ Server error: ' . $e->getMessage());
            jsonError('Server error: ' . $e->getMessage(), 500);
        }
    }
);

// ============================
// 5. Save changes
// ============================

route($routes, 'PUT', '#^/api/departments/([^/]+)/save$#', function (array $p) {
    $db = getDb();
    $id = $p[1];
    $body = getJsonBody();

    $name = $body['name'] ?? null;
    $iconUrl = array_key_exists('icon_url', $body) ? $body['icon_url'] : null;
    $hasIconUrl = array_key_exists('icon_url', $body);
    $doctorTypes = $body['doctor_types'] ?? null;

    try {
        $db->beginTransaction();

        if ($name || $hasIconUrl) {
            $fields = [];
            $params = ['id' => $id];
            if ($name) {
                $fields[] = 'name = :name';
                $params['name'] = $name;
            }
            if ($hasIconUrl) {
                $fields[] = 'icon_url = :icon_url';
                $params['icon_url'] = $iconUrl;
            }
            $fields[] = 'updated_at = NOW(6)';

            $stmt = $db->prepare('UPDATE departments SET ' . implode(', ', $fields) . ' WHERE id = :id');
            $stmt->execute($params);
        }

        if (is_array($doctorTypes)) {
            foreach ($doctorTypes as $typeData) {
                $selectType = $db->prepare(
                    'SELECT id FROM doctor_types WHERE department_id = :dept AND type = :type LIMIT 1'
                );
                $selectType->execute(['dept' => $id, 'type' => $typeData['type'] ?? null]);
                $doctorType = $selectType->fetch();

                if (!$doctorType) {
                    $label = $typeData['label'] ?? (($typeData['type'] ?? '') === 'male' ? 'دكتور' : 'دكتورة');
                    $enabled = $typeData['enabled'] ?? true;

                    $insertType = $db->prepare(
                        'INSERT INTO doctor_types (department_id, type, label, enabled, created_at, updated_at)
                         VALUES (:dept, :type, :label, :enabled, NOW(6), NOW(6))'
                    );
                    $insertType->execute([
                        'dept' => $id,
                        'type' => $typeData['type'] ?? null,
                        'label' => $label,
                        'enabled' => $enabled ? 1 : 0,
                    ]);
                    $doctorType = fetchRowById($db, 'doctor_types', (int) $db->lastInsertId());
                } else {
                    $fields = ['updated_at = NOW(6)'];
                    $params = ['id' => $doctorType['id']];
                    if (array_key_exists('enabled', $typeData)) {
                        $fields[] = 'enabled = :enabled';
                        $params['enabled'] = $typeData['enabled'] ? 1 : 0;
                    }
                    if (!empty($typeData['label'])) {
                        $fields[] = 'label = :label';
                        $params['label'] = $typeData['label'];
                    }
                    $updateType = $db->prepare(
                        'UPDATE doctor_types SET ' . implode(', ', $fields) . ' WHERE id = :id'
                    );
                    $updateType->execute($params);
                }

                $deleteSlots = $db->prepare('DELETE FROM custom_slots WHERE doctor_type_id = :dt_id');
                $deleteSlots->execute(['dt_id' => $doctorType['id']]);

                if (!empty($typeData['custom_slots']) && is_array($typeData['custom_slots'])) {
                    $insertSlot = $db->prepare(
                        'INSERT INTO custom_slots (doctor_type_id, date, capacity, from_time, to_time, created_at, updated_at)
                         VALUES (:dt_id, :date, :capacity, :from_time, :to_time, NOW(6), NOW(6))'
                    );
                    foreach ($typeData['custom_slots'] as $slot) {
                        $insertSlot->execute([
                            'dt_id' => $doctorType['id'],
                            'date' => $slot['date'] ?? null,
                            'capacity' => $slot['capacity'] ?? null,
                            'from_time' => $slot['from_time'] ?? null,
                            'to_time' => $slot['to_time'] ?? null,
                        ]);
                    }
                }
            }
        }

        $db->commit();

        // ===== Re-fetch the department with everything after the update =====
        $deptStmt = $db->prepare('SELECT * FROM departments WHERE id = :id LIMIT 1');
        $deptStmt->execute(['id' => $id]);
        $updatedDepartment = $deptStmt->fetch();

        $typesStmt = $db->prepare('SELECT * FROM doctor_types WHERE department_id = :id');
        $typesStmt->execute(['id' => $id]);
        $doctorTypesRows = $typesStmt->fetchAll();

        $doctorTypesWithBookings = [];
        foreach ($doctorTypesRows as $dt) {
            $dt['enabled'] = (bool) $dt['enabled'];
            $slots = fetchCustomSlots($db, $dt['id']);
            $dt['custom_slots'] = array_map(function ($slot) use ($db) {
                $currentBookings = countBookingsForSlot($db, $slot['id']);
                return array_merge($slot, [
                    'current_bookings' => $currentBookings,
                    'remaining' => $slot['capacity'] - $currentBookings,
                    'available' => $currentBookings < $slot['capacity'],
                ]);
            }, $slots);
            $doctorTypesWithBookings[] = $dt;
        }

        $updatedDepartment['doctor_types'] = $doctorTypesWithBookings;

        jsonResponse([
            'success' => true,
            'department' => $updatedDepartment,
        ]);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('❌ Server error: ' . $e->getMessage());
        jsonError('Server error: ' . $e->getMessage(), 500);
    }
});

// ============================
// 6. Bookings
// ============================

route($routes, 'POST', '#^/api/bookings$#', function () {
    $db = getDb();
    $body = getJsonBody();

    $departmentId = $body['department_id'] ?? null;
    $doctorType = $body['doctor_type'] ?? null;
    $slotId = $body['slot_id'] ?? null;
    $bookingDate = $body['booking_date'] ?? null;
    $bookingTime = $body['booking_time'] ?? null;
    $patientName = $body['patient_name'] ?? null;
    $patientAge = $body['patient_age'] ?? null;
    $patientPhone = $body['patient_phone'] ?? null;
    $patientGender = $body['patient_gender'] ?? null;

    if (!$departmentId || !$doctorType || !$slotId || !$bookingDate || !$patientName || !$patientAge || !$patientPhone || !$patientGender) {
        jsonError('جميع الحقول مطلوبة', 400);
    }

    if (!in_array($patientGender, ['male', 'female'], true)) {
        jsonError('الجنس يجب أن يكون male أو female', 400);
    }

    // ✅ Phone number uniqueness check has been removed to allow multiple bookings with same phone

    $typeStmt = $db->prepare('SELECT id FROM doctor_types WHERE department_id = :dept AND type = :type LIMIT 1');
    $typeStmt->execute(['dept' => $departmentId, 'type' => $doctorType]);
    $doctorTypeRow = $typeStmt->fetch();

    if (!$doctorTypeRow) {
        jsonError('نوع الطبيب غير موجود', 404);
    }

    $slotStmt = $db->prepare(
        'SELECT id, capacity, from_time, to_time FROM custom_slots
         WHERE id = :id AND doctor_type_id = :dt_id AND date = :date LIMIT 1'
    );
    $slotStmt->execute([
        'id' => $slotId,
        'dt_id' => $doctorTypeRow['id'],
        'date' => $bookingDate,
    ]);
    $customSlot = $slotStmt->fetch();

    if (!$customSlot) {
        jsonError('الموعد غير موجود', 404);
    }

    $currentCount = countBookingsForSlot($db, $slotId);

    if ($currentCount >= $customSlot['capacity']) {
        jsonResponse([
            'error' => 'الموعد مكتمل، لا توجد أماكن متاحة',
            'capacity' => $customSlot['capacity'],
            'current_bookings' => $currentCount,
            'remaining' => 0,
        ], 400);
    }

    $finalBookingTime = $bookingTime ?: (timeShort($customSlot['from_time']) . ' - ' . timeShort($customSlot['to_time']));

    try {
        $insert = $db->prepare(
            'INSERT INTO bookings
                (department_id, doctor_type_id, custom_slot_id, booking_date, booking_time,
                 patient_name, patient_age, patient_phone, patient_gender, created_at, updated_at)
             VALUES
                (:department_id, :doctor_type_id, :custom_slot_id, :booking_date, :booking_time,
                 :patient_name, :patient_age, :patient_phone, :patient_gender, NOW(6), NOW(6))'
        );
        $insert->execute([
            'department_id' => $departmentId,
            'doctor_type_id' => $doctorTypeRow['id'],
            'custom_slot_id' => $slotId,
            'booking_date' => $bookingDate,
            'booking_time' => $finalBookingTime,
            'patient_name' => $patientName,
            'patient_age' => $patientAge,
            'patient_phone' => $patientPhone,
            'patient_gender' => $patientGender,
        ]);
        $booking = fetchRowById($db, 'bookings', (int) $db->lastInsertId());

        $newCount = countBookingsForSlot($db, $slotId);

        jsonResponse([
            'success' => true,
            'booking' => $booking,
            'capacity' => $customSlot['capacity'],
            'current_bookings' => $newCount,
            'remaining' => $customSlot['capacity'] - $newCount,
        ], 201);
    } catch (Throwable $e) {
        error_log('❌ Server error: ' . $e->getMessage());
        jsonError('Server error: ' . $e->getMessage(), 500);
    }
});

/**
 * Fetches bookings joined with department/doctor/slot data (used by more than one route)
 */
function fetchBookingsWithRelations(PDO $db, ?string $departmentId = null): array
{
    $sql = '
        SELECT
            b.*,
            d.id AS d_id, d.name AS d_name, d.icon_url AS d_icon_url,
            dt.id AS dt_id, dt.type AS dt_type, dt.label AS dt_label,
            cs.id AS cs_id, cs.date AS cs_date, cs.from_time AS cs_from_time,
            cs.to_time AS cs_to_time, cs.capacity AS cs_capacity
        FROM bookings b
        LEFT JOIN departments d ON d.id = b.department_id
        LEFT JOIN doctor_types dt ON dt.id = b.doctor_type_id
        LEFT JOIN custom_slots cs ON cs.id = b.custom_slot_id
    ';

    $params = [];
    if ($departmentId !== null) {
        $sql .= ' WHERE b.department_id = :dept_id';
        $params['dept_id'] = $departmentId;
    }
    $sql .= ' ORDER BY b.created_at DESC';

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

route($routes, 'GET', '#^/api/bookings/all$#', function () {
    $db = getDb();
    $rows = fetchBookingsWithRelations($db);

    $formatted = array_map(function ($row) use ($db) {
        $currentBookings = $row['cs_id'] ? countBookingsForSlot($db, $row['cs_id']) : 0;
        $slotFrom = timeShort($row['cs_from_time']);
        $slotTo = timeShort($row['cs_to_time']);
        $capacity = (int) ($row['cs_capacity'] ?? 0);

        return [
            'id' => $row['id'],
            'patient_name' => $row['patient_name'],
            'patient_age' => $row['patient_age'],
            'patient_phone' => $row['patient_phone'],
            'patient_gender' => $row['patient_gender'],
            'booking_date' => $row['booking_date'],
            'booking_time' => $row['booking_time'],
            'status' => $row['status'],
            'department' => [
                'id' => $row['d_id'],
                'name' => $row['d_name'] ?? 'غير معروف',
            ],
            'doctor' => [
                'id' => $row['dt_id'],
                'type' => $row['dt_type'],
                'label' => $row['dt_label'] ?? 'غير معروف',
            ],
            'slot' => [
                'id' => $row['cs_id'],
                'date' => $row['cs_date'],
                'from_time' => $slotFrom,
                'to_time' => $slotTo,
                'capacity' => $capacity,
                'current_bookings' => $currentBookings,
                'remaining' => $capacity - $currentBookings,
            ],
            'created_at' => $row['created_at'],
            'display' => "{$row['patient_name']} | {$row['booking_date']} | " .
                ($row['d_name'] ?? 'غير معروف') . ' | ' . ($row['dt_label'] ?? 'غير معروف'),
        ];
    }, $rows);

    jsonResponse($formatted);
});

route($routes, 'GET', '#^/api/admin/bookings$#', function () {
    $db = getDb();
    $rows = fetchBookingsWithRelations($db);

    $formatted = array_map(function ($row) use ($db) {
        $currentBookings = $row['cs_id'] ? countBookingsForSlot($db, $row['cs_id']) : 0;
        $slotFrom = timeShort($row['cs_from_time']);
        $slotTo = timeShort($row['cs_to_time']);
        $capacity = (int) ($row['cs_capacity'] ?? 0);

        return [
            'patient' => [
                'id' => $row['id'],
                'name' => $row['patient_name'],
                'age' => $row['patient_age'],
                'phone' => $row['patient_phone'],
                'gender' => $row['patient_gender'] === 'male' ? 'ذكر' : 'أنثى',
            ],
            'booking' => [
                'id' => $row['id'],
                'date' => $row['booking_date'],
                'booking_time' => $row['booking_time'],
                'slot_range' => ($slotFrom && $slotTo) ? "$slotFrom - $slotTo" : null,
                'slot_from' => $slotFrom,
                'slot_to' => $slotTo,
                'capacity' => $capacity,
                'current_bookings' => $currentBookings,
                'remaining' => $capacity - $currentBookings,
                'is_full' => $currentBookings >= $capacity,
                'status' => $row['status'],
            ],
            'department' => [
                'id' => $row['d_id'],
                'name' => $row['d_name'] ?? 'غير معروف',
                'icon' => $row['d_icon_url'],
            ],
            'doctor' => [
                'id' => $row['dt_id'],
                'type' => $row['dt_type'],
                'label' => $row['dt_label'] ?? 'غير معروف',
            ],
            'created_at' => $row['created_at'],
            'display' => "{$row['patient_name']} | {$row['booking_date']} | " .
                ($row['d_name'] ?? 'غير معروف') . ' | ' . ($row['dt_label'] ?? 'غير معروف') .
                " | $slotFrom - $slotTo | $currentBookings/$capacity",
        ];
    }, $rows);

    jsonResponse($formatted);
});

route($routes, 'GET', '#^/api/bookings/department/([^/]+)$#', function (array $p) {
    $db = getDb();
    $departmentId = $p[1];
    $rows = fetchBookingsWithRelations($db, $departmentId);

    $formatted = array_map(function ($row) use ($db) {
        $currentBookings = $row['cs_id'] ? countBookingsForSlot($db, $row['cs_id']) : 0;
        $slotFrom = timeShort($row['cs_from_time']);
        $slotTo = timeShort($row['cs_to_time']);
        $capacity = (int) ($row['cs_capacity'] ?? 0);
        $timeLabel = $row['booking_time'] ?: "$slotFrom - $slotTo";

        return [
            'id' => $row['id'],
            'patient_name' => $row['patient_name'],
            'patient_age' => $row['patient_age'],
            'patient_phone' => $row['patient_phone'],
            'patient_gender' => $row['patient_gender'],
            'booking_date' => $row['booking_date'],
            'booking_time' => $row['booking_time'],
            'status' => $row['status'],
            'department' => [
                'id' => $row['d_id'],
                'name' => $row['d_name'] ?? 'غير معروف',
            ],
            'doctor' => [
                'id' => $row['dt_id'],
                'type' => $row['dt_type'],
                'label' => $row['dt_label'] ?? 'غير معروف',
            ],
            'slot' => [
                'id' => $row['cs_id'],
                'from_time' => $slotFrom,
                'to_time' => $slotTo,
                'capacity' => $capacity,
                'current_bookings' => $currentBookings,
                'remaining' => $capacity - $currentBookings,
            ],
            'created_at' => $row['created_at'],
            'summary' => "حجز {$row['patient_name']} في {$row['booking_date']} الفترة $timeLabel ($currentBookings/$capacity)",
        ];
    }, $rows);

    jsonResponse($formatted);
});

route($routes, 'PATCH', '#^/api/bookings/([^/]+)/status$#', function (array $p) {
    $db = getDb();
    $id = $p[1];
    $body = getJsonBody();
    $status = $body['status'] ?? null;

    if (!in_array($status, ['pending', 'attended', 'absent'], true)) {
        jsonError('حالة الحضور غير صالحة', 400);
    }

    $stmt = $db->prepare('SELECT id FROM bookings WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    if (!$stmt->fetch()) {
        jsonError('الحجز غير موجود', 404);
    }

    try {
        $update = $db->prepare('UPDATE bookings SET status = :status, updated_at = NOW(6) WHERE id = :id');
        $update->execute(['status' => $status, 'id' => $id]);
        jsonResponse([
            'success' => true,
            'message' => 'تم تحديث حالة حضور المريض بنجاح',
            'status' => $status
        ]);
    } catch (Throwable $e) {
        error_log('❌ Server error: ' . $e->getMessage());
        jsonError('Server error: ' . $e->getMessage(), 500);
    }
});

route($routes, 'DELETE', '#^/api/bookings/([^/]+)$#', function (array $p) {
    $db = getDb();
    $id = $p[1];

    $fetchStmt = $db->prepare('SELECT custom_slot_id FROM bookings WHERE id = :id LIMIT 1');
    $fetchStmt->execute(['id' => $id]);
    $booking = $fetchStmt->fetch();

    if (!$booking) {
        jsonError('الحجز غير موجود', 404);
    }

    $slotStmt = $db->prepare('SELECT id, capacity FROM custom_slots WHERE id = :id LIMIT 1');
    $slotStmt->execute(['id' => $booking['custom_slot_id']]);
    $customSlot = $slotStmt->fetch();

    try {
        $delete = $db->prepare('DELETE FROM bookings WHERE id = :id');
        $delete->execute(['id' => $id]);

        $capacityInfo = [];
        if ($customSlot) {
            $newCurrentBookings = countBookingsForSlot($db, $customSlot['id']);
            $capacityInfo = [
                'slot_id' => $customSlot['id'],
                'capacity' => $customSlot['capacity'],
                'current_bookings' => $newCurrentBookings,
                'remaining' => $customSlot['capacity'] - $newCurrentBookings,
            ];
        }

        jsonResponse(array_merge(['message' => 'تم إلغاء الحجز بنجاح'], $capacityInfo));
    } catch (Throwable $e) {
        error_log('❌ Server error: ' . $e->getMessage());
        jsonError('Server error: ' . $e->getMessage(), 500);
    }
});

// ============================
// 7. Role-Based Access Control (RBAC): profile + account management
// ============================

// --- Profile (any authenticated role: user / admin / superadmin) ---

route($routes, 'GET', '#^/api/profile$#', function () {
    $db = getDb();
    $account = currentAccount($db);
    jsonResponse([
        'id' => $account['id'],
        'email' => $account['email'],
        'role' => $account['role'],
    ]);
});

route($routes, 'PUT', '#^/api/profile$#', function () {
    $db = getDb();
    $account = currentAccount($db);
    $body = getJsonBody();

    $fields = [];
    $params = ['id' => $account['id']];
    if (!empty($body['email'])) {
        $fields[] = 'email = :email';
        $params['email'] = $body['email'];
    }
    if (!empty($body['password'])) {
        $fields[] = 'password = :password';
        $params['password'] = $body['password'];
    }
    if (!$fields) {
        jsonError('لا توجد بيانات للتحديث', 400);
    }
    $fields[] = 'updated_at = NOW(6)';

    try {
        $stmt = $db->prepare('UPDATE admins SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $stmt->execute($params);
        $updated = fetchRowById($db, 'admins', $account['id']);
        jsonResponse([
            'success' => true,
            'message' => 'تم تحديث الملف الشخصي بنجاح',
            'profile' => [
                'id' => $updated['id'],
                'email' => $updated['email'],
                'role' => $updated['role'],
            ],
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            jsonError('البريد الإلكتروني مستخدم بالفعل', 409);
        }
        error_log('❌ Server error: ' . $e->getMessage());
        jsonError('Server error: ' . $e->getMessage(), 500);
    }
});

// --- Account management (superadmin only) ---

// List admin accounts (admin + superadmin)
route($routes, 'GET', '#^/api/admins$#', function () {
    $db = getDb();
    requireRole(currentAccount($db), ['superadmin']);
    $rows = $db->query(
        "SELECT id, email, role, created_at, updated_at FROM admins
         WHERE role IN ('admin','superadmin') ORDER BY id"
    )->fetchAll();
    jsonResponse($rows);
});

// List user accounts (role = user)
route($routes, 'GET', '#^/api/users$#', function () {
    $db = getDb();
    requireRole(currentAccount($db), ['superadmin']);
    $rows = $db->query(
        "SELECT id, email, role, created_at, updated_at FROM admins
         WHERE role = 'user' ORDER BY id"
    )->fetchAll();
    jsonResponse($rows);
});

// Create a new account (default role = admin)
route($routes, 'POST', '#^/api/admins$#', function () {
    $db = getDb();
    requireRole(currentAccount($db), ['superadmin']);
    $body = getJsonBody();

    $email = $body['email'] ?? null;
    $password = $body['password'] ?? null;
    $role = $body['role'] ?? 'admin';

    if (!$email || !$password) {
        jsonError('البريد الإلكتروني وكلمة المرور مطلوبان', 400);
    }
    if (!in_array($role, ['user', 'admin', 'superadmin'], true)) {
        jsonError('الدور غير صالح', 400);
    }

    try {
        $stmt = $db->prepare(
            'INSERT INTO admins (email, password, role, created_at, updated_at)
             VALUES (:email, :password, :role, NOW(6), NOW(6))'
        );
        $stmt->execute(['email' => $email, 'password' => $password, 'role' => $role]);
        $new = fetchRowById($db, 'admins', (int) $db->lastInsertId());
        jsonResponse([
            'success' => true,
            'message' => 'تم إنشاء الحساب بنجاح',
            'admin' => [
                'id' => $new['id'],
                'email' => $new['email'],
                'role' => $new['role'],
            ],
        ], 201);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            jsonError('البريد الإلكتروني مستخدم بالفعل', 409);
        }
        error_log('❌ Server error: ' . $e->getMessage());
        jsonError('Server error: ' . $e->getMessage(), 500);
    }
});

// Promote a user -> admin
route($routes, 'PATCH', '#^/api/admins/([^/]+)/promote$#', function (array $p) {
    $db = getDb();
    requireRole(currentAccount($db), ['superadmin']);
    $target = fetchRowById($db, 'admins', $p[1]);
    if (!$target) {
        jsonError('الحساب غير موجود', 404);
    }
    if ($target['role'] !== 'user') {
        jsonError('يمكن ترقية المستخدمين فقط', 400);
    }
    $stmt = $db->prepare("UPDATE admins SET role = 'admin', updated_at = NOW(6) WHERE id = :id");
    $stmt->execute(['id' => $p[1]]);
    $updated = fetchRowById($db, 'admins', $p[1]);
    jsonResponse([
        'success' => true,
        'message' => 'تمت ترقية المستخدم إلى أدمن',
        'account' => ['id' => $updated['id'], 'email' => $updated['email'], 'role' => $updated['role']],
    ]);
});

// Demote an admin -> user
route($routes, 'PATCH', '#^/api/admins/([^/]+)/demote$#', function (array $p) {
    $db = getDb();
    requireRole(currentAccount($db), ['superadmin']);
    $target = fetchRowById($db, 'admins', $p[1]);
    if (!$target) {
        jsonError('الحساب غير موجود', 404);
    }
    if ($target['role'] !== 'admin') {
        jsonError('يمكن تخفيض الأدمن فقط', 400);
    }
    $stmt = $db->prepare("UPDATE admins SET role = 'user', updated_at = NOW(6) WHERE id = :id");
    $stmt->execute(['id' => $p[1]]);
    $updated = fetchRowById($db, 'admins', $p[1]);
    jsonResponse([
        'success' => true,
        'message' => 'تم تخفيض الأدمن إلى مستخدم',
        'account' => ['id' => $updated['id'], 'email' => $updated['email'], 'role' => $updated['role']],
    ]);
});

// Edit any account (email/password) by id
route($routes, 'PUT', '#^/api/admins/([^/]+)$#', function (array $p) {
    $db = getDb();
    requireRole(currentAccount($db), ['superadmin']);
    $body = getJsonBody();
    $target = fetchRowById($db, 'admins', $p[1]);
    if (!$target) {
        jsonError('الحساب غير موجود', 404);
    }

    $fields = [];
    $params = ['id' => $p[1]];
    if (!empty($body['email'])) {
        $fields[] = 'email = :email';
        $params['email'] = $body['email'];
    }
    if (!empty($body['password'])) {
        $fields[] = 'password = :password';
        $params['password'] = $body['password'];
    }
    if (!$fields) {
        jsonError('لا توجد بيانات للتحديث', 400);
    }
    $fields[] = 'updated_at = NOW(6)';

    try {
        $stmt = $db->prepare('UPDATE admins SET ' . implode(', ', $fields) . ' WHERE id = :id');
        $stmt->execute($params);
        $updated = fetchRowById($db, 'admins', $p[1]);
        jsonResponse([
            'success' => true,
            'message' => 'تم تحديث الحساب بنجاح',
            'account' => ['id' => $updated['id'], 'email' => $updated['email'], 'role' => $updated['role']],
        ]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            jsonError('البريد الإلكتروني مستخدم بالفعل', 409);
        }
        error_log('❌ Server error: ' . $e->getMessage());
        jsonError('Server error: ' . $e->getMessage(), 500);
    }
});

// Delete an account by id (cannot delete yourself)
route($routes, 'DELETE', '#^/api/admins/([^/]+)$#', function (array $p) {
    $db = getDb();
    $account = currentAccount($db);
    requireRole($account, ['superadmin']);
    $target = fetchRowById($db, 'admins', $p[1]);
    if (!$target) {
        jsonError('الحساب غير موجود', 404);
    }
    if ((int) $target['id'] === (int) $account['id']) {
        jsonError('لا يمكنك حذف حسابك الخاص', 400);
    }
    try {
        $stmt = $db->prepare('DELETE FROM admins WHERE id = :id');
        $stmt->execute(['id' => $p[1]]);
        jsonResponse(['success' => true, 'message' => 'تم حذف الحساب بنجاح']);
    } catch (Throwable $e) {
        error_log('❌ Server error: ' . $e->getMessage());
        jsonError('Server error: ' . $e->getMessage(), 500);
    }
});

// ============================
// 8. Chat
// ============================

route($routes, 'POST', '#^/api/chat/rooms/create$#', function () {
    $db = getDb();
    $helper = new ChatHelper($db);
    $helper->createChatRoom(getJsonBody());
});

route($routes, 'GET', '#^/api/chat/rooms$#', function () {
    $db = getDb();
    $helper = new ChatHelper($db);
    $helper->getChatRooms($_GET);
});

route($routes, 'GET', '#^/api/chat/rooms/([^/]+)$#', function (array $p) {
    $db = getDb();
    $helper = new ChatHelper($db);
    $helper->getChatRoom($p[1]);
});

route($routes, 'POST', '#^/api/chat/messages/send$#', function () {
    $db = getDb();
    $helper = new ChatHelper($db);
    $helper->sendMessage(getJsonBody());
});

route($routes, 'PATCH', '#^/api/chat/rooms/([^/]+)/status$#', function (array $p) {
    $db = getDb();
    $helper = new ChatHelper($db);
    $helper->updateChatRoomStatus($p[1], getJsonBody());
});

route($routes, 'DELETE', '#^/api/chat/rooms/([^/]+)$#', function (array $p) {
    $db = getDb();
    $helper = new ChatHelper($db);
    $helper->deleteChatRoom($p[1], $_GET);
});

// ============================
// Run the router
// ============================

try {
    dispatch($routes, $method, $uri);
} catch (Throwable $e) {
    error_log('❌ Unhandled error: ' . $e->getMessage());
    jsonError('Server error: ' . $e->getMessage(), 500);
}
