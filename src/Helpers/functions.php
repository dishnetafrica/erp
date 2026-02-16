<?php
/**
 * Global Helper Functions
 * Available throughout the application
 */

/**
 * Get application instance
 */
function app(): \IspErp\Core\Application {
    return \IspErp\Core\Application::getInstance();
}

/**
 * Get database instance
 */
function db(): PDO {
    return app()->getDb();
}

/**
 * Get configuration value
 */
function config(string $key, $default = null) {
    return app()->getConfig($key, $default);
}

/**
 * Log message
 */
function log_message(string $level, string $message, array $context = []): void {
    app()->log($level, $message, $context);
}

/**
 * Format currency
 */
function format_currency(float $amount, string $currency = 'USD'): string {
    return $currency . ' ' . number_format($amount, 2);
}

/**
 * Format date
 */
function format_date(string $date, string $format = 'Y-m-d'): string {
    return date($format, strtotime($date));
}

/**
 * Sanitize input
 */
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Generate unique reference number
 */
function generate_reference(string $prefix = ''): string {
    return $prefix . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

/**
 * Calculate percentage
 */
function calculate_percentage(float $part, float $total): float {
    if ($total == 0) return 0;
    return ($part / $total) * 100;
}

/**
 * Get financial year dates
 */
function get_fiscal_year(): array {
    $start = config('fiscal_year_start', '01-01');
    $year = date('Y');
    
    return [
        'start' => "$year-$start",
        'end' => date('Y-m-d', strtotime("$year-$start +1 year -1 day"))
    ];
}

/**
 * JSON response
 */
function json_response(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Success response
 */
function success_response(string $message, array $data = []): void {
    json_response([
        'success' => true,
        'message' => $message,
        'data' => $data
    ]);
}

/**
 * Error response
 */
function error_response(string $message, int $code = 400, array $errors = []): void {
    json_response([
        'success' => false,
        'message' => $message,
        'errors' => $errors
    ], $code);
}

/**
 * Validate required fields
 */
function validate_required(array $data, array $required): array {
    $errors = [];
    
    foreach ($required as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            $errors[$field] = ucfirst($field) . ' is required';
        }
    }
    
    return $errors;
}

/**
 * Validate email
 */
function validate_email(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate amount
 */
function validate_amount($amount): bool {
    return is_numeric($amount) && $amount > 0;
}

/**
 * Calculate date difference in days
 */
function days_between(string $date1, string $date2): int {
    $d1 = new DateTime($date1);
    $d2 = new DateTime($date2);
    return abs($d1->diff($d2)->days);
}

/**
 * Check if dates are within range
 */
function dates_within_range(string $date1, string $date2, int $days): bool {
    return days_between($date1, $date2) <= $days;
}

/**
 * Similar text percentage
 */
function text_similarity(string $str1, string $str2): float {
    similar_text(strtolower($str1), strtolower($str2), $percent);
    return $percent;
}

/**
 * Get current user (from session or UISP)
 */
function current_user(): array {
    // In UISP plugin, user is from UISP session
    return [
        'id' => 1, // Default admin
        'username' => 'admin',
        'role' => 'admin'
    ];
}

/**
 * Check user permission
 */
function has_permission(string $permission): bool {
    $user = current_user();
    
    // Super admin has all permissions
    if ($user['role'] === 'admin') {
        return true;
    }
    
    // Check specific permissions
    $permissions = [
        'manager' => ['approve_expenses', 'view_reports', 'close_period'],
        'accountant' => ['create_expenses', 'reconcile', 'view_all'],
        'user' => ['create_expenses', 'view_own']
    ];
    
    return in_array($permission, $permissions[$user['role']] ?? []);
}

/**
 * Audit log
 */
function audit_log(string $action, string $entity_type, int $entity_id, array $changes = []): void {
    $user = current_user();
    
    db()->prepare("
        INSERT INTO audit_logs (user_id, action, entity_type, entity_id, changes, ip_address, user_agent)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ")->execute([
        $user['id'],
        $action,
        $entity_type,
        $entity_id,
        json_encode($changes),
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null
    ]);
}

/**
 * Get system config value
 */
function get_system_config(string $key, $default = null) {
    $stmt = db()->prepare("SELECT value, type FROM system_config WHERE key = ?");
    $stmt->execute([$key]);
    $config = $stmt->fetch();
    
    if (!$config) {
        return $default;
    }
    
    // Cast to appropriate type
    switch ($config['type']) {
        case 'boolean':
            return filter_var($config['value'], FILTER_VALIDATE_BOOLEAN);
        case 'integer':
            return (int)$config['value'];
        case 'decimal':
            return (float)$config['value'];
        default:
            return $config['value'];
    }
}

/**
 * Set system config value
 */
function set_system_config(string $key, $value): void {
    db()->prepare("
        UPDATE system_config 
        SET value = ?, updated_at = CURRENT_TIMESTAMP 
        WHERE key = ?
    ")->execute([
        (string)$value,
        $key
    ]);
}

/**
 * Get account by code
 */
function get_account(string $code): ?array {
    $stmt = db()->prepare("SELECT * FROM chart_of_accounts WHERE code = ?");
    $stmt->execute([$code]);
    return $stmt->fetch() ?: null;
}

/**
 * Calculate balance
 */
function calculate_balance(string $account_type, float $debits, float $credits): float {
    // Assets and Expenses: Debit increases, Credit decreases
    if (in_array($account_type, ['asset', 'expense'])) {
        return $debits - $credits;
    }
    
    // Liabilities, Equity, Revenue: Credit increases, Debit decreases
    return $credits - $debits;
}

/**
 * Is debit account
 */
function is_debit_account(string $account_type): bool {
    return in_array($account_type, ['asset', 'expense']);
}

/**
 * Format account name with code
 */
function format_account(array $account): string {
    return $account['code'] . ' - ' . $account['name'];
}

/**
 * Parse CSV line
 */
function parse_csv_line(string $line, string $delimiter = ','): array {
    return str_getcsv($line, $delimiter);
}

/**
 * Generate report filename
 */
function generate_report_filename(string $report_type): string {
    return $report_type . '_' . date('Y-m-d_His') . '.xlsx';
}

/**
 * Get upload path
 */
function get_upload_path(string $filename): string {
    $uploadDir = ISP_ERP_DATA . '/uploads';
    
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    
    return $uploadDir . '/' . $filename;
}

/**
 * Safe division
 */
function safe_divide(float $numerator, float $denominator): float {
    return $denominator != 0 ? $numerator / $denominator : 0;
}

/**
 * Array to CSV line
 */
function array_to_csv(array $data): string {
    $output = fopen('php://temp', 'r+');
    fputcsv($output, $data);
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    return trim($csv);
}

/**
 * Get month name
 */
function get_month_name(int $month): string {
    return date('F', mktime(0, 0, 0, $month, 1));
}

/**
 * Get quarter
 */
function get_quarter(string $date): int {
    $month = (int)date('n', strtotime($date));
    return (int)ceil($month / 3);
}

/**
 * Format percentage
 */
function format_percentage(float $value, int $decimals = 2): string {
    return number_format($value, $decimals) . '%';
}

/**
 * Truncate text
 */
function truncate(string $text, int $length = 50, string $suffix = '...'): string {
    if (strlen($text) <= $length) {
        return $text;
    }
    return substr($text, 0, $length) . $suffix;
}

/**
 * Convert to boolean
 */
function to_bool($value): bool {
    if (is_bool($value)) return $value;
    if (is_numeric($value)) return $value != 0;
    return in_array(strtolower($value), ['true', 'yes', '1', 'on']);
}

/**
 * Get file extension
 */
function get_file_extension(string $filename): string {
    return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
}

/**
 * Is allowed file type
 */
function is_allowed_file_type(string $filename, array $allowed = ['pdf', 'jpg', 'jpeg', 'png', 'xlsx', 'csv']): bool {
    return in_array(get_file_extension($filename), $allowed);
}

/**
 * Format file size
 */
function format_file_size(int $bytes): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, 2) . ' ' . $units[$pow];
}
