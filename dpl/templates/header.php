<?php
/**
 * Шапка сайта (header)
 * Отображает логотип, навигацию и информацию о пользователе
 */

if (!isset($auth)) {
    require_once __DIR__ . '/../includes/auth.php';
    $auth = new Auth();
}

$currentUser = $auth->getCurrentUser();
?>
<header class="header">
    <div class="header-left">
        <h1><?php echo SYSTEM_NAME; ?></h1>
    </div>
    
    <div class="user-info">
        <?php if ($currentUser): ?>
            <span>
                <strong><?php echo htmlspecialchars($currentUser['full_name']); ?></strong>
                <small>(<?php echo htmlspecialchars(ucfirst($currentUser['role'])); ?>)</small>
            </span>
            <a href="/dpl/logout.php" class="btn btn-danger btn-sm">Выход</a>
        <?php else: ?>
            <a href="/dpl/login.php" class="btn btn-primary">Вход</a>
        <?php endif; ?>
    </div>
</header>

<!-- Скрытый элемент с данными пользователя для JS -->
<?php if ($currentUser): ?>
<script id="current-user-data" type="application/json">
<?php echo json_encode($currentUser); ?>
</script>
<?php endif; ?>
