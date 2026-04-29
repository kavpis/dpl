<?php
/**
 * Страница выхода из системы
 * Обрабатывает выход пользователя и перенаправляет на страницу входа
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Инициализация модуля аутентификации
$auth = new Auth();

// Выполнение выхода
$result = $auth->logout();

// Перенаправление на страницу входа
header('Location: /dpl/login.php');
exit;
