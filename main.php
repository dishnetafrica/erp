<?php
/**
 * ISP ERP Platform - Main Bootstrap
 * 
 * Enterprise accounting system for ISP businesses
 * Integrates with Ubiquiti UISP/UCRM
 * 
 * @version 2.0.0
 * @author ISP ERP Team
 */

declare(strict_types=1);

// Define constants
define('ISP_ERP_VERSION', '2.0.0');
define('ISP_ERP_ROOT', __DIR__);
define('ISP_ERP_CONFIG', ISP_ERP_ROOT . '/config');
define('ISP_ERP_SRC', ISP_ERP_ROOT . '/src');
define('ISP_ERP_DATA', ISP_ERP_ROOT . '/data');
define('ISP_ERP_LIB', ISP_ERP_ROOT . '/lib');

// Composer autoloader (if available)
if (file_exists(ISP_ERP_ROOT . '/vendor/autoload.php')) {
    require_once ISP_ERP_ROOT . '/vendor/autoload.php';
}

// Custom autoloader
spl_autoload_register(function ($class) {
    // Remove namespace prefix
    $prefix = 'IspErp\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    
    $relative_class = substr($class, $len);
    $file = ISP_ERP_SRC . '/' . str_replace('\\', '/', $relative_class) . '.php';
    
    if (file_exists($file)) {
        require $file;
    }
});

// Load core files
require_once ISP_ERP_SRC . '/Helpers/functions.php';
require_once ISP_ERP_SRC . '/Config/Database.php';
require_once ISP_ERP_SRC . '/Core/Application.php';

// Initialize UISP environment variables
$uispUrl = getenv('UCRM_PUBLIC_URL') ?: 'http://localhost';
$pluginToken = getenv('PLUGIN_APP_KEY') ?: '';

// Create application instance
$app = new \IspErp\Core\Application([
    'debug' => getenv('DEBUG') === 'true',
    'uisp_url' => $uispUrl,
    'plugin_token' => $pluginToken,
    'data_dir' => ISP_ERP_DATA,
]);

// Run database migrations on first load
if (!file_exists(ISP_ERP_DATA . '/.initialized')) {
    try {
        $app->runMigrations();
        touch(ISP_ERP_DATA . '/.initialized');
        $app->log('info', 'ISP ERP Platform initialized successfully');
    } catch (Exception $e) {
        $app->log('error', 'Initialization failed: ' . $e->getMessage());
        die('Failed to initialize ISP ERP Platform. Check logs for details.');
    }
}

// Schedule background tasks
$app->registerSchedule('sync_uisp_invoices', '*/15 * * * *'); // Every 15 minutes
$app->registerSchedule('sync_uisp_payments', '*/10 * * * *'); // Every 10 minutes
$app->registerSchedule('auto_reconcile', '0 */1 * * *');      // Every hour
$app->registerSchedule('update_dashboard_cache', '*/5 * * * *'); // Every 5 minutes

return $app;
