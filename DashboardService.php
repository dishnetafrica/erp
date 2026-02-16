<?php

namespace IspErp\Services;

use PDO;
use Exception;

/**
 * Dashboard Service
 * Generates real-time financial metrics and KPIs
 */
class DashboardService
{
    private $db;
    private $cacheLifetime = 300; // 5 minutes
    
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }
    
    /**
     * Get complete dashboard data
     */
    public function getDashboard(): array
    {
        return [
            'summary' => $this->getSummaryMetrics(),
            'cash_flow' => $this->getCashFlowChart(30),
            'expenses' => $this->getExpenseMetrics(),
            'receivables' => $this->getReceivablesMetrics(),
            'reconciliation' => $this->getReconciliationStatus(),
            'alerts' => $this->getAlerts(),
            'recent_activity' => $this->getRecentActivity(),
        ];
    }
    
    /**
     * Get summary metrics (cached)
     */
    public function getSummaryMetrics(): array
    {
        $cached = $this->getFromCache('summary_metrics');
        if ($cached) {
            return $cached;
        }
        
        $metrics = [
            'cash_balance' => $this->getCashBalance(),
            'bank_balance' => $this->getTotalBankBalance(),
            'accounts_receivable' => $this->getAccountsReceivable(),
            'pending_expenses' => $this->getPendingExpenses(),
            'unreconciled_count' => $this->getUnreconciledCount(),
            'today_receipts' => $this->getTodayReceipts(),
            'today_payments' => $this->getTodayPayments(),
            'monthly_revenue' => $this->getMonthlyRevenue(),
            'monthly_expenses' => $this->getMonthlyExpenses(),
        ];
        
        $this->saveToCache('summary_metrics', $metrics);
        
        return $metrics;
    }
    
    /**
     * Get cash flow chart data
     */
    public function getCashFlowChart(int $days = 30): array
    {
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-$days days"));
        
        $stmt = $this->db->prepare("
            SELECT 
                transaction_date as date,
                SUM(CASE WHEN type = 'receipt' THEN amount ELSE 0 END) as receipts,
                SUM(CASE WHEN type = 'payment' THEN amount ELSE 0 END) as payments
            FROM cashbook_transactions
            WHERE transaction_date BETWEEN ? AND ?
            GROUP BY transaction_date
            ORDER BY transaction_date
        ");
        
        $stmt->execute([$startDate, $endDate]);
        $data = $stmt->fetchAll();
        
        // Fill missing dates with zeros
        $chartData = [];
        $currentDate = $startDate;
        
        while ($currentDate <= $endDate) {
            $found = false;
            foreach ($data as $row) {
                if ($row['date'] === $currentDate) {
                    $chartData[] = [
                        'date' => $currentDate,
                        'receipts' => (float)$row['receipts'],
                        'payments' => (float)$row['payments'],
                        'net' => (float)$row['receipts'] - (float)$row['payments'],
                    ];
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $chartData[] = [
                    'date' => $currentDate,
                    'receipts' => 0,
                    'payments' => 0,
                    'net' => 0,
                ];
            }
            
            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        }
        
        return $chartData;
    }
    
    /**
     * Get expense metrics
     */
    public function getExpenseMetrics(): array
    {
        $monthStart = date('Y-m-01');
        $monthEnd = date('Y-m-d');
        
        // Get expense summary by status
        $stmt = $this->db->prepare("
            SELECT 
                status,
                COUNT(*) as count,
                SUM(total_amount) as total
            FROM expenses
            WHERE expense_date BETWEEN ? AND ?
            GROUP BY status
        ");
        
        $stmt->execute([$monthStart, $monthEnd]);
        $byStatus = $stmt->fetchAll();
        
        // Get top categories
        $stmt = $this->db->prepare("
            SELECT 
                ec.name as category,
                COUNT(e.id) as count,
                SUM(e.total_amount) as total
            FROM expenses e
            JOIN expense_categories ec ON e.category_id = ec.id
            WHERE e.expense_date BETWEEN ? AND ? AND e.status = 'paid'
            GROUP BY ec.name
            ORDER BY total DESC
            LIMIT 5
        ");
        
        $stmt->execute([$monthStart, $monthEnd]);
        $topCategories = $stmt->fetchAll();
        
        return [
            'by_status' => $byStatus,
            'top_categories' => $topCategories,
            'period' => ['from' => $monthStart, 'to' => $monthEnd],
        ];
    }
    
    /**
     * Get receivables metrics
     */
    public function getReceivablesMetrics(): array
    {
        // Total AR
        $stmt = $this->db->query("
            SELECT SUM(total_amount) as total
            FROM uisp_invoices
            WHERE status IN ('unpaid', 'partially_paid')
        ");
        $totalAR = (float)$stmt->fetchColumn();
        
        // Aged receivables
        $today = date('Y-m-d');
        
        $stmt = $this->db->prepare("
            SELECT 
                CASE 
                    WHEN JULIANDAY(?) - JULIANDAY(due_date) <= 0 THEN 'current'
                    WHEN JULIANDAY(?) - JULIANDAY(due_date) <= 30 THEN '1-30'
                    WHEN JULIANDAY(?) - JULIANDAY(due_date) <= 60 THEN '31-60'
                    WHEN JULIANDAY(?) - JULIANDAY(due_date) <= 90 THEN '61-90'
                    ELSE '90+'
                END as age_bucket,
                COUNT(*) as count,
                SUM(total_amount) as total
            FROM uisp_invoices
            WHERE status IN ('unpaid', 'partially_paid')
            GROUP BY age_bucket
        ");
        
        $stmt->execute([$today, $today, $today, $today]);
        $aged = $stmt->fetchAll();
        
        return [
            'total' => $totalAR,
            'aged' => $aged,
        ];
    }
    
    /**
     * Get reconciliation status
     */
    public function getReconciliationStatus(): array
    {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN is_reconciled = 1 THEN 1 ELSE 0 END) as reconciled,
                SUM(CASE WHEN is_reconciled = 0 THEN 1 ELSE 0 END) as unreconciled
            FROM bank_transactions
        ");
        
        $status = $stmt->fetch();
        
        $total = (int)$status['total'];
        $reconciled = (int)$status['reconciled'];
        $unreconciled = (int)$status['unreconciled'];
        
        return [
            'total' => $total,
            'reconciled' => $reconciled,
            'unreconciled' => $unreconciled,
            'rate' => $total > 0 ? ($reconciled / $total) * 100 : 0,
        ];
    }
    
    /**
     * Get system alerts
     */
    public function getAlerts(): array
    {
        $alerts = [];
        
        // Check for negative cash balance
        $cashBalance = $this->getCashBalance();
        if ($cashBalance < 0) {
            $alerts[] = [
                'type' => 'danger',
                'title' => 'Negative Cash Balance',
                'message' => 'Cash balance is negative: ' . format_currency($cashBalance),
                'action' => 'Review cashbook',
            ];
        }
        
        // Check for pending expenses requiring approval
        $pendingCount = $this->getPendingExpensesCount();
        if ($pendingCount > 0) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Pending Approvals',
                'message' => "$pendingCount expenses awaiting approval",
                'action' => 'Review expenses',
            ];
        }
        
        // Check for high unreconciled transactions
        $unreconciledCount = $this->getUnreconciledCount();
        if ($unreconciledCount > 50) {
            $alerts[] = [
                'type' => 'info',
                'title' => 'Reconciliation Needed',
                'message' => "$unreconciledCount unreconciled bank transactions",
                'action' => 'Run auto-reconciliation',
            ];
        }
        
        // Check for overdue invoices
        $overdueCount = $this->getOverdueInvoicesCount();
        if ($overdueCount > 0) {
            $alerts[] = [
                'type' => 'warning',
                'title' => 'Overdue Invoices',
                'message' => "$overdueCount invoices are overdue",
                'action' => 'View receivables',
            ];
        }
        
        // Check last UISP sync
        $lastSync = $this->getLastSyncTime();
        if ($lastSync && time() - strtotime($lastSync) > 3600) { // 1 hour
            $alerts[] = [
                'type' => 'info',
                'title' => 'UISP Sync Delayed',
                'message' => 'Last sync: ' . date('H:i', strtotime($lastSync)),
                'action' => 'Run manual sync',
            ];
        }
        
        return $alerts;
    }
    
    /**
     * Get recent activity
     */
    public function getRecentActivity(int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                action,
                entity_type,
                entity_id,
                timestamp,
                user_id
            FROM audit_logs
            ORDER BY timestamp DESC
            LIMIT ?
        ");
        
        $stmt->execute([$limit]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Get profit & loss summary
     */
    public function getProfitLoss(string $fromDate, string $toDate): array
    {
        // Revenue
        $stmt = $this->db->prepare("
            SELECT SUM(amount) as total
            FROM uisp_invoices
            WHERE invoice_date BETWEEN ? AND ?
        ");
        $stmt->execute([$fromDate, $toDate]);
        $revenue = (float)$stmt->fetchColumn();
        
        // Expenses
        $stmt = $this->db->prepare("
            SELECT SUM(total_amount) as total
            FROM expenses
            WHERE expense_date BETWEEN ? AND ? AND status = 'paid'
        ");
        $stmt->execute([$fromDate, $toDate]);
        $expenses = (float)$stmt->fetchColumn();
        
        $netProfit = $revenue - $expenses;
        $profitMargin = $revenue > 0 ? ($netProfit / $revenue) * 100 : 0;
        
        return [
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'revenue' => $revenue,
            'expenses' => $expenses,
            'net_profit' => $netProfit,
            'profit_margin' => $profitMargin,
        ];
    }
    
    // Helper methods
    
    private function getCashBalance(): float
    {
        $stmt = $this->db->query("
            SELECT 
                (SELECT COALESCE(SUM(amount), 0) FROM cashbook_transactions WHERE type = 'receipt') -
                (SELECT COALESCE(SUM(amount), 0) FROM cashbook_transactions WHERE type = 'payment') as balance
        ");
        
        $openingBalance = get_system_config('cash_opening_balance', 0);
        return $openingBalance + (float)$stmt->fetchColumn();
    }
    
    private function getTotalBankBalance(): float
    {
        $stmt = $this->db->query("
            SELECT COALESCE(SUM(current_balance), 0) as total
            FROM bank_accounts
            WHERE is_active = 1
        ");
        
        return (float)$stmt->fetchColumn();
    }
    
    private function getAccountsReceivable(): float
    {
        $stmt = $this->db->query("
            SELECT COALESCE(SUM(total_amount), 0) as total
            FROM uisp_invoices
            WHERE status IN ('unpaid', 'partially_paid')
        ");
        
        return (float)$stmt->fetchColumn();
    }
    
    private function getPendingExpenses(): float
    {
        $stmt = $this->db->query("
            SELECT COALESCE(SUM(total_amount), 0) as total
            FROM expenses
            WHERE status = 'pending'
        ");
        
        return (float)$stmt->fetchColumn();
    }
    
    private function getPendingExpensesCount(): int
    {
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM expenses WHERE status = 'pending'
        ");
        
        return (int)$stmt->fetchColumn();
    }
    
    private function getUnreconciledCount(): int
    {
        $stmt = $this->db->query("
            SELECT COUNT(*) FROM bank_transactions WHERE is_reconciled = 0
        ");
        
        return (int)$stmt->fetchColumn();
    }
    
    private function getOverdueInvoicesCount(): int
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM uisp_invoices
            WHERE status IN ('unpaid', 'partially_paid')
            AND due_date < ?
        ");
        
        $stmt->execute([date('Y-m-d')]);
        
        return (int)$stmt->fetchColumn();
    }
    
    private function getTodayReceipts(): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM cashbook_transactions
            WHERE type = 'receipt' AND transaction_date = ?
        ");
        
        $stmt->execute([date('Y-m-d')]);
        
        return (float)$stmt->fetchColumn();
    }
    
    private function getTodayPayments(): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM cashbook_transactions
            WHERE type = 'payment' AND transaction_date = ?
        ");
        
        $stmt->execute([date('Y-m-d')]);
        
        return (float)$stmt->fetchColumn();
    }
    
    private function getMonthlyRevenue(): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(amount), 0) as total
            FROM uisp_invoices
            WHERE invoice_date >= ?
        ");
        
        $stmt->execute([date('Y-m-01')]);
        
        return (float)$stmt->fetchColumn();
    }
    
    private function getMonthlyExpenses(): float
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total
            FROM expenses
            WHERE expense_date >= ? AND status = 'paid'
        ");
        
        $stmt->execute([date('Y-m-01')]);
        
        return (float)$stmt->fetchColumn();
    }
    
    private function getLastSyncTime(): ?string
    {
        $stmt = $this->db->query("
            SELECT MAX(completed_at) FROM sync_logs
            WHERE status = 'success'
        ");
        
        return $stmt->fetchColumn() ?: null;
    }
    
    // Cache management
    
    private function getFromCache(string $key): ?array
    {
        $stmt = $this->db->prepare("
            SELECT metric_value, calculated_at
            FROM dashboard_metrics
            WHERE metric_key = ?
        ");
        
        $stmt->execute([$key]);
        $row = $stmt->fetch();
        
        if (!$row) {
            return null;
        }
        
        // Check if cache is still valid
        $age = time() - strtotime($row['calculated_at']);
        if ($age > $this->cacheLifetime) {
            return null;
        }
        
        return json_decode($row['metric_value'], true);
    }
    
    private function saveToCache(string $key, array $data): void
    {
        $stmt = $this->db->prepare("
            INSERT OR REPLACE INTO dashboard_metrics (metric_key, metric_value)
            VALUES (?, ?)
        ");
        
        $stmt->execute([$key, json_encode($data)]);
    }
    
    public function clearCache(): void
    {
        $this->db->exec("DELETE FROM dashboard_metrics");
    }
}
