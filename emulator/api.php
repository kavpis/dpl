<?php
// api.php - API для управления эмулятором
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');

$config_file = 'config.json';

// Структура по умолчанию
$default = [
    'update_interval' => 30,
    'zones' => []
];

// Создание 4 зон по умолчанию
for ($i = 1; $i <= 4; $i++) {
    $zone_names = [
        1 => "Цех №1 (Механический)",
        2 => "Цех №2 (Сборочный)",
        3 => "Цех №3 (Покрасочный)",
        4 => "Административное здание"
    ];
    
    $default['zones'][$i] = [
        'name' => $zone_names[$i],
        'counters' => [
            ['type' => 'water', 'name' => "Счётчик воды вход №{$i}", 'value' => 2.5, 'jitter' => 5, 'disabled' => false, 'unit' => 'м³/ч'],
            ['type' => 'heat', 'name' => "Счётчик тепла котёл №{$i}", 'temp_supply' => 95, 'temp_return' => 65, 'flow_rate' => 15, 'energy' => 0.45, 'jitter' => 3, 'disabled' => false],
            ['type' => 'electricity', 'name' => "ВРУ Цех №{$i}", 'voltage' => 220, 'power' => 45, 'power_factor' => 0.92, 'jitter' => 2, 'disabled' => false]
        ],
        'anomalies' => [
            'water_leak' => false,
            'heat_overheat' => false,
            'heat_low_delta' => false,
            'power_overload' => false,
            'voltage_low' => false,
            'voltage_high' => false
        ]
    ];
}

// Создание конфига, если не существует
if (!file_exists($config_file)) {
    file_put_contents($config_file, json_encode($default, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    file_put_contents('audit.log', "[" . date('Y-m-d H:i:s') . "] Создан новый конфиг по умолчанию\n");
}

// Загрузка конфигурации
$data = json_decode(file_get_contents($config_file), true);

// Логирование действий
function logAction($message) {
    file_put_contents('audit.log', "[" . date('Y-m-d H:i:s') . "] " . $message . "\n", FILE_APPEND);
}

$action = $_GET['action'] ?? '';

// Получение текущей конфигурации
if ($action === 'get') {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

// Сохранение глобальных настроек
if ($action === 'save_global') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $new_interval = max(5, min(120, (int)($input['update_interval'] ?? 30)));
    
    if ($new_interval != $data['update_interval']) {
        $data['update_interval'] = $new_interval;
        file_put_contents($config_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        logAction("Изменён интервал обновления: {$new_interval} сек");
    }
    
    echo json_encode(['status' => 'ok', 'message' => 'Настройки сохранены']);
    exit;
}

// Сохранение настроек зон
if ($action === 'save_zones') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (isset($input['zones'])) {
        $changes = [];
        
        foreach ([1, 2, 3, 4] as $zone_id) {
            if (isset($input['zones'][$zone_id])) {
                // Валидация счётчиков
                $validated_counters = [];
                foreach ($input['zones'][$zone_id]['counters'] as $counter) {
                    $validated = validateCounter($counter);
                    if ($validated) {
                        $validated_counters[] = $validated;
                    }
                }
                
                $data['zones'][$zone_id]['counters'] = $validated_counters;
                $data['zones'][$zone_id]['anomalies'] = $input['zones'][$zone_id]['anomalies'] ?? [];
                $data['zones'][$zone_id]['name'] = $input['zones'][$zone_id]['name'] ?? "Зона {$zone_id}";
                
                $changes[] = "Зона {$zone_id}: " . $data['zones'][$zone_id]['name'];
            }
        }
        
        file_put_contents($config_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        logAction("Обновлены настройки зон: " . implode(", ", $changes));
        
        echo json_encode(['status' => 'ok', 'message' => 'Настройки зон сохранены']);
    }
    exit;
}

// Применение предустановленного сценария
if ($action === 'apply_scenario') {
    $input = json_decode(file_get_contents('php://input'), true);
    $scenario = $input['scenario'] ?? '';
    $zone_id = (int)($input['zone_id'] ?? 1);
    
    if (!isset($data['zones'][$zone_id])) {
        echo json_encode(['status' => 'error', 'message' => 'Зона не найдена']);
        exit;
    }
    
    $zone = &$data['zones'][$zone_id];
    $zone_name = $zone['name'];
    
    switch ($scenario) {
        // ===== СЦЕНАРИИ ДЛЯ ВОДЫ =====
        case 'water_leak_major':
            // Крупная утечка: расход резко вырастает до 10-15 м³/ч
            foreach ($zone['counters'] as &$counter) {
                if ($counter['type'] === 'water') {
                    $counter['value'] = 12.5; // Резкий скачок
                    $counter['jitter'] = 15;  // Высокая вариативность
                }
            }
            $zone['anomalies']['water_leak'] = true;
            logAction("Зона {$zone_id} ({$zone_name}): применён сценарий 'Крупная утечка воды'");
            break;
            
        case 'water_leak_silent':
            // Тихая утечка: небольшой постоянный расход ночью
            foreach ($zone['counters'] as &$counter) {
                if ($counter['type'] === 'water') {
                    $counter['value'] = 0.35; // Постоянный небольшой расход
                    $counter['jitter'] = 2;
                }
            }
            $zone['anomalies']['water_leak'] = true;
            logAction("Зона {$zone_id} ({$zone_name}): применён сценарий 'Тихая утечка воды'");
            break;
            
        case 'water_pressure_drop':
            // Падение давления: расход падает до нуля (обрыв трубы)
            foreach ($zone['counters'] as &$counter) {
                if ($counter['type'] === 'water') {
                    $counter['value'] = 0;
                    $counter['disabled'] = true; // Имитация отказа
                }
            }
            $zone['anomalies']['water_leak'] = true;
            logAction("Зона {$zone_id} ({$zone_name}): применён сценарий 'Падение давления воды'");
            break;
        
        // ===== СЦЕНАРИИ ДЛЯ ТЕПЛА =====
        case 'heat_overheat':
            // Перегрев теплоносителя: температура подачи > 130°C
            foreach ($zone['counters'] as &$counter) {
                if ($counter['type'] === 'heat') {
                    $counter['temp_supply'] = 145;  // Критическая температура
                    $counter['temp_return'] = 85;    // Высокая обратка
                    $counter['flow_rate'] = 25;      // Повышенный расход
                    $counter['energy'] = 0.85;
                    $counter['jitter'] = 8;
                }
            }
            $zone['anomalies']['heat_overheat'] = true;
            logAction("Зона {$zone_id} ({$zone_name}): применён сценарий 'Перегрев теплоносителя'");
            break;
            
        case 'heat_low_delta':
            // Низкая дельта температур: подача и обратка почти равны
            foreach ($zone['counters'] as &$counter) {
                if ($counter['type'] === 'heat') {
                    $counter['temp_supply'] = 85;    // Нормальная подача
                    $counter['temp_return'] = 80;    // Почти такая же обратка!
                    $counter['flow_rate'] = 18;
                    $counter['energy'] = 0.15;       // Низкая энергия
                }
            }
            $zone['anomalies']['heat_low_delta'] = true;
            logAction("Зона {$zone_id} ({$zone_name}): применён сценарий 'Низкая дельта температур'");
            break;
            
        case 'heat_freezing':
            // Замерзание системы: температура падает ниже 40°C
            foreach ($zone['counters'] as &$counter) {
                if ($counter['type'] === 'heat') {
                    $counter['temp_supply'] = 35;    // Ниже нормы
                    $counter['temp_return'] = 32;
                    $counter['flow_rate'] = 5;       // Низкий расход
                    $counter['energy'] = 0.08;
                }
            }
            $zone['anomalies']['heat_overheat'] = true; // Используем тот же флаг
            logAction("Зона {$zone_id} ({$zone_name}): применён сценарий 'Замерзание системы'");
            break;
        
        // ===== СЦЕНАРИИ ДЛЯ ЭЛЕКТРИЧЕСТВА =====
        case 'power_overload':
            // Перегрузка по мощности: > 100 кВт
            foreach ($zone['counters'] as &$counter) {
                if ($counter['type'] === 'electricity') {
                    $counter['voltage'] = 215;
                    $counter['power'] = 125;         // Критическая перегрузка
                    $counter['power_factor'] = 0.78; // Пониженный cos φ
                    $counter['jitter'] = 10;
                }
            }
            $zone['anomalies']['power_overload'] = true;
            logAction("Зона {$zone_id} ({$zone_name}): применён сценарий 'Перегрузка по мощности'");
            break;
            
        case 'voltage_sag':
            // Провал напряжения: < 198 В
            foreach ($zone['counters'] as &$counter) {
                if ($counter['type'] === 'electricity') {
                    $counter['voltage'] = 185;       // Критически низкое
                    $counter['power'] = 30;
                    $counter['power_factor'] = 0.88;
                }
            }
            $zone['anomalies']['voltage_low'] = true;
            logAction("Зона {$zone_id} ({$zone_name}): применён сценарий 'Провал напряжения'");
            break;
            
        case 'voltage_surge':
            // Всплеск напряжения: > 242 В
            foreach ($zone['counters'] as &$counter) {
                if ($counter['type'] === 'electricity') {
                    $counter['voltage'] = 255;       // Критически высокое
                    $counter['power'] = 55;
                    $counter['power_factor'] = 0.90;
                }
            }
            $zone['anomalies']['voltage_high'] = true;
            logAction("Зона {$zone_id} ({$zone_name}): применён сценарий 'Всплеск напряжения'");
            break;
            
        case 'low_power_factor':
            // Низкий коэффициент мощности: < 0.85
            foreach ($zone['counters'] as &$counter) {
                if ($counter['type'] === 'electricity') {
                    $counter['voltage'] = 220;
                    $counter['power'] = 48;
                    $counter['power_factor'] = 0.65; // Очень низкий!
                }
            }
            // Нет отдельного флага, но система должна это детектировать
            logAction("Зона {$zone_id} ({$zone_name}): применён сценарий 'Низкий коэффициент мощности'");
            break;
        
        // ===== КОМПЛЕКСНЫЕ СЦЕНАРИИ =====
        case 'multi_resource_crisis':
            // Кризис по всем ресурсам одновременно
            foreach ($zone['counters'] as &$counter) {
                if ($counter['type'] === 'water') {
                    $counter['value'] = 15.0; // Утечка
                    $counter['jitter'] = 20;
                } elseif ($counter['type'] === 'heat') {
                    $counter['temp_supply'] = 140; // Перегрев
                    $counter['temp_return'] = 90;
                    $counter['flow_rate'] = 28;
                    $counter['energy'] = 0.90;
                } elseif ($counter['type'] === 'electricity') {
                    $counter['voltage'] = 180; // Провал напряжения
                    $counter['power'] = 130;   // Перегрузка
                    $counter['power_factor'] = 0.70;
                }
            }
            $zone['anomalies']['water_leak'] = true;
            $zone['anomalies']['heat_overheat'] = true;
            $zone['anomalies']['power_overload'] = true;
            $zone['anomalies']['voltage_low'] = true;
            logAction("Зона {$zone_id} ({$zone_name}): применён сценарий 'Комплексный кризис по всем ресурсам'");
            break;
            
        case 'normal_operation':
            // Возврат к нормальному режиму
            $zone = [
                'name' => $zone['name'],
                'counters' => [
                    ['type' => 'water', 'name' => "Счётчик воды вход №{$zone_id}", 'value' => 2.5, 'jitter' => 5, 'disabled' => false, 'unit' => 'м³/ч'],
                    ['type' => 'heat', 'name' => "Счётчик тепла котёл №{$zone_id}", 'temp_supply' => 95, 'temp_return' => 65, 'flow_rate' => 15, 'energy' => 0.45, 'jitter' => 3, 'disabled' => false],
                    ['type' => 'electricity', 'name' => "ВРУ Цех №{$zone_id}", 'voltage' => 220, 'power' => 45, 'power_factor' => 0.92, 'jitter' => 2, 'disabled' => false]
                ],
                'anomalies' => [
                    'water_leak' => false,
                    'heat_overheat' => false,
                    'heat_low_delta' => false,
                    'power_overload' => false,
                    'voltage_low' => false,
                    'voltage_high' => false
                ]
            ];
            logAction("Зона {$zone_id} ({$zone_name}): возвращена в нормальный режим");
            break;
            
        default:
            echo json_encode(['status' => 'error', 'message' => 'Неизвестный сценарий']);
            exit;
    }
    
    file_put_contents($config_file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo json_encode(['status' => 'ok', 'message' => "Сценарий '{$scenario}' применён к зоне {$zone_id}"]);
    exit;
}

// Получение списка доступных сценариев
if ($action === 'get_scenarios') {
    $scenarios = [
        [
            'id' => 'normal_operation',
            'name' => '✅ Нормальный режим',
            'category' => 'normal',
            'description' => 'Все параметры в пределах нормы',
            'icon' => '✅'
        ],
        [
            'id' => 'water_leak_major',
            'name' => '💧 Крупная утечка воды',
            'category' => 'water',
            'description' => 'Расход вырастает до 12.5 м³/ч (резкий скачок)',
            'icon' => '💧',
            'severity' => 'emergency'
        ],
        [
            'id' => 'water_leak_silent',
            'name' => '💧 Тихая утечка воды',
            'category' => 'water',
            'description' => 'Постоянный ночной расход 0.35 м³/ч',
            'icon' => '🌙',
            'severity' => 'warning'
        ],
        [
            'id' => 'water_pressure_drop',
            'name' => '💧 Падение давления воды',
            'category' => 'water',
            'description' => 'Обрыв трубы — расход = 0',
            'icon' => '⚠️',
            'severity' => 'emergency'
        ],
        [
            'id' => 'heat_overheat',
            'name' => '🔥 Перегрев теплоносителя',
            'category' => 'heat',
            'description' => 'Температура подачи 145°C (критично!)',
            'icon' => '🔥',
            'severity' => 'critical'
        ],
        [
            'id' => 'heat_low_delta',
            'name' => '🌡️ Низкая дельта температур',
            'category' => 'heat',
            'description' => 'Подача 85°C, обратка 80°C (почти равны)',
            'icon' => '🌡️',
            'severity' => 'warning'
        ],
        [
            'id' => 'heat_freezing',
            'name' => '❄️ Замерзание системы',
            'category' => 'heat',
            'description' => 'Температура подачи 35°C (ниже нормы)',
            'icon' => '❄️',
            'severity' => 'emergency'
        ],
        [
            'id' => 'power_overload',
            'name' => '⚡ Перегрузка по мощности',
            'category' => 'electricity',
            'description' => 'Мощность 125 кВт (превышение лимита)',
            'icon' => '⚡',
            'severity' => 'emergency'
        ],
        [
            'id' => 'voltage_sag',
            'name' => '🔽 Провал напряжения',
            'category' => 'electricity',
            'description' => 'Напряжение 185 В (критически низкое)',
            'icon' => '🔽',
            'severity' => 'critical'
        ],
        [
            'id' => 'voltage_surge',
            'name' => '🔼 Всплеск напряжения',
            'category' => 'electricity',
            'description' => 'Напряжение 255 В (критически высокое)',
            'icon' => '🔼',
            'severity' => 'critical'
        ],
        [
            'id' => 'low_power_factor',
            'name' => '📉 Низкий коэффициент мощности',
            'category' => 'electricity',
            'description' => 'cos φ = 0.65 (требуется компенсация)',
            'icon' => '📉',
            'severity' => 'warning'
        ],
        [
            'id' => 'multi_resource_crisis',
            'name' => '💥 Комплексный кризис',
            'category' => 'complex',
            'description' => 'Аварии по всем ресурсам одновременно',
            'icon' => '💥',
            'severity' => 'critical'
        ]
    ];
    
    echo json_encode($scenarios, JSON_UNESCAPED_UNICODE);
    exit;
}

// Отладочная информация Modbus
if ($action === 'get_debug') {
    header('Content-Type: text/plain; charset=utf-8');
    include 'modbus_sim.php';
    echo generateModbusDebug($data);
    exit;
}

// Статистика использования
if ($action === 'get_stats') {
    $stats = [
        'total_zones' => count($data['zones']),
        'total_counters' => 0,
        'active_anomalies' => 0,
        'update_interval' => $data['update_interval'],
        'last_updated' => date('Y-m-d H:i:s')
    ];
    
    foreach ($data['zones'] as $zone) {
        $stats['total_counters'] += count($zone['counters']);
        foreach ($zone['anomalies'] as $anomaly) {
            if ($anomaly) $stats['active_anomalies']++;
        }
    }
    
    echo json_encode($stats, JSON_UNESCAPED_UNICODE);
    exit;
}

// Валидация данных счётчика
function validateCounter($counter) {
    if (!isset($counter['type'])) return null;
    
    $validated = [
        'type' => $counter['type'],
        'name' => $counter['name'] ?? 'Безымянный счётчик',
        'jitter' => max(0, min(50, (int)($counter['jitter'] ?? 5))),
        'disabled' => isset($counter['disabled']) ? (bool)$counter['disabled'] : false
    ];
    
    switch ($counter['type']) {
        case 'water':
            $validated['value'] = max(0, (float)($counter['value'] ?? 0));
            $validated['unit'] = 'м³/ч';
            break;
            
        case 'heat':
            $validated['temp_supply'] = max(0, min(150, (float)($counter['temp_supply'] ?? 95)));
            $validated['temp_return'] = max(0, min(150, (float)($counter['temp_return'] ?? 65)));
            $validated['flow_rate'] = max(0, (float)($counter['flow_rate'] ?? 15));
            $validated['energy'] = max(0, (float)($counter['energy'] ?? 0.45));
            break;
            
        case 'electricity':
            $validated['voltage'] = max(0, (float)($counter['voltage'] ?? 220));
            $validated['power'] = max(0, (float)($counter['power'] ?? 45));
            $validated['power_factor'] = max(0, min(1, (float)($counter['power_factor'] ?? 0.92)));
            break;
            
        default:
            return null;
    }
    
    return $validated;
}
?>