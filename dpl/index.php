<?php
/**
 * Index.php - Точка входа в систему
 * Перенаправляет пользователя на страницу входа или в личный кабинет
 */

session_start();

// Подключаем конфигурацию и авторизацию
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Если пользователь уже авторизован, перенаправляем на соответствующую страницу
if (Auth::isLoggedIn()) {
    $role = Auth::getUserRole();
    
    switch ($role) {
        case ROLE_ADMIN:
            header('Location: admin.php');
            break;
        case ROLE_DISPATCHER:
            header('Location: dispatcher.php');
            break;
        case ROLE_ENGINEER:
            header('Location: engineer.php');
            break;
        case ROLE_MANAGER:
            header('Location: reports.php');
            break;
        default:
            // Если роль неизвестна, выходим из системы
            Auth::logout();
            header('Location: login.php');
            break;
    }
    exit;
}

// Если не авторизован, перенаправляем на страницу входа
header('Location: login.php');
exit;
