<?php
/**
 * Database Connection
 */
require_once __DIR__ . '/../config.php';

class Database {
    private static $instance = null;
    private $connection;
    
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false, // Disable persistent connections for better connection management
                PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true, // Use buffered queries for better performance
            ];
            
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->connection;
    }
    
    public function query($sql, $params = []) {
        try {
            $stmt = $this->connection->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Database query error: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Execute multiple queries in a batch (for bulk inserts)
     */
    public function batchInsert($table, $columns, $rows) {
        if (empty($rows)) {
            return 0;
        }
        
        $placeholders = '(' . str_repeat('?,', count($columns) - 1) . '?)';
        $values = str_repeat($placeholders . ',', count($rows) - 1) . $placeholders;
        
        $sql = "INSERT INTO $table (" . implode(', ', $columns) . ") VALUES $values";
        
        $params = [];
        foreach ($rows as $row) {
            foreach ($columns as $col) {
                $params[] = $row[$col] ?? null;
            }
        }
        
        $this->query($sql, $params);
        return count($rows);
    }
}

