<?php
/**
 * API: Получение текущих показаний счётчиков
 * Возвращает последние показания по всем активным счётчикам
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
    
    // Получение последних показаний по каждому счётчику
    $sql = "SELECT 
                c.id as counter_id,
                c.name as counter_name,
                c.serial_number,
                c.resource_type,
                c.unit,
                c.status,
                c.last_reading_time,
                z.id as zone_id,
                z.name as zone_name,
                cr.value,
                cr.reading_time,
                cr.quality
            FROM counters c
            LEFT JOIN zones z ON c.zone_id = z.id
            LEFT JOIN (
                SELECT counter_id, value, reading_time, quality,
                       ROW_NUMBER() OVER (PARTITION BY counter_id ORDER BY reading_time DESC) as rn
                FROM counter_readings
            ) cr ON c.id = cr.counter_id AND cr.rn = 1
            WHERE c.is_active = 1
            ORDER BY z.name, c.name";
    
    $readings = $db->fetchAll($sql);
    
    // Форматирование данных
    $result = [];
    foreach ($readings as $reading) {
        $result[] = [
            'counter_id' => (int)$reading['counter_id'],
            'counter_name' => $reading['counter_name'],
            'serial_number' => $reading['serial_number'],
            'resource_type' => $reading['resource_type'],
            'unit' => $reading['unit'],
            'status' => $reading['status'],
            'zone_id' => (int)$reading['zone_id'],
            'zone_name' => $reading['zone_name'],
            'value' => $reading['value'] !== null ? (float)$reading['value'] : null,
            'reading_time' => $reading['reading_time'],
            'quality' => (int)$reading['quality'],
            'is_online' => $reading['last_reading_time'] !== null && 
                          (strtotime($reading['last_reading_time']) > (time() - CONNECTION_LOSS_TIMEOUT))
        ];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("Ошибка в get_readings.php: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера']);
}
