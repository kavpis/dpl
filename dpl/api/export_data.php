<?php
/**
 * API: Экспорт данных в CSV/Excel/PDF
 * Поддерживает экспорт показаний, тревог и отчётов
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';

$auth = new Auth();

// Проверка авторизации
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    die('Требуется авторизация');
}

try {
    $db = Database::getInstance();
    
    // Параметры
    $format = $_GET['format'] ?? 'csv'; // csv, excel, pdf (упрощенно csv для excel)
    $dataType = $_GET['type'] ?? 'readings'; // readings, alerts, reports
    $dateFrom = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $dateTo = $_GET['to'] ?? date('Y-m-d');
    $zoneId = (int)($_GET['zone_id'] ?? 0);
    
    $data = [];
    $filename = '';
    $headers = [];
    
    switch ($dataType) {
        case 'readings':
            $data = getReadingsData($db, $dateFrom, $dateTo, $zoneId);
            $headers = ['ID', 'Счётчик', 'Зона', 'Тип ресурса', 'Значение', 'Ед. изм.', 'Время', 'Статус'];
            $filename = "readings_{$dateFrom}_{$dateTo}";
            break;
            
        case 'alerts':
            $data = getAlertsData($db, $dateFrom, $dateTo, $zoneId);
            $headers = ['ID', 'Тип', 'Уровень', 'Сообщение', 'Зона', 'Счётчик', 'Статус', 'Создано', 'Подтверждено', 'Закрыто'];
            $filename = "alerts_{$dateFrom}_{$dateTo}";
            break;
            
        case 'reports':
            $data = getReportsData($db, $dateFrom, $dateTo, $zoneId);
            $headers = ['Зона', 'Тип ресурса', 'Ед. изм.', 'Кол-во замеров', 'Среднее', 'Мин', 'Макс', 'Сумма'];
            $filename = "report_{$dateFrom}_{$dateTo}";
            break;
    }
    
    // Генерация файла в зависимости от формата
    if ($format === 'csv' || $format === 'excel') {
        exportToCSV($data, $headers, $filename . '.csv');
    } elseif ($format === 'pdf') {
        // Упрощенный PDF через HTML
        exportToPDF($data, $headers, $filename . '.pdf');
    } else {
        throw new Exception('Неподдерживаемый формат');
    }
    
} catch (Exception $e) {
    error_log("Ошибка в export_data.php: " . $e->getMessage());
    http_response_code(400);
    echo "Ошибка экспорта: " . $e->getMessage();
}

/**
 * Получение данных показаний
 */
function getReadingsData($db, $dateFrom, $dateTo, $zoneId) {
    $params = ['dateFrom' => $dateFrom . ' 00:00:00', 'dateTo' => $dateTo . ' 23:59:59'];
    
    $sql = "SELECT 
                cr.id,
                c.name as counter_name,
                z.name as zone_name,
                c.resource_type,
                cr.value,
                c.unit,
                cr.reading_time,
                cr.quality
            FROM counter_readings cr
            JOIN counters c ON cr.counter_id = c.id
            JOIN zones z ON c.zone_id = z.id
            WHERE cr.reading_time >= :dateFrom AND cr.reading_time <= :dateTo
            " . ($zoneId > 0 ? "AND c.zone_id = :zoneId" : "") . "
            ORDER BY cr.reading_time DESC
            LIMIT 10000";
    
    $stmt = $db->connection->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            $row['id'],
            $row['counter_name'],
            $row['zone_name'],
            translateResourceType($row['resource_type']),
            number_format($row['value'], 4, '.', ''),
            $row['unit'],
            $row['reading_time'],
            $row['quality'] ? 'OK' : 'Error'
        ];
    }
    return $result;
}

/**
 * Получение данных тревог
 */
function getAlertsData($db, $dateFrom, $dateTo, $zoneId) {
    $params = ['dateFrom' => $dateFrom . ' 00:00:00', 'dateTo' => $dateTo . ' 23:59:59'];
    
    $sql = "SELECT 
                a.id,
                a.alert_type,
                a.level,
                a.message,
                z.name as zone_name,
                c.name as counter_name,
                a.status,
                a.created_at,
                a.acknowledged_at,
                a.resolved_at
            FROM alerts a
            LEFT JOIN counters c ON a.counter_id = c.id
            LEFT JOIN zones z ON a.zone_id = z.id
            WHERE a.created_at >= :dateFrom AND a.created_at <= :dateTo
            " . ($zoneId > 0 ? "AND a.zone_id = :zoneId" : "") . "
            ORDER BY a.created_at DESC
            LIMIT 5000";
    
    $stmt = $db->connection->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            $row['id'],
            translateAlertType($row['alert_type']),
            translateLevel($row['level']),
            $row['message'],
            $row['zone_name'] ?? '-',
            $row['counter_name'] ?? '-',
            translateStatus($row['status']),
            $row['created_at'],
            $row['acknowledged_at'] ?? '-',
            $row['resolved_at'] ?? '-'
        ];
    }
    return $result;
}

/**
 * Получение данных отчёта
 */
function getReportsData($db, $dateFrom, $dateTo, $zoneId) {
    $params = ['dateFrom' => $dateFrom . ' 00:00:00', 'dateTo' => $dateTo . ' 23:59:59'];
    
    $sql = "SELECT 
                z.name as zone_name,
                c.resource_type,
                c.unit,
                COUNT(cr.id) as readings_count,
                ROUND(AVG(cr.value), 4) as avg_value,
                ROUND(MIN(cr.value), 4) as min_value,
                ROUND(MAX(cr.value), 4) as max_value,
                ROUND(SUM(cr.value), 4) as total_value
            FROM counter_readings cr
            JOIN counters c ON cr.counter_id = c.id
            JOIN zones z ON c.zone_id = z.id
            WHERE cr.reading_time >= :dateFrom AND cr.reading_time <= :dateTo
            " . ($zoneId > 0 ? "AND c.zone_id = :zoneId" : "") . "
            GROUP BY z.name, c.resource_type, c.unit
            ORDER BY z.name, c.resource_type";
    
    $stmt = $db->connection->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    
    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            $row['zone_name'],
            translateResourceType($row['resource_type']),
            $row['unit'],
            $row['readings_count'],
            $row['avg_value'],
            $row['min_value'],
            $row['max_value'],
            $row['total_value']
        ];
    }
    return $result;
}

/**
 * Экспорт в CSV
 */
function exportToCSV($data, $headers, $filename) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Добавляем BOM для корректного отображения кириллицы в Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Заголовки
    fputcsv($output, $headers, ';');
    
    // Данные
    foreach ($data as $row) {
        fputcsv($output, $row, ';');
    }
    
    fclose($output);
    exit;
}

/**
 * Экспорт в PDF (упрощенный через HTML)
 */
function exportToPDF($data, $headers, $filename) {
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . str_replace('.pdf', '.html', $filename) . '"');
    
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><style>';
    echo 'body{font-family:Arial,sans-serif;} table{border-collapse:collapse;width:100%;}';
    echo 'th,td{border:1px solid #ddd;padding:8px;text-align:left;} th{background:#333;color:white;}';
    echo '</style></head><body>';
    echo '<h2>Отчёт</h2><table><thead><tr>';
    
    foreach ($headers as $header) {
        echo '<th>' . htmlspecialchars($header) . '</th>';
    }
    
    echo '</tr></thead><tbody>';
    
    foreach ($data as $row) {
        echo '<tr>';
        foreach ($row as $cell) {
            echo '<td>' . htmlspecialchars($cell) . '</td>';
        }
        echo '</tr>';
    }
    
    echo '</tbody></table></body></html>';
    exit;
}

// Вспомогательные функции перевода
function translateResourceType($type) {
    $map = ['water' => 'Вода', 'heat' => 'Тепло', 'electricity' => 'Электричество'];
    return $map[$type] ?? $type;
}

function translateAlertType($type) {
    $map = [
        'spike' => 'Скачок',
        'drop' => 'Падение',
        'overload' => 'Перегрузка',
        'temp_deviation' => 'Отклонение температуры',
        'connection_loss' => 'Потеря связи'
    ];
    return $map[$type] ?? $type;
}

function translateLevel($level) {
    $map = ['warning' => 'Предупреждение', 'alarm' => 'Авария', 'critical' => 'Критическая'];
    return $map[$level] ?? $level;
}

function translateStatus($status) {
    $map = ['active' => 'Активна', 'acknowledged' => 'Подтверждена', 'resolved' => 'Закрыта'];
    return $map[$status] ?? $status;
}
