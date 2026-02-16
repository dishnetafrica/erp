<?php

namespace IspErp\Config;

use PDO;
use Exception;

/**
 * Database Configuration and Connection
 */
class Database
{
    private static $instance;
    private $connection;
    private $config;
    
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'driver' => 'sqlite',
            'database' => ISP_ERP_DATA . '/isp_erp.db',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        ], $config);
    }
    
    public static function getInstance(array $config = []): self
    {
        if (self::$instance === null) {
            self::$instance = new self($config);
        }
        
        return self::$instance;
    }
    
    public function connect(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        
        try {
            $dsn = $this->buildDsn();
            $this->connection = new PDO(
                $dsn,
                $this->config['username'] ?? null,
                $this->config['password'] ?? null,
                $this->config['options']
            );
            
            // SQLite specific settings
            if ($this->config['driver'] === 'sqlite') {
                $this->connection->exec('PRAGMA foreign_keys = ON');
                $this->connection->exec('PRAGMA journal_mode = WAL');
                $this->connection->exec('PRAGMA synchronous = NORMAL');
            }
            
            return $this->connection;
            
        } catch (Exception $e) {
            throw new Exception('Database connection failed: ' . $e->getMessage());
        }
    }
    
    private function buildDsn(): string
    {
        switch ($this->config['driver']) {
            case 'sqlite':
                return 'sqlite:' . $this->config['database'];
                
            case 'mysql':
                return sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    $this->config['host'],
                    $this->config['port'] ?? 3306,
                    $this->config['database'],
                    $this->config['charset']
                );
                
            case 'pgsql':
                return sprintf(
                    'pgsql:host=%s;port=%s;dbname=%s',
                    $this->config['host'],
                    $this->config['port'] ?? 5432,
                    $this->config['database']
                );
                
            default:
                throw new Exception('Unsupported database driver: ' . $this->config['driver']);
        }
    }
    
    public function getConnection(): ?PDO
    {
        return $this->connection;
    }
    
    public function disconnect(): void
    {
        $this->connection = null;
    }
    
    public function beginTransaction(): bool
    {
        return $this->connect()->beginTransaction();
    }
    
    public function commit(): bool
    {
        return $this->connect()->commit();
    }
    
    public function rollBack(): bool
    {
        return $this->connect()->rollBack();
    }
    
    public function inTransaction(): bool
    {
        return $this->connect()->inTransaction();
    }
}
