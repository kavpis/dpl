<?php
// modbus_sim.php - Симуляция Modbus TCP регистров

/**
 * Применение джиттера (случайных колебаний) к значению
 */
function applyJitter($base, $percent) {
    if ($base == 0 || $percent == 0) return $base;
    $jitter = ($base * $percent / 100) * (mt_rand(-100, 100) / 100.0);
    return round($base + $jitter, 2);
}

/**
 * Преобразование float в два 16-битных слова (Modbus регистр)
 * Используется для передачи вещественных чисел
 */
function floatToWords($value) {
    $packed = pack('f', $value);         // little-endian float
    $reversed = strrev($packed);         // to big-endian byte order
    $words = unpack('v2', $reversed);    // two unsigned 16-bit words
    return [$words[1], $words[2]];
}

/**
 * Преобразование int в одно 16-битное слово
 */
function intToWord($value) {
    return pack('v', (int)$value);
}

/**
 * Генерация отладочной информации о Modbus-регистрах
 */
function generateModbusDebug($data) {
    $output = "=== ЭМУЛЯТОР MODBUS TCP — Система мониторинга ресурсов ===\n";
    $output .= "Обновлено: " . date('Y-m-d H:i:s') . "\n";
    $output .= "Интервал опроса: {$data['update_interval']} сек\n";
    $output .= "=========================================================\n\n";
    
    $addr = 0;
    
    foreach ([1, 2, 3, 4] as $zone_id) {
        $zone = $data['zones'][$zone_id];
        $output .= "--- ЗОНА {$zone_id}: {$zone['name']} ---\n";
        
        // Обработка счётчиков
        foreach ($zone['counters'] as $counter) {
            $disabled = $counter['disabled'] ?? false;
            
            switch ($counter['type']) {
                case 'water':
                    // Вода: расход (float) = 2 регистра
                    $base_val = $counter['value'];
                    $jitter = $counter['jitter'];
                    
                    if (!$disabled) {
                        $val = applyJitter($base_val, $jitter);
                    } else {
                        $val = $base_val;
                    }
                    
                    $words = floatToWords($val);
                    $output .= ($disabled ? "[ОТКАЗ] " : "") . 
                               "Адрес {$addr}-" . ($addr+1) . ": Вода — расход = {$val} м³/ч " .
                               "(0x" . dechex($words[1]) . ", 0x" . dechex($words[2]) . ")\n";
                    $addr += 2;
                    break;
                    
                case 'heat':
                    // Тепло: температура подачи (float), температура обратки (float), 
                    // расход теплоносителя (float), тепловая энергия (float)
                    // Итого: 8 регистров
                    
                    $temp_supply = applyJitter($counter['temp_supply'], $counter['jitter']);
                    $temp_return = applyJitter($counter['temp_return'], $counter['jitter']);
                    $flow_rate = applyJitter($counter['flow_rate'], $counter['jitter']);
                    $energy = applyJitter($counter['energy'], $counter['jitter']);
                    
                    $words_supply = floatToWords($temp_supply);
                    $words_return = floatToWords($temp_return);
                    $words_flow = floatToWords($flow_rate);
                    $words_energy = floatToWords($energy);
                    
                    $output .= ($disabled ? "[ОТКАЗ] " : "") . 
                               "Адрес {$addr}-" . ($addr+1) . ": Тепло — T подачи = {$temp_supply}°C\n";
                    $addr += 2;
                    $output .= ($disabled ? "[ОТКАЗ] " : "") . 
                               "Адрес {$addr}-" . ($addr+1) . ": Тепло — T обратки = {$temp_return}°C\n";
                    $addr += 2;
                    $output .= ($disabled ? "[ОТКАЗ] " : "") . 
                               "Адрес {$addr}-" . ($addr+1) . ": Тепло — расход = {$flow_rate} т/ч\n";
                    $addr += 2;
                    $output .= ($disabled ? "[ОТКАЗ] " : "") . 
                               "Адрес {$addr}-" . ($addr+1) . ": Тепло — энергия = {$energy} Гкал\n";
                    $addr += 2;
                    break;
                    
                case 'electricity':
                    // Электричество: напряжение (int), мощность (float), cos φ (float)
                    // Итого: 5 регистров
                    
                    $voltage = (int)applyJitter($counter['voltage'], $counter['jitter']);
                    $power = applyJitter($counter['power'], $counter['jitter']);
                    $power_factor = applyJitter($counter['power_factor'], $counter['jitter']);
                    
                    $words_power = floatToWords($power);
                    $words_pf = floatToWords($power_factor);
                    
                    $output .= ($disabled ? "[ОТКАЗ] " : "") . 
                               "Адрес {$addr}: Электричество — напряжение = {$voltage} В (0x" . dechex($voltage) . ")\n";
                    $addr++;
                    $output .= ($disabled ? "[ОТКАЗ] " : "") . 
                               "Адрес {$addr}-" . ($addr+1) . ": Электричество — мощность = {$power} кВт\n";
                    $addr += 2;
                    $output .= ($disabled ? "[ОТКАЗ] " : "") . 
                               "Адрес {$addr}-" . ($addr+1) . ": Электричество — cos φ = {$power_factor}\n";
                    $addr += 2;
                    break;
            }
        }
        
        // Аномалии (1 бит на каждую = 1 регистр)
        $anomalies = $zone['anomalies'] ?? [];
        $alert_byte = 0;
        
        // Бит 0: утечка воды
        if ($anomalies['water_leak'] ?? false) $alert_byte |= 0x01;
        // Бит 1: перегрев теплоносителя
        if ($anomalies['heat_overheat'] ?? false) $alert_byte |= 0x02;
        // Бит 2: низкая дельта температур
        if ($anomalies['heat_low_delta'] ?? false) $alert_byte |= 0x04;
        // Бит 3: перегрузка по мощности
        if ($anomalies['power_overload'] ?? false) $alert_byte |= 0x08;
        // Бит 4: низкое напряжение
        if ($anomalies['voltage_low'] ?? false) $alert_byte |= 0x10;
        // Бит 5: высокое напряжение
        if ($anomalies['voltage_high'] ?? false) $alert_byte |= 0x20;
        
        $output .= "\nАлерты (битовая маска 0x" . dechex($alert_byte) . "):\n";
        $output .= "  Адрес {$addr}: ";
        $output .= ($anomalies['water_leak'] ? "💧УТЕЧКА ВОДЫ " : "");
        $output .= ($anomalies['heat_overheat'] ? "🔥ПЕРЕГРЕВ " : "");
        $output .= ($anomalies['heat_low_delta'] ? "🌡️НИЗКАЯ ДЕЛЬТА " : "");
        $output .= ($anomalies['power_overload'] ? "⚡ПЕРЕГРУЗКА " : "");
        $output .= ($anomalies['voltage_low'] ? "🔽НИЗКОЕ НАПРЯЖЕНИЕ " : "");
        $output .= ($anomalies['voltage_high'] ? "🔼ВЫСОКОЕ НАПРЯЖЕНИЕ " : "");
        $output .= ($alert_byte == 0) ? "Нет активных алертов" : "";
        $output .= " (0x" . dechex($alert_byte) . ")\n";
        $addr++;
        
        $output .= "\n";
    }
    
    $output .= "=========================================================\n";
    $output .= "Итого использовано регистров: {$addr}\n";
    $output .= "=========================================================\n";
    
    return $output;
}
?>