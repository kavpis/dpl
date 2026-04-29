<?php
/**
 * API: Запрос вызова бригады
 * Создает заявку в таблице maintenance_requests
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

// Только POST запросы
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Метод не разрешен']);
    exit;
}

try {
    $db = Database::getInstance();
    $currentUser = $auth->getCurrentUser();
    
    // Инженер или администратор может создавать заявки
    if (!in_array($currentUser['role'], [ROLE_ENGINEER, ROLE_ADMIN, ROLE_DISPATCHER])) {
        throw new Exception('Недостаточно прав для создания заявки');
    }
    
    // Получение данных из запроса
    $data = json_decode(file_get_contents('php://input'), true);
    $alertId = (int)($data['alert_id'] ?? 0);
    $zoneId = (int)($data['zone_id'] ?? 0);
    $description = trim($data['description'] ?? '');
    
    if (empty($description)) {
        throw new Exception('Описание проблемы не может быть пустым');
    }
    
    if ($zoneId <= 0) {
        throw new Exception('Не указана зона');
    }
    
    // Проверка существования зоны
    $zone = $db->fetchOne("SELECT id, name FROM zones WHERE id = :id AND is_active = 1", ['id' => $zoneId]);
    
    if (!$zone) {
        throw new Exception('Зона не найдена или не активна');
    }
    
    // Если указан alert_id, проверяем его существование
    if ($alertId > 0) {
        $alert = $db->fetchOne("SELECT id FROM alerts WHERE id = :id", ['id' => $alertId]);
        if (!$alert) {
            $alertId = null; // Игнорируем неверный ID тревоги
        }
    } else {
        $alertId = null;
    }
    
    // Создание заявки
    $requestId = $db->insert('maintenance_requests', [
        'alert_id' => $alertId,
        'zone_id' => $zoneId,
        'requester_id' => $currentUser['id'],
        'description' => $description,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    // Логирование действия
    $db->insert('system_log', [
        'user_id' => $currentUser['id'],
        'action' => 'brigade_request',
        'details' => "Пользователь {$currentUser['full_name']} создал заявку на вызов бригады в зону '{$zone['name']}' (#{$requestId})",
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Заявка на вызов бригады создана',
        'request_id' => $requestId,
        'zone_name' => $zone['name']
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в request_brigade.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
