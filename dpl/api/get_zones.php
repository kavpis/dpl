<?php
/**
 * API: Получение списка зон/цехов предприятия
 * Возвращает все активные зоны с информацией о счётчиках
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

try {
    $db = Database::getInstance();
    
    // Получение всех активных зон
    $sql = "SELECT 
                z.id,
                z.name,
                z.description,
                z.coordinates,
                z.parent_zone_id,
                COUNT(c.id) as counters_count,
                SUM(CASE WHEN c.status = 'ok' THEN 1 ELSE 0 END) as ok_count,
                SUM(CASE WHEN c.status != 'ok' THEN 1 ELSE 0 END) as problem_count
            FROM zones z
            LEFT JOIN counters c ON z.id = c.zone_id AND c.is_active = 1
            WHERE z.is_active = 1
            GROUP BY z.id
            ORDER BY z.parent_zone_id, z.name";
    
    $zones = $db->fetchAll($sql);
    
    // Форматирование данных
    $result = [];
    foreach ($zones as $zone) {
        $result[] = [
            'id' => (int)$zone['id'],
            'name' => $zone['name'],
            'description' => $zone['description'],
            'coordinates' => $zone['coordinates'] ? array_map('floatval', explode(',', $zone['coordinates'])) : null,
            'parent_zone_id' => $zone['parent_zone_id'] ? (int)$zone['parent_zone_id'] : null,
            'counters_count' => (int)$zone['counters_count'],
            'ok_count' => (int)$zone['ok_count'],
            'problem_count' => (int)$zone['problem_count']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в get_zones.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера']);
}
