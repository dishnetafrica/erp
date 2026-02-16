<?php
/**
 * Database Schema - Complete ERP Structure
 * Migration: 001_initial_schema
 */

return [
    'up' => function($db) {
        // ============================================
        // FOUNDATION TABLES
        // ============================================
        
        // System Configuration
        $db->exec("
            CREATE TABLE IF NOT EXISTS system_config (
                key TEXT PRIMARY KEY,
                value TEXT,
                type TEXT DEFAULT 'string',
                description TEXT,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        // ============================================
        // CUSTOMER MASTER DATA
        // ============================================
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uisp_id INTEGER UNIQUE,
                name TEXT NOT NULL,
                email TEXT,
                phone TEXT,
                address TEXT,
                status TEXT DEFAULT 'active',
                balance REAL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                synced_at DATETIME
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_uisp_id ON customers(uisp_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_customers_status ON customers(status)");
        
        // ============================================
        // UISP INTEGRATION (READ ONLY)
        // ============================================
        
        // UISP Invoices (Sales Ledger)
        $db->exec("
            CREATE TABLE IF NOT EXISTS uisp_invoices (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uisp_id INTEGER UNIQUE NOT NULL,
                customer_id INTEGER NOT NULL,
                invoice_number TEXT,
                amount REAL NOT NULL,
                tax_amount REAL DEFAULT 0,
                total_amount REAL NOT NULL,
                invoice_date DATE NOT NULL,
                due_date DATE,
                status TEXT DEFAULT 'unpaid',
                notes TEXT,
                synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (customer_id) REFERENCES customers(id)
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_uisp_invoices_uisp_id ON uisp_invoices(uisp_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_uisp_invoices_customer ON uisp_invoices(customer_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_uisp_invoices_date ON uisp_invoices(invoice_date)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_uisp_invoices_status ON uisp_invoices(status)");
        
        // UISP Payments (Cash Receipts)
        $db->exec("
            CREATE TABLE IF NOT EXISTS uisp_payments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uisp_id INTEGER UNIQUE NOT NULL,
                customer_id INTEGER NOT NULL,
                invoice_id INTEGER,
                amount REAL NOT NULL,
                payment_date DATE NOT NULL,
                method TEXT,
                reference TEXT,
                notes TEXT,
                synced_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (customer_id) REFERENCES customers(id),
                FOREIGN KEY (invoice_id) REFERENCES uisp_invoices(id)
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_uisp_payments_uisp_id ON uisp_payments(uisp_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_uisp_payments_customer ON uisp_payments(customer_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_uisp_payments_date ON uisp_payments(payment_date)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_uisp_payments_invoice ON uisp_payments(invoice_id)");
        
        // ============================================
        // CHART OF ACCOUNTS
        // ============================================
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS chart_of_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                code TEXT UNIQUE NOT NULL,
                name TEXT NOT NULL,
                type TEXT NOT NULL, -- asset, liability, equity, revenue, expense
                category TEXT, -- current_asset, fixed_asset, etc.
                parent_id INTEGER,
                is_system INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                balance REAL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (parent_id) REFERENCES chart_of_accounts(id)
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_coa_code ON chart_of_accounts(code)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_coa_type ON chart_of_accounts(type)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_coa_parent ON chart_of_accounts(parent_id)");
        
        // ============================================
        // JOURNAL ENTRIES (DOUBLE ENTRY)
        // ============================================
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS journal_entries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entry_number TEXT UNIQUE NOT NULL,
                entry_date DATE NOT NULL,
                reference TEXT,
                description TEXT,
                source_type TEXT, -- uisp_invoice, uisp_payment, expense, transfer, manual
                source_id INTEGER,
                status TEXT DEFAULT 'draft', -- draft, posted, reversed
                posted_at DATETIME,
                posted_by INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_by INTEGER
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_journal_date ON journal_entries(entry_date)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_journal_status ON journal_entries(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_journal_source ON journal_entries(source_type, source_id)");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS journal_lines (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entry_id INTEGER NOT NULL,
                line_number INTEGER NOT NULL,
                account_id INTEGER NOT NULL,
                debit REAL DEFAULT 0,
                credit REAL DEFAULT 0,
                description TEXT,
                FOREIGN KEY (entry_id) REFERENCES journal_entries(id) ON DELETE CASCADE,
                FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id)
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_journal_lines_entry ON journal_lines(entry_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_journal_lines_account ON journal_lines(account_id)");
        
        // ============================================
        // CASHBOOK
        // ============================================
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS cashbook_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                transaction_date DATE NOT NULL,
                type TEXT NOT NULL, -- receipt, payment
                category TEXT,
                amount REAL NOT NULL,
                description TEXT,
                reference TEXT,
                source_type TEXT, -- uisp_payment, expense, manual
                source_id INTEGER,
                balance_after REAL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                created_by INTEGER
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cashbook_date ON cashbook_transactions(transaction_date)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cashbook_type ON cashbook_transactions(type)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cashbook_source ON cashbook_transactions(source_type, source_id)");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS cashbook_daily_summary (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                summary_date DATE UNIQUE NOT NULL,
                opening_balance REAL DEFAULT 0,
                total_receipts REAL DEFAULT 0,
                total_payments REAL DEFAULT 0,
                closing_balance REAL DEFAULT 0,
                is_closed INTEGER DEFAULT 0,
                closed_at DATETIME,
                closed_by INTEGER
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_cashbook_summary_date ON cashbook_daily_summary(summary_date)");
        
        // ============================================
        // BANK ACCOUNTS
        // ============================================
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS bank_accounts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                bank_name TEXT,
                account_number TEXT,
                account_type TEXT, -- checking, savings, credit_card
                currency TEXT DEFAULT 'USD',
                opening_balance REAL DEFAULT 0,
                current_balance REAL DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS bank_transactions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                transaction_date DATE NOT NULL,
                value_date DATE,
                type TEXT NOT NULL, -- debit, credit
                amount REAL NOT NULL,
                description TEXT,
                reference TEXT,
                statement_ref TEXT,
                balance_after REAL,
                is_reconciled INTEGER DEFAULT 0,
                reconciled_at DATETIME,
                source_type TEXT, -- import, manual, transfer
                source_id INTEGER,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (account_id) REFERENCES bank_accounts(id)
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_bank_txn_account ON bank_transactions(account_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_bank_txn_date ON bank_transactions(transaction_date)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_bank_txn_reconciled ON bank_transactions(is_reconciled)");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS bank_statements (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                account_id INTEGER NOT NULL,
                statement_date DATE NOT NULL,
                opening_balance REAL,
                closing_balance REAL,
                total_credits REAL,
                total_debits REAL,
                filename TEXT,
                imported_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (account_id) REFERENCES bank_accounts(id)
            )
        ");
        
        // ============================================
        // VENDORS & EXPENSES
        // ============================================
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS vendors (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                contact_person TEXT,
                email TEXT,
                phone TEXT,
                address TEXT,
                category TEXT,
                tax_id TEXT,
                status TEXT DEFAULT 'active',
                balance REAL DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_vendors_status ON vendors(status)");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS expense_categories (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT UNIQUE NOT NULL,
                code TEXT UNIQUE,
                account_id INTEGER,
                parent_id INTEGER,
                is_active INTEGER DEFAULT 1,
                FOREIGN KEY (account_id) REFERENCES chart_of_accounts(id),
                FOREIGN KEY (parent_id) REFERENCES expense_categories(id)
            )
        ");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS expenses (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                expense_number TEXT UNIQUE NOT NULL,
                vendor_id INTEGER,
                category_id INTEGER NOT NULL,
                amount REAL NOT NULL,
                tax_amount REAL DEFAULT 0,
                total_amount REAL NOT NULL,
                expense_date DATE NOT NULL,
                description TEXT,
                reference TEXT,
                payment_source TEXT, -- cash, bank
                payment_source_id INTEGER,
                status TEXT DEFAULT 'pending', -- pending, approved, rejected, paid
                submitted_by INTEGER,
                approved_by INTEGER,
                approved_at DATETIME,
                paid_at DATETIME,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (vendor_id) REFERENCES vendors(id),
                FOREIGN KEY (category_id) REFERENCES expense_categories(id)
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_expenses_vendor ON expenses(vendor_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses(category_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_expenses_status ON expenses(status)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_expenses_date ON expenses(expense_date)");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS expense_attachments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                expense_id INTEGER NOT NULL,
                filename TEXT NOT NULL,
                original_filename TEXT,
                filepath TEXT NOT NULL,
                filesize INTEGER,
                mime_type TEXT,
                uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (expense_id) REFERENCES expenses(id) ON DELETE CASCADE
            )
        ");
        
        // ============================================
        // RECONCILIATION
        // ============================================
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS reconciliation_rules (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                rule_type TEXT NOT NULL, -- exact_amount, reference_match, date_range, customer_name
                conditions TEXT, -- JSON
                priority INTEGER DEFAULT 0,
                auto_match INTEGER DEFAULT 0,
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS reconciliation_matches (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                bank_transaction_id INTEGER NOT NULL,
                uisp_payment_id INTEGER NOT NULL,
                match_type TEXT, -- auto, manual, suggested
                confidence_score INTEGER, -- 0-100
                status TEXT DEFAULT 'matched', -- matched, unmatched, disputed
                matched_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                matched_by INTEGER,
                notes TEXT,
                FOREIGN KEY (bank_transaction_id) REFERENCES bank_transactions(id),
                FOREIGN KEY (uisp_payment_id) REFERENCES uisp_payments(id)
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_reconcile_bank ON reconciliation_matches(bank_transaction_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_reconcile_payment ON reconciliation_matches(uisp_payment_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_reconcile_status ON reconciliation_matches(status)");
        
        // ============================================
        // ISP-SPECIFIC MODULES
        // ============================================
        
        // Starlink Equipment Tracking
        $db->exec("
            CREATE TABLE IF NOT EXISTS starlink_equipment (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                serial_number TEXT UNIQUE NOT NULL,
                purchase_date DATE,
                purchase_price REAL,
                vendor_id INTEGER,
                assigned_customer_id INTEGER,
                assigned_date DATE,
                status TEXT DEFAULT 'in_stock', -- in_stock, assigned, returned, defective
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (vendor_id) REFERENCES vendors(id),
                FOREIGN KEY (assigned_customer_id) REFERENCES customers(id)
            )
        ");
        
        // Installation Tracking
        $db->exec("
            CREATE TABLE IF NOT EXISTS installations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id INTEGER NOT NULL,
                installation_date DATE,
                technician TEXT,
                equipment_cost REAL DEFAULT 0,
                labor_cost REAL DEFAULT 0,
                total_cost REAL DEFAULT 0,
                revenue REAL DEFAULT 0,
                profit REAL DEFAULT 0,
                status TEXT DEFAULT 'scheduled',
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (customer_id) REFERENCES customers(id)
            )
        ");
        
        // ============================================
        // ACCOUNTING PERIODS
        // ============================================
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS accounting_periods (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                period_name TEXT NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                status TEXT DEFAULT 'open', -- open, closed, locked
                closed_at DATETIME,
                closed_by INTEGER
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_periods_dates ON accounting_periods(start_date, end_date)");
        
        // ============================================
        // CONTROL & AUDIT
        // ============================================
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT UNIQUE NOT NULL,
                email TEXT UNIQUE,
                role TEXT DEFAULT 'user', -- admin, manager, accountant, user
                is_active INTEGER DEFAULT 1,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS approval_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_type TEXT NOT NULL,
                entity_id INTEGER NOT NULL,
                action TEXT NOT NULL, -- submit, approve, reject
                approver_id INTEGER,
                previous_status TEXT,
                new_status TEXT,
                comments TEXT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (approver_id) REFERENCES users(id)
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_approval_entity ON approval_logs(entity_type, entity_id)");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS audit_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER,
                action TEXT NOT NULL,
                entity_type TEXT,
                entity_id INTEGER,
                changes TEXT, -- JSON
                ip_address TEXT,
                user_agent TEXT,
                timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id)
            )
        ");
        
        $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_timestamp ON audit_logs(timestamp)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_logs(user_id)");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_logs(entity_type, entity_id)");
        
        // ============================================
        // DASHBOARD & CACHE
        // ============================================
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS dashboard_metrics (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                metric_key TEXT UNIQUE NOT NULL,
                metric_value TEXT,
                calculated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        $db->exec("
            CREATE TABLE IF NOT EXISTS sync_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                sync_type TEXT NOT NULL,
                status TEXT NOT NULL,
                records_processed INTEGER DEFAULT 0,
                errors TEXT,
                started_at DATETIME,
                completed_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");
        
        echo "✓ All tables created successfully\n";
    },
    
    'down' => function($db) {
        $tables = [
            'dashboard_metrics', 'sync_logs', 'audit_logs', 'approval_logs',
            'users', 'accounting_periods', 'installations', 'starlink_equipment',
            'reconciliation_matches', 'reconciliation_rules', 'expense_attachments',
            'expenses', 'expense_categories', 'vendors', 'bank_statements',
            'bank_transactions', 'bank_accounts', 'cashbook_daily_summary',
            'cashbook_transactions', 'journal_lines', 'journal_entries',
            'chart_of_accounts', 'uisp_payments', 'uisp_invoices',
            'customers', 'system_config'
        ];
        
        foreach ($tables as $table) {
            $db->exec("DROP TABLE IF EXISTS $table");
        }
        
        echo "✓ All tables dropped\n";
    }
];
