<?php
/**
 * Конфигурационный файл системы
 * Настройки подключения к MySQL и общие параметры
 */

// Параметры подключения к базе данных
define('DB_HOST', 'localhost');
define('DB_NAME', 'dpl_system');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');

// Настройки сессии
define('SESSION_LIFETIME', 3600); // Время жизни сессии в секундах (1 час)

// Настройки безопасности
define('PASSWORD_MIN_LENGTH', 8);
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // Время блокировки при превышении попыток входа (15 минут)

// Роли пользователей
define('ROLE_DISPATCHER', 'dispatcher');
define('ROLE_ENGINEER', 'engineer');
define('ROLE_ADMIN', 'admin');
define('ROLE_MANAGER', 'manager');

// Уровни тревог
define('ALERT_WARNING', 'warning');
define('ALERT_EMERGENCY', 'emergency');
define('ALERT_CRITICAL', 'critical');

// Настройки системы
define('SYSTEM_NAME', 'Система сбора и анализа показаний счётчиков');
define('DATA_RETENTION_YEARS', 3);
define('CONNECTION_LOSS_TIMEOUT', 600); // Потеря связи через 10 минут (в секундах)

// Пути к директориям
define('BASE_PATH', dirname(__DIR__));
define('TEMPLATES_PATH', BASE_PATH . '/templates');
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('API_PATH', BASE_PATH . '/api');

// Ошибки (в продакшене отключить)
error_reporting(E_ALL);
ini_set('display_errors', 1); // Включено для отладки
ini_set('log_errors', 1);
ini_set('error_log', BASE_PATH . '/logs/error.log');

// Таймзона
date_default_timezone_set('Europe/Moscow');
