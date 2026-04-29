<?php
/**
 * API: Управление оборудованием (счётчиками)
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

// Только администраторы могут управлять оборудованием
$currentUser = $auth->getCurrentUser();
if ($currentUser['role'] !== ROLE_ADMIN) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

try {
    $db = Database::getInstance();
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? 'list';
    
    switch ($action) {
        case 'list':
            // Получение списка счётчиков
            $counters = $db->fetchAll("
                SELECT c.*, z.name as zone_name 
                FROM counters c 
                LEFT JOIN zones z ON c.zone_id = z.id 
                ORDER BY c.id
            ");
            echo json_encode(['success' => true, 'data' => $counters]);
            break;
            
        case 'create':
            if ($method !== 'POST') {
                throw new Exception('Метод не разрешен');
            }
            $data = json_decode(file_get_contents('php://input'), true);
            createCounter($db, $currentUser, $data);
            break;
            
        case 'update':
            if ($method !== 'POST') {
                throw new Exception('Метод не разрешен');
            }
            $data = json_decode(file_get_contents('php://input'), true);
            updateCounter($db, $currentUser, $data);
            break;
            
        case 'delete':
            if ($method !== 'POST') {
                throw new Exception('Метод не разрешен');
            }
            $data = json_decode(file_get_contents('php://input'), true);
            deleteCounter($db, $currentUser, $data);
            break;
            
        case 'toggle_active':
            if ($method !== 'POST') {
                throw new Exception('Метод не разрешен');
            }
            $data = json_decode(file_get_contents('php://input'), true);
            toggleCounterActive($db, $currentUser, $data);
            break;
            
        default:
            throw new Exception('Неизвестное действие');
    }
    
} catch (Exception $e) {
    error_log("Ошибка в manage_counters.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Создание счётчика
 */
function createCounter($db, $currentUser, $data) {
    $name = trim($data['name'] ?? '');
    $serialNumber = trim($data['serial_number'] ?? '');
    $zoneId = (int)($data['zone_id'] ?? 0);
    $resourceType = $data['resource_type'] ?? '';
    $modbusAddress = isset($data['modbus_address']) ? (int)$data['modbus_address'] : null;
    $modbusRegister = isset($data['modbus_register']) ? (int)$data['modbus_register'] : null;
    $unit = trim($data['unit'] ?? '');
    
    // Валидация
    if (empty($name) || empty($serialNumber) || $zoneId <= 0 || empty($resourceType)) {
        throw new Exception('Все обязательные поля должны быть заполнены');
    }
    
    if (!in_array($resourceType, ['water', 'heat', 'electricity'])) {
        throw new Exception('Неверный тип ресурса');
    }
    
    // Проверка существования зоны
    $zone = $db->fetchOne("SELECT id FROM zones WHERE id = :id AND is_active = 1", ['id' => $zoneId]);
    if (!$zone) {
        throw new Exception('Зона не найдена или не активна');
    }
    
    // Проверка уникальности серийного номера
    $existing = $db->fetchOne("SELECT id FROM counters WHERE serial_number = :sn", ['sn' => $serialNumber]);
    if ($existing) {
        throw new Exception('Счётчик с таким серийным номером уже существует');
    }
    
    // Создание
    $counterId = $db->insert('counters', [
        'name' => $name,
        'serial_number' => $serialNumber,
        'zone_id' => $zoneId,
        'resource_type' => $resourceType,
        'modbus_address' => $modbusAddress,
        'modbus_register' => $modbusRegister,
        'unit' => $unit,
        'is_active' => 1,
        'status' => 'ok'
    ]);
    
    // Логирование
    $db->insert('system_log', [
        'user_id' => $currentUser['id'],
        'action' => 'counter_create',
        'details' => "Создан счётчик '{$name}' (ID: {$counterId})",
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Счётчик создан', 'counter_id' => $counterId]);
}

/**
 * Обновление счётчика
 */
function updateCounter($db, $currentUser, $data) {
    $counterId = (int)($data['id'] ?? 0);
    
    if ($counterId <= 0) {
        throw new Exception('Неверный ID счётчика');
    }
    
    $counter = $db->fetchOne("SELECT id, name, serial_number FROM counters WHERE id = :id", ['id' => $counterId]);
    if (!$counter) {
        throw new Exception('Счётчик не найден');
    }
    
    $updateData = [];
    $params = ['id' => $counterId];
    
    if (isset($data['name'])) {
        $updateData['name'] = trim($data['name']);
    }
    
    if (isset($data['serial_number'])) {
        // Проверка уникальности
        $existing = $db->fetchOne("SELECT id FROM counters WHERE serial_number = :sn AND id != :id", [
            'sn' => trim($data['serial_number']),
            'id' => $counterId
        ]);
        if ($existing) {
            throw new Exception('Счётчик с таким серийным номером уже существует');
        }
        $updateData['serial_number'] = trim($data['serial_number']);
    }
    
    if (isset($data['zone_id'])) {
        $zoneId = (int)$data['zone_id'];
        if ($zoneId > 0) {
            $zone = $db->fetchOne("SELECT id FROM zones WHERE id = :id AND is_active = 1", ['id' => $zoneId]);
            if (!$zone) {
                throw new Exception('Зона не найдена или не активна');
            }
            $updateData['zone_id'] = $zoneId;
        }
    }
    
    if (isset($data['resource_type']) && in_array($data['resource_type'], ['water', 'heat', 'electricity'])) {
        $updateData['resource_type'] = $data['resource_type'];
    }
    
    if (isset($data['modbus_address'])) {
        $updateData['modbus_address'] = (int)$data['modbus_address'];
    }
    
    if (isset($data['modbus_register'])) {
        $updateData['modbus_register'] = (int)$data['modbus_register'];
    }
    
    if (isset($data['unit'])) {
        $updateData['unit'] = trim($data['unit']);
    }
    
    if (isset($data['status']) && in_array($data['status'], ['ok', 'warning', 'error', 'offline'])) {
        $updateData['status'] = $data['status'];
    }
    
    if (!empty($updateData)) {
        $setParts = [];
        foreach (array_keys($updateData) as $key) {
            $setParts[] = "{$key} = :{$key}";
            $params[$key] = $updateData[$key];
        }
        
        $sql = "UPDATE counters SET " . implode(', ', $setParts) . " WHERE id = :id";
        $db->query($sql, $params);
    }
    
    // Логирование
    $db->insert('system_log', [
        'user_id' => $currentUser['id'],
        'action' => 'counter_update',
        'details' => "Обновлены данные счётчика '{$counter['name']}' (ID: {$counterId})",
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Данные счётчика обновлены']);
}

/**
 * Удаление счётчика
 */
function deleteCounter($db, $currentUser, $data) {
    $counterId = (int)($data['id'] ?? 0);
    
    if ($counterId <= 0) {
        throw new Exception('Неверный ID счётчика');
    }
    
    $counter = $db->fetchOne("SELECT id, name FROM counters WHERE id = :id", ['id' => $counterId]);
    if (!$counter) {
        throw new Exception('Счётчик не найден');
    }
    
    // Удаление (каскадное удаление показаний через FOREIGN KEY)
    $db->delete('counters', 'id = :id', ['id' => $counterId]);
    
    // Логирование
    $db->insert('system_log', [
        'user_id' => $currentUser['id'],
        'action' => 'counter_delete',
        'details' => "Удалён счётчик '{$counter['name']}' (ID: {$counterId})",
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Счётчик удалён']);
}

/**
 * Переключение статуса активности
 */
function toggleCounterActive($db, $currentUser, $data) {
    $counterId = (int)($data['id'] ?? 0);
    
    if ($counterId <= 0) {
        throw new Exception('Неверный ID счётчика');
    }
    
    $counter = $db->fetchOne("SELECT id, name, is_active FROM counters WHERE id = :id", ['id' => $counterId]);
    if (!$counter) {
        throw new Exception('Счётчик не найден');
    }
    
    $newStatus = $counter['is_active'] ? 0 : 1;
    $db->update('counters', ['is_active' => $newStatus], 'id = :id', ['id' => $counterId]);
    
    // Логирование
    $db->insert('system_log', [
        'user_id' => $currentUser['id'],
        'action' => 'counter_toggle_active',
        'details' => "Статус счётчика '{$counter['name']}' изменен на " . ($newStatus ? 'активен' : 'неактивен'),
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Статус счётчика изменен']);
}
