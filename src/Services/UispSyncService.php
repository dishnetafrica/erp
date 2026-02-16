<?php

namespace IspErp\Services;

use IspErp\Repositories\CustomerRepository;
use IspErp\Repositories\InvoiceRepository;
use IspErp\Repositories\PaymentRepository;
use Exception;

/**
 * UISP Sync Service
 * Handles all synchronization with UISP/UCRM
 * READ ONLY - Never modifies UISP data
 */
class UispSyncService
{
    private $invoiceRepo;
    private $paymentRepo;
    private $customerRepo;
    private $uispUrl;
    private $apiToken;
    
    public function __construct(
        InvoiceRepository $invoiceRepo,
        PaymentRepository $paymentRepo,
        CustomerRepository $customerRepo
    ) {
        $this->invoiceRepo = $invoiceRepo;
        $this->paymentRepo = $paymentRepo;
        $this->customerRepo = $customerRepo;
        
        // Get UISP credentials from environment
        $this->uispUrl = getenv('UCRM_PUBLIC_URL');
        $this->apiToken = getenv('PLUGIN_APP_KEY');
    }
    
    /**
     * Sync all UISP data (customers, invoices, payments)
     */
    public function syncAll(): array
    {
        $results = [
            'customers' => ['processed' => 0, 'new' => 0, 'updated' => 0, 'errors' => 0],
            'invoices' => ['processed' => 0, 'new' => 0, 'updated' => 0, 'errors' => 0],
            'payments' => ['processed' => 0, 'new' => 0, 'updated' => 0, 'errors' => 0],
        ];
        
        try {
            $results['customers'] = $this->syncCustomers();
            $results['invoices'] = $this->syncInvoices();
            $results['payments'] = $this->syncPayments();
        } catch (Exception $e) {
            app()->log('error', 'Sync failed: ' . $e->getMessage());
            throw $e;
        }
        
        return $results;
    }
    
    /**
     * Sync customers from UISP
     */
    public function syncCustomers(): array
    {
        $stats = ['processed' => 0, 'new' => 0, 'updated' => 0, 'errors' => 0];
        
        try {
            $customers = $this->fetchFromUisp('/clients');
            
            foreach ($customers as $uispCustomer) {
                $stats['processed']++;
                
                $existing = $this->customerRepo->findByUispId($uispCustomer['id']);
                
                $customerData = [
                    'uisp_id' => $uispCustomer['id'],
                    'name' => $uispCustomer['firstName'] . ' ' . $uispCustomer['lastName'],
                    'email' => $uispCustomer['contacts'][0]['email'] ?? null,
                    'phone' => $uispCustomer['contacts'][0]['phone'] ?? null,
                    'address' => $this->formatAddress($uispCustomer),
                    'status' => $uispCustomer['isArchived'] ? 'inactive' : 'active',
                    'synced_at' => date('Y-m-d H:i:s'),
                ];
                
                if ($existing) {
                    $this->customerRepo->update($existing['id'], $customerData);
                    $stats['updated']++;
                } else {
                    $this->customerRepo->create($customerData);
                    $stats['new']++;
                }
            }
            
        } catch (Exception $e) {
            $stats['errors']++;
            app()->log('error', 'Customer sync error: ' . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Sync invoices from UISP
     */
    public function syncInvoices(string $since = null): array
    {
        $stats = ['processed' => 0, 'new' => 0, 'updated' => 0, 'errors' => 0];
        
        try {
            // Build query parameters
            $params = [];
            if ($since) {
                $params['createdDateFrom'] = $since;
            }
            
            $invoices = $this->fetchFromUisp('/invoices', $params);
            
            foreach ($invoices as $uispInvoice) {
                $stats['processed']++;
                
                try {
                    $existing = $this->invoiceRepo->findByUispId($uispInvoice['id']);
                    
                    // Get or create customer
                    $customer = $this->customerRepo->findByUispId($uispInvoice['clientId']);
                    if (!$customer) {
                        // Customer not synced yet, skip for now
                        continue;
                    }
                    
                    $invoiceData = [
                        'uisp_id' => $uispInvoice['id'],
                        'customer_id' => $customer['id'],
                        'invoice_number' => $uispInvoice['number'],
                        'amount' => $uispInvoice['subtotal'],
                        'tax_amount' => $uispInvoice['taxAmount'],
                        'total_amount' => $uispInvoice['total'],
                        'invoice_date' => $uispInvoice['createdDate'],
                        'due_date' => $uispInvoice['dueDate'],
                        'status' => $this->mapInvoiceStatus($uispInvoice['status']),
                        'notes' => $uispInvoice['notes'] ?? null,
                        'synced_at' => date('Y-m-d H:i:s'),
                    ];
                    
                    if ($existing) {
                        $this->invoiceRepo->update($existing['id'], $invoiceData);
                        $stats['updated']++;
                    } else {
                        $invoiceId = $this->invoiceRepo->create($invoiceData);
                        $stats['new']++;
                        
                        // Auto-create journal entry for new invoice
                        $this->createInvoiceJournalEntry($invoiceId, $invoiceData);
                    }
                    
                } catch (Exception $e) {
                    $stats['errors']++;
                    app()->log('error', 'Invoice sync error for ID ' . $uispInvoice['id'] . ': ' . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            $stats['errors']++;
            app()->log('error', 'Invoice sync failed: ' . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Sync payments from UISP
     */
    public function syncPayments(string $since = null): array
    {
        $stats = ['processed' => 0, 'new' => 0, 'updated' => 0, 'errors' => 0];
        
        try {
            $params = [];
            if ($since) {
                $params['createdDateFrom'] = $since;
            }
            
            $payments = $this->fetchFromUisp('/payments', $params);
            
            foreach ($payments as $uispPayment) {
                $stats['processed']++;
                
                try {
                    $existing = $this->paymentRepo->findByUispId($uispPayment['id']);
                    
                    $customer = $this->customerRepo->findByUispId($uispPayment['clientId']);
                    if (!$customer) {
                        continue;
                    }
                    
                    // Find related invoice
                    $invoice = null;
                    if (!empty($uispPayment['invoiceId'])) {
                        $invoice = $this->invoiceRepo->findByUispId($uispPayment['invoiceId']);
                    }
                    
                    $paymentData = [
                        'uisp_id' => $uispPayment['id'],
                        'customer_id' => $customer['id'],
                        'invoice_id' => $invoice['id'] ?? null,
                        'amount' => $uispPayment['amount'],
                        'payment_date' => $uispPayment['createdDate'],
                        'method' => $uispPayment['method']['name'] ?? 'Unknown',
                        'reference' => $uispPayment['receiptNumber'] ?? null,
                        'notes' => $uispPayment['note'] ?? null,
                        'synced_at' => date('Y-m-d H:i:s'),
                    ];
                    
                    if ($existing) {
                        $this->paymentRepo->update($existing['id'], $paymentData);
                        $stats['updated']++;
                    } else {
                        $paymentId = $this->paymentRepo->create($paymentData);
                        $stats['new']++;
                        
                        // Auto-create journal entry and update cashbook
                        $this->createPaymentJournalEntry($paymentId, $paymentData);
                        $this->updateCashbook($paymentId, $paymentData);
                    }
                    
                } catch (Exception $e) {
                    $stats['errors']++;
                    app()->log('error', 'Payment sync error for ID ' . $uispPayment['id'] . ': ' . $e->getMessage());
                }
            }
            
        } catch (Exception $e) {
            $stats['errors']++;
            app()->log('error', 'Payment sync failed: ' . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Fetch data from UISP API
     */
    private function fetchFromUisp(string $endpoint, array $params = []): array
    {
        if (!$this->uispUrl || !$this->apiToken) {
            throw new Exception('UISP credentials not configured');
        }
        
        $url = rtrim($this->uispUrl, '/') . '/api/v1.0' . $endpoint;
        
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'X-Auth-App-Key: ' . $this->apiToken,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($httpCode !== 200) {
            throw new Exception("UISP API error: HTTP $httpCode");
        }
        
        $data = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from UISP');
        }
        
        return $data;
    }
    
    /**
     * Create journal entry for invoice (Automatic Posting)
     */
    private function createInvoiceJournalEntry(int $invoiceId, array $invoiceData): void
    {
        $journalService = app()->resolve('journalService');
        
        // Dr: Accounts Receivable
        // Cr: Service Revenue
        $journalService->createEntry([
            'entry_date' => $invoiceData['invoice_date'],
            'reference' => 'INV-' . $invoiceData['invoice_number'],
            'description' => 'Invoice for customer',
            'source_type' => 'uisp_invoice',
            'source_id' => $invoiceId,
            'lines' => [
                [
                    'account_code' => '1140', // Accounts Receivable
                    'debit' => $invoiceData['total_amount'],
                    'credit' => 0,
                    'description' => 'Customer invoice receivable',
                ],
                [
                    'account_code' => '4110', // Service Revenue
                    'debit' => 0,
                    'credit' => $invoiceData['amount'],
                    'description' => 'Service revenue',
                ],
            ]
        ]);
        
        // Handle tax if present
        if ($invoiceData['tax_amount'] > 0) {
            $journalService->addLine([
                'account_code' => '2140', // Tax Payable
                'debit' => 0,
                'credit' => $invoiceData['tax_amount'],
                'description' => 'Sales tax',
            ]);
        }
        
        $journalService->postEntry();
    }
    
    /**
     * Create journal entry for payment (Automatic Posting)
     */
    private function createPaymentJournalEntry(int $paymentId, array $paymentData): void
    {
        $journalService = app()->resolve('journalService');
        
        // Dr: Cash/Bank
        // Cr: Accounts Receivable
        $journalService->createEntry([
            'entry_date' => $paymentData['payment_date'],
            'reference' => 'PAY-' . $paymentData['reference'],
            'description' => 'Payment received',
            'source_type' => 'uisp_payment',
            'source_id' => $paymentId,
            'lines' => [
                [
                    'account_code' => '1110', // Cash on Hand
                    'debit' => $paymentData['amount'],
                    'credit' => 0,
                    'description' => 'Payment received',
                ],
                [
                    'account_code' => '1140', // Accounts Receivable
                    'debit' => 0,
                    'credit' => $paymentData['amount'],
                    'description' => 'Payment against receivable',
                ],
            ]
        ]);
        
        $journalService->postEntry();
    }
    
    /**
     * Update cashbook with payment
     */
    private function updateCashbook(int $paymentId, array $paymentData): void
    {
        $cashbookService = app()->resolve('cashbookService');
        
        $cashbookService->recordTransaction([
            'transaction_date' => $paymentData['payment_date'],
            'type' => 'receipt',
            'category' => 'Customer Payment',
            'amount' => $paymentData['amount'],
            'description' => 'Payment from customer - ' . $paymentData['reference'],
            'reference' => $paymentData['reference'],
            'source_type' => 'uisp_payment',
            'source_id' => $paymentId,
        ]);
    }
    
    /**
     * Map UISP invoice status to our status
     */
    private function mapInvoiceStatus(int $uispStatus): string
    {
        $statusMap = [
            0 => 'draft',
            1 => 'unpaid',
            2 => 'partially_paid',
            3 => 'paid',
            4 => 'void',
        ];
        
        return $statusMap[$uispStatus] ?? 'unknown';
    }
    
    /**
     * Format customer address
     */
    private function formatAddress(array $customer): string
    {
        if (empty($customer['street1'])) {
            return '';
        }
        
        $parts = array_filter([
            $customer['street1'] ?? '',
            $customer['street2'] ?? '',
            $customer['city'] ?? '',
            $customer['state'] ?? '',
            $customer['zipCode'] ?? '',
            $customer['country']['name'] ?? '',
        ]);
        
        return implode(', ', $parts);
    }
}
