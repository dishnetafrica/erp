<?php

namespace IspErp\Services;

use IspErp\Repositories\BankRepository;
use Exception;

/**
 * Bank Service
 * Manages bank accounts, transactions, and statement imports
 */
class BankService
{
    private $bankRepo;
    private $journalService;
    
    public function __construct(
        BankRepository $bankRepo,
        JournalService $journalService
    ) {
        $this->bankRepo = $bankRepo;
        $this->journalService = $journalService;
    }
    
    /**
     * Create bank account
     */
    public function createAccount(array $data): int
    {
        $errors = validate_required($data, ['name', 'account_type']);
        if (!empty($errors)) {
            throw new Exception('Missing required fields');
        }
        
        $accountData = [
            'name' => $data['name'],
            'bank_name' => $data['bank_name'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'account_type' => $data['account_type'],
            'currency' => $data['currency'] ?? 'USD',
            'opening_balance' => $data['opening_balance'] ?? 0,
            'current_balance' => $data['opening_balance'] ?? 0,
            'is_active' => 1,
        ];
        
        $accountId = $this->bankRepo->createAccount($accountData);
        
        // Create opening balance journal entry if > 0
        if ($accountData['opening_balance'] != 0) {
            $this->createOpeningBalanceEntry($accountId, $accountData);
        }
        
        audit_log('create', 'bank_account', $accountId, $data);
        
        return $accountId;
    }
    
    /**
     * Record bank transaction
     */
    public function recordTransaction(array $data): int
    {
        $errors = validate_required($data, ['account_id', 'transaction_date', 'type', 'amount']);
        if (!empty($errors)) {
            throw new Exception('Missing required fields');
        }
        
        $account = $this->bankRepo->getAccount($data['account_id']);
        if (!$account) {
            throw new Exception('Bank account not found');
        }
        
        // Calculate new balance
        $currentBalance = $account['current_balance'];
        if ($data['type'] === 'credit') {
            $newBalance = $currentBalance + $data['amount'];
        } else {
            $newBalance = $currentBalance - $data['amount'];
        }
        
        db()->beginTransaction();
        
        try {
            // Create transaction
            $txnData = [
                'account_id' => $data['account_id'],
                'transaction_date' => $data['transaction_date'],
                'value_date' => $data['value_date'] ?? $data['transaction_date'],
                'type' => $data['type'],
                'amount' => $data['amount'],
                'description' => $data['description'] ?? '',
                'reference' => $data['reference'] ?? null,
                'statement_ref' => $data['statement_ref'] ?? null,
                'balance_after' => $newBalance,
                'source_type' => $data['source_type'] ?? 'manual',
                'source_id' => $data['source_id'] ?? null,
            ];
            
            $txnId = $this->bankRepo->createTransaction($txnData);
            
            // Update account balance
            $this->bankRepo->updateAccount($data['account_id'], [
                'current_balance' => $newBalance
            ]);
            
            // Create journal entry
            if (!isset($data['skip_journal']) || !$data['skip_journal']) {
                $this->createTransactionJournalEntry($txnId, $txnData, $account);
            }
            
            db()->commit();
            
            audit_log('create', 'bank_transaction', $txnId, $data);
            
            return $txnId;
            
        } catch (Exception $e) {
            db()->rollBack();
            throw $e;
        }
    }
    
    /**
     * Record internal transfer between accounts
     */
    public function recordTransfer(int $fromAccountId, int $toAccountId, float $amount, string $date, string $description = ''): array
    {
        if ($amount <= 0) {
            throw new Exception('Transfer amount must be positive');
        }
        
        $fromAccount = $this->bankRepo->getAccount($fromAccountId);
        $toAccount = $this->bankRepo->getAccount($toAccountId);
        
        if (!$fromAccount || !$toAccount) {
            throw new Exception('Bank account not found');
        }
        
        db()->beginTransaction();
        
        try {
            // Debit from source account
            $debitTxnId = $this->recordTransaction([
                'account_id' => $fromAccountId,
                'transaction_date' => $date,
                'type' => 'debit',
                'amount' => $amount,
                'description' => 'Transfer to ' . $toAccount['name'] . ': ' . $description,
                'source_type' => 'transfer',
                'skip_journal' => true, // Create single combined journal
            ]);
            
            // Credit to destination account
            $creditTxnId = $this->recordTransaction([
                'account_id' => $toAccountId,
                'transaction_date' => $date,
                'type' => 'credit',
                'amount' => $amount,
                'description' => 'Transfer from ' . $fromAccount['name'] . ': ' . $description,
                'source_type' => 'transfer',
                'source_id' => $debitTxnId,
                'skip_journal' => true,
            ]);
            
            // Create combined journal entry
            $this->journalService->createEntry([
                'entry_date' => $date,
                'reference' => "XFER-$debitTxnId-$creditTxnId",
                'description' => "Bank transfer: {$fromAccount['name']} to {$toAccount['name']} - $description",
                'source_type' => 'bank_transfer',
                'source_id' => $debitTxnId,
                'lines' => [
                    [
                        'account_code' => '1120', // Destination bank
                        'debit' => $amount,
                        'credit' => 0,
                        'description' => 'Transfer received',
                    ],
                    [
                        'account_code' => '1120', // Source bank
                        'debit' => 0,
                        'credit' => $amount,
                        'description' => 'Transfer sent',
                    ],
                ],
                'auto_post' => true,
            ]);
            
            db()->commit();
            
            audit_log('transfer', 'bank_account', $fromAccountId, [
                'to_account' => $toAccountId,
                'amount' => $amount,
            ]);
            
            return [
                'debit_transaction_id' => $debitTxnId,
                'credit_transaction_id' => $creditTxnId,
            ];
            
        } catch (Exception $e) {
            db()->rollBack();
            throw $e;
        }
    }
    
    /**
     * Import bank statement from CSV
     */
    public function importStatement(int $accountId, string $filepath, array $options = []): array
    {
        $account = $this->bankRepo->getAccount($accountId);
        if (!$account) {
            throw new Exception('Bank account not found');
        }
        
        if (!file_exists($filepath)) {
            throw new Exception('Statement file not found');
        }
        
        $results = [
            'total_rows' => 0,
            'imported' => 0,
            'duplicates' => 0,
            'errors' => 0,
            'opening_balance' => null,
            'closing_balance' => null,
        ];
        
        db()->beginTransaction();
        
        try {
            $handle = fopen($filepath, 'r');
            
            // Skip header if specified
            if ($options['has_header'] ?? true) {
                fgetcsv($handle);
            }
            
            $statementDate = $options['statement_date'] ?? date('Y-m-d');
            
            while (($row = fgetcsv($handle)) !== false) {
                $results['total_rows']++;
                
                try {
                    // Map CSV columns (configurable per bank)
                    $dateCol = $options['date_column'] ?? 0;
                    $descCol = $options['description_column'] ?? 1;
                    $amountCol = $options['amount_column'] ?? 2;
                    $balanceCol = $options['balance_column'] ?? 3;
                    
                    $date = $this->parseDate($row[$dateCol]);
                    $description = trim($row[$descCol]);
                    $amount = $this->parseAmount($row[$amountCol]);
                    $balance = isset($row[$balanceCol]) ? $this->parseAmount($row[$balanceCol]) : null;
                    
                    // Determine transaction type
                    $type = $amount >= 0 ? 'credit' : 'debit';
                    $amount = abs($amount);
                    
                    // Check for duplicates
                    if ($this->isDuplicateTransaction($accountId, $date, $amount, $description)) {
                        $results['duplicates']++;
                        continue;
                    }
                    
                    // Import transaction
                    $this->recordTransaction([
                        'account_id' => $accountId,
                        'transaction_date' => $date,
                        'type' => $type,
                        'amount' => $amount,
                        'description' => $description,
                        'statement_ref' => $results['total_rows'],
                        'source_type' => 'import',
                    ]);
                    
                    $results['imported']++;
                    
                    // Track opening/closing balance
                    if ($balance !== null) {
                        if ($results['opening_balance'] === null) {
                            $results['opening_balance'] = $balance;
                        }
                        $results['closing_balance'] = $balance;
                    }
                    
                } catch (Exception $e) {
                    $results['errors']++;
                    app()->log('error', "Statement import row error: " . $e->getMessage());
                }
            }
            
            fclose($handle);
            
            // Create statement record
            $this->bankRepo->createStatement([
                'account_id' => $accountId,
                'statement_date' => $statementDate,
                'opening_balance' => $results['opening_balance'],
                'closing_balance' => $results['closing_balance'],
                'filename' => basename($filepath),
            ]);
            
            db()->commit();
            
            app()->log('info', "Statement imported: {$results['imported']} transactions");
            
            return $results;
            
        } catch (Exception $e) {
            db()->rollBack();
            app()->log('error', "Statement import failed: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get account balance
     */
    public function getBalance(int $accountId, string $asOfDate = null): float
    {
        $account = $this->bankRepo->getAccount($accountId);
        if (!$account) {
            throw new Exception('Bank account not found');
        }
        
        if ($asOfDate === null) {
            return $account['current_balance'];
        }
        
        return $this->bankRepo->getBalanceAsOf($accountId, $asOfDate);
    }
    
    /**
     * Get account transactions
     */
    public function getTransactions(int $accountId, string $fromDate = null, string $toDate = null): array
    {
        $fromDate = $fromDate ?? date('Y-m-d', strtotime('-30 days'));
        $toDate = $toDate ?? date('Y-m-d');
        
        return $this->bankRepo->getAccountTransactions($accountId, $fromDate, $toDate);
    }
    
    /**
     * Get bank statement summary
     */
    public function getStatementSummary(int $accountId, string $fromDate, string $toDate): array
    {
        $openingBalance = $this->getBalance($accountId, date('Y-m-d', strtotime($fromDate . ' -1 day')));
        $transactions = $this->getTransactions($accountId, $fromDate, $toDate);
        
        $totalDebits = 0;
        $totalCredits = 0;
        
        foreach ($transactions as $txn) {
            if ($txn['type'] === 'debit') {
                $totalDebits += $txn['amount'];
            } else {
                $totalCredits += $txn['amount'];
            }
        }
        
        $closingBalance = $openingBalance + $totalCredits - $totalDebits;
        
        return [
            'period' => ['from' => $fromDate, 'to' => $toDate],
            'opening_balance' => $openingBalance,
            'total_credits' => $totalCredits,
            'total_debits' => $totalDebits,
            'closing_balance' => $closingBalance,
            'transaction_count' => count($transactions),
            'transactions' => $transactions,
        ];
    }
    
    /**
     * Parse date from various formats
     */
    private function parseDate(string $date): string
    {
        $formats = ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'm-d-Y'];
        
        foreach ($formats as $format) {
            $parsed = date_create_from_format($format, $date);
            if ($parsed) {
                return $parsed->format('Y-m-d');
            }
        }
        
        // Try strtotime as fallback
        $timestamp = strtotime($date);
        if ($timestamp) {
            return date('Y-m-d', $timestamp);
        }
        
        throw new Exception("Unable to parse date: $date");
    }
    
    /**
     * Parse amount from string
     */
    private function parseAmount(string $amount): float
    {
        // Remove currency symbols, spaces, commas
        $amount = str_replace(['$', '€', '£', ' ', ','], '', $amount);
        
        // Handle brackets for negative (accounting format)
        if (strpos($amount, '(') !== false) {
            $amount = '-' . str_replace(['(', ')'], '', $amount);
        }
        
        return (float)$amount;
    }
    
    /**
     * Check for duplicate transaction
     */
    private function isDuplicateTransaction(int $accountId, string $date, float $amount, string $description): bool
    {
        return $this->bankRepo->findDuplicate($accountId, $date, $amount, $description) !== null;
    }
    
    /**
     * Create opening balance journal entry
     */
    private function createOpeningBalanceEntry(int $accountId, array $accountData): void
    {
        $amount = $accountData['opening_balance'];
        
        $this->journalService->createEntry([
            'entry_date' => date('Y-m-d'),
            'reference' => 'OB-BANK-' . $accountId,
            'description' => 'Opening balance: ' . $accountData['name'],
            'source_type' => 'opening_balance',
            'source_id' => $accountId,
            'lines' => [
                [
                    'account_code' => '1120', // Bank Account
                    'debit' => $amount > 0 ? $amount : 0,
                    'credit' => $amount < 0 ? abs($amount) : 0,
                    'description' => 'Opening balance',
                ],
                [
                    'account_code' => '3200', // Retained Earnings
                    'debit' => $amount < 0 ? abs($amount) : 0,
                    'credit' => $amount > 0 ? $amount : 0,
                    'description' => 'Opening balance equity',
                ],
            ],
            'auto_post' => true,
        ]);
    }
    
    /**
     * Create journal entry for bank transaction
     */
    private function createTransactionJournalEntry(int $txnId, array $txnData, array $account): void
    {
        $lines = [];
        
        if ($txnData['type'] === 'credit') {
            // Credit: Dr Bank / Cr Income/AR
            $lines[] = [
                'account_code' => '1120',
                'debit' => $txnData['amount'],
                'credit' => 0,
                'description' => $txnData['description'],
            ];
            $lines[] = [
                'account_code' => '4200', // Other Revenue (default)
                'debit' => 0,
                'credit' => $txnData['amount'],
                'description' => $txnData['description'],
            ];
        } else {
            // Debit: Dr Expense / Cr Bank
            $lines[] = [
                'account_code' => '6600', // Other Expenses (default)
                'debit' => $txnData['amount'],
                'credit' => 0,
                'description' => $txnData['description'],
            ];
            $lines[] = [
                'account_code' => '1120',
                'debit' => 0,
                'credit' => $txnData['amount'],
                'description' => $txnData['description'],
            ];
        }
        
        $this->journalService->createEntry([
            'entry_date' => $txnData['transaction_date'],
            'reference' => 'BANK-' . $txnId,
            'description' => 'Bank transaction: ' . $account['name'],
            'source_type' => 'bank_transaction',
            'source_id' => $txnId,
            'lines' => $lines,
            'auto_post' => true,
        ]);
    }
}
