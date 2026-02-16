<?php

namespace IspErp\Services;

use IspErp\Repositories\JournalRepository;
use IspErp\Repositories\AccountRepository;
use Exception;

/**
 * Journal Service
 * Handles double-entry journal posting and general ledger operations
 */
class JournalService
{
    private $journalRepo;
    private $accountRepo;
    private $currentEntry;
    
    public function __construct(
        JournalRepository $journalRepo,
        AccountRepository $accountRepo
    ) {
        $this->journalRepo = $journalRepo;
        $this->accountRepo = $accountRepo;
    }
    
    /**
     * Create a new journal entry
     */
    public function createEntry(array $data): int
    {
        // Validate required fields
        $errors = validate_required($data, ['entry_date', 'description', 'lines']);
        if (!empty($errors)) {
            throw new Exception('Missing required fields: ' . implode(', ', array_keys($errors)));
        }
        
        // Validate lines balance
        if (!$this->validateBalance($data['lines'])) {
            throw new Exception('Journal entry does not balance. Debits must equal credits.');
        }
        
        // Generate entry number
        $entryNumber = $data['entry_number'] ?? $this->generateEntryNumber();
        
        // Create journal entry header
        $entryData = [
            'entry_number' => $entryNumber,
            'entry_date' => $data['entry_date'],
            'reference' => $data['reference'] ?? null,
            'description' => $data['description'],
            'source_type' => $data['source_type'] ?? 'manual',
            'source_id' => $data['source_id'] ?? null,
            'status' => 'draft',
            'created_by' => current_user()['id'],
        ];
        
        $entryId = $this->journalRepo->createEntry($entryData);
        
        // Create journal lines
        $lineNumber = 1;
        foreach ($data['lines'] as $line) {
            // Get account
            $account = null;
            if (isset($line['account_id'])) {
                $account = $this->accountRepo->find($line['account_id']);
            } elseif (isset($line['account_code'])) {
                $account = $this->accountRepo->findByCode($line['account_code']);
            }
            
            if (!$account) {
                throw new Exception('Invalid account in line ' . $lineNumber);
            }
            
            $lineData = [
                'entry_id' => $entryId,
                'line_number' => $lineNumber,
                'account_id' => $account['id'],
                'debit' => $line['debit'] ?? 0,
                'credit' => $line['credit'] ?? 0,
                'description' => $line['description'] ?? $data['description'],
            ];
            
            $this->journalRepo->createLine($lineData);
            $lineNumber++;
        }
        
        $this->currentEntry = $entryId;
        
        // Auto-post if requested
        if ($data['auto_post'] ?? false) {
            $this->postEntry($entryId);
        }
        
        audit_log('create', 'journal_entry', $entryId, $data);
        
        return $entryId;
    }
    
    /**
     * Add a line to current entry
     */
    public function addLine(array $lineData): void
    {
        if (!$this->currentEntry) {
            throw new Exception('No current entry. Create an entry first.');
        }
        
        // Get account
        $account = null;
        if (isset($lineData['account_id'])) {
            $account = $this->accountRepo->find($lineData['account_id']);
        } elseif (isset($lineData['account_code'])) {
            $account = $this->accountRepo->findByCode($lineData['account_code']);
        }
        
        if (!$account) {
            throw new Exception('Invalid account');
        }
        
        // Get next line number
        $lines = $this->journalRepo->getLines($this->currentEntry);
        $lineNumber = count($lines) + 1;
        
        $data = [
            'entry_id' => $this->currentEntry,
            'line_number' => $lineNumber,
            'account_id' => $account['id'],
            'debit' => $lineData['debit'] ?? 0,
            'credit' => $lineData['credit'] ?? 0,
            'description' => $lineData['description'] ?? '',
        ];
        
        $this->journalRepo->createLine($data);
    }
    
    /**
     * Post journal entry to ledger
     */
    public function postEntry(int $entryId = null): bool
    {
        $entryId = $entryId ?? $this->currentEntry;
        
        if (!$entryId) {
            throw new Exception('No entry to post');
        }
        
        $entry = $this->journalRepo->find($entryId);
        
        if (!$entry) {
            throw new Exception('Entry not found');
        }
        
        if ($entry['status'] === 'posted') {
            throw new Exception('Entry already posted');
        }
        
        // Get lines
        $lines = $this->journalRepo->getLines($entryId);
        
        if (empty($lines)) {
            throw new Exception('Entry has no lines');
        }
        
        // Validate balance
        if (!$this->validateBalance($lines)) {
            throw new Exception('Entry does not balance');
        }
        
        // Start transaction
        db()->beginTransaction();
        
        try {
            // Update entry status
            $this->journalRepo->updateEntry($entryId, [
                'status' => 'posted',
                'posted_at' => date('Y-m-d H:i:s'),
                'posted_by' => current_user()['id'],
            ]);
            
            // Update account balances
            foreach ($lines as $line) {
                $account = $this->accountRepo->find($line['account_id']);
                
                $debitAmount = (float)$line['debit'];
                $creditAmount = (float)$line['credit'];
                
                // Calculate new balance based on account type
                $currentBalance = (float)$account['balance'];
                $newBalance = $currentBalance;
                
                if (is_debit_account($account['type'])) {
                    // Assets and Expenses: Debit increases, Credit decreases
                    $newBalance += $debitAmount - $creditAmount;
                } else {
                    // Liabilities, Equity, Revenue: Credit increases, Debit decreases
                    $newBalance += $creditAmount - $debitAmount;
                }
                
                $this->accountRepo->update($line['account_id'], [
                    'balance' => $newBalance
                ]);
            }
            
            db()->commit();
            
            audit_log('post', 'journal_entry', $entryId);
            
            app()->log('info', "Journal entry {$entry['entry_number']} posted successfully");
            
            return true;
            
        } catch (Exception $e) {
            db()->rollBack();
            app()->log('error', "Failed to post journal entry: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Reverse a posted journal entry
     */
    public function reverseEntry(int $entryId, string $reason = ''): int
    {
        $entry = $this->journalRepo->find($entryId);
        
        if (!$entry) {
            throw new Exception('Entry not found');
        }
        
        if ($entry['status'] !== 'posted') {
            throw new Exception('Can only reverse posted entries');
        }
        
        // Get original lines
        $originalLines = $this->journalRepo->getLines($entryId);
        
        // Create reversal entry
        $reversalLines = [];
        foreach ($originalLines as $line) {
            $reversalLines[] = [
                'account_id' => $line['account_id'],
                'debit' => $line['credit'], // Swap debit and credit
                'credit' => $line['debit'],
                'description' => 'REVERSAL: ' . $line['description'],
            ];
        }
        
        $reversalData = [
            'entry_date' => date('Y-m-d'),
            'reference' => 'REV-' . $entry['entry_number'],
            'description' => 'REVERSAL: ' . $entry['description'] . ' - ' . $reason,
            'source_type' => 'reversal',
            'source_id' => $entryId,
            'lines' => $reversalLines,
            'auto_post' => true,
        ];
        
        $reversalId = $this->createEntry($reversalData);
        
        // Update original entry status
        $this->journalRepo->updateEntry($entryId, [
            'status' => 'reversed'
        ]);
        
        audit_log('reverse', 'journal_entry', $entryId, ['reversal_id' => $reversalId, 'reason' => $reason]);
        
        return $reversalId;
    }
    
    /**
     * Get trial balance
     */
    public function getTrialBalance(string $asOfDate = null): array
    {
        $asOfDate = $asOfDate ?? date('Y-m-d');
        
        $accounts = $this->accountRepo->findAll();
        $trial = [];
        
        foreach ($accounts as $account) {
            // Get balance as of date
            $balance = $this->accountRepo->getBalanceAsOf($account['id'], $asOfDate);
            
            if ($balance != 0) {
                $trial[] = [
                    'code' => $account['code'],
                    'name' => $account['name'],
                    'type' => $account['type'],
                    'debit' => is_debit_account($account['type']) && $balance > 0 ? $balance : 0,
                    'credit' => !is_debit_account($account['type']) && $balance > 0 ? $balance : 0,
                    'balance' => $balance,
                ];
            }
        }
        
        // Calculate totals
        $totalDebits = array_sum(array_column($trial, 'debit'));
        $totalCredits = array_sum(array_column($trial, 'credit'));
        
        return [
            'as_of_date' => $asOfDate,
            'accounts' => $trial,
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'difference' => abs($totalDebits - $totalCredits),
            'is_balanced' => abs($totalDebits - $totalCredits) < 0.01, // Allow 1 cent rounding
        ];
    }
    
    /**
     * Get account ledger (detailed transactions)
     */
    public function getAccountLedger(int $accountId, string $fromDate = null, string $toDate = null): array
    {
        $fromDate = $fromDate ?? date('Y-01-01'); // Start of year
        $toDate = $toDate ?? date('Y-m-d'); // Today
        
        $account = $this->accountRepo->find($accountId);
        
        if (!$account) {
            throw new Exception('Account not found');
        }
        
        // Get opening balance
        $openingBalance = $this->accountRepo->getBalanceAsOf($accountId, date('Y-m-d', strtotime($fromDate . ' -1 day')));
        
        // Get transactions
        $transactions = $this->journalRepo->getAccountTransactions($accountId, $fromDate, $toDate);
        
        $ledger = [];
        $runningBalance = $openingBalance;
        
        foreach ($transactions as $txn) {
            $debit = (float)$txn['debit'];
            $credit = (float)$txn['credit'];
            
            // Calculate running balance
            if (is_debit_account($account['type'])) {
                $runningBalance += $debit - $credit;
            } else {
                $runningBalance += $credit - $debit;
            }
            
            $ledger[] = [
                'date' => $txn['entry_date'],
                'entry_number' => $txn['entry_number'],
                'description' => $txn['description'],
                'reference' => $txn['reference'],
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $runningBalance,
            ];
        }
        
        return [
            'account' => $account,
            'period' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'opening_balance' => $openingBalance,
            'closing_balance' => $runningBalance,
            'transactions' => $ledger,
        ];
    }
    
    /**
     * Validate journal entry balance (Debits = Credits)
     */
    private function validateBalance(array $lines): bool
    {
        $totalDebits = 0;
        $totalCredits = 0;
        
        foreach ($lines as $line) {
            $totalDebits += (float)($line['debit'] ?? 0);
            $totalCredits += (float)($line['credit'] ?? 0);
        }
        
        // Allow 1 cent difference for rounding
        return abs($totalDebits - $totalCredits) < 0.01;
    }
    
    /**
     * Generate unique entry number
     */
    private function generateEntryNumber(): string
    {
        $prefix = 'JE';
        $date = date('Ymd');
        
        // Get last entry number for today
        $lastEntry = $this->journalRepo->getLastEntryNumber($date);
        
        if ($lastEntry) {
            // Extract sequence number and increment
            $parts = explode('-', $lastEntry);
            $sequence = isset($parts[2]) ? (int)$parts[2] + 1 : 1;
        } else {
            $sequence = 1;
        }
        
        return sprintf('%s-%s-%04d', $prefix, $date, $sequence);
    }
    
    /**
     * Get journal entry with lines
     */
    public function getEntry(int $entryId): array
    {
        $entry = $this->journalRepo->find($entryId);
        
        if (!$entry) {
            throw new Exception('Entry not found');
        }
        
        $entry['lines'] = $this->journalRepo->getLines($entryId);
        
        return $entry;
    }
    
    /**
     * Get all entries for a period
     */
    public function getEntries(string $fromDate = null, string $toDate = null, string $status = null): array
    {
        return $this->journalRepo->findByPeriod($fromDate, $toDate, $status);
    }
}
