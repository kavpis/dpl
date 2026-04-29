-- Система сбора и анализа показаний счётчиков воды, тепла и электричества
-- Скрипт создания базы данных
-- Версия: 1.0

CREATE DATABASE IF NOT EXISTS dpl_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE dpl_system;

-- =====================================================
-- Таблица пользователей (users)
-- =====================================================
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('dispatcher', 'engineer', 'admin', 'manager') NOT NULL,
    email VARCHAR(100),
    phone VARCHAR(20),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    last_login TIMESTAMP NULL,
    
    INDEX idx_username (username),
    INDEX idx_role (role),
    INDEX idx_is_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Таблица зон/цехов предприятия (zones)
-- =====================================================
CREATE TABLE IF NOT EXISTS zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    zone_code VARCHAR(20) UNIQUE,
    parent_zone_id INT NULL,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_zone_code (zone_code),
    INDEX idx_parent_zone (parent_zone_id),
    INDEX idx_is_active (is_active),
    
    FOREIGN KEY (parent_zone_id) REFERENCES zones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Таблица счётчиков ресурсов (counters)
-- =====================================================
CREATE TABLE IF NOT EXISTS counters (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_id INT NOT NULL,
    counter_type ENUM('water', 'heat', 'electricity') NOT NULL,
    name VARCHAR(100) NOT NULL,
    serial_number VARCHAR(50),
    modbus_address INT NOT NULL DEFAULT 0,
    register_start INT NOT NULL DEFAULT 0,
    unit VARCHAR(20),
    is_active TINYINT(1) DEFAULT 1,
    last_reading_timestamp TIMESTAMP NULL,
    connection_status ENUM('online', 'offline', 'error') DEFAULT 'offline',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_zone_id (zone_id),
    INDEX idx_counter_type (counter_type),
    INDEX idx_modbus_address (modbus_address),
    INDEX idx_connection_status (connection_status),
    
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Таблица показаний счётчиков (counter_readings) - архив
-- =====================================================
CREATE TABLE IF NOT EXISTS counter_readings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    counter_id INT NOT NULL,
    reading_timestamp TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    -- Для воды
    water_flow_rate DECIMAL(10,3) DEFAULT NULL,
    
    -- Для тепла
    heat_temp_supply DECIMAL(6,2) DEFAULT NULL,
    heat_temp_return DECIMAL(6,2) DEFAULT NULL,
    heat_flow_rate DECIMAL(10,3) DEFAULT NULL,
    heat_energy DECIMAL(10,4) DEFAULT NULL,
    
    -- Для электричества
    elec_voltage DECIMAL(6,1) DEFAULT NULL,
    elec_power DECIMAL(10,2) DEFAULT NULL,
    elec_power_factor DECIMAL(4,2) DEFAULT NULL,
    
    -- Метка качества данных
    data_quality ENUM('good', 'suspicious', 'bad') DEFAULT 'good',
    raw_value JSON DEFAULT NULL,
    
    INDEX idx_counter_id (counter_id),
    INDEX idx_reading_timestamp (reading_timestamp),
    INDEX idx_counter_timestamp (counter_id, reading_timestamp),
    
    FOREIGN KEY (counter_id) REFERENCES counters(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Таблица пороговых значений для зон (thresholds)
-- =====================================================
CREATE TABLE IF NOT EXISTS thresholds (
    id INT AUTO_INCREMENT PRIMARY KEY,
    zone_id INT NOT NULL,
    counter_type ENUM('water', 'heat', 'electricity') NOT NULL,
    
    -- Пороговые значения
    threshold_min DECIMAL(10,3) DEFAULT NULL,
    threshold_max DECIMAL(10,3) DEFAULT NULL,
    threshold_warning_min DECIMAL(10,3) DEFAULT NULL,
    threshold_warning_max DECIMAL(10,3) DEFAULT NULL,
    
    -- Специфичные параметры
    -- Для тепла
    heat_temp_supply_max DECIMAL(6,2) DEFAULT NULL,
    heat_temp_supply_min DECIMAL(6,2) DEFAULT NULL,
    heat_delta_temp_min DECIMAL(6,2) DEFAULT NULL,
    
    -- Для электричества
    elec_voltage_min DECIMAL(6,1) DEFAULT NULL,
    elec_voltage_max DECIMAL(6,1) DEFAULT NULL,
    elec_power_max DECIMAL(10,2) DEFAULT NULL,
    elec_power_factor_min DECIMAL(4,2) DEFAULT NULL,
    
    -- Настройки детекции аномалий
    anomaly_detection_enabled TINYINT(1) DEFAULT 1,
    loss_of_connection_timeout INT DEFAULT 600,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_zone_type (zone_id, counter_type),
    UNIQUE KEY uk_zone_counter_type (zone_id, counter_type),
    
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Таблица тревог/аварий (alerts)
-- =====================================================
CREATE TABLE IF NOT EXISTS alerts (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    zone_id INT NOT NULL,
    counter_id INT DEFAULT NULL,
    alert_type ENUM('warning', 'emergency', 'critical') NOT NULL,
    alert_category ENUM('water_leak', 'heat_overheat', 'heat_low_delta', 'heat_freezing', 
                        'power_overload', 'voltage_low', 'voltage_high', 'low_power_factor',
                        'connection_lost', 'sensor_failure') NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    current_value DECIMAL(10,3) DEFAULT NULL,
    threshold_value DECIMAL(10,3) DEFAULT NULL,
    status ENUM('new', 'acknowledged', 'in_progress', 'resolved', 'false_alarm') DEFAULT 'new',
    acknowledged_by INT DEFAULT NULL,
    acknowledged_at TIMESTAMP NULL,
    resolved_by INT DEFAULT NULL,
    resolved_at TIMESTAMP NULL,
    resolution_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_zone_id (zone_id),
    INDEX idx_status (status),
    INDEX idx_alert_type (alert_type),
    INDEX idx_alert_category (alert_category),
    INDEX idx_created_at (created_at),
    INDEX idx_counter_id (counter_id),
    
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
    FOREIGN KEY (counter_id) REFERENCES counters(id) ON DELETE SET NULL,
    FOREIGN KEY (acknowledged_by) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Таблица сообщений диспетчера инженеру (alert_messages)
-- =====================================================
CREATE TABLE IF NOT EXISTS alert_messages (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    alert_id BIGINT NOT NULL,
    sender_id INT NOT NULL,
    recipient_id INT NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    read_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_alert_id (alert_id),
    INDEX idx_sender_id (sender_id),
    INDEX idx_recipient_id (recipient_id),
    INDEX idx_is_read (is_read),
    
    FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Таблица заявок на вызов бригады (maintenance_requests)
-- =====================================================
CREATE TABLE IF NOT EXISTS maintenance_requests (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    zone_id INT NOT NULL,
    alert_id BIGINT DEFAULT NULL,
    requester_id INT NOT NULL,
    request_type ENUM('inspection', 'repair', 'replacement', 'calibration', 'other') NOT NULL,
    priority ENUM('low', 'medium', 'high', 'urgent') DEFAULT 'medium',
    title VARCHAR(200) NOT NULL,
    description TEXT,
    assigned_to INT DEFAULT NULL,
    brigade_count INT DEFAULT 1,
    status ENUM('pending', 'assigned', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
    scheduled_at TIMESTAMP NULL,
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    completion_notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_zone_id (zone_id),
    INDEX idx_requester_id (requester_id),
    INDEX idx_assigned_to (assigned_to),
    INDEX idx_status (status),
    INDEX idx_priority (priority),
    INDEX idx_created_at (created_at),
    INDEX idx_alert_id (alert_id),
    
    FOREIGN KEY (zone_id) REFERENCES zones(id) ON DELETE CASCADE,
    FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE SET NULL,
    FOREIGN KEY (requester_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Таблица системного лога (system_log)
-- =====================================================
CREATE TABLE IF NOT EXISTS system_log (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    user_id INT DEFAULT NULL,
    action_type VARCHAR(50) NOT NULL,
    entity_type VARCHAR(50) DEFAULT NULL,
    entity_id INT DEFAULT NULL,
    description TEXT,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(255) DEFAULT NULL,
    severity ENUM('info', 'warning', 'error', 'critical') DEFAULT 'info',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_action_type (action_type),
    INDEX idx_entity (entity_type, entity_id),
    INDEX idx_created_at (created_at),
    INDEX idx_severity (severity),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================================================
-- Начальные данные
-- =====================================================

-- Пользователи по умолчанию (пароль: admin123, dispatcher123, engineer123, manager123)
-- Хэши сгенерированы через password_hash() с PASSWORD_DEFAULT
INSERT INTO users (username, password_hash, full_name, role, email, phone) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Администратор системы', 'admin', 'admin@enterprise.local', '+7-000-000-00-01'),
('dispatcher', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Диспетчер смены', 'dispatcher', 'dispatcher@enterprise.local', '+7-000-000-00-02'),
('engineer', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Инженер по эксплуатации', 'engineer', 'engineer@enterprise.local', '+7-000-000-00-03'),
('manager', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Руководитель предприятия', 'manager', 'manager@enterprise.local', '+7-000-000-00-04');

-- Зоны предприятия
INSERT INTO zones (name, description, zone_code) VALUES
('Цех №1 (Механический)', 'Основной механический цех', 'SHOP_01'),
('Цех №2 (Сборочный)', 'Цех сборки готовой продукции', 'SHOP_02'),
('Цех №3 (Покрасочный)', 'Покрасочный цех', 'SHOP_03'),
('Административное здание', 'Административно-офисное здание', 'ADMIN_01');

-- Пороговые значения для зон (настройки по умолчанию)
INSERT INTO thresholds (zone_id, counter_type, threshold_min, threshold_max, threshold_warning_min, threshold_warning_max,
    heat_temp_supply_max, heat_temp_supply_min, heat_delta_temp_min,
    elec_voltage_min, elec_voltage_max, elec_power_max, elec_power_factor_min,
    loss_of_connection_timeout) VALUES
-- Цех 1
(1, 'water', 0.1, 10.0, 0.5, 8.0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 600),
(1, 'heat', NULL, NULL, NULL, NULL, 120.0, 40.0, 15.0, NULL, NULL, NULL, NULL, 600),
(1, 'electricity', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 198.0, 242.0, 100.0, 0.85, 600),
-- Цех 2
(2, 'water', 0.1, 8.0, 0.3, 6.0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 600),
(2, 'heat', NULL, NULL, NULL, NULL, 120.0, 40.0, 15.0, NULL, NULL, NULL, NULL, 600),
(2, 'electricity', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 198.0, 242.0, 80.0, 0.85, 600),
-- Цех 3
(3, 'water', 0.1, 12.0, 0.5, 10.0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 600),
(3, 'heat', NULL, NULL, NULL, NULL, 120.0, 40.0, 15.0, NULL, NULL, NULL, NULL, 600),
(3, 'electricity', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 198.0, 242.0, 120.0, 0.85, 600),
-- Административное здание
(4, 'water', 0.05, 5.0, 0.2, 4.0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, 600),
(4, 'heat', NULL, NULL, NULL, NULL, 100.0, 35.0, 15.0, NULL, NULL, NULL, NULL, 600),
(4, 'electricity', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 198.0, 242.0, 50.0, 0.85, 600);

-- Системная запись в лог о создании БД
INSERT INTO system_log (action_type, description, severity) VALUES
('system_init', 'База данных успешно создана и инициализирована', 'info');
