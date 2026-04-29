<?php
/**
 * API: Отправка сообщения диспетчера инженеру
 * Создает запись в таблице alert_messages
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
    
    // Диспетчер может отправлять сообщения
    if ($currentUser['role'] !== ROLE_ADMIN && $currentUser['role'] !== ROLE_DISPATCHER) {
        throw new Exception('Недостаточно прав для отправки сообщений');
    }
    
    // Получение данных из запроса
    $data = json_decode(file_get_contents('php://input'), true);
    $alertId = (int)($data['alert_id'] ?? 0);
    $receiverId = (int)($data['receiver_id'] ?? 0);
    $message = trim($data['message'] ?? '');
    
    if (empty($message)) {
        throw new Exception('Сообщение не может быть пустым');
    }
    
    if ($receiverId <= 0) {
        throw new Exception('Не указан получатель');
    }
    
    // Проверка существования получателя и его роли
    $receiver = $db->fetchOne(
        "SELECT id, role FROM users WHERE id = :id AND is_active = 1",
        ['id' => $receiverId]
    );
    
    if (!$receiver) {
        throw new Exception('Получатель не найден или не активен');
    }
    
    if ($receiver['role'] !== ROLE_ENGINEER && $receiver['role'] !== ROLE_ADMIN) {
        throw new Exception('Сообщение можно отправить только инженеру или администратору');
    }
    
    // Создание сообщения
    $messageId = $db->insert('alert_messages', [
        'alert_id' => $alertId > 0 ? $alertId : null,
        'sender_id' => $currentUser['id'],
        'receiver_id' => $receiverId,
        'message' => $message,
        'is_read' => 0,
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    // Логирование действия
    $db->insert('system_log', [
        'user_id' => $currentUser['id'],
        'action' => 'message_send',
        'details' => "Пользователь {$currentUser['full_name']} отправил сообщение пользователю #{$receiverId}",
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Сообщение отправлено',
        'message_id' => $messageId
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в send_message.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
