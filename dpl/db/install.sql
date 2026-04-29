-- SQL скрипт для развёртывания системы сбора и анализа показаний счётчиков
-- База данных: dpl_system
-- Версия: 1.0

-- Удаление базы данных если существует (для чистой установки)
DROP DATABASE IF EXISTS `dpl_system`;

-- Создание базы данных
CREATE DATABASE IF NOT EXISTS `dpl_system`
DEFAULT CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE `dpl_system`;

-- --------------------------------------------------------
-- Таблица пользователей (users)
-- --------------------------------------------------------
CREATE TABLE `users` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL UNIQUE,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `role` ENUM('admin', 'dispatcher', 'engineer', 'manager') NOT NULL,
  `email` VARCHAR(100) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `last_login` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  INDEX `idx_role` (`role`),
  INDEX `idx_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Таблица зон/цехов (zones)
-- --------------------------------------------------------
CREATE TABLE `zones` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `description` TEXT,
  `parent_zone_id` INT(11) UNSIGNED DEFAULT NULL,
  `coordinates` VARCHAR(50) DEFAULT NULL, -- Для схемы (x,y)
  `is_active` TINYINT(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`parent_zone_id`) REFERENCES `zones`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Таблица счётчиков (counters)
-- --------------------------------------------------------
CREATE TABLE `counters` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `serial_number` VARCHAR(50) NOT NULL UNIQUE,
  `zone_id` INT(11) UNSIGNED NOT NULL,
  `resource_type` ENUM('water', 'heat', 'electricity') NOT NULL,
  `modbus_address` INT(11) DEFAULT NULL,
  `modbus_register` INT(11) DEFAULT NULL,
  `unit` VARCHAR(20) DEFAULT NULL, -- м3, Гкал, кВт*ч
  `is_active` TINYINT(1) DEFAULT 1,
  `last_reading_time` TIMESTAMP NULL DEFAULT NULL,
  `status` ENUM('ok', 'warning', 'error', 'offline') DEFAULT 'ok',
  PRIMARY KEY (`id`),
  FOREIGN KEY (`zone_id`) REFERENCES `zones`(`id`) ON DELETE CASCADE,
  INDEX `idx_resource` (`resource_type`),
  INDEX `idx_zone` (`zone_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Таблица показаний счётчиков (counter_readings)
-- Хранение данных за 3 года+
-- --------------------------------------------------------
CREATE TABLE `counter_readings` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `counter_id` INT(11) UNSIGNED NOT NULL,
  `value` DECIMAL(15, 4) NOT NULL,
  `reading_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `quality` TINYINT(1) DEFAULT 1, -- 1 - хорошее, 0 - плохое
  PRIMARY KEY (`id`),
  FOREIGN KEY (`counter_id`) REFERENCES `counters`(`id`) ON DELETE CASCADE,
  INDEX `idx_time` (`reading_time`),
  INDEX `idx_counter_time` (`counter_id`, `reading_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Таблица пороговых значений (thresholds)
-- --------------------------------------------------------
CREATE TABLE `thresholds` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `zone_id` INT(11) UNSIGNED DEFAULT NULL, -- NULL значит глобальные настройки
  `resource_type` ENUM('water', 'heat', 'electricity') NOT NULL,
  `min_value` DECIMAL(15, 4) DEFAULT NULL,
  `max_value` DECIMAL(15, 4) DEFAULT NULL,
  `delta_percent` DECIMAL(5, 2) DEFAULT 20.00, -- Допустимое изменение за период (%)
  `loss_timeout_seconds` INT(11) DEFAULT 600, -- Таймаут потери связи (10 мин)
  `alert_level` ENUM('warning', 'alarm', 'critical') DEFAULT 'warning',
  `is_active` TINYINT(1) DEFAULT 1,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`zone_id`) REFERENCES `zones`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `unique_threshold` (`zone_id`, `resource_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Таблица тревог/аварий (alerts)
-- --------------------------------------------------------
CREATE TABLE `alerts` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `counter_id` INT(11) UNSIGNED DEFAULT NULL,
  `zone_id` INT(11) UNSIGNED DEFAULT NULL,
  `alert_type` ENUM('spike', 'drop', 'overload', 'temp_deviation', 'connection_loss') NOT NULL,
  `level` ENUM('warning', 'alarm', 'critical') NOT NULL,
  `message` TEXT NOT NULL,
  `current_value` DECIMAL(15, 4) DEFAULT NULL,
  `threshold_value` DECIMAL(15, 4) DEFAULT NULL,
  `status` ENUM('active', 'acknowledged', 'resolved') DEFAULT 'active',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `acknowledged_at` TIMESTAMP NULL DEFAULT NULL,
  `acknowledged_by` INT(11) UNSIGNED DEFAULT NULL,
  `resolved_at` TIMESTAMP NULL DEFAULT NULL,
  `resolved_by` INT(11) UNSIGNED DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`counter_id`) REFERENCES `counters`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`zone_id`) REFERENCES `zones`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`acknowledged_by`) REFERENCES `users`(`id`),
  FOREIGN KEY (`resolved_by`) REFERENCES `users`(`id`),
  INDEX `idx_status` (`status`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Таблица сообщений диспетчера инженеру (alert_messages)
-- --------------------------------------------------------
CREATE TABLE `alert_messages` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_id` INT(11) UNSIGNED NOT NULL,
  `sender_id` INT(11) UNSIGNED NOT NULL,
  `receiver_id` INT(11) UNSIGNED NOT NULL,
  `message` TEXT NOT NULL,
  `is_read` TINYINT(1) DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`alert_id`) REFERENCES `alerts`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`sender_id`) REFERENCES `users`(`id`),
  FOREIGN KEY (`receiver_id`) REFERENCES `users`(`id`),
  INDEX `idx_receiver_read` (`receiver_id`, `is_read`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Таблица заявок на вызов бригады (maintenance_requests)
-- --------------------------------------------------------
CREATE TABLE `maintenance_requests` (
  `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
  `alert_id` INT(11) UNSIGNED DEFAULT NULL,
  `zone_id` INT(11) UNSIGNED NOT NULL,
  `requester_id` INT(11) UNSIGNED NOT NULL,
  `description` TEXT NOT NULL,
  `status` ENUM('pending', 'in_progress', 'completed', 'cancelled') DEFAULT 'pending',
  `brigade_comment` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `completed_at` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`alert_id`) REFERENCES `alerts`(`id`) ON DELETE SET NULL,
  FOREIGN KEY (`zone_id`) REFERENCES `zones`(`id`) ON DELETE CASCADE,
  FOREIGN KEY (`requester_id`) REFERENCES `users`(`id`),
  INDEX `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --------------------------------------------------------
-- Таблица системного лога (system_log)
-- --------------------------------------------------------
CREATE TABLE `system_log` (
  `id` BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) UNSIGNED DEFAULT NULL,
  `action` VARCHAR(100) NOT NULL,
  `details` TEXT,
  `ip_address` VARCHAR(45) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
  INDEX `idx_action` (`action`),
  INDEX `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========================================================
-- НАЧАЛЬНЫЕ ДАННЫЕ (SEED DATA)
-- ========================================================

-- 1. Пользователи (Пароли по умолчанию: 'password123')
-- Хэш получен через password_hash('password123', PASSWORD_DEFAULT)
INSERT INTO `users` (`username`, `password_hash`, `full_name`, `role`, `email`) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Администратор Системы', 'admin', 'admin@plant.local'),
('dispatcher1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Иванов Иван (Диспетчер)', 'dispatcher', 'disp@plant.local'),
('engineer1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Петров Петр (Инженер)', 'engineer', 'eng@plant.local'),
('manager1', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Сидоров Сидор (Руководитель)', 'manager', 'boss@plant.local');

-- 2. Зоны (Цеха)
INSERT INTO `zones` (`name`, `description`, `coordinates`) VALUES
('Главный корпус', 'Основное производственное здание', '50,50'),
('Цех №1', 'Литейный цех', '20,30'),
('Цех №2', 'Сборочный цех', '80,30'),
('ТЭЦ узел', 'Теплоэнергетический центр', '50,80'),
('Насосная станция', 'Водоснабжение объекта', '20,80');

-- 3. Счётчики
-- Привязка к зонам: Цех №1 (2), Цех №2 (3), ТЭЦ (4), Насосная (5)
INSERT INTO `counters` (`name`, `serial_number`, `zone_id`, `resource_type`, `modbus_address`, `modbus_register`, `unit`, `status`) VALUES
('Счетчик воды Цех1', 'WTR-001-2023', 2, 'water', 1, 40001, 'м³', 'ok'),
('Счетчик эл-ва Цех1', 'ELC-001-2023', 2, 'electricity', 1, 40010, 'кВт*ч', 'ok'),
('Счетчик тепла Цех1', 'HT-001-2023', 2, 'heat', 1, 40020, 'Гкал', 'ok'),
('Счетчик воды Цех2', 'WTR-002-2023', 3, 'water', 2, 40001, 'м³', 'ok'),
('Счетчик эл-ва Цех2', 'ELC-002-2023', 3, 'electricity', 2, 40010, 'кВт*ч', 'ok'),
('Счетчик тепла ТЭЦ', 'HT-003-2023', 4, 'heat', 3, 40020, 'Гкал', 'ok'),
('Счетчик воды Насосная', 'WTR-004-2023', 5, 'water', 4, 40001, 'м³', 'ok'),
('Счетчик эл-ва Насосная', 'ELC-004-2023', 5, 'electricity', 4, 40010, 'кВт*ч', 'ok');

-- 4. Пороговые значения (Дефолтные настройки)
-- Глобальные настройки (zone_id IS NULL)
INSERT INTO `thresholds` (`resource_type`, `min_value`, `max_value`, `delta_percent`, `loss_timeout_seconds`, `alert_level`) VALUES
('water', 0, 1000, 25.00, 600, 'warning'),
('electricity', 0, 5000, 15.00, 600, 'alarm'),
('heat', 10, 200, 10.00, 600, 'warning');

-- Специфичные настройки для ТЭЦ (zone_id = 4)
INSERT INTO `thresholds` (`zone_id`, `resource_type`, `min_value`, `max_value`, `delta_percent`, `loss_timeout_seconds`, `alert_level`) VALUES
(4, 'heat', 50, 500, 5.00, 300, 'critical');

-- 5. Начальный систем лог
INSERT INTO `system_log` (`user_id`, `action`, `details`) VALUES
(1, 'SYSTEM_INIT', 'База данных развернута и заполнена начальными данными.');
