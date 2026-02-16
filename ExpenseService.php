<?php

namespace IspErp\Services;

use IspErp\Repositories\ExpenseRepository;
use Exception;

/**
 * Expense Service
 * Manages expenses with approval workflow and posting
 */
class ExpenseService
{
    private $expenseRepo;
    private $journalService;
    private $cashbookService;
    
    public function __construct(
        ExpenseRepository $expenseRepo,
        JournalService $journalService,
        CashbookService $cashbookService
    ) {
        $this->expenseRepo = $expenseRepo;
        $this->journalService = $journalService;
        $this->cashbookService = $cashbookService;
    }
    
    /**
     * Create new expense
     */
    public function create(array $data): int
    {
        // Validate required fields
        $errors = validate_required($data, ['category_id', 'amount', 'expense_date', 'description']);
        if (!empty($errors)) {
            throw new Exception('Missing required fields: ' . implode(', ', array_keys($errors)));
        }
        
        // Validate amount
        if (!validate_amount($data['amount'])) {
            throw new Exception('Invalid amount');
        }
        
        // Calculate tax if provided
        $taxAmount = $data['tax_amount'] ?? 0;
        $totalAmount = $data['amount'] + $taxAmount;
        
        // Generate expense number
        $expenseNumber = $this->generateExpenseNumber();
        
        // Check if auto-approval applies
        $approvalThreshold = get_system_config('approval_threshold_amount', 500);
        $requiresApproval = get_system_config('approval_required_expenses', true);
        
        $status = 'pending';
        if (!$requiresApproval || $totalAmount < $approvalThreshold) {
            $status = 'approved';
        }
        
        $expenseData = [
            'expense_number' => $expenseNumber,
            'vendor_id' => $data['vendor_id'] ?? null,
            'category_id' => $data['category_id'],
            'amount' => $data['amount'],
            'tax_amount' => $taxAmount,
            'total_amount' => $totalAmount,
            'expense_date' => $data['expense_date'],
            'description' => $data['description'],
            'reference' => $data['reference'] ?? null,
            'payment_source' => $data['payment_source'] ?? 'cash',
            'payment_source_id' => $data['payment_source_id'] ?? null,
            'status' => $status,
            'submitted_by' => current_user()['id'],
        ];
        
        // Auto-approve if applicable
        if ($status === 'approved') {
            $expenseData['approved_by'] = current_user()['id'];
            $expenseData['approved_at'] = date('Y-m-d H:i:s');
        }
        
        $expenseId = $this->expenseRepo->create($expenseData);
        
        // Handle attachments
        if (!empty($data['attachments'])) {
            $this->saveAttachments($expenseId, $data['attachments']);
        }
        
        // If auto-approved and payment source specified, process payment
        if ($status === 'approved' && isset($data['payment_source'])) {
            $this->processPayment($expenseId);
        }
        
        audit_log('create', 'expense', $expenseId, $data);
        
        app()->log('info', "Expense created: $expenseNumber - {$data['amount']}");
        
        return $expenseId;
    }
    
    /**
     * Approve expense
     */
    public function approve(int $expenseId, string $comments = ''): bool
    {
        if (!has_permission('approve_expenses')) {
            throw new Exception('Insufficient permissions to approve expenses');
        }
        
        $expense = $this->expenseRepo->find($expenseId);
        
        if (!$expense) {
            throw new Exception('Expense not found');
        }
        
        if ($expense['status'] !== 'pending') {
            throw new Exception('Only pending expenses can be approved');
        }
        
        // Update expense status
        $this->expenseRepo->update($expenseId, [
            'status' => 'approved',
            'approved_by' => current_user()['id'],
            'approved_at' => date('Y-m-d H:i:s'),
        ]);
        
        // Log approval
        $this->logApproval($expenseId, 'approve', $comments);
        
        // Process payment if payment source specified
        if ($expense['payment_source']) {
            $this->processPayment($expenseId);
        }
        
        audit_log('approve', 'expense', $expenseId, ['comments' => $comments]);
        
        app()->log('info', "Expense approved: {$expense['expense_number']}");
        
        return true;
    }
    
    /**
     * Reject expense
     */
    public function reject(int $expenseId, string $reason): bool
    {
        if (!has_permission('approve_expenses')) {
            throw new Exception('Insufficient permissions to reject expenses');
        }
        
        $expense = $this->expenseRepo->find($expenseId);
        
        if (!$expense) {
            throw new Exception('Expense not found');
        }
        
        if ($expense['status'] !== 'pending') {
            throw new Exception('Only pending expenses can be rejected');
        }
        
        // Update expense status
        $this->expenseRepo->update($expenseId, [
            'status' => 'rejected',
        ]);
        
        // Log rejection
        $this->logApproval($expenseId, 'reject', $reason);
        
        audit_log('reject', 'expense', $expenseId, ['reason' => $reason]);
        
        app()->log('info', "Expense rejected: {$expense['expense_number']}");
        
        return true;
    }
    
    /**
     * Process payment for approved expense
     */
    public function processPayment(int $expenseId): bool
    {
        $expense = $this->expenseRepo->find($expenseId);
        
        if (!$expense) {
            throw new Exception('Expense not found');
        }
        
        if ($expense['status'] !== 'approved') {
            throw new Exception('Only approved expenses can be paid');
        }
        
        if ($expense['status'] === 'paid') {
            throw new Exception('Expense already paid');
        }
        
        db()->beginTransaction();
        
        try {
            // Get category for account mapping
            $category = $this->expenseRepo->getCategory($expense['category_id']);
            $expenseAccount = $category['account_id'] ?? null;
            
            // Create journal entry
            $lines = [];
            
            // Debit: Expense Account
            if ($expenseAccount) {
                $account = get_account($expenseAccount);
                $lines[] = [
                    'account_id' => $expenseAccount,
                    'debit' => $expense['amount'],
                    'credit' => 0,
                    'description' => $expense['description'],
                ];
            } else {
                // Default to general expenses
                $lines[] = [
                    'account_code' => '6600',
                    'debit' => $expense['amount'],
                    'credit' => 0,
                    'description' => $expense['description'],
                ];
            }
            
            // Handle tax
            if ($expense['tax_amount'] > 0) {
                $lines[] = [
                    'account_code' => '2140', // Tax Payable
                    'debit' => $expense['tax_amount'],
                    'credit' => 0,
                    'description' => 'Tax on ' . $expense['description'],
                ];
            }
            
            // Credit: Cash or Bank
            if ($expense['payment_source'] === 'cash') {
                $lines[] = [
                    'account_code' => '1110', // Cash on Hand
                    'debit' => 0,
                    'credit' => $expense['total_amount'],
                    'description' => 'Payment for ' . $expense['description'],
                ];
                
                // Record in cashbook
                $this->cashbookService->recordTransaction([
                    'transaction_date' => date('Y-m-d'),
                    'type' => 'payment',
                    'category' => $category['name'] ?? 'Expense',
                    'amount' => $expense['total_amount'],
                    'description' => $expense['description'],
                    'reference' => $expense['expense_number'],
                    'source_type' => 'expense',
                    'source_id' => $expenseId,
                    'skip_journal' => true, // Already creating journal here
                ]);
                
            } else {
                // Bank payment
                $bankAccountId = $expense['payment_source_id'];
                if (!$bankAccountId) {
                    throw new Exception('Bank account not specified');
                }
                
                $lines[] = [
                    'account_code' => '1120', // Bank Account (simplified)
                    'debit' => 0,
                    'credit' => $expense['total_amount'],
                    'description' => 'Bank payment for ' . $expense['description'],
                ];
                
                // Record bank transaction
                $this->recordBankTransaction($expenseId, $expense);
            }
            
            // Create journal entry
            $this->journalService->createEntry([
                'entry_date' => date('Y-m-d'),
                'reference' => $expense['expense_number'],
                'description' => 'Expense payment: ' . $expense['description'],
                'source_type' => 'expense',
                'source_id' => $expenseId,
                'lines' => $lines,
                'auto_post' => true,
            ]);
            
            // Update expense status
            $this->expenseRepo->update($expenseId, [
                'status' => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
            ]);
            
            db()->commit();
            
            audit_log('pay', 'expense', $expenseId);
            
            app()->log('info', "Expense paid: {$expense['expense_number']}");
            
            return true;
            
        } catch (Exception $e) {
            db()->rollBack();
            app()->log('error', "Failed to process expense payment: " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get expenses by status
     */
    public function getByStatus(string $status): array
    {
        return $this->expenseRepo->findByStatus($status);
    }
    
    /**
     * Get expenses for period
     */
    public function getByPeriod(string $fromDate, string $toDate, string $status = null): array
    {
        return $this->expenseRepo->findByPeriod($fromDate, $toDate, $status);
    }
    
    /**
     * Get expense with details
     */
    public function getExpense(int $expenseId): array
    {
        $expense = $this->expenseRepo->find($expenseId);
        
        if (!$expense) {
            throw new Exception('Expense not found');
        }
        
        // Get vendor if exists
        if ($expense['vendor_id']) {
            $expense['vendor'] = $this->expenseRepo->getVendor($expense['vendor_id']);
        }
        
        // Get category
        $expense['category'] = $this->expenseRepo->getCategory($expense['category_id']);
        
        // Get attachments
        $expense['attachments'] = $this->expenseRepo->getAttachments($expenseId);
        
        // Get approval history
        $expense['approval_history'] = $this->getApprovalHistory($expenseId);
        
        return $expense;
    }
    
    /**
     * Get expense summary
     */
    public function getSummary(string $fromDate = null, string $toDate = null): array
    {
        $fromDate = $fromDate ?? date('Y-m-01'); // Start of month
        $toDate = $toDate ?? date('Y-m-d');
        
        $expenses = $this->getByPeriod($fromDate, $toDate);
        
        $summary = [
            'total_expenses' => 0,
            'total_pending' => 0,
            'total_approved' => 0,
            'total_paid' => 0,
            'total_rejected' => 0,
            'count_pending' => 0,
            'count_approved' => 0,
            'count_paid' => 0,
            'count_rejected' => 0,
            'by_category' => [],
            'by_vendor' => [],
        ];
        
        foreach ($expenses as $expense) {
            $amount = $expense['total_amount'];
            
            $summary['total_expenses'] += $amount;
            
            switch ($expense['status']) {
                case 'pending':
                    $summary['total_pending'] += $amount;
                    $summary['count_pending']++;
                    break;
                case 'approved':
                    $summary['total_approved'] += $amount;
                    $summary['count_approved']++;
                    break;
                case 'paid':
                    $summary['total_paid'] += $amount;
                    $summary['count_paid']++;
                    break;
                case 'rejected':
                    $summary['total_rejected'] += $amount;
                    $summary['count_rejected']++;
                    break;
            }
            
            // By category
            $categoryName = $expense['category_name'] ?? 'Uncategorized';
            if (!isset($summary['by_category'][$categoryName])) {
                $summary['by_category'][$categoryName] = 0;
            }
            $summary['by_category'][$categoryName] += $amount;
            
            // By vendor
            if ($expense['vendor_id']) {
                $vendorName = $expense['vendor_name'] ?? 'Unknown';
                if (!isset($summary['by_vendor'][$vendorName])) {
                    $summary['by_vendor'][$vendorName] = 0;
                }
                $summary['by_vendor'][$vendorName] += $amount;
            }
        }
        
        return $summary;
    }
    
    /**
     * Generate expense number
     */
    private function generateExpenseNumber(): string
    {
        return generate_reference('EXP');
    }
    
    /**
     * Save expense attachments
     */
    private function saveAttachments(int $expenseId, array $files): void
    {
        foreach ($files as $file) {
            if (!is_allowed_file_type($file['name'])) {
                continue;
            }
            
            $filename = uniqid() . '_' . basename($file['name']);
            $filepath = get_upload_path($filename);
            
            if (move_uploaded_file($file['tmp_name'], $filepath)) {
                $this->expenseRepo->createAttachment([
                    'expense_id' => $expenseId,
                    'filename' => $filename,
                    'original_filename' => $file['name'],
                    'filepath' => $filepath,
                    'filesize' => $file['size'],
                    'mime_type' => $file['type'],
                ]);
            }
        }
    }
    
    /**
     * Log approval action
     */
    private function logApproval(int $expenseId, string $action, string $comments): void
    {
        $expense = $this->expenseRepo->find($expenseId);
        
        db()->prepare("
            INSERT INTO approval_logs (entity_type, entity_id, action, approver_id, previous_status, new_status, comments)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ")->execute([
            'expense',
            $expenseId,
            $action,
            current_user()['id'],
            $expense['status'],
            $action === 'approve' ? 'approved' : 'rejected',
            $comments
        ]);
    }
    
    /**
     * Get approval history
     */
    private function getApprovalHistory(int $expenseId): array
    {
        $stmt = db()->prepare("
            SELECT * FROM approval_logs
            WHERE entity_type = 'expense' AND entity_id = ?
            ORDER BY timestamp DESC
        ");
        $stmt->execute([$expenseId]);
        
        return $stmt->fetchAll();
    }
    
    /**
     * Record bank transaction for expense
     */
    private function recordBankTransaction(int $expenseId, array $expense): void
    {
        // This would integrate with BankService
        // Placeholder for now
        app()->log('info', "Bank transaction recorded for expense {$expense['expense_number']}");
    }
}
