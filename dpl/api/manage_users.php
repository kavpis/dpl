<?php
/**
 * API: Управление пользователями (CRUD)
 * Доступно только администраторам
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();

// Проверка авторизации
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Требуется авторизация']);
    exit;
}

// Только администраторы могут управлять пользователями
$currentUser = $auth->getCurrentUser();
if ($currentUser['role'] !== ROLE_ADMIN) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

try {
    $db = Database::getInstance();
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            // Получение списка пользователей
            $users = $db->fetchAll("SELECT id, username, full_name, role, email, is_active, created_at, last_login FROM users ORDER BY id");
            echo json_encode(['success' => true, 'data' => $users]);
            break;
            
        case 'create':
            if ($method !== 'POST') {
                throw new Exception('Метод не разрешен');
            }
            $data = json_decode(file_get_contents('php://input'), true);
            createUser($db, $currentUser, $data);
            break;
            
        case 'update':
            if ($method !== 'POST') {
                throw new Exception('Метод не разрешен');
            }
            $data = json_decode(file_get_contents('php://input'), true);
            updateUser($db, $currentUser, $data);
            break;
            
        case 'delete':
            if ($method !== 'POST') {
                throw new Exception('Метод не разрешен');
            }
            $data = json_decode(file_get_contents('php://input'), true);
            deleteUser($db, $currentUser, $data);
            break;
            
        case 'toggle_active':
            if ($method !== 'POST') {
                throw new Exception('Метод не разрешен');
            }
            $data = json_decode(file_get_contents('php://input'), true);
            toggleUserActive($db, $currentUser, $data);
            break;
            
        default:
            throw new Exception('Неизвестное действие');
    }
    
} catch (Exception $e) {
    error_log("Ошибка в manage_users.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Создание пользователя
 */
function createUser($db, $currentUser, $data) {
    $username = trim($data['username'] ?? '');
    $password = $data['password'] ?? '';
    $fullName = trim($data['full_name'] ?? '');
    $role = $data['role'] ?? '';
    $email = trim($data['email'] ?? '');
    
    // Валидация
    if (empty($username) || empty($password) || empty($fullName) || empty($role)) {
        throw new Exception('Все обязательные поля должны быть заполнены');
    }
    
    if (!in_array($role, [ROLE_ADMIN, ROLE_DISPATCHER, ROLE_ENGINEER, ROLE_MANAGER])) {
        throw new Exception('Неверная роль');
    }
    
    // Проверка существования пользователя с таким логином
    $existing = $db->fetchOne("SELECT id FROM users WHERE username = :username", ['username' => $username]);
    if ($existing) {
        throw new Exception('Пользователь с таким логином уже существует');
    }
    
    // Хэширование пароля
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    // Создание
    $userId = $db->insert('users', [
        'username' => $username,
        'password_hash' => $passwordHash,
        'full_name' => $fullName,
        'role' => $role,
        'email' => $email,
        'is_active' => 1,
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    // Логирование
    $db->insert('system_log', [
        'user_id' => $currentUser['id'],
        'action' => 'user_create',
        'details' => "Создан пользователь '{$username}' (ID: {$userId})",
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Пользователь создан', 'user_id' => $userId]);
}

/**
 * Обновление пользователя
 */
function updateUser($db, $currentUser, $data) {
    $userId = (int)($data['id'] ?? 0);
    
    if ($userId <= 0) {
        throw new Exception('Неверный ID пользователя');
    }
    
    // Нельзя редактировать самого себя через эту функцию (для смены пароля отдельный метод)
    if ($userId === $currentUser['id']) {
        throw new Exception('Для изменения собственных данных используйте профиль');
    }
    
    $user = $db->fetchOne("SELECT id, username FROM users WHERE id = :id", ['id' => $userId]);
    if (!$user) {
        throw new Exception('Пользователь не найден');
    }
    
    $updateData = [];
    $params = ['id' => $userId];
    
    if (isset($data['full_name'])) {
        $updateData['full_name'] = trim($data['full_name']);
    }
    
    if (isset($data['email'])) {
        $updateData['email'] = trim($data['email']);
    }
    
    if (isset($data['role']) && in_array($data['role'], [ROLE_ADMIN, ROLE_DISPATCHER, ROLE_ENGINEER, ROLE_MANAGER])) {
        $updateData['role'] = $data['role'];
    }
    
    if (isset($data['password']) && !empty($data['password'])) {
        $updateData['password_hash'] = password_hash($data['password'], PASSWORD_DEFAULT);
    }
    
    if (!empty($updateData)) {
        $setParts = [];
        foreach (array_keys($updateData) as $key) {
            $setParts[] = "{$key} = :{$key}";
            $params[$key] = $updateData[$key];
        }
        
        $sql = "UPDATE users SET " . implode(', ', $setParts) . " WHERE id = :id";
        $db->query($sql, $params);
    }
    
    // Логирование
    $db->insert('system_log', [
        'user_id' => $currentUser['id'],
        'action' => 'user_update',
        'details' => "Обновлены данные пользователя '{$user['username']}' (ID: {$userId})",
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Данные пользователя обновлены']);
}

/**
 * Удаление пользователя (деактивация)
 */
function deleteUser($db, $currentUser, $data) {
    $userId = (int)($data['id'] ?? 0);
    
    if ($userId <= 0) {
        throw new Exception('Неверный ID пользователя');
    }
    
    if ($userId === $currentUser['id']) {
        throw new Exception('Нельзя удалить самого себя');
    }
    
    $user = $db->fetchOne("SELECT id, username FROM users WHERE id = :id", ['id' => $userId]);
    if (!$user) {
        throw new Exception('Пользователь не найден');
    }
    
    // Деактивация вместо физического удаления
    $db->update('users', ['is_active' => 0], 'id = :id', ['id' => $userId]);
    
    // Логирование
    $db->insert('system_log', [
        'user_id' => $currentUser['id'],
        'action' => 'user_delete',
        'details' => "Деактивирован пользователь '{$user['username']}' (ID: {$userId})",
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Пользователь деактивирован']);
}

/**
 * Переключение статуса активности
 */
function toggleUserActive($db, $currentUser, $data) {
    $userId = (int)($data['id'] ?? 0);
    
    if ($userId <= 0) {
        throw new Exception('Неверный ID пользователя');
    }
    
    if ($userId === $currentUser['id']) {
        throw new Exception('Нельзя изменить статус самого себя');
    }
    
    $user = $db->fetchOne("SELECT id, username, is_active FROM users WHERE id = :id", ['id' => $userId]);
    if (!$user) {
        throw new Exception('Пользователь не найден');
    }
    
    $newStatus = $user['is_active'] ? 0 : 1;
    $db->update('users', ['is_active' => $newStatus], 'id = :id', ['id' => $userId]);
    
    // Логирование
    $db->insert('system_log', [
        'user_id' => $currentUser['id'],
        'action' => 'user_toggle_active',
        'details' => "Статус пользователя '{$user['username']}' изменен на " . ($newStatus ? 'активен' : 'неактивен'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Статус пользователя изменен']);
}
