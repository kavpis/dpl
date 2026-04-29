<?php
/**
 * API: Резервное копирование базы данных
 * Доступно только администраторам
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json');

$auth = new Auth();

// Проверка авторизации
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Требуется авторизация']);
    exit;
}

// Только администраторы могут создавать резервные копии
$currentUser = $auth->getCurrentUser();
if ($currentUser['role'] !== ROLE_ADMIN) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
    exit;
}

try {
    $db = Database::getInstance();
    
    $action = $_GET['action'] ?? 'backup';
    
    switch ($action) {
        case 'backup':
            createBackup($db, $currentUser);
            break;
            
        case 'list':
            listBackups($db);
            break;
            
        case 'download':
            downloadBackup();
            break;
            
        default:
            throw new Exception('Неизвестное действие');
    }
    
} catch (Exception $e) {
    error_log("Ошибка в backup_db.php: " . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * Создание резервной копии БД
 */
function createBackup($db, $currentUser) {
    $backupDir = BASE_PATH . '/backups';
    
    // Создание директории если не существует
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }
    
    // Имя файла с датой и временем
    $filename = 'backup_' . date('Y-m-d_H-i-s') . '.sql';
    $filepath = $backupDir . '/' . $filename;
    
    // Параметры подключения
    $host = DB_HOST;
    $dbname = DB_NAME;
    $user = DB_USER;
    $pass = DB_PASS;
    
    // Формирование команды mysqldump
    $command = "mysqldump --host={$host} --user={$user}";
    
    if (!empty($pass)) {
        $command .= " --password={$pass}";
    }
    
    $command .= " --single-transaction --quick --lock-tables=false {$dbname} > {$filepath}";
    
    // Выполнение команды
    exec($command, $output, $returnCode);
    
    if ($returnCode !== 0) {
        throw new Exception('Ошибка создания резервной копии. Код возврата: ' . $returnCode);
    }
    
    // Проверка существования файла
    if (!file_exists($filepath)) {
        throw new Exception('Файл резервной копии не был создан');
    }
    
    $filesize = filesize($filepath);
    
    // Логирование
    $db->insert('system_log', [
        'user_id' => $currentUser['id'],
        'action' => 'backup_create',
        'details' => "Создана резервная копия БД: {$filename} (" . round($filesize / 1024, 2) . " КБ)",
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'created_at' => date('Y-m-d H:i:s')
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Резервная копия создана успешно',
        'filename' => $filename,
        'size' => $filesize,
        'size_formatted' => formatFileSize($filesize)
    ]);
}

/**
 * Список резервных копий
 */
function listBackups($db) {
    $backupDir = BASE_PATH . '/backups';
    
    if (!is_dir($backupDir)) {
        echo json_encode(['success' => true, 'data' => []]);
        return;
    }
    
    $files = glob($backupDir . '/backup_*.sql');
    
    if ($files === false) {
        $files = [];
    }
    
    // Сортировка по времени изменения (новые первые)
    usort($files, function($a, $b) {
        return filemtime($b) - filemtime($a);
    });
    
    $result = [];
    foreach ($files as $file) {
        $stat = stat($file);
        $result[] = [
            'filename' => basename($file),
            'size' => $stat['size'],
            'size_formatted' => formatFileSize($stat['size']),
            'created_at' => date('Y-m-d H:i:s', $stat['mtime']),
            'download_url' => '/dpl/api/backup_db.php?action=download&file=' . urlencode(basename($file))
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $result]);
}

/**
 * Скачивание резервной копии
 */
function downloadBackup() {
    $filename = $_GET['file'] ?? '';
    
    // Валидация имени файла (только безопасные символы)
    if (!preg_match('/^backup_[\d\-]+_[\d\-]+\.sql$/', $filename)) {
        throw new Exception('Неверное имя файла');
    }
    
    $backupDir = BASE_PATH . '/backups';
    $filepath = $backupDir . '/' . $filename;
    
    if (!file_exists($filepath)) {
        throw new Exception('Файл не найден');
    }
    
    // Отправка файла
    header('Content-Type: application/sql');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . filesize($filepath));
    header('Cache-Control: no-cache, must-revalidate');
    
    readfile($filepath);
    exit;
}

/**
 * Форматирование размера файла
 */
function formatFileSize($bytes) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    
    return round($bytes, 2) . ' ' . $units[$i];
}
