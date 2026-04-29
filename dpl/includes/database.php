<?php
/**
 * Класс для работы с базой данных
 * Реализует паттерн Singleton и обеспечивает безопасное подключение к MySQL
 */

class Database {
    private static $instance = null;
    private $connection;
    
    /**
     * Приватный конструктор для предотвращения прямого создания экземпляра
     */
    private function __construct() {
        $this->connect();
    }
    
    /**
     * Получение единственного экземпляра класса
     * @return Database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Подключение к базе данных
     */
    private function connect() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . DB_CHARSET
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Ошибка подключения к БД: " . $e->getMessage());
            throw new Exception("Не удалось подключиться к базе данных");
        }
    }
    
    /**
     * Выполнение запроса с параметрами
     * @param string $sql SQL-запрос
     * @param array $params Параметры запроса
     * @return PDOStatement
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Ошибка выполнения запроса: " . $e->getMessage());
            throw new Exception("Ошибка выполнения запроса к базе данных");
        }
    }
    
    /**
     * Получение одной строки результата
     * @param string $sql SQL-запрос
     * @param array $params Параметры запроса
     * @return array|null
     */
    public function fetchOne($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    /**
     * Получение всех строк результата
     * @param string $sql SQL-запрос
     * @param array $params Параметры запроса
     * @return array
     */
    public function fetchAll($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->fetchAll();
    }
    
    /**
     * Вставка записи и получение последнего ID
     * @param string $table Имя таблицы
     * @param array $data Данные для вставки
     * @return int Последний вставленный ID
     */
    public function insert($table, $data) {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));
        
        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";
        
        $this->query($sql, $data);
        return (int)$this->connection->lastInsertId();
    }
    
    /**
     * Обновление записи
     * @param string $table Имя таблицы
     * @param array $data Данные для обновления
     * @param string $where Условие WHERE
     * @param array $whereParams Параметры условия WHERE
     * @return int Количество затронутых строк
     */
    public function update($table, $data, $where, $whereParams = []) {
        $setParts = [];
        foreach (array_keys($data) as $column) {
            $setParts[] = "{$column} = :{$column}";
        }
        $setClause = implode(', ', $setParts);
        
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$where}";
        
        $params = array_merge($data, $whereParams);
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Удаление записей
     * @param string $table Имя таблицы
     * @param string $where Условие WHERE
     * @param array $params Параметры условия WHERE
     * @return int Количество удаленных строк
     */
    public function delete($table, $where, $params = []) {
        $sql = "DELETE FROM {$table} WHERE {$where}";
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    /**
     * Начало транзакции
     */
    public function beginTransaction() {
        $this->connection->beginTransaction();
    }
    
    /**
     * Фиксация транзакции
     */
    public function commit() {
        $this->connection->commit();
    }
    
    /**
     * Откат транзакции
     */
    public function rollback() {
        $this->connection->rollBack();
    }
    
    /**
     * Проверка существования таблицы
     * @param string $table Имя таблицы
     * @return bool
     */
    public function tableExists($table) {
        $sql = "SHOW TABLES LIKE ?";
        $stmt = $this->connection->prepare($sql);
        $stmt->execute([$table]);
        return $stmt->rowCount() > 0;
    }
    
    /**
     * Запрет клонирования экземпляра
     */
    private function __clone() {}
    
    /**
     * Запрет десериализации экземпляра
     */
    public function __wakeup() {
        throw new Exception("Нельзя десериализовать синглтон");
    }
}
