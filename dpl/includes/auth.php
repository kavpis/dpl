<?php
/**
 * Модуль аутентификации и авторизации пользователей
 * Управление сессиями, логин/логаут, проверка прав доступа
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

class Auth {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->initSession();
    }
    
    /**
     * Инициализация сессии
     */
    private function initSession() {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => SESSION_LIFETIME,
                'path' => '/',
                'secure' => false,
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
            session_start();
        }
    }
    
    /**
     * Аутентификация пользователя по логину и паролю
     * @param string $login Логин пользователя
     * @param string $password Пароль
     * @return array Результат аутентификации
     */
    public function login($login, $password) {
        // Проверка на блокировку из-за превышения попыток входа
        if ($this->isLockedOut($login)) {
            return [
                'success' => false,
                'message' => 'Слишком много неудачных попыток входа. Попробуйте позже.'
            ];
        }
        
        // Поиск пользователя в базе данных
        $user = $this->db->fetchOne(
            "SELECT id, login, password_hash, role, full_name, is_active 
             FROM users 
             WHERE login = :login",
            ['login' => $login]
        );
        
        if (!$user) {
            $this->recordFailedAttempt($login);
            return [
                'success' => false,
                'message' => 'Неверный логин или пароль'
            ];
        }
        
        // Проверка активности пользователя
        if (!$user['is_active']) {
            return [
                'success' => false,
                'message' => 'Учетная запись заблокирована администратором'
            ];
        }
        
        // Проверка пароля
        if (!password_verify($password, $user['password_hash'])) {
            $this->recordFailedAttempt($login);
            return [
                'success' => false,
                'message' => 'Неверный логин или пароль'
            ];
        }
        
        // Успешная аутентификация
        $this->setUserSession($user);
        $this->logAction($user['id'], 'login', 'Вход в систему');
        
        return [
            'success' => true,
            'message' => 'Вход выполнен успешно',
            'user' => [
                'id' => $user['id'],
                'login' => $user['login'],
                'role' => $user['role'],
                'full_name' => $user['full_name']
            ]
        ];
    }
    
    /**
     * Выход из системы
     */
    public function logout() {
        if ($this->isLoggedIn()) {
            $userId = $_SESSION['user_id'];
            $this->logAction($userId, 'logout', 'Выход из системы');
        }
        
        session_unset();
        session_destroy();
        
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }
        
        return ['success' => true, 'message' => 'Выход выполнен успешно'];
    }
    
    /**
     * Проверка авторизации пользователя
     * @return bool
     */
    public function isLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
    
    /**
     * Получение данных текущего пользователя
     * @return array|null
     */
    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        return [
            'id' => $_SESSION['user_id'],
            'login' => $_SESSION['user_login'],
            'role' => $_SESSION['user_role'],
            'full_name' => $_SESSION['user_full_name']
        ];
    }
    
    /**
     * Проверка наличия у пользователя определенной роли
     * @param string $role Роль для проверки
     * @return bool
     */
    public function hasRole($role) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        return $_SESSION['user_role'] === $role;
    }
    
    /**
     * Проверка наличия у пользователя одной из указанных ролей
     * @param array $roles Массив ролей
     * @return bool
     */
    public function hasAnyRole($roles) {
        if (!$this->isLoggedIn()) {
            return false;
        }
        return in_array($_SESSION['user_role'], $roles);
    }
    
    /**
     * Принудительная авторизация (требуется для всех страниц)
     * @param array $allowedRoles Разрешенные роли (опционально)
     * @return bool
     */
    public function requireAuth($allowedRoles = null) {
        if (!$this->isLoggedIn()) {
            header('Location: /dpl/login.php');
            exit;
        }
        
        if ($allowedRoles !== null && !in_array($_SESSION['user_role'], $allowedRoles)) {
            http_response_code(403);
            die('Доступ запрещен: недостаточно прав');
        }
        
        return true;
    }
    
    /**
     * Установка данных пользователя в сессию
     * @param array $user Данные пользователя
     */
    private function setUserSession($user) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_login'] = $user['login'];
        $_SESSION['user_role'] = $user['role'];
        $_SESSION['user_full_name'] = $user['full_name'];
        $_SESSION['login_time'] = time();
        
        // Регенерация ID сессии для безопасности
        session_regenerate_id(true);
    }
    
    /**
     * Проверка на блокировку из-за множественных неудачных попыток
     * @param string $login Логин
     * @return bool
     */
    private function isLockedOut($login) {
        $lockoutKey = 'login_attempts_' . md5($login);
        
        if (!isset($_SESSION[$lockoutKey])) {
            return false;
        }
        
        $attempts = $_SESSION[$lockoutKey];
        
        if ($attempts['count'] >= MAX_LOGIN_ATTEMPTS) {
            $timeSinceLastAttempt = time() - $attempts['last_attempt'];
            if ($timeSinceLastAttempt < LOCKOUT_TIME) {
                return true;
            }
            // Сброс счетчика после истечения времени блокировки
            unset($_SESSION[$lockoutKey]);
        }
        
        return false;
    }
    
    /**
     * Запись неудачной попытки входа
     * @param string $login Логин
     */
    private function recordFailedAttempt($login) {
        $lockoutKey = 'login_attempts_' . md5($login);
        
        if (!isset($_SESSION[$lockoutKey])) {
            $_SESSION[$lockoutKey] = ['count' => 0, 'last_attempt' => 0];
        }
        
        $_SESSION[$lockoutKey]['count']++;
        $_SESSION[$lockoutKey]['last_attempt'] = time();
    }
    
    /**
     * Логирование действий пользователя
     * @param int $userId ID пользователя
     * @param string $action Тип действия
     * @param string $description Описание действия
     */
    private function logAction($userId, $action, $description) {
        try {
            $this->db->insert('system_log', [
                'user_id' => $userId,
                'action' => $action,
                'description' => $description,
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (Exception $e) {
            error_log("Ошибка логирования действия: " . $e->getMessage());
        }
    }
    
    /**
     * Хэширование пароля
     * @param string $password Пароль
     * @return string Хэш пароля
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * Проверка сложности пароля
     * @param string $password Пароль
     * @return array Результат проверки
     */
    public static function validatePassword($password) {
        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            return [
                'valid' => false,
                'message' => 'Пароль должен содержать не менее ' . PASSWORD_MIN_LENGTH . ' символов'
            ];
        }
        
        if (!preg_match('/[A-Z]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Пароль должен содержать хотя бы одну заглавную букву'
            ];
        }
        
        if (!preg_match('/[a-z]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Пароль должен содержать хотя бы одну строчную букву'
            ];
        }
        
        if (!preg_match('/[0-9]/', $password)) {
            return [
                'valid' => false,
                'message' => 'Пароль должен содержать хотя бы одну цифру'
            ];
        }
        
        return ['valid' => true, 'message' => 'Пароль соответствует требованиям'];
    }
}
