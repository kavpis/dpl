<?php
/**
 * Модуль анализа аномалий и детекции аварий
 * Проверяет показания на соответствие пороговым значениям и выявляет аномалии
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

class AnomalyDetector {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
    }
    
    /**
     * Основной метод анализа всех счётчиков
     * @return array Результаты анализа
     */
    public function analyzeAll() {
        $results = [
            'analyzed_count' => 0,
            'alerts_created' => 0,
            'warnings' => []
        ];
        
        try {
            // Получение всех активных счётчиков с последними показаниями
            $counters = $this->db->fetchAll("
                SELECT 
                    c.id,
                    c.name,
                    c.resource_type,
                    c.zone_id,
                    c.last_reading_time,
                    c.status,
                    z.name as zone_name,
                    (SELECT value FROM counter_readings cr 
                     WHERE cr.counter_id = c.id 
                     ORDER BY cr.reading_time DESC LIMIT 1) as last_value,
                    (SELECT value FROM counter_readings cr 
                     WHERE cr.counter_id = c.id 
                     ORDER BY cr.reading_time DESC LIMIT 1 OFFSET 1) as prev_value,
                    (SELECT reading_time FROM counter_readings cr 
                     WHERE cr.counter_id = c.id 
                     ORDER BY cr.reading_time DESC LIMIT 1 OFFSET 1) as prev_time
                FROM counters c
                LEFT JOIN zones z ON c.zone_id = z.id
                WHERE c.is_active = 1
            ");
            
            foreach ($counters as $counter) {
                $results['analyzed_count']++;
                
                // Пропускаем счётчики без показаний
                if ($counter['last_value'] === null) {
                    continue;
                }
                
                // Анализ текущего показания
                $alert = $this->analyzeReading($counter);
                
                if ($alert !== null) {
                    $this->createAlert($alert);
                    $results['alerts_created']++;
                    $results['warnings'][] = $alert['message'];
                }
            }
            
            return $results;
            
        } catch (Exception $e) {
            error_log("Ошибка анализа аномалий: " . $e->getMessage());
            $results['error'] = $e->getMessage();
            return $results;
        }
    }
    
    /**
     * Анализ отдельного показания
     * @param array $counter Данные счётчика
     * @return array|null Данные тревоги или null если аномалий нет
     */
    private function analyzeReading($counter) {
        $currentValue = (float)$counter['last_value'];
        $resourceType = $counter['resource_type'];
        $zoneId = $counter['zone_id'];
        
        // Получение пороговых значений (сначала для зоны, потом глобальные)
        $threshold = $this->getThreshold($zoneId, $resourceType);
        
        if (!$threshold) {
            return null; // Нет порогов для этого типа ресурса
        }
        
        // Проверка 1: Выход за пределы min/max
        if ($threshold['min_value'] !== null && $currentValue < (float)$threshold['min_value']) {
            return [
                'counter_id' => $counter['id'],
                'zone_id' => $zoneId,
                'alert_type' => 'drop',
                'level' => $threshold['alert_level'],
                'message' => "Значение ниже минимального порога ({$currentValue} < {$threshold['min_value']})",
                'current_value' => $currentValue,
                'threshold_value' => (float)$threshold['min_value']
            ];
        }
        
        if ($threshold['max_value'] !== null && $currentValue > (float)$threshold['max_value']) {
            $alertType = ($resourceType === 'electricity') ? 'overload' : 'spike';
            return [
                'counter_id' => $counter['id'],
                'zone_id' => $zoneId,
                'alert_type' => $alertType,
                'level' => $threshold['alert_level'],
                'message' => "Значение выше максимального порога ({$currentValue} > {$threshold['max_value']})",
                'current_value' => $currentValue,
                'threshold_value' => (float)$threshold['max_value']
            ];
        }
        
        // Проверка 2: Резкое изменение (delta_percent)
        if ($counter['prev_value'] !== null && $threshold['delta_percent'] !== null) {
            $prevValue = (float)$counter['prev_value'];
            
            if ($prevValue > 0) {
                $deltaPercent = abs(($currentValue - $prevValue) / $prevValue * 100);
                $maxDelta = (float)$threshold['delta_percent'];
                
                if ($deltaPercent > $maxDelta) {
                    $alertType = ($currentValue > $prevValue) ? 'spike' : 'drop';
                    return [
                        'counter_id' => $counter['id'],
                        'zone_id' => $zoneId,
                        'alert_type' => $alertType,
                        'level' => 'warning',
                        'message' => "Резкое изменение показания на " . round($deltaPercent, 1) . "% (превышает порог {$maxDelta}%)",
                        'current_value' => $currentValue,
                        'threshold_value' => $prevValue
                    ];
                }
            }
        }
        
        // Проверка 3: Отклонение температуры (для тепла)
        if ($resourceType === 'heat') {
            $tempAlert = $this->checkTemperatureDeviation($counter, $threshold);
            if ($tempAlert !== null) {
                return $tempAlert;
            }
        }
        
        return null;
    }
    
    /**
     * Получение пороговых значений
     * @param int $zoneId ID зоны
     * @param string $resourceType Тип ресурса
     * @return array|null Пороговые значения
     */
    private function getThreshold($zoneId, $resourceType) {
        // Сначала пробуем найти порог для конкретной зоны
        $threshold = $this->db->fetchOne(
            "SELECT * FROM thresholds 
             WHERE zone_id = :zoneId AND resource_type = :resourceType AND is_active = 1",
            ['zoneId' => $zoneId, 'resourceType' => $resourceType]
        );
        
        // Если не найдено, берём глобальные настройки
        if (!$threshold) {
            $threshold = $this->db->fetchOne(
                "SELECT * FROM thresholds 
                 WHERE zone_id IS NULL AND resource_type = :resourceType AND is_active = 1",
                ['resourceType' => $resourceType]
            );
        }
        
        return $threshold;
    }
    
    /**
     * Проверка отклонения температуры теплоносителя
     * @param array $counter Данные счётчика
     * @param array $threshold Пороговые значения
     * @return array|null Данные тревоги
     */
    private function checkTemperatureDeviation($counter, $threshold) {
        // Для простоты эмулируем проверку по абсолютному значению
        // В реальной системе нужно сравнивать с температурой подачи/обратки
        
        $currentValue = (float)$counter['last_value'];
        
        // Если температура слишком низкая (< 40 Гкал для примера)
        if ($currentValue < 40) {
            return [
                'counter_id' => $counter['id'],
                'zone_id' => $counter['zone_id'],
                'alert_type' => 'temp_deviation',
                'level' => 'warning',
                'message' => "Отклонение температуры теплоносителя (значение: {$currentValue})",
                'current_value' => $currentValue,
                'threshold_value' => 40.0
            ];
        }
        
        return null;
    }
    
    /**
     * Создание записи о тревоге в БД
     * @param array $alertData Данные тревоги
     * @return int ID созданной тревоги
     */
    private function createAlert($alertData) {
        // Проверка на дублирование активных тревог
        $existing = $this->db->fetchOne(
            "SELECT id FROM alerts 
             WHERE counter_id = :counterId 
             AND alert_type = :alertType 
             AND status = 'active'
             AND created_at > DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
            [
                'counterId' => $alertData['counter_id'],
                'alertType' => $alertData['alert_type']
            ]
        );
        
        if ($existing) {
            return $existing['id']; // Не создаём дубликат
        }
        
        return $this->db->insert('alerts', [
            'counter_id' => $alertData['counter_id'],
            'zone_id' => $alertData['zone_id'],
            'alert_type' => $alertData['alert_type'],
            'level' => $alertData['level'],
            'message' => $alertData['message'],
            'current_value' => $alertData['current_value'],
            'threshold_value' => $alertData['threshold_value'],
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s')
        ]);
    }
    
    /**
     * Автоматическое закрытие устаревших тревог
     * @param int $hours Часов после которых закрывать тревогу
     * @return int Количество закрытых тревог
     */
    public function autoResolveOldAlerts($hours = 24) {
        try {
            $sql = "UPDATE alerts 
                    SET status = 'resolved', resolved_at = NOW()
                    WHERE status = 'active'
                    AND created_at < DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
                    AND resolved_at IS NULL";
            
            $stmt = $this->db->query($sql);
            return $stmt->rowCount();
            
        } catch (Exception $e) {
            error_log("Ошибка автозакрытия тревог: " . $e->getMessage());
            return 0;
        }
    }
}
