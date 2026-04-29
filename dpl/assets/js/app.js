/**
 * Основной JavaScript файл системы DPL
 * Обработка данных, графики, интерактивная схема
 */

// Глобальные переменные
let currentUser = null;
let refreshInterval = null;
const REFRESH_RATE = 30000; // 30 секунд

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', function() {
    initApp();
});

/**
 * Инициализация приложения
 */
function initApp() {
    // Получение данных текущего пользователя из DOM
    const userElement = document.getElementById('current-user-data');
    if (userElement) {
        try {
            currentUser = JSON.parse(userElement.textContent);
        } catch (e) {
            console.error('Ошибка получения данных пользователя');
        }
    }
    
    // Инициализация в зависимости от страницы
    const pageType = document.body.dataset.page;
    
    switch (pageType) {
        case 'dispatcher':
            initDispatcherPage();
            break;
        case 'engineer':
            initEngineerPage();
            break;
        case 'admin':
            initAdminPage();
            break;
        case 'reports':
            initReportsPage();
            break;
    }
}

/**
 * Инициализация страницы диспетчера
 */
function initDispatcherPage() {
    loadReadings();
    loadAlerts();
    loadZones();
    
    // Автообновление данных
    startAutoRefresh();
}

/**
 * Инициализация страницы инженера
 */
function initEngineerPage() {
    loadAlerts();
    loadMessages();
    loadMaintenanceRequests();
    
    startAutoRefresh();
}

/**
 * Инициализация страницы администратора
 */
function initAdminPage() {
    loadUsers();
    loadCounters();
    loadThresholds();
}

/**
 * Инициализация страницы отчётов
 */
function initReportsPage() {
    loadReportSummary();
    initCharts();
}

/**
 * Запуск автообновления
 */
function startAutoRefresh() {
    if (refreshInterval) {
        clearInterval(refreshInterval);
    }
    
    refreshInterval = setInterval(function() {
        const pageType = document.body.dataset.page;
        
        if (pageType === 'dispatcher') {
            loadReadings();
            loadAlerts();
        } else if (pageType === 'engineer') {
            loadAlerts();
            loadMessages();
        }
    }, REFRESH_RATE);
}

/**
 * Загрузка показаний счётчиков
 */
async function loadReadings() {
    try {
        const response = await fetch('/dpl/api/get_readings.php');
        const result = await response.json();
        
        if (result.success) {
            updateReadingsTable(result.data);
            updateScheme(result.data);
        }
    } catch (error) {
        console.error('Ошибка загрузки показаний:', error);
    }
}

/**
 * Обновление таблицы показаний
 */
function updateReadingsTable(readings) {
    const tbody = document.getElementById('readings-table-body');
    if (!tbody) return;
    
    tbody.innerHTML = '';
    
    readings.forEach(function(reading) {
        const tr = document.createElement('tr');
        
        const statusClass = reading.is_online ? 'badge-success' : 'badge-danger';
        const statusText = reading.is_online ? 'Онлайн' : 'Офлайн';
        
        tr.innerHTML = `
            <td>${escapeHtml(reading.counter_name)}</td>
            <td>${escapeHtml(reading.zone_name)}</td>
            <td>${getResourceTypeLabel(reading.resource_type)}</td>
            <td>${reading.value !== null ? reading.value.toFixed(4) : '-'}</td>
            <td>${escapeHtml(reading.unit || '-')}</td>
            <td>${reading.reading_time || '-'}</td>
            <td><span class="badge ${statusClass}">${statusText}</span></td>
        `;
        
        tbody.appendChild(tr);
    });
}

/**
 * Загрузка тревог
 */
async function loadAlerts() {
    try {
        const response = await fetch('/dpl/api/get_alerts.php?status=active');
        const result = await response.json();
        
        if (result.success) {
            updateAlertsList(result.data);
            showAlertNotifications(result.data);
        }
    } catch (error) {
        console.error('Ошибка загрузки тревог:', error);
    }
}

/**
 * Обновление списка тревог
 */
function updateAlertsList(alerts) {
    const tbody = document.getElementById('alerts-table-body');
    if (!tbody) return;
    
    tbody.innerHTML = '';
    
    alerts.forEach(function(alert) {
        const tr = document.createElement('tr');
        
        const levelClass = 'badge-' + (alert.level === 'critical' ? 'critical' : 
                         alert.level === 'alarm' ? 'danger' : 'warning');
        
        tr.innerHTML = `
            <td><span class="badge ${levelClass}">${getLevelLabel(alert.level)}</span></td>
            <td>${escapeHtml(alert.message)}</td>
            <td>${escapeHtml(alert.zone_name || '-')}</td>
            <td>${escapeHtml(alert.counter_name || '-')}</td>
            <td>${alert.created_at}</td>
            <td>
                ${currentUser && (currentUser.role === 'admin' || currentUser.role === 'dispatcher' || currentUser.role === 'engineer') 
                    ? `<button class="btn btn-primary btn-sm" onclick="acknowledgeAlert(${alert.id})">Подтвердить</button>` 
                    : '-'}
            </td>
        `;
        
        tbody.appendChild(tr);
    });
}

/**
 * Подтверждение тревоги
 */
async function acknowledgeAlert(alertId) {
    if (!confirm('Подтвердить обработку тревоги?')) return;
    
    try {
        const response = await fetch('/dpl/api/acknowledge_alert.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({alert_id: alertId})
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('Тревога подтверждена', 'success');
            loadAlerts();
        } else {
            showAlert(result.message, 'error');
        }
    } catch (error) {
        console.error('Ошибка подтверждения тревоги:', error);
        showAlert('Ошибка при подтверждении тревоги', 'error');
    }
}

/**
 * Загрузка зон
 */
async function loadZones() {
    try {
        const response = await fetch('/dpl/api/get_zones.php');
        const result = await response.json();
        
        if (result.success) {
            drawScheme(result.data);
        }
    } catch (error) {
        console.error('Ошибка загрузки зон:', error);
    }
}

/**
 * Отрисовка интерактивной схемы
 */
function drawScheme(zones) {
    const container = document.getElementById('scheme-container');
    if (!container) return;
    
    container.innerHTML = '';
    
    zones.forEach(function(zone) {
        const zoneEl = document.createElement('div');
        zoneEl.className = 'scheme-zone';
        zoneEl.style.left = zone.coordinates ? zone.coordinates[0] + '%' : '50%';
        zoneEl.style.top = zone.coordinates ? zone.coordinates[1] + '%' : '50%';
        
        const problemClass = zone.problem_count > 0 ? 'zone-problem' : '';
        
        zoneEl.innerHTML = `
            <div class="zone-marker ${problemClass}">
                <span class="zone-name">${escapeHtml(zone.name)}</span>
                <span class="zone-counters">${zone.counters_count} счётчиков</span>
            </div>
        `;
        
        zoneEl.onclick = function() {
            showZoneDetails(zone);
        };
        
        container.appendChild(zoneEl);
    });
}

/**
 * Обновление схемы данными
 */
function updateScheme(readings) {
    // Группировка по зонам
    const byZone = {};
    readings.forEach(function(r) {
        if (!byZone[r.zone_id]) {
            byZone[r.zone_id] = {ok: 0, problem: 0};
        }
        if (r.is_online && r.status === 'ok') {
            byZone[r.zone_id].ok++;
        } else {
            byZone[r.zone_id].problem++;
        }
    });
    
    // Обновление визуализации
    Object.keys(byZone).forEach(function(zoneId) {
        const zoneEl = document.querySelector(`.scheme-zone[data-zone-id="${zoneId}"]`);
        if (zoneEl) {
            if (byZone[zoneId].problem > 0) {
                zoneEl.classList.add('zone-problem');
            } else {
                zoneEl.classList.remove('zone-problem');
            }
        }
    });
}

/**
 * Отправка сообщения инженеру
 */
async function sendMessage(engineerId, message, alertId) {
    try {
        const response = await fetch('/dpl/api/send_message.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                receiver_id: engineerId,
                message: message,
                alert_id: alertId || null
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('Сообщение отправлено', 'success');
        } else {
            showAlert(result.message, 'error');
        }
    } catch (error) {
        console.error('Ошибка отправки сообщения:', error);
        showAlert('Ошибка при отправке сообщения', 'error');
    }
}

/**
 * Запрос вызова бригады
 */
async function requestBrigade(zoneId, description, alertId) {
    try {
        const response = await fetch('/dpl/api/request_brigade.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                zone_id: zoneId,
                description: description,
                alert_id: alertId || null
            })
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('Заявка на вызов бригады создана', 'success');
            loadMaintenanceRequests();
        } else {
            showAlert(result.message, 'error');
        }
    } catch (error) {
        console.error('Ошибка создания заявки:', error);
        showAlert('Ошибка при создании заявки', 'error');
    }
}

/**
 * Инициализация графиков (Chart.js)
 */
function initCharts() {
    const ctx = document.getElementById('consumption-chart');
    if (!ctx) return;
    
    // Пример инициализации графика
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: [],
            datasets: []
        },
        options: {
            responsive: true,
            maintainAspectRatio: false
        }
    });
}

/**
 * Показ уведомления
 */
function showAlert(message, type) {
    const alertBox = document.createElement('div');
    alertBox.className = `alert alert-${type}`;
    alertBox.textContent = message;
    
    const container = document.querySelector('.main-content') || document.body;
    container.insertBefore(alertBox, container.firstChild);
    
    setTimeout(function() {
        alertBox.remove();
    }, 5000);
}

/**
 * Экранирование HTML
 */
function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

/**
 * Получение метки типа ресурса
 */
function getResourceTypeLabel(type) {
    const labels = {
        'water': 'Вода',
        'heat': 'Тепло',
        'electricity': 'Электричество'
    };
    return labels[type] || type;
}

/**
 * Получение метки уровня тревоги
 */
function getLevelLabel(level) {
    const labels = {
        'warning': 'Предупреждение',
        'alarm': 'Авария',
        'critical': 'Критическая'
    };
    return labels[level] || level;
}
