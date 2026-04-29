<?php
/**
 * API: Подтверждение тревоги
 * Изменяет статус тревоги на "acknowledged"
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
    
    // Получение данных из запроса
    $data = json_decode(file_get_contents('php://input'), true);
    $alertId = (int)($data['alert_id'] ?? 0);
    
    if ($alertId <= 0) {
        throw new Exception('Неверный ID тревоги');
    }
    
    // Проверка существования тревоги
    $alert = $db->fetchOne("SELECT id, status FROM alerts WHERE id = :id", ['id' => $alertId]);
    
    if (!$alert) {
        throw new Exception('Тревога не найдена');
    }
    
    if ($alert['status'] !== 'active') {
        throw new Exception('Тревога уже была подтверждена или закрыта');
    }
    
    // Обновление статуса тревоги
    $db->update('alerts', [
        'status' => 'acknowledged',
        'acknowledged_at' => date('Y-m-d H:i:s'),
        'acknowledged_by' => $currentUser['id']
    ], 'id = :id', ['id' => $alertId]);
    
    // Логирование действия
    $db->insert('system_log', [
        'user_id' => $currentUser['id'],
        'action' => 'alert_acknowledge',
        'details' => "Пользователь {$currentUser['full_name']} подтвердил тревогу #{$alertId}",
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Тревога подтверждена',
        'alert_id' => $alertId
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в acknowledge_alert.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
