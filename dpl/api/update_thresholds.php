<?php
/**
 * API: Обновление пороговых значений
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

// Только администраторы могут изменять пороги
$currentUser = $auth->getCurrentUser();
if ($currentUser['role'] !== ROLE_ADMIN) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
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
    
    // Получение данных из запроса
    $data = json_decode(file_get_contents('php://input'), true);
    
    $zoneId = isset($data['zone_id']) ? (int)$data['zone_id'] : null;
    $resourceType = $data['resource_type'] ?? null;
    $minValue = isset($data['min_value']) ? (float)$data['min_value'] : null;
    $maxValue = isset($data['max_value']) ? (float)$data['max_value'] : null;
    $deltaPercent = isset($data['delta_percent']) ? (float)$data['delta_percent'] : null;
    $lossTimeout = isset($data['loss_timeout_seconds']) ? (int)$data['loss_timeout_seconds'] : null;
    $alertLevel = $data['alert_level'] ?? null;
    $isActive = isset($data['is_active']) ? (int)$data['is_active'] : 1;
    
    // Валидация
    if ($resourceType === null || !in_array($resourceType, ['water', 'heat', 'electricity'])) {
        throw new Exception('Неверный тип ресурса');
    }
    
    if ($alertLevel !== null && !in_array($alertLevel, ['warning', 'alarm', 'critical'])) {
        throw new Exception('Неверный уровень тревоги');
    }
    
    // Проверка существования записи
    $where = "resource_type = :resourceType";
    $params = ['resourceType' => $resourceType];
    
    if ($zoneId !== null) {
        $where .= " AND zone_id = :zoneId";
        $params['zoneId'] = $zoneId;
        
        // Проверка существования зоны
        $zone = $db->fetchOne("SELECT id FROM zones WHERE id = :id", ['id' => $zoneId]);
        if (!$zone) {
            throw new Exception('Зона не найдена');
        }
    } else {
        $where .= " AND zone_id IS NULL";
    }
    
    $existing = $db->fetchOne("SELECT id FROM thresholds WHERE {$where}", $params);
    
    if ($existing) {
        // Обновление существующей записи
        $updateData = [];
        if ($minValue !== null) $updateData['min_value'] = $minValue;
        if ($maxValue !== null) $updateData['max_value'] = $maxValue;
        if ($deltaPercent !== null) $updateData['delta_percent'] = $deltaPercent;
        if ($lossTimeout !== null) $updateData['loss_timeout_seconds'] = $lossTimeout;
        if ($alertLevel !== null) $updateData['alert_level'] = $alertLevel;
        $updateData['is_active'] = $isActive;
        
        if (!empty($updateData)) {
            $db->update('thresholds', $updateData, $where, $params);
        }
        
        $message = 'Пороговые значения обновлены';
    } else {
        // Создание новой записи
        if ($minValue === null || $maxValue === null) {
            throw new Exception('Для создания новой записи необходимо указать min_value и max_value');
        }
        
        $insertData = [
            'resource_type' => $resourceType,
            'min_value' => $minValue,
            'max_value' => $maxValue,
            'delta_percent' => $deltaPercent ?? 20.00,
            'loss_timeout_seconds' => $lossTimeout ?? 600,
            'alert_level' => $alertLevel ?? 'warning',
            'is_active' => $isActive
        ];
        
        if ($zoneId !== null) {
            $insertData['zone_id'] = $zoneId;
        }
        
        $db->insert('thresholds', $insertData);
        $message = 'Пороговые значения созданы';
    }
    
    // Логирование действия
    $db->insert('system_log', [
        'user_id' => $currentUser['id'],
        'action' => 'thresholds_update',
        'details' => "Администратор {$currentUser['full_name']} обновил пороги для ресурса '{$resourceType}'" . 
                     ($zoneId ? " в зоне #{$zoneId}" : " (глобальные)"),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => $message
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в update_thresholds.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
