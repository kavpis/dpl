<?php
/**
 * Боковое меню (sidebar)
 * Динамическая навигация в зависимости от роли пользователя
 */

if (!isset($currentUser)) {
    $currentUser = null;
}

$currentPage = basename($_SERVER['PHP_SELF'], '.php');
?>

<nav class="sidebar">
    <ul class="nav-menu">
        <?php if ($currentUser): ?>
            
            <!-- Общие пункты для всех -->
            <li class="nav-header">Основное</li>
            <li>
                <a href="/dpl/index.php" class="<?php echo $currentPage === 'index' ? 'active' : ''; ?>">
                    📊 Главная
                </a>
            </li>
            
            <?php if ($currentUser['role'] === ROLE_DISPATCHER || $currentUser['role'] === ROLE_ADMIN): ?>
                <li class="nav-header">Диспетчер</li>
                <li>
                    <a href="/dpl/dispatcher.php" class="<?php echo $currentPage === 'dispatcher' ? 'active' : ''; ?>">
                        🔔 Мониторинг
                    </a>
                </li>
                <li>
                    <a href="/dpl/alerts.php" class="<?php echo $currentPage === 'alerts' ? 'active' : ''; ?>">
                        ⚠️ Тревоги
                    </a>
                </li>
            <?php endif; ?>
            
            <?php if ($currentUser['role'] === ROLE_ENGINEER || $currentUser['role'] === ROLE_ADMIN): ?>
                <li class="nav-header">Инженер</li>
                <li>
                    <a href="/dpl/engineer.php" class="<?php echo $currentPage === 'engineer' ? 'active' : ''; ?>">
                        🔧 Заявки
                    </a>
                </li>
                <li>
                    <a href="/dpl/messages.php" class="<?php echo $currentPage === 'messages' ? 'active' : ''; ?>">
                        ✉️ Сообщения
                    </a>
                </li>
            <?php endif; ?>
            
            <?php if ($currentUser['role'] === ROLE_MANAGER || $currentUser['role'] === ROLE_ADMIN): ?>
                <li class="nav-header">Руководство</li>
                <li>
                    <a href="/dpl/reports.php" class="<?php echo $currentPage === 'reports' ? 'active' : ''; ?>">
                        📈 Отчёты
                    </a>
                </li>
                <li>
                    <a href="/dpl/analytics.php" class="<?php echo $currentPage === 'analytics' ? 'active' : ''; ?>">
                        📉 Аналитика
                    </a>
                </li>
            <?php endif; ?>
            
            <?php if ($currentUser['role'] === ROLE_ADMIN): ?>
                <li class="nav-header">Администрирование</li>
                <li>
                    <a href="/dpl/admin.php" class="<?php echo $currentPage === 'admin' ? 'active' : ''; ?>">
                        ⚙️ Настройки
                    </a>
                </li>
                <li>
                    <a href="/dpl/users.php" class="<?php echo $currentPage === 'users' ? 'active' : ''; ?>">
                        👥 Пользователи
                    </a>
                </li>
                <li>
                    <a href="/dpl/counters.php" class="<?php echo $currentPage === 'counters' ? 'active' : ''; ?>">
                        📟 Оборудование
                    </a>
                </li>
                <li>
                    <a href="/dpl/backup.php" class="<?php echo $currentPage === 'backup' ? 'active' : ''; ?>">
                        💾 Резервные копии
                    </a>
                </li>
            <?php endif; ?>
            
        <?php else: ?>
            <li>
                <a href="/dpl/login.php">Вход в систему</a>
            </li>
        <?php endif; ?>
    </ul>
</nav>
