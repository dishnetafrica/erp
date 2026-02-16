<?php

namespace IspErp\Services;

use IspErp\Repositories\CashbookRepository;
use Exception;

/**
 * Cashbook Service
 * Manages daily cash operations and balances
 */
class CashbookService
{
    private $cashbookRepo;
    private $journalService;
    
    public function __construct(
        CashbookRepository $cashbookRepo,
        JournalService $journalService
    ) {
        $this->cashbookRepo = $cashbookRepo;
        $this->journalService = $journalService;
    }
    
    /**
     * Record a cash transaction
     */
    public function recordTransaction(array $data): int
    {
        // Validate required fields
        $errors = validate_required($data, ['transaction_date', 'type', 'amount', 'description']);
        if (!empty($errors)) {
            throw new Exception('Missing required fields: ' . implode(', ', array_keys($errors)));
        }
        
        // Validate type
        if (!in_array($data['type'], ['receipt', 'payment'])) {
            throw new Exception('Invalid transaction type. Must be receipt or payment.');
        }
        
        // Validate amount
        if (!validate_amount($data['amount'])) {
            throw new Exception('Invalid amount');
        }
        
        // Get current balance
        $currentBalance = $this->getCurrentBalance($data['transaction_date']);
        
        // Calculate new balance
        if ($data['type'] === 'receipt') {
            $newBalance = $currentBalance + $data['amount'];
        } else {
            $newBalance = $currentBalance - $data['amount'];
            
            // Check for negative balance
            if ($newBalance < 0 && !($data['allow_negative'] ?? false)) {
                throw new Exception('Insufficient cash balance');
            }
        }
        
        // Create transaction record
        $transactionData = [
            'transaction_date' => $data['transaction_date'],
            'type' => $data['type'],
            'category' => $data['category'] ?? null,
            'amount' => $data['amount'],
            'description' => $data['description'],
            'reference' => $data['reference'] ?? null,
            'source_type' => $data['source_type'] ?? 'manual',
            'source_id' => $data['source_id'] ?? null,
            'balance_after' => $newBalance,
            'created_by' => current_user()['id'],
        ];
        
        $transactionId = $this->cashbookRepo->create($transactionData);
        
        // Create journal entry if not from automated source
        if (!isset($data['skip_journal']) || !$data['skip_journal']) {
            $this->createJournalEntry($transactionId, $transactionData);
        }
        
        // Update daily summary
        $this->updateDailySummary($data['transaction_date']);
        
        audit_log('create', 'cashbook_transaction', $transactionId, $data);
        
        app()->log('info', "Cashbook {$data['type']} recorded: {$data['amount']}");
        
        return $transactionId;
    }
    
    /**
     * Get current cash balance
     */
    public function getCurrentBalance(string $asOfDate = null): float
    {
        $asOfDate = $asOfDate ?? date('Y-m-d');
        
        // Get opening balance from config
        $openingBalance = get_system_config('cash_opening_balance', 0);
        
        // Get all transactions up to date
        $transactions = $this->cashbookRepo->getTransactionsUpTo($asOfDate);
        
        $balance = $openingBalance;
        
        foreach ($transactions as $txn) {
            if ($txn['type'] === 'receipt') {
                $balance += $txn['amount'];
            } else {
                $balance -= $txn['amount'];
            }
        }
        
        return $balance;
    }
    
    /**
     * Get daily summary
     */
    public function getDailySummary(string $date): array
    {
        $summary = $this->cashbookRepo->getDailySummary($date);
        
        if (!$summary) {
            // Create summary
            $summary = $this->calculateDailySummary($date);
        }
        
        return $summary;
    }
    
    /**
     * Get transactions for a period
     */
    public function getTransactions(string $fromDate = null, string $toDate = null, string $type = null): array
    {
        $fromDate = $fromDate ?? date('Y-m-d', strtotime('-30 days'));
        $toDate = $toDate ?? date('Y-m-d');
        
        return $this->cashbookRepo->findByPeriod($fromDate, $toDate, $type);
    }
    
    /**
     * Get cashbook report
     */
    public function getCashbookReport(string $fromDate, string $toDate): array
    {
        $openingBalance = $this->getCurrentBalance(date('Y-m-d', strtotime($fromDate . ' -1 day')));
        
        $transactions = $this->getTransactions($fromDate, $toDate);
        
        $totalReceipts = 0;
        $totalPayments = 0;
        $runningBalance = $openingBalance;
        
        $report = [];
        
        foreach ($transactions as $txn) {
            if ($txn['type'] === 'receipt') {
                $totalReceipts += $txn['amount'];
                $runningBalance += $txn['amount'];
            } else {
                $totalPayments += $txn['amount'];
                $runningBalance -= $txn['amount'];
            }
            
            $report[] = [
                'date' => $txn['transaction_date'],
                'type' => $txn['type'],
                'category' => $txn['category'],
                'description' => $txn['description'],
                'reference' => $txn['reference'],
                'receipt' => $txn['type'] === 'receipt' ? $txn['amount'] : 0,
                'payment' => $txn['type'] === 'payment' ? $txn['amount'] : 0,
                'balance' => $runningBalance,
            ];
        }
        
        return [
            'period' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'opening_balance' => $openingBalance,
            'total_receipts' => $totalReceipts,
            'total_payments' => $totalPayments,
            'closing_balance' => $runningBalance,
            'transactions' => $report,
        ];
    }
    
    /**
     * Close daily cashbook
     */
    public function closeDailyCashbook(string $date): bool
    {
        $summary = $this->getDailySummary($date);
        
        if ($summary['is_closed']) {
            throw new Exception('Daily cashbook already closed for ' . $date);
        }
        
        // Update summary as closed
        $this->cashbookRepo->updateDailySummary($date, [
            'is_closed' => 1,
            'closed_at' => date('Y-m-d H:i:s'),
            'closed_by' => current_user()['id'],
        ]);
        
        audit_log('close', 'cashbook_daily', 0, ['date' => $date]);
        
        app()->log('info', "Daily cashbook closed for $date");
        
        return true;
    }
    
    /**
     * Reopen daily cashbook
     */
    public function reopenDailyCashbook(string $date): bool
    {
        if (!has_permission('reopen_cashbook')) {
            throw new Exception('Insufficient permissions to reopen cashbook');
        }
        
        $this->cashbookRepo->updateDailySummary($date, [
            'is_closed' => 0,
            'closed_at' => null,
            'closed_by' => null,
        ]);
        
        audit_log('reopen', 'cashbook_daily', 0, ['date' => $date]);
        
        return true;
    }
    
    /**
     * Get cash flow summary
     */
    public function getCashFlowSummary(int $days = 30): array
    {
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime("-$days days"));
        
        $dailyData = [];
        $currentDate = $startDate;
        
        while ($currentDate <= $endDate) {
            $summary = $this->getDailySummary($currentDate);
            
            $dailyData[] = [
                'date' => $currentDate,
                'receipts' => $summary['total_receipts'],
                'payments' => $summary['total_payments'],
                'net' => $summary['total_receipts'] - $summary['total_payments'],
                'balance' => $summary['closing_balance'],
            ];
            
            $currentDate = date('Y-m-d', strtotime($currentDate . ' +1 day'));
        }
        
        return [
            'period_days' => $days,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'daily_data' => $dailyData,
            'total_receipts' => array_sum(array_column($dailyData, 'receipts')),
            'total_payments' => array_sum(array_column($dailyData, 'payments')),
            'net_change' => array_sum(array_column($dailyData, 'net')),
        ];
    }
    
    /**
     * Get top expense categories
     */
    public function getTopExpenseCategories(string $fromDate = null, string $toDate = null, int $limit = 10): array
    {
        $fromDate = $fromDate ?? date('Y-m-01'); // Start of month
        $toDate = $toDate ?? date('Y-m-d');
        
        $payments = $this->cashbookRepo->findByPeriod($fromDate, $toDate, 'payment');
        
        $categories = [];
        foreach ($payments as $payment) {
            $category = $payment['category'] ?? 'Uncategorized';
            
            if (!isset($categories[$category])) {
                $categories[$category] = [
                    'category' => $category,
                    'amount' => 0,
                    'count' => 0,
                ];
            }
            
            $categories[$category]['amount'] += $payment['amount'];
            $categories[$category]['count']++;
        }
        
        // Sort by amount descending
        usort($categories, function($a, $b) {
            return $b['amount'] <=> $a['amount'];
        });
        
        return array_slice($categories, 0, $limit);
    }
    
    /**
     * Calculate daily summary
     */
    private function calculateDailySummary(string $date): array
    {
        $previousDate = date('Y-m-d', strtotime($date . ' -1 day'));
        $openingBalance = $this->getCurrentBalance($previousDate);
        
        $transactions = $this->cashbookRepo->findByDate($date);
        
        $totalReceipts = 0;
        $totalPayments = 0;
        
        foreach ($transactions as $txn) {
            if ($txn['type'] === 'receipt') {
                $totalReceipts += $txn['amount'];
            } else {
                $totalPayments += $txn['amount'];
            }
        }
        
        $closingBalance = $openingBalance + $totalReceipts - $totalPayments;
        
        return [
            'summary_date' => $date,
            'opening_balance' => $openingBalance,
            'total_receipts' => $totalReceipts,
            'total_payments' => $totalPayments,
            'closing_balance' => $closingBalance,
            'is_closed' => 0,
        ];
    }
    
    /**
     * Update daily summary
     */
    private function updateDailySummary(string $date): void
    {
        $summary = $this->calculateDailySummary($date);
        
        // Check if summary exists
        $existing = $this->cashbookRepo->getDailySummary($date);
        
        if ($existing) {
            $this->cashbookRepo->updateDailySummary($date, $summary);
        } else {
            $this->cashbookRepo->createDailySummary($summary);
        }
    }
    
    /**
     * Create journal entry for cashbook transaction
     */
    private function createJournalEntry(int $transactionId, array $transactionData): void
    {
        $lines = [];
        
        if ($transactionData['type'] === 'receipt') {
            // Receipt: Dr Cash / Cr Income or AR
            $lines[] = [
                'account_code' => '1110', // Cash on Hand
                'debit' => $transactionData['amount'],
                'credit' => 0,
                'description' => 'Cash receipt: ' . $transactionData['description'],
            ];
            
            // Credit side depends on source
            if ($transactionData['source_type'] === 'uisp_payment') {
                $lines[] = [
                    'account_code' => '1140', // Accounts Receivable
                    'debit' => 0,
                    'credit' => $transactionData['amount'],
                    'description' => 'Payment received',
                ];
            } else {
                $lines[] = [
                    'account_code' => '4200', // Other Revenue
                    'debit' => 0,
                    'credit' => $transactionData['amount'],
                    'description' => $transactionData['description'],
                ];
            }
        } else {
            // Payment: Dr Expense / Cr Cash
            $lines[] = [
                'account_code' => '6600', // Other Expenses (default)
                'debit' => $transactionData['amount'],
                'credit' => 0,
                'description' => $transactionData['description'],
            ];
            
            $lines[] = [
                'account_code' => '1110', // Cash on Hand
                'debit' => 0,
                'credit' => $transactionData['amount'],
                'description' => 'Cash payment',
            ];
        }
        
        $this->journalService->createEntry([
            'entry_date' => $transactionData['transaction_date'],
            'reference' => $transactionData['reference'] ?? 'CB-' . $transactionId,
            'description' => 'Cashbook: ' . $transactionData['description'],
            'source_type' => 'cashbook',
            'source_id' => $transactionId,
            'lines' => $lines,
            'auto_post' => true,
        ]);
    }
}
