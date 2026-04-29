<?php
/**
 * API: Получение списка тревог/аварий
 * Поддерживает фильтрацию по статусу и уровню
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
    
    // Параметры фильтрации
    $status = $_GET['status'] ?? 'active'; // active, acknowledged, resolved, all
    $level = $_GET['level'] ?? null; // warning, alarm, critical
    $limit = (int)($_GET['limit'] ?? 50);
    
    // Построение запроса
    $where = [];
    $params = [];
    
    if ($status !== 'all') {
        $where[] = "a.status = :status";
        $params['status'] = $status;
    }
    
    if ($level !== null) {
        $where[] = "a.level = :level";
        $params['level'] = $level;
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $sql = "SELECT 
                a.id,
                a.alert_type,
                a.level,
                a.message,
                a.current_value,
                a.threshold_value,
                a.status,
                a.created_at,
                a.acknowledged_at,
                a.resolved_at,
                c.name as counter_name,
                c.resource_type,
                z.id as zone_id,
                z.name as zone_name,
                u_ack.full_name as acknowledged_by_name,
                u_res.full_name as resolved_by_name
            FROM alerts a
            LEFT JOIN counters c ON a.counter_id = c.id
            LEFT JOIN zones z ON a.zone_id = z.id
            LEFT JOIN users u_ack ON a.acknowledged_by = u_ack.id
            LEFT JOIN users u_res ON a.resolved_by = u_res.id
            {$whereClause}
            ORDER BY a.created_at DESC
            LIMIT :limit";
    
    $stmt = $db->query($sql, array_merge($params, ['limit' => $limit]));
    // Переопределяем limit через bindValue для PDO
    $stmt = $db->connection->prepare("SELECT 
                a.id,
                a.alert_type,
                a.level,
                a.message,
                a.current_value,
                a.threshold_value,
                a.status,
                a.created_at,
                a.acknowledged_at,
                a.resolved_at,
                c.name as counter_name,
                c.resource_type,
                z.id as zone_id,
                z.name as zone_name,
                u_ack.full_name as acknowledged_by_name,
                u_res.full_name as resolved_by_name
            FROM alerts a
            LEFT JOIN counters c ON a.counter_id = c.id
            LEFT JOIN zones z ON a.zone_id = z.id
            LEFT JOIN users u_ack ON a.acknowledged_by = u_ack.id
            LEFT JOIN users u_res ON a.resolved_by = u_res.id
            {$whereClause}
            ORDER BY a.created_at DESC
            LIMIT {$limit}");
    $stmt->execute($params);
    $alerts = $stmt->fetchAll();
    
    // Форматирование данных
    $result = [];
    foreach ($alerts as $alert) {
        $result[] = [
            'id' => (int)$alert['id'],
            'alert_type' => $alert['alert_type'],
            'level' => $alert['level'],
            'message' => $alert['message'],
            'current_value' => $alert['current_value'] !== null ? (float)$alert['current_value'] : null,
            'threshold_value' => $alert['threshold_value'] !== null ? (float)$alert['threshold_value'] : null,
            'status' => $alert['status'],
            'created_at' => $alert['created_at'],
            'acknowledged_at' => $alert['acknowledged_at'],
            'resolved_at' => $alert['resolved_at'],
            'counter_name' => $alert['counter_name'],
            'resource_type' => $alert['resource_type'],
            'zone_id' => (int)$alert['zone_id'],
            'zone_name' => $alert['zone_name'],
            'acknowledged_by_name' => $alert['acknowledged_by_name'],
            'resolved_by_name' => $alert['resolved_by_name']
        ];
    }
    
    // Получение количества активных тревог для счетчика
    $countSql = "SELECT COUNT(*) as total FROM alerts {$whereClause}";
    $countStmt = $db->connection->prepare($countSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetch()['total'];
    
    echo json_encode([
        'success' => true,
        'data' => $result,
        'total' => $total,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в get_alerts.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера']);
}
