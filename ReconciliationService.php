<?php

namespace IspErp\Services;

use IspErp\Repositories\BankRepository;
use IspErp\Repositories\PaymentRepository;
use Exception;

/**
 * Reconciliation Service
 * Auto-matches bank transactions with UISP payments
 */
class ReconciliationService
{
    private $bankRepo;
    private $paymentRepo;
    
    public function __construct(
        BankRepository $bankRepo,
        PaymentRepository $paymentRepo
    ) {
        $this->bankRepo = $bankRepo;
        $this->paymentRepo = $paymentRepo;
    }
    
    /**
     * Run automatic reconciliation
     */
    public function autoReconcile(int $bankAccountId = null): array
    {
        $results = [
            'total_processed' => 0,
            'auto_matched' => 0,
            'suggested_matches' => 0,
            'no_match' => 0,
            'errors' => 0,
        ];
        
        // Get unreconciled bank transactions
        $bankTransactions = $this->bankRepo->getUnreconciledTransactions($bankAccountId);
        
        foreach ($bankTransactions as $bankTxn) {
            $results['total_processed']++;
            
            try {
                $matches = $this->findMatches($bankTxn);
                
                if (empty($matches)) {
                    $results['no_match']++;
                    continue;
                }
                
                // Get best match
                $bestMatch = $matches[0];
                
                // Auto-match if confidence >= 90%
                if ($bestMatch['confidence'] >= 90) {
                    $this->confirmMatch(
                        $bankTxn['id'],
                        $bestMatch['payment_id'],
                        'auto',
                        $bestMatch['confidence']
                    );
                    $results['auto_matched']++;
                } else {
                    // Store as suggested match for manual review
                    $this->storeSuggestedMatch($bankTxn['id'], $matches);
                    $results['suggested_matches']++;
                }
                
            } catch (Exception $e) {
                $results['errors']++;
                app()->log('error', "Reconciliation error: " . $e->getMessage());
            }
        }
        
        app()->log('info', "Auto-reconciliation completed: {$results['auto_matched']} matched, {$results['suggested_matches']} suggested");
        
        return $results;
    }
    
    /**
     * Find potential matches for bank transaction
     */
    public function findMatches(array $bankTransaction): array
    {
        $matches = [];
        
        // Stage 1: Exact amount + date proximity (95% confidence)
        $exactMatches = $this->findExactAmountMatches($bankTransaction);
        foreach ($exactMatches as $match) {
            $matches[] = [
                'payment_id' => $match['id'],
                'payment' => $match,
                'confidence' => 95,
                'match_type' => 'exact_amount_date',
                'reason' => 'Exact amount match within date range',
            ];
        }
        
        // Stage 2: Reference number match (90% confidence)
        if ($bankTransaction['reference']) {
            $refMatches = $this->findReferenceMatches($bankTransaction);
            foreach ($refMatches as $match) {
                // Skip if already in matches
                if ($this->isAlreadyInMatches($matches, $match['id'])) {
                    continue;
                }
                
                $matches[] = [
                    'payment_id' => $match['id'],
                    'payment' => $match,
                    'confidence' => 90,
                    'match_type' => 'reference',
                    'reason' => 'Reference number matches',
                ];
            }
        }
        
        // Stage 3: Customer name fuzzy match (75% confidence)
        if ($bankTransaction['description']) {
            $nameMatches = $this->findNameMatches($bankTransaction);
            foreach ($nameMatches as $match) {
                if ($this->isAlreadyInMatches($matches, $match['id'])) {
                    continue;
                }
                
                $matches[] = [
                    'payment_id' => $match['id'],
                    'payment' => $match,
                    'confidence' => $match['similarity'],
                    'match_type' => 'fuzzy_name',
                    'reason' => 'Customer name similarity: ' . round($match['similarity']) . '%',
                ];
            }
        }
        
        // Sort by confidence descending
        usort($matches, function($a, $b) {
            return $b['confidence'] <=> $a['confidence'];
        });
        
        return $matches;
    }
    
    /**
     * Confirm a match (manual or auto)
     */
    public function confirmMatch(
        int $bankTransactionId,
        int $paymentId,
        string $matchType = 'manual',
        int $confidence = 100,
        string $notes = ''
    ): bool {
        // Validate both exist
        $bankTxn = $this->bankRepo->getTransaction($bankTransactionId);
        $payment = $this->paymentRepo->find($paymentId);
        
        if (!$bankTxn || !$payment) {
            throw new Exception('Bank transaction or payment not found');
        }
        
        // Check if already matched
        $existing = $this->getMatch($bankTransactionId);
        if ($existing && $existing['status'] === 'matched') {
            throw new Exception('Bank transaction already matched');
        }
        
        db()->beginTransaction();
        
        try {
            // Create match record
            $matchData = [
                'bank_transaction_id' => $bankTransactionId,
                'uisp_payment_id' => $paymentId,
                'match_type' => $matchType,
                'confidence_score' => $confidence,
                'status' => 'matched',
                'matched_by' => current_user()['id'],
                'notes' => $notes,
            ];
            
            if ($existing) {
                $this->updateMatch($existing['id'], $matchData);
            } else {
                $this->createMatch($matchData);
            }
            
            // Mark bank transaction as reconciled
            $this->bankRepo->updateTransaction($bankTransactionId, [
                'is_reconciled' => 1,
                'reconciled_at' => date('Y-m-d H:i:s'),
            ]);
            
            db()->commit();
            
            audit_log('reconcile', 'bank_transaction', $bankTransactionId, [
                'payment_id' => $paymentId,
                'match_type' => $matchType,
            ]);
            
            return true;
            
        } catch (Exception $e) {
            db()->rollBack();
            throw $e;
        }
    }
    
    /**
     * Unmatch a reconciliation
     */
    public function unmatch(int $bankTransactionId): bool
    {
        $match = $this->getMatch($bankTransactionId);
        
        if (!$match) {
            throw new Exception('No match found');
        }
        
        db()->beginTransaction();
        
        try {
            // Update match status
            $this->updateMatch($match['id'], [
                'status' => 'unmatched',
            ]);
            
            // Mark bank transaction as unreconciled
            $this->bankRepo->updateTransaction($bankTransactionId, [
                'is_reconciled' => 0,
                'reconciled_at' => null,
            ]);
            
            db()->commit();
            
            audit_log('unmatch', 'bank_transaction', $bankTransactionId);
            
            return true;
            
        } catch (Exception $e) {
            db()->rollBack();
            throw $e;
        }
    }
    
    /**
     * Get reconciliation status summary
     */
    public function getStatus(int $bankAccountId = null): array
    {
        $bankTxns = $this->bankRepo->getTransactions($bankAccountId);
        
        $total = count($bankTxns);
        $reconciled = 0;
        $unreconciled = 0;
        $suggested = 0;
        
        foreach ($bankTxns as $txn) {
            if ($txn['is_reconciled']) {
                $reconciled++;
            } else {
                $unreconciled++;
                
                // Check if has suggested match
                $match = $this->getMatch($txn['id']);
                if ($match && $match['status'] === 'suggested') {
                    $suggested++;
                }
            }
        }
        
        return [
            'total_transactions' => $total,
            'reconciled' => $reconciled,
            'unreconciled' => $unreconciled,
            'suggested_matches' => $suggested,
            'reconciliation_rate' => $total > 0 ? ($reconciled / $total) * 100 : 0,
        ];
    }
    
    /**
     * Get unreconciled transactions with suggested matches
     */
    public function getUnreconciledWithSuggestions(int $bankAccountId = null): array
    {
        $unreconciled = $this->bankRepo->getUnreconciledTransactions($bankAccountId);
        
        $result = [];
        foreach ($unreconciled as $txn) {
            $matches = $this->findMatches($txn);
            
            $result[] = [
                'bank_transaction' => $txn,
                'suggested_matches' => array_slice($matches, 0, 5), // Top 5 suggestions
                'has_suggestions' => !empty($matches),
            ];
        }
        
        return $result;
    }
    
    /**
     * Find exact amount matches within date range
     */
    private function findExactAmountMatches(array $bankTransaction): array
    {
        $amount = abs($bankTransaction['amount']);
        $date = $bankTransaction['transaction_date'];
        
        // Search within ±3 days
        $dateFrom = date('Y-m-d', strtotime($date . ' -3 days'));
        $dateTo = date('Y-m-d', strtotime($date . ' +3 days'));
        
        return $this->paymentRepo->findByAmountAndDateRange($amount, $dateFrom, $dateTo);
    }
    
    /**
     * Find reference number matches
     */
    private function findReferenceMatches(array $bankTransaction): array
    {
        $reference = $bankTransaction['reference'];
        
        if (empty($reference)) {
            return [];
        }
        
        return $this->paymentRepo->findByReference($reference);
    }
    
    /**
     * Find customer name fuzzy matches
     */
    private function findNameMatches(array $bankTransaction): array
    {
        $description = $bankTransaction['description'];
        
        if (empty($description)) {
            return [];
        }
        
        // Get all unmatched payments
        $payments = $this->paymentRepo->getUnmatched();
        
        $matches = [];
        foreach ($payments as $payment) {
            // Get customer name
            $customerName = $payment['customer_name'] ?? '';
            
            if (empty($customerName)) {
                continue;
            }
            
            // Calculate similarity
            $similarity = text_similarity($description, $customerName);
            
            // Only include if similarity > 75% and amount matches
            if ($similarity >= 75 && abs($payment['amount'] - abs($bankTransaction['amount'])) < 0.01) {
                $payment['similarity'] = $similarity;
                $matches[] = $payment;
            }
        }
        
        // Sort by similarity
        usort($matches, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        return $matches;
    }
    
    /**
     * Check if payment already in matches array
     */
    private function isAlreadyInMatches(array $matches, int $paymentId): bool
    {
        foreach ($matches as $match) {
            if ($match['payment_id'] === $paymentId) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Store suggested matches for manual review
     */
    private function storeSuggestedMatch(int $bankTransactionId, array $matches): void
    {
        // Store only the best match as suggested
        if (empty($matches)) {
            return;
        }
        
        $bestMatch = $matches[0];
        
        $matchData = [
            'bank_transaction_id' => $bankTransactionId,
            'uisp_payment_id' => $bestMatch['payment_id'],
            'match_type' => 'suggested',
            'confidence_score' => $bestMatch['confidence'],
            'status' => 'suggested',
            'notes' => $bestMatch['reason'],
        ];
        
        // Check if already exists
        $existing = $this->getMatch($bankTransactionId);
        
        if ($existing) {
            $this->updateMatch($existing['id'], $matchData);
        } else {
            $this->createMatch($matchData);
        }
    }
    
    /**
     * Create match record
     */
    private function createMatch(array $data): int
    {
        $stmt = db()->prepare("
            INSERT INTO reconciliation_matches 
            (bank_transaction_id, uisp_payment_id, match_type, confidence_score, status, matched_by, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $data['bank_transaction_id'],
            $data['uisp_payment_id'],
            $data['match_type'],
            $data['confidence_score'],
            $data['status'],
            $data['matched_by'] ?? null,
            $data['notes'] ?? null,
        ]);
        
        return (int)db()->lastInsertId();
    }
    
    /**
     * Update match record
     */
    private function updateMatch(int $matchId, array $data): bool
    {
        $sets = [];
        $values = [];
        
        foreach ($data as $key => $value) {
            $sets[] = "$key = ?";
            $values[] = $value;
        }
        
        $values[] = $matchId;
        
        $sql = "UPDATE reconciliation_matches SET " . implode(', ', $sets) . " WHERE id = ?";
        $stmt = db()->prepare($sql);
        
        return $stmt->execute($values);
    }
    
    /**
     * Get match for bank transaction
     */
    private function getMatch(int $bankTransactionId): ?array
    {
        $stmt = db()->prepare("
            SELECT * FROM reconciliation_matches 
            WHERE bank_transaction_id = ? 
            ORDER BY matched_at DESC 
            LIMIT 1
        ");
        
        $stmt->execute([$bankTransactionId]);
        
        return $stmt->fetch() ?: null;
    }
}
