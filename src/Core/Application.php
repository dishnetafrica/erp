<?php

namespace IspErp\Core;

use PDO;
use Exception;

/**
 * Core Application Class
 * Handles initialization, routing, and dependency injection
 */
class Application
{
    private static $instance;
    private $config;
    private $db;
    private $container = [];
    
    public function __construct(array $config = [])
    {
        self::$instance = $this;
        $this->config = $config;
        $this->initializeDatabase();
        $this->registerServices();
    }
    
    public static function getInstance(): self
    {
        return self::$instance;
    }
    
    private function initializeDatabase(): void
    {
        $dbPath = $this->config['data_dir'] . '/isp_erp.db';
        
        try {
            $this->db = new PDO('sqlite:' . $dbPath);
            $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            
            // Enable foreign keys
            $this->db->exec('PRAGMA foreign_keys = ON');
            
        } catch (Exception $e) {
            $this->log('error', 'Database initialization failed: ' . $e->getMessage());
            throw $e;
        }
    }
    
    public function getDb(): PDO
    {
        return $this->db;
    }
    
    public function getConfig(string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->config;
        }
        
        return $this->config[$key] ?? $default;
    }
    
    private function registerServices(): void
    {
        // Register core services
        $this->bind('db', function() {
            return $this->db;
        });
        
        // Register repositories
        $this->bind('customerRepo', function() {
            return new \IspErp\Repositories\CustomerRepository($this->db);
        });
        
        $this->bind('invoiceRepo', function() {
            return new \IspErp\Repositories\InvoiceRepository($this->db);
        });
        
        $this->bind('paymentRepo', function() {
            return new \IspErp\Repositories\PaymentRepository($this->db);
        });
        
        $this->bind('expenseRepo', function() {
            return new \IspErp\Repositories\ExpenseRepository($this->db);
        });
        
        $this->bind('cashbookRepo', function() {
            return new \IspErp\Repositories\CashbookRepository($this->db);
        });
        
        $this->bind('bankRepo', function() {
            return new \IspErp\Repositories\BankRepository($this->db);
        });
        
        $this->bind('journalRepo', function() {
            return new \IspErp\Repositories\JournalRepository($this->db);
        });
        
        $this->bind('accountRepo', function() {
            return new \IspErp\Repositories\AccountRepository($this->db);
        });
        
        // Register services
        $this->bind('uispSync', function() {
            return new \IspErp\Services\UispSyncService(
                $this->resolve('invoiceRepo'),
                $this->resolve('paymentRepo'),
                $this->resolve('customerRepo')
            );
        });
        
        $this->bind('journalService', function() {
            return new \IspErp\Services\JournalService(
                $this->resolve('journalRepo'),
                $this->resolve('accountRepo')
            );
        });
        
        $this->bind('cashbookService', function() {
            return new \IspErp\Services\CashbookService(
                $this->resolve('cashbookRepo'),
                $this->resolve('journalService')
            );
        });
        
        $this->bind('expenseService', function() {
            return new \IspErp\Services\ExpenseService(
                $this->resolve('expenseRepo'),
                $this->resolve('journalService'),
                $this->resolve('cashbookService')
            );
        });
        
        $this->bind('reconciliationService', function() {
            return new \IspErp\Services\ReconciliationService(
                $this->resolve('bankRepo'),
                $this->resolve('paymentRepo')
            );
        });
        
        $this->bind('dashboardService', function() {
            return new \IspErp\Services\DashboardService($this->db);
        });
    }
    
    public function bind(string $key, callable $resolver): void
    {
        $this->container[$key] = $resolver;
    }
    
    public function resolve(string $key)
    {
        if (!isset($this->container[$key])) {
            throw new Exception("Service not found: $key");
        }
        
        $resolver = $this->container[$key];
        return $resolver($this);
    }
    
    public function runMigrations(): void
    {
        $migrationsPath = ISP_ERP_ROOT . '/database/migrations';
        $migrations = glob($migrationsPath . '/*.php');
        
        foreach ($migrations as $migration) {
            $this->log('info', 'Running migration: ' . basename($migration));
            
            $migrationFunctions = require $migration;
            
            if (isset($migrationFunctions['up'])) {
                try {
                    $this->db->beginTransaction();
                    $migrationFunctions['up']($this->db);
                    $this->db->commit();
                    $this->log('info', 'Migration completed: ' . basename($migration));
                } catch (Exception $e) {
                    $this->db->rollBack();
                    $this->log('error', 'Migration failed: ' . $e->getMessage());
                    throw $e;
                }
            }
        }
        
        // Run seeds
        $seedsPath = ISP_ERP_ROOT . '/database/seeds';
        $seeds = glob($seedsPath . '/*.php');
        
        foreach ($seeds as $seed) {
            $this->log('info', 'Running seed: ' . basename($seed));
            
            $seedFunctions = require $seed;
            
            if (isset($seedFunctions['run'])) {
                try {
                    $seedFunctions['run']($this->db);
                    $this->log('info', 'Seed completed: ' . basename($seed));
                } catch (Exception $e) {
                    $this->log('error', 'Seed failed: ' . $e->getMessage());
                    throw $e;
                }
            }
        }
    }
    
    public function registerSchedule(string $job, string $schedule): void
    {
        // Store scheduled jobs for execution
        // This would be handled by UISP's scheduler
    }
    
    public function log(string $level, string $message, array $context = []): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $logFile = $this->config['data_dir'] . '/app.log';
        
        $logMessage = sprintf(
            "[%s] [%s] %s %s\n",
            $timestamp,
            strtoupper($level),
            $message,
            !empty($context) ? json_encode($context) : ''
        );
        
        file_put_contents($logFile, $logMessage, FILE_APPEND);
        
        if ($this->config['debug'] ?? false) {
            error_log($logMessage);
        }
    }
    
    public function handleRequest(): void
    {
        $action = $_GET['action'] ?? 'dashboard';
        $method = $_SERVER['REQUEST_METHOD'];
        
        // Route to appropriate controller
        try {
            $controller = $this->getController($action);
            $controller->handle($method);
        } catch (Exception $e) {
            $this->log('error', 'Request handling failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }
    
    private function getController(string $action)
    {
        $controllerMap = [
            'dashboard' => 'DashboardController',
            'cashbook' => 'CashbookController',
            'banks' => 'BankController',
            'expenses' => 'ExpenseController',
            'reconciliation' => 'ReconciliationController',
            'ledger' => 'LedgerController',
            'reports' => 'ReportController',
            'settings' => 'SettingsController',
            'api' => 'ApiController',
        ];
        
        $controllerName = $controllerMap[$action] ?? 'DashboardController';
        $controllerClass = "\\IspErp\\Controllers\\$controllerName";
        
        if (!class_exists($controllerClass)) {
            throw new Exception("Controller not found: $controllerClass");
        }
        
        return new $controllerClass($this);
    }
}
