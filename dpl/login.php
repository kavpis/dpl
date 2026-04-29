<?php
/**
 * Страница входа в систему
 * Отображает форму авторизации и обрабатывает вход пользователя
 */

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

// Инициализация модуля аутентификации
$auth = new Auth();

// Если пользователь уже авторизован, перенаправляем на главную страницу
if ($auth->isLoggedIn()) {
    header('Location: /dpl/index.php');
    exit;
}

$error = '';
$loginValue = '';

// Обработка POST-запроса (попытка входа)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['login'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($login) || empty($password)) {
        $error = 'Введите логин и пароль';
        $loginValue = htmlspecialchars($login);
    } else {
        $result = $auth->login($login, $password);
        
        if ($result['success']) {
            // Успешный вход - перенаправление на главную страницу
            header('Location: /dpl/index.php');
            exit;
        } else {
            $error = $result['message'];
            $loginValue = htmlspecialchars($login);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в систему - <?php echo SYSTEM_NAME; ?></title>
    <link rel="stylesheet" href="/dpl/assets/css/style.css">
    <link rel="stylesheet" href="/dpl/assets/css/login.css">
</head>
<body class="login-page">
    <div class="login-container">
        <div class="login-box">
            <div class="login-header">
                <h1><?php echo SYSTEM_NAME; ?></h1>
                <p class="subtitle">Авторизация</p>
            </div>
            
            <?php if (!empty($error)): ?>
                <div class="alert alert-error">
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" class="login-form">
                <div class="form-group">
                    <label for="login">Логин</label>
                    <input 
                        type="text" 
                        id="login" 
                        name="login" 
                        value="<?php echo $loginValue; ?>" 
                        required 
                        autofocus
                        autocomplete="username"
                        placeholder="Введите ваш логин"
                    >
                </div>
                
                <div class="form-group">
                    <label for="password">Пароль</label>
                    <input 
                        type="password" 
                        id="password" 
                        name="password" 
                        required 
                        autocomplete="current-password"
                        placeholder="Введите ваш пароль"
                    >
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn btn-primary btn-block">Войти</button>
                </div>
            </form>
            
            <div class="login-footer">
                <p class="help-text">
                    Для получения доступа к системе обратитесь к администратору
                </p>
            </div>
        </div>
        
        <div class="login-info">
            <h2>Система мониторинга ресурсов</h2>
            <ul>
                <li>Мониторинг потребления воды, тепла и электричества</li>
                <li>Оперативное выявление аварий и аномалий</li>
                <li>Автоматическое оповещение персонала</li>
                <li>Формирование отчетов и аналитика</li>
            </ul>
        </div>
    </div>
</body>
</html>
