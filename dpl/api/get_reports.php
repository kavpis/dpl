<?php
/**
 * API: Получение отчётов
 * Генерация данных для отчётов по авариям, простоям, эффективности
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

// Только руководители и администраторы могут получать отчёты
$currentUser = $auth->getCurrentUser();
if (!in_array($currentUser['role'], [ROLE_MANAGER, ROLE_ADMIN, ROLE_ENGINEER, ROLE_DISPATCHER])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

try {
    $db = Database::getInstance();
    
    // Параметры фильтрации
    $reportType = $_GET['type'] ?? 'summary'; // summary, alerts, consumption, downtime
    $dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $dateTo = $_GET['to'] ?? date('Y-m-d');
    $zoneId = (int)($_GET['zone_id'] ?? 0);
    
    // Валидация дат
    if (strtotime($dateFrom) > strtotime($dateTo)) {
        throw new Exception('Дата начала не может быть больше даты окончания');
    }
    
    $result = [];
    
    switch ($reportType) {
        case 'summary':
            // Общая сводка
            $result = getSummaryReport($db, $dateFrom, $dateTo, $zoneId);
            break;
            
        case 'alerts':
            // Отчёт по тревогам
            $result = getAlertsReport($db, $dateFrom, $dateTo, $zoneId);
            break;
            
        case 'consumption':
            // Отчёт по потреблению
            $result = getConsumptionReport($db, $dateFrom, $dateTo, $zoneId);
            break;
            
        case 'downtime':
            // Отчёт по простоям
            $result = getDowntimeReport($db, $dateFrom, $dateTo, $zoneId);
            break;
            
        default:
            throw new Exception('Неизвестный тип отчёта');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result,
        'report_type' => $reportType,
        'period' => [
            'from' => $dateFrom,
            'to' => $dateTo
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в get_reports.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Общая сводка
 */
function getSummaryReport($db, $dateFrom, $dateTo, $zoneId) {
    $where = [];
    $params = ['dateFrom' => $dateFrom . ' 00:00:00', 'dateTo' => $dateTo . ' 23:59:59'];
    
    if ($zoneId > 0) {
        $where[] = "a.zone_id = :zoneId";
        $params['zoneId'] = $zoneId;
    }
    
    $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Количество тревог по уровням
    $sql = "SELECT 
                level,
                COUNT(*) as count,
                SUM(CASE WHEN status = 'resolved' THEN 1 ELSE 0 END) as resolved_count
            FROM alerts a
            WHERE a.created_at >= :dateFrom AND a.created_at <= :dateTo
            " . ($zoneId > 0 ? "AND a.zone_id = :zoneId" : "") . "
            GROUP BY level";
    
    $stmt = $db->connection->prepare($sql);
    $stmt->execute($params);
    $alertsByLevel = $stmt->fetchAll();
    
    // Общее количество заявок
    $sql = "SELECT 
                status,
                COUNT(*) as count
            FROM maintenance_requests mr
            JOIN zones z ON mr.zone_id = z.id
            WHERE mr.created_at >= :dateFrom AND mr.created_at <= :dateTo
            " . ($zoneId > 0 ? "AND mr.zone_id = :zoneId" : "") . "
            GROUP BY status";
    
    $stmt = $db->connection->prepare($sql);
    $stmt->execute($params);
    $requestsByStatus = $stmt->fetchAll();
    
    return [
        'alerts_by_level' => $alertsByLevel,
        'requests_by_status' => $requestsByStatus,
        'total_alerts' => array_sum(array_column($alertsByLevel, 'count')),
        'total_requests' => array_sum(array_column($requestsByStatus, 'count'))
    ];
}

/**
 * Отчёт по тревогам
 */
function getAlertsReport($db, $dateFrom, $dateTo, $zoneId) {
    $params = ['dateFrom' => $dateFrom . ' 00:00:00', 'dateTo' => $dateTo . ' 23:59:59'];
    
    $sql = "SELECT 
                a.id,
                a.alert_type,
                a.level,
                a.message,
                a.status,
                a.created_at,
                a.acknowledged_at,
                a.resolved_at,
                c.name as counter_name,
                z.name as zone_name
            FROM alerts a
            LEFT JOIN counters c ON a.counter_id = c.id
            LEFT JOIN zones z ON a.zone_id = z.id
            WHERE a.created_at >= :dateFrom AND a.created_at <= :dateTo
            " . ($zoneId > 0 ? "AND a.zone_id = :zoneId" : "") . "
            ORDER BY a.created_at DESC";
    
    $stmt = $db->connection->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Отчёт по потреблению
 */
function getConsumptionReport($db, $dateFrom, $dateTo, $zoneId) {
    $params = ['dateFrom' => $dateFrom . ' 00:00:00', 'dateTo' => $dateTo . ' 23:59:59'];
    
    $sql = "SELECT 
                z.name as zone_name,
                c.resource_type,
                c.unit,
                COUNT(cr.id) as readings_count,
                AVG(cr.value) as avg_value,
                MIN(cr.value) as min_value,
                MAX(cr.value) as max_value,
                SUM(cr.value) as total_value
            FROM counter_readings cr
            JOIN counters c ON cr.counter_id = c.id
            JOIN zones z ON c.zone_id = z.id
            WHERE cr.reading_time >= :dateFrom AND cr.reading_time <= :dateTo
            " . ($zoneId > 0 ? "AND c.zone_id = :zoneId" : "") . "
            GROUP BY z.name, c.resource_type, c.unit
            ORDER BY z.name, c.resource_type";
    
    $stmt = $db->connection->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/**
 * Отчёт по простоям
 */
function getDowntimeReport($db, $dateFrom, $dateTo, $zoneId) {
    $params = ['dateFrom' => $dateFrom . ' 00:00:00', 'dateTo' => $dateTo . ' 23:59:59'];
    
    $sql = "SELECT 
                z.name as zone_name,
                c.name as counter_name,
                c.status,
                c.last_reading_time,
                TIMESTAMPDIFF(MINUTE, c.last_reading_time, NOW()) as minutes_offline
            FROM counters c
            JOIN zones z ON c.zone_id = z.id
            WHERE c.is_active = 1
            " . ($zoneId > 0 ? "AND c.zone_id = :zoneId" : "") . "
            AND (c.status != 'ok' OR c.last_reading_time < :dateFrom)
            ORDER BY minutes_offline DESC";
    
    $stmt = $db->connection->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}
