<?php
/**
 * Клиент для подключения к эмулятору счётчиков
 * Получение данных через Modbus TCP (эмуляция)
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

class EmulatorClient {
    private $db;
    private $emulatorUrl;
    
    public function __construct() {
        $this->db = Database::getInstance();
        // URL эмулятора (предполагается, что эмулятор доступен по этому адресу)
        $this->emulatorUrl = 'http://localhost/dpl/emulator/api.php';
    }
    
    /**
     * Получение данных от эмулятора
     * @return array|false Массив показаний или false при ошибке
     */
    public function fetchReadings() {
        try {
            // Запрос к эмулятору
            $context = stream_context_create([
                'http' => [
                    'timeout' => 5,
                    'ignore_errors' => true
                ]
            ]);
            
            $response = file_get_contents($this->emulatorUrl, false, $context);
            
            if ($response === false) {
                error_log("Не удалось подключиться к эмулятору: {$this->emulatorUrl}");
                return false;
            }
            
            $data = json_decode($response, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("Ошибка парсинга JSON от эмулятора: " . json_last_error_msg());
                return false;
            }
            
            if (!isset($data['success']) || !$data['success']) {
                error_log("Эмулятор вернул ошибку: " . ($data['message'] ?? 'Неизвестная ошибка'));
                return false;
            }
            
            return $data['data'] ?? [];
            
        } catch (Exception $e) {
            error_log("Ошибка получения данных от эмулятора: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Парсинг данных Modbus TCP из ответа эмулятора
     * Эмулятор возвращает данные в формате, имитирующем регистры Modbus
     * @param array $rawData Сырые данные от эмулятора
     * @return array Обработанные показания
     */
    public function parseModbusData($rawData) {
        $readings = [];
        
        foreach ($rawData as $item) {
            // Ожидаемый формат от эмулятора:
            // {
            //     "counter_id": 1,
            //     "modbus_address": 1,
            //     "register": 40001,
            //     "value": 123.45,
            //     "timestamp": "2024-01-01 12:00:00"
            // }
            
            $counterId = (int)($item['counter_id'] ?? 0);
            $value = isset($item['value']) ? (float)$item['value'] : null;
            $timestamp = $item['timestamp'] ?? date('Y-m-d H:i:s');
            $quality = isset($item['quality']) ? (int)$item['quality'] : 1;
            
            if ($counterId > 0 && $value !== null) {
                $readings[] = [
                    'counter_id' => $counterId,
                    'value' => $value,
                    'reading_time' => $timestamp,
                    'quality' => $quality
                ];
            }
        }
        
        return $readings;
    }
    
    /**
     * Сохранение показаний в базу данных
     * @param array $readings Массив показаний
     * @return int Количество сохранённых записей
     */
    public function saveReadings($readings) {
        if (empty($readings)) {
            return 0;
        }
        
        $savedCount = 0;
        
        try {
            $this->db->beginTransaction();
            
            foreach ($readings as $reading) {
                // Проверка существования счётчика
                $counter = $this->db->fetchOne(
                    "SELECT id, is_active FROM counters WHERE id = :id",
                    ['id' => $reading['counter_id']]
                );
                
                if (!$counter || !$counter['is_active']) {
                    continue; // Пропускаем неактивные или несуществующие счётчики
                }
                
                // Вставка показания
                $this->db->insert('counter_readings', [
                    'counter_id' => $reading['counter_id'],
                    'value' => $reading['value'],
                    'reading_time' => $reading['reading_time'],
                    'quality' => $reading['quality']
                ]);
                
                // Обновление времени последнего показания и статуса счётчика
                $this->db->update('counters', [
                    'last_reading_time' => $reading['reading_time'],
                    'status' => $reading['quality'] ? 'ok' : 'warning'
                ], 'id = :id', ['id' => $reading['counter_id']]);
                
                $savedCount++;
            }
            
            $this->db->commit();
            
        } catch (Exception $e) {
            $this->db->rollback();
            error_log("Ошибка сохранения показаний: " . $e->getMessage());
            throw $e;
        }
        
        return $savedCount;
    }
    
    /**
     * Основной метод для опроса эмулятора и сохранения данных
     * @return array Результат операции
     */
    public function pollAndSave() {
        // Получение данных от эмулятора
        $rawData = $this->fetchReadings();
        
        if ($rawData === false) {
            return [
                'success' => false,
                'message' => 'Не удалось получить данные от эмулятора',
                'saved_count' => 0
            ];
        }
        
        // Парсинг данных Modbus
        $readings = $this->parseModbusData($rawData);
        
        if (empty($readings)) {
            return [
                'success' => true,
                'message' => 'Данные получены, но нет показаний для сохранения',
                'saved_count' => 0
            ];
        }
        
        // Сохранение в БД
        $savedCount = $this->saveReadings($readings);
        
        return [
            'success' => true,
            'message' => "Сохранено {$savedCount} показаний",
            'saved_count' => $savedCount,
            'total_received' => count($readings)
        ];
    }
    
    /**
     * Проверка потери связи со счётчиками
     * Обновляет статус счётчиков, от которых давно не было данных
     */
    public function checkConnectionLoss() {
        $timeout = CONNECTION_LOSS_TIMEOUT; // 10 минут по умолчанию
        
        try {
            // Поиск счётчиков с просроченным временем последнего показания
            $sql = "UPDATE counters 
                    SET status = 'offline' 
                    WHERE is_active = 1 
                    AND status != 'offline'
                    AND (last_reading_time IS NULL OR last_reading_time < DATE_SUB(NOW(), INTERVAL {$timeout} SECOND))";
            
            $stmt = $this->db->query($sql);
            $offlineCount = $stmt->rowCount();
            
            if ($offlineCount > 0) {
                // Создание тревог для потерянных счётчиков
                $lostCounters = $this->db->fetchAll(
                    "SELECT id, name, zone_id, resource_type 
                     FROM counters 
                     WHERE status = 'offline' 
                     AND last_reading_time < DATE_SUB(NOW(), INTERVAL {$timeout} SECOND)
                     AND id NOT IN (
                         SELECT counter_id FROM alerts 
                         WHERE alert_type = 'connection_loss' 
                         AND status = 'active'
                         AND created_at > DATE_SUB(NOW(), INTERVAL {$timeout} SECOND)
                     )"
                );
                
                foreach ($lostCounters as $counter) {
                    $this->db->insert('alerts', [
                        'counter_id' => $counter['id'],
                        'zone_id' => $counter['zone_id'],
                        'alert_type' => 'connection_loss',
                        'level' => 'critical',
                        'message' => "Потеря связи со счётчиком '{$counter['name']}'",
                        'status' => 'active',
                        'created_at' => date('Y-m-d H:i:s')
                    ]);
                }
                
                error_log("Обнаружена потеря связи с {$offlineCount} счётчиками");
            }
            
            return $offlineCount;
            
        } catch (Exception $e) {
            error_log("Ошибка проверки потери связи: " . $e->getMessage());
            return 0;
        }
    }
}
