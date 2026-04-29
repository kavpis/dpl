<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Эмулятор Modbus TCP — Система мониторинга ресурсов</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        header {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
            margin-bottom: 25px;
            text-align: center;
        }
        
        h1 {
            color: #2c3e50;
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #7f8c8d;
            font-size: 16px;
        }
        
        .controls {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .control-group {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        label {
            font-weight: 600;
            color: #2c3e50;
            min-width: 220px;
        }
        
        input[type="number"] {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            width: 100px;
            transition: border-color 0.3s;
        }
        
        input[type="number"]:focus {
            border-color: #667eea;
            outline: none;
        }
        
        button {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        .btn-success {
            background: #27ae60;
            color: white;
        }
        
        .btn-success:hover {
            background: #229954;
        }
        
        .btn-danger {
            background: #e74c3c;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
        }
        
        .zone {
            background: white;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 25px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .zone-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 2px solid #f0f0f0;
        }
        
        .zone-title {
            font-size: 22px;
            color: #2c3e50;
            font-weight: 700;
        }
        
        .counter {
            background: #f8f9fa;
            padding: 15px;
            margin: 12px 0;
            border-radius: 8px;
            border-left: 4px solid #667eea;
        }
        
        .counter.water { border-left-color: #3498db; }
        .counter.heat { border-left-color: #e74c3c; }
        .counter.electricity { border-left-color: #f39c12; }
        
        .counter-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .counter-type {
            font-weight: 600;
            font-size: 16px;
        }
        
        .counter.water .counter-type { color: #3498db; }
        .counter.heat .counter-type { color: #e74c3c; }
        .counter.electricity .counter-type { color: #f39c12; }
        
        .counter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 12px;
            align-items: center;
        }
        
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .form-group label {
            font-size: 13px;
            color: #555;
            font-weight: 500;
            min-width: auto;
        }
        
        .form-group input {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .anomaly-section {
            background: #fef9e7;
            padding: 15px;
            margin: 15px 0;
            border-radius: 8px;
            border-left: 4px solid #f39c12;
        }
        
        .anomaly-title {
            font-weight: 600;
            color: #7f8c8d;
            margin-bottom: 10px;
            font-size: 15px;
        }
        
        .anomaly-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }
        
        .anomaly-label {
            display: flex;
            align-items: center;
            gap: 8px;
            cursor: pointer;
        }
        
        .anomaly-label input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .debug-box {
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
            font-family: 'Courier New', monospace;
            white-space: pre-wrap;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.2);
            height: 500px;
            overflow-y: auto;
            margin-top: 25px;
            font-size: 13px;
            line-height: 1.6;
        }
        
        .stats-bar {
            background: white;
            padding: 15px;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 20px;
        }
        
        .stat-item {
            text-align: center;
            padding: 10px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #667eea;
        }
        
        .stat-label {
            font-size: 13px;
            color: #7f8c8d;
            margin-top: 5px;
        }
        
        .alert {
            padding: 12px;
            border-radius: 8px;
            margin: 10px 0;
            font-weight: 500;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>🔧 Эмулятор счётчиков ресурсов</h1>
            <p class="subtitle">Modbus TCP симулятор для системы мониторинга воды, тепла и электричества</p>
        </header>
        
        <div id="alertContainer"></div>
        
        <div class="stats-bar" id="statsBar">
            <!-- Статистика будет загружена динамически -->
        </div>
        
        <div class="controls">
            <div class="control-group">
                <label>⏱️ Интервал обновления:</label>
                <input type="number" id="update_interval" min="5" max="120" value="30" step="5">
                <span>секунд</span>
            </div>
            <button class="btn-primary" onclick="saveGlobal()">
                <span>💾</span> Сохранить настройки
            </button>
            <button class="btn-success" onclick="saveAllZones()">
                <span>✅</span> Сохранить все зоны
            </button>
            <div class="control-group" style="flex: 1;">
    <label>🎭 Быстрый сценарий аварии:</label>
    <select id="scenarioSelect" style="width: 250px; padding: 10px; border: 2px solid #e0e0e0; border-radius: 8px;">
        <option value="">— Выберите сценарий —</option>
    </select>
    <button class="btn-danger" onclick="applyScenario()" style="background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%);">
        <span>🚀</span> Применить
    </button>
</div>
        </div>
        
        <form id="zonesForm">
            <?php for ($zone_id = 1; $zone_id <= 4; $zone_id++): ?>
            <div class="zone" id="zone_<?= $zone_id ?>">
                <div class="zone-header">
                    <div class="zone-title">🏭 Зона <?= $zone_id ?> — <span id="zone_name_<?= $zone_id ?>">Загрузка...</span></div>
                </div>
                
                <!-- Аномалии -->
                <div class="anomaly-section">
                    <div class="anomaly-title">🚨 Активные аварии:</div>
                    <div class="anomaly-grid">
                        <label class="anomaly-label">
                            <input type="checkbox" name="water_leak_<?= $zone_id ?>">
                            <span>💧 Утечка воды</span>
                        </label>
                        <label class="anomaly-label">
                            <input type="checkbox" name="heat_overheat_<?= $zone_id ?>">
                            <span>🔥 Перегрев теплоносителя</span>
                        </label>
                        <label class="anomaly-label">
                            <input type="checkbox" name="heat_low_delta_<?= $zone_id ?>">
                            <span>🌡️ Низкая дельта температур</span>
                        </label>
                        <label class="anomaly-label">
                            <input type="checkbox" name="power_overload_<?= $zone_id ?>">
                            <span>⚡ Перегрузка по мощности</span>
                        </label>
                        <label class="anomaly-label">
                            <input type="checkbox" name="voltage_low_<?= $zone_id ?>">
                            <span>🔽 Низкое напряжение</span>
                        </label>
                        <label class="anomaly-label">
                            <input type="checkbox" name="voltage_high_<?= $zone_id ?>">
                            <span>🔼 Высокое напряжение</span>
                        </label>
                    </div>
                </div>
                
                <!-- Счётчики -->
                <div id="counters_<?= $zone_id ?>">
                    <!-- Счётчики будут загружены динамически -->
                </div>
            </div>
            <?php endfor; ?>
        </form>
        
        <h2 style="color: white; margin: 30px 0 15px;">📊 Отладочное окно: Modbus-регистры</h2>
        <div id="debugBox" class="debug-box">Загрузка...</div>
    </div>

    <script>
        let updateIntervalMs = 30000;
        let debugTimer;
        let statsTimer;
        
        // Загрузка конфигурации
        async function loadConfig() {
            try {
                const res = await fetch('api.php?action=get');
                const data = await res.json();
                
                // Глобальные настройки
                document.getElementById('update_interval').value = data.update_interval;
                updateIntervalMs = data.update_interval * 1000;
                
                // Зоны
                for (let zoneId = 1; zoneId <= 4; zoneId++) {
                    const zone = data.zones[zoneId];
                    if (!zone) continue;
                    
                    // Название зоны
                    document.getElementById(`zone_name_${zoneId}`).textContent = zone.name;
                    
                    // Счётчики
                    const container = document.getElementById(`counters_${zoneId}`);
                    container.innerHTML = '';
                    
                    zone.counters.forEach(counter => {
                        renderCounter(zoneId, counter);
                    });
                    
                    // Аномалии
                    const anomalies = zone.anomalies || {};
                    document.querySelector(`[name="water_leak_${zoneId}"]`).checked = anomalies.water_leak || false;
                    document.querySelector(`[name="heat_overheat_${zoneId}"]`).checked = anomalies.heat_overheat || false;
                    document.querySelector(`[name="heat_low_delta_${zoneId}"]`).checked = anomalies.heat_low_delta || false;
                    document.querySelector(`[name="power_overload_${zoneId}"]`).checked = anomalies.power_overload || false;
                    document.querySelector(`[name="voltage_low_${zoneId}"]`).checked = anomalies.voltage_low || false;
                    document.querySelector(`[name="voltage_high_${zoneId}"]`).checked = anomalies.voltage_high || false;
                }
                
                // Запуск обновлений
                loadStats();
                updateDebugBox();
                clearInterval(debugTimer);
                clearInterval(statsTimer);
                debugTimer = setInterval(updateDebugBox, updateIntervalMs);
                statsTimer = setInterval(loadStats, 5000);
                
            } catch (error) {
                showAlert('Ошибка загрузки конфигурации: ' + error.message, 'error');
            }
        }
        
        // Рендеринг счётчика
        function renderCounter(zoneId, counter) {
            const container = document.getElementById(`counters_${zoneId}`);
            const div = document.createElement('div');
            div.className = `counter ${counter.type}`;
            
            let html = `
                <div class="counter-header">
                    <span class="counter-type">📊 ${getCounterTypeName(counter.type)}</span>
                    <span>${counter.name}</span>
                </div>
                <div class="counter-form">
            `;
            
            switch (counter.type) {
                case 'water':
                    html += `
                        <div class="form-group">
                            <label>Расход (м³/ч)</label>
                            <input type="number" step="0.1" name="water_value_${zoneId}_${counter.name}" 
                                   value="${counter.value}" onchange="validateInput(this, 0, 100)">
                        </div>
                        <div class="form-group">
                            <label>Джиттер (%)</label>
                            <input type="number" name="water_jitter_${zoneId}_${counter.name}" 
                                   value="${counter.jitter}" min="0" max="50">
                        </div>
                        <div class="form-group" style="align-self: end;">
                            <label style="visibility: hidden;">Отказ</label>
                            <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                <input type="checkbox" name="water_disabled_${zoneId}_${counter.name}" 
                                       ${counter.disabled ? 'checked' : ''}>
                                <span>Отказ счётчика</span>
                            </label>
                        </div>
                    `;
                    break;
                    
                case 'heat':
                    html += `
                        <div class="form-group">
                            <label>T подачи (°C)</label>
                            <input type="number" name="heat_temp_supply_${zoneId}_${counter.name}" 
                                   value="${counter.temp_supply}" onchange="validateInput(this, 0, 150)">
                        </div>
                        <div class="form-group">
                            <label>T обратки (°C)</label>
                            <input type="number" name="heat_temp_return_${zoneId}_${counter.name}" 
                                   value="${counter.temp_return}" onchange="validateInput(this, 0, 150)">
                        </div>
                        <div class="form-group">
                            <label>Расход (т/ч)</label>
                            <input type="number" step="0.1" name="heat_flow_rate_${zoneId}_${counter.name}" 
                                   value="${counter.flow_rate}" onchange="validateInput(this, 0, 100)">
                        </div>
                        <div class="form-group">
                            <label>Энергия (Гкал)</label>
                            <input type="number" step="0.01" name="heat_energy_${zoneId}_${counter.name}" 
                                   value="${counter.energy}" onchange="validateInput(this, 0, 10)">
                        </div>
                        <div class="form-group">
                            <label>Джиттер (%)</label>
                            <input type="number" name="heat_jitter_${zoneId}_${counter.name}" 
                                   value="${counter.jitter}" min="0" max="50">
                        </div>
                        <div class="form-group" style="align-self: end;">
                            <label style="visibility: hidden;">Отказ</label>
                            <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                <input type="checkbox" name="heat_disabled_${zoneId}_${counter.name}" 
                                       ${counter.disabled ? 'checked' : ''}>
                                <span>Отказ счётчика</span>
                            </label>
                        </div>
                    `;
                    break;
                    
                case 'electricity':
                    html += `
                        <div class="form-group">
                            <label>Напряжение (В)</label>
                            <input type="number" name="electricity_voltage_${zoneId}_${counter.name}" 
                                   value="${counter.voltage}" onchange="validateInput(this, 0, 1000)">
                        </div>
                        <div class="form-group">
                            <label>Мощность (кВт)</label>
                            <input type="number" step="0.1" name="electricity_power_${zoneId}_${counter.name}" 
                                   value="${counter.power}" onchange="validateInput(this, 0, 1000)">
                        </div>
                        <div class="form-group">
                            <label>cos φ</label>
                            <input type="number" step="0.01" name="electricity_power_factor_${zoneId}_${counter.name}" 
                                   value="${counter.power_factor}" onchange="validateInput(this, 0, 1)">
                        </div>
                        <div class="form-group">
                            <label>Джиттер (%)</label>
                            <input type="number" name="electricity_jitter_${zoneId}_${counter.name}" 
                                   value="${counter.jitter}" min="0" max="50">
                        </div>
                        <div class="form-group" style="align-self: end;">
                            <label style="visibility: hidden;">Отказ</label>
                            <label style="display: flex; align-items: center; gap: 5px; cursor: pointer;">
                                <input type="checkbox" name="electricity_disabled_${zoneId}_${counter.name}" 
                                       ${counter.disabled ? 'checked' : ''}>
                                <span>Отказ счётчика</span>
                            </label>
                        </div>
                    `;
                    break;
            }
            
            html += `</div>`;
            div.innerHTML = html;
            container.appendChild(div);
        }
        
        // Получение названия типа счётчика
        function getCounterTypeName(type) {
            const names = {
                'water': 'Вода',
                'heat': 'Тепло',
                'electricity': 'Электричество'
            };
            return names[type] || type;
        }
        
        // Валидация ввода
        function validateInput(input, min, max) {
            let value = parseFloat(input.value);
            if (isNaN(value)) value = min;
            value = Math.max(min, Math.min(max, value));
            input.value = value;
        }
        
        // Сохранение глобальных настроек
        async function saveGlobal() {
            try {
                const interval = parseInt(document.getElementById('update_interval').value);
                const res = await fetch('api.php?action=save_global', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({update_interval: interval})
                });
                const result = await res.json();
                showAlert(result.message || 'Настройки сохранены', 'success');
                clearInterval(debugTimer);
                loadConfig();
            } catch (error) {
                showAlert('Ошибка сохранения: ' + error.message, 'error');
            }
        }
        
        // Сохранение всех зон
        async function saveAllZones() {
            try {
                const zones = {};
                
                for (let zoneId = 1; zoneId <= 4; zoneId++) {
                    const zoneName = document.getElementById(`zone_name_${zoneId}`).textContent;
                    
                    // Собираем счётчики
                    const counters = [];
                    const zoneDiv = document.getElementById(`zone_${zoneId}`);
                    
                    // Вода
                    const waterInputs = zoneDiv.querySelectorAll('[name^="water_value_"]');
                    waterInputs.forEach(input => {
                        const name = input.name.split('_').slice(3).join('_');
                        counters.push({
                            type: 'water',
                            name: decodeURIComponent(name),
                            value: parseFloat(input.value),
                            jitter: parseFloat(zoneDiv.querySelector(`[name="water_jitter_${zoneId}_${name}"]`).value),
                            disabled: zoneDiv.querySelector(`[name="water_disabled_${zoneId}_${name}"]`).checked
                        });
                    });
                    
                    // Тепло
                    const heatInputs = zoneDiv.querySelectorAll('[name^="heat_temp_supply_"]');
                    heatInputs.forEach(input => {
                        const name = input.name.split('_').slice(4).join('_');
                        counters.push({
                            type: 'heat',
                            name: decodeURIComponent(name),
                            temp_supply: parseFloat(input.value),
                            temp_return: parseFloat(zoneDiv.querySelector(`[name="heat_temp_return_${zoneId}_${name}"]`).value),
                            flow_rate: parseFloat(zoneDiv.querySelector(`[name="heat_flow_rate_${zoneId}_${name}"]`).value),
                            energy: parseFloat(zoneDiv.querySelector(`[name="heat_energy_${zoneId}_${name}"]`).value),
                            jitter: parseFloat(zoneDiv.querySelector(`[name="heat_jitter_${zoneId}_${name}"]`).value),
                            disabled: zoneDiv.querySelector(`[name="heat_disabled_${zoneId}_${name}"]`).checked
                        });
                    });
                    
                    // Электричество
                    const electricityInputs = zoneDiv.querySelectorAll('[name^="electricity_voltage_"]');
                    electricityInputs.forEach(input => {
                        const name = input.name.split('_').slice(3).join('_');
                        counters.push({
                            type: 'electricity',
                            name: decodeURIComponent(name),
                            voltage: parseFloat(input.value),
                            power: parseFloat(zoneDiv.querySelector(`[name="electricity_power_${zoneId}_${name}"]`).value),
                            power_factor: parseFloat(zoneDiv.querySelector(`[name="electricity_power_factor_${zoneId}_${name}"]`).value),
                            jitter: parseFloat(zoneDiv.querySelector(`[name="electricity_jitter_${zoneId}_${name}"]`).value),
                            disabled: zoneDiv.querySelector(`[name="electricity_disabled_${zoneId}_${name}"]`).checked
                        });
                    });
                    
                    // Аномалии
                    const anomalies = {
                        water_leak: document.querySelector(`[name="water_leak_${zoneId}"]`).checked,
                        heat_overheat: document.querySelector(`[name="heat_overheat_${zoneId}"]`).checked,
                        heat_low_delta: document.querySelector(`[name="heat_low_delta_${zoneId}"]`).checked,
                        power_overload: document.querySelector(`[name="power_overload_${zoneId}"]`).checked,
                        voltage_low: document.querySelector(`[name="voltage_low_${zoneId}"]`).checked,
                        voltage_high: document.querySelector(`[name="voltage_high_${zoneId}"]`).checked
                    };
                    
                    zones[zoneId] = {
                        name: zoneName,
                        counters: counters,
                        anomalies: anomalies
                    };
                }
                
                const res = await fetch('api.php?action=save_zones', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({zones: zones})
                });
                
                const result = await res.json();
                showAlert(result.message || 'Все зоны сохранены', 'success');
                
            } catch (error) {
                showAlert('Ошибка сохранения зон: ' + error.message, 'error');
            }
        }
        
        // Обновление отладочного окна
        async function updateDebugBox() {
            try {
                const res = await fetch('api.php?action=get_debug');
                const text = await res.text();
                document.getElementById('debugBox').textContent = text;
            } catch (error) {
                console.error('Ошибка обновления отладки:', error);
            }
        }
        
        // Загрузка статистики
        async function loadStats() {
            try {
                const res = await fetch('api.php?action=get_stats');
                const stats = await res.json();
                
                const statsBar = document.getElementById('statsBar');
                statsBar.innerHTML = `
                    <div class="stat-item">
                        <div class="stat-value">${stats.total_zones}</div>
                        <div class="stat-label">Зон</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">${stats.total_counters}</div>
                        <div class="stat-label">Счётчиков</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">${stats.active_anomalies}</div>
                        <div class="stat-label">Активных аварий</div>
                    </div>
                    <div class="stat-item">
                        <div class="stat-value">${stats.update_interval}с</div>
                        <div class="stat-label">Интервал опроса</div>
                    </div>
                `;
            } catch (error) {
                console.error('Ошибка загрузки статистики:', error);
            }
        }
        
        // Показать уведомление
        function showAlert(message, type = 'success') {
            const container = document.getElementById('alertContainer');
            const div = document.createElement('div');
            div.className = `alert alert-${type}`;
            div.textContent = message;
            div.style.animation = 'fadeIn 0.3s, fadeOut 0.3s 2.7s';
            container.innerHTML = '';
            container.appendChild(div);
            
            setTimeout(() => {
                div.remove();
            }, 3000);
        }
        
        // Инициализация
        loadConfig();

    // Загрузка списка сценариев
async function loadScenarios() {
    try {
        const res = await fetch('api.php?action=get_scenarios');
        const scenarios = await res.json();
        
        const select = document.getElementById('scenarioSelect');
        select.innerHTML = '<option value="">— Выберите сценарий —</option>';
        
        // Группировка по категориям
        const categories = {
            'normal': { name: 'Нормальный режим', scenarios: [] },
            'water': { name: 'Вода', scenarios: [] },
            'heat': { name: 'Тепло', scenarios: [] },
            'electricity': { name: 'Электричество', scenarios: [] },
            'complex': { name: 'Комплексные', scenarios: [] }
        };
        
        scenarios.forEach(scenario => {
            categories[scenario.category].scenarios.push(scenario);
        });
        
        // Добавление в селект с оптгруппами
        Object.entries(categories).forEach(([catId, cat]) => {
            if (cat.scenarios.length > 0) {
                const optgroup = document.createElement('optgroup');
                optgroup.label = `— ${cat.name} —`;
                
                cat.scenarios.forEach(scenario => {
                    const option = document.createElement('option');
                    option.value = scenario.id;
                    option.textContent = `${scenario.icon} ${scenario.name}`;
                    option.title = scenario.description;
                    if (scenario.severity) {
                        option.dataset.severity = scenario.severity;
                    }
                    optgroup.appendChild(option);
                });
                
                select.appendChild(optgroup);
            }
        });
    } catch (error) {
        console.error('Ошибка загрузки сценариев:', error);
    }
}

// Применение сценария
async function applyScenario() {
    const scenarioId = document.getElementById('scenarioSelect').value;
    if (!scenarioId) {
        showAlert('Выберите сценарий из списка', 'error');
        return;
    }
    
    // Определяем, к какой зоне применить (можно добавить выбор зоны)
    const zoneId = 1; // По умолчанию к зоне 1, можно сделать выбор
    
    try {
        const res = await fetch('api.php?action=apply_scenario', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                scenario: scenarioId,
                zone_id: zoneId
            })
        });
        
        const result = await res.json();
        
        if (result.status === 'ok') {
            showAlert(result.message, 'success');
            // Обновляем конфигурацию
            await loadConfig();
        } else {
            showAlert(result.message || 'Ошибка применения сценария', 'error');
        }
    } catch (error) {
        showAlert('Ошибка: ' + error.message, 'error');
    }
}

// Инициализация (добавь в конец функции loadConfig или после неё)
loadScenarios();
    </script>
</body>
</html>