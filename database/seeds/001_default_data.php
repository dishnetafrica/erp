<?php
/**
 * Database Seeds - Default Chart of Accounts & Configuration
 * Seed: 001_default_data
 */

return [
    'run' => function($db) {
        
        // ============================================
        // DEFAULT CHART OF ACCOUNTS (ISP-Optimized)
        // ============================================
        
        $accounts = [
            // ASSETS
            ['1000', 'Assets', 'asset', 'group', null, 1],
            ['1100', 'Current Assets', 'asset', 'current_asset', '1000', 1],
            ['1110', 'Cash on Hand', 'asset', 'cash', '1100', 1],
            ['1120', 'Bank - Operating Account', 'asset', 'bank', '1100', 1],
            ['1130', 'Bank - Savings Account', 'asset', 'bank', '1100', 1],
            ['1140', 'Accounts Receivable', 'asset', 'receivable', '1100', 1],
            ['1150', 'Prepaid Expenses', 'asset', 'current_asset', '1100', 0],
            
            ['1200', 'Fixed Assets', 'asset', 'fixed_asset', '1000', 1],
            ['1210', 'Network Equipment', 'asset', 'fixed_asset', '1200', 0],
            ['1220', 'Starlink Equipment', 'asset', 'fixed_asset', '1200', 0],
            ['1230', 'Installation Equipment', 'asset', 'fixed_asset', '1200', 0],
            ['1240', 'Office Equipment', 'asset', 'fixed_asset', '1200', 0],
            ['1250', 'Vehicles', 'asset', 'fixed_asset', '1200', 0],
            ['1260', 'Accumulated Depreciation', 'asset', 'contra_asset', '1200', 0],
            
            // LIABILITIES
            ['2000', 'Liabilities', 'liability', 'group', null, 1],
            ['2100', 'Current Liabilities', 'liability', 'current_liability', '2000', 1],
            ['2110', 'Accounts Payable', 'liability', 'payable', '2100', 1],
            ['2120', 'Accrued Expenses', 'liability', 'current_liability', '2100', 0],
            ['2130', 'Customer Deposits', 'liability', 'current_liability', '2100', 0],
            ['2140', 'Tax Payable', 'liability', 'current_liability', '2100', 0],
            
            ['2200', 'Long-term Liabilities', 'liability', 'long_term_liability', '2000', 0],
            ['2210', 'Loans Payable', 'liability', 'long_term_liability', '2200', 0],
            ['2220', 'Equipment Financing', 'liability', 'long_term_liability', '2200', 0],
            
            // EQUITY
            ['3000', 'Equity', 'equity', 'group', null, 1],
            ['3100', 'Owner\'s Capital', 'equity', 'capital', '3000', 0],
            ['3200', 'Retained Earnings', 'equity', 'retained_earnings', '3000', 1],
            ['3300', 'Current Year Earnings', 'equity', 'current_earnings', '3000', 1],
            
            // REVENUE
            ['4000', 'Revenue', 'revenue', 'group', null, 1],
            ['4100', 'Service Revenue', 'revenue', 'operating_revenue', '4000', 1],
            ['4110', 'Internet Service Revenue', 'revenue', 'operating_revenue', '4100', 1],
            ['4120', 'Installation Revenue', 'revenue', 'operating_revenue', '4100', 0],
            ['4130', 'Equipment Sales', 'revenue', 'operating_revenue', '4100', 0],
            ['4140', 'Late Fees', 'revenue', 'operating_revenue', '4100', 0],
            
            ['4200', 'Other Revenue', 'revenue', 'other_revenue', '4000', 0],
            ['4210', 'Interest Income', 'revenue', 'other_revenue', '4200', 0],
            ['4220', 'Gain on Asset Sale', 'revenue', 'other_revenue', '4200', 0],
            
            // EXPENSES
            ['5000', 'Cost of Services', 'expense', 'group', null, 1],
            ['5100', 'Network Costs', 'expense', 'operating_expense', '5000', 1],
            ['5110', 'Bandwidth/Transit Costs', 'expense', 'operating_expense', '5100', 1],
            ['5120', 'Starlink Subscriptions', 'expense', 'operating_expense', '5100', 1],
            ['5130', 'Peering Costs', 'expense', 'operating_expense', '5100', 0],
            ['5140', 'Network Maintenance', 'expense', 'operating_expense', '5100', 0],
            
            ['5200', 'Equipment & Materials', 'expense', 'operating_expense', '5000', 0],
            ['5210', 'Customer Equipment', 'expense', 'operating_expense', '5200', 0],
            ['5220', 'Network Hardware', 'expense', 'operating_expense', '5200', 0],
            ['5230', 'Installation Materials', 'expense', 'operating_expense', '5200', 0],
            
            ['6000', 'Operating Expenses', 'expense', 'group', null, 1],
            ['6100', 'Administrative', 'expense', 'operating_expense', '6000', 1],
            ['6110', 'Salaries & Wages', 'expense', 'operating_expense', '6100', 0],
            ['6120', 'Office Rent', 'expense', 'operating_expense', '6100', 0],
            ['6130', 'Utilities', 'expense', 'operating_expense', '6100', 0],
            ['6140', 'Insurance', 'expense', 'operating_expense', '6100', 0],
            ['6150', 'Office Supplies', 'expense', 'operating_expense', '6100', 0],
            
            ['6200', 'Sales & Marketing', 'expense', 'operating_expense', '6000', 0],
            ['6210', 'Advertising', 'expense', 'operating_expense', '6200', 0],
            ['6220', 'Marketing Materials', 'expense', 'operating_expense', '6200', 0],
            ['6230', 'Sales Commissions', 'expense', 'operating_expense', '6200', 0],
            
            ['6300', 'Technology', 'expense', 'operating_expense', '6000', 0],
            ['6310', 'Software Subscriptions', 'expense', 'operating_expense', '6300', 0],
            ['6320', 'UISP License', 'expense', 'operating_expense', '6300', 0],
            ['6330', 'IT Services', 'expense', 'operating_expense', '6300', 0],
            
            ['6400', 'Vehicle & Travel', 'expense', 'operating_expense', '6000', 0],
            ['6410', 'Fuel', 'expense', 'operating_expense', '6400', 0],
            ['6420', 'Vehicle Maintenance', 'expense', 'operating_expense', '6400', 0],
            ['6430', 'Travel Expenses', 'expense', 'operating_expense', '6400', 0],
            
            ['6500', 'Professional Services', 'expense', 'operating_expense', '6000', 0],
            ['6510', 'Legal Fees', 'expense', 'operating_expense', '6500', 0],
            ['6520', 'Accounting Fees', 'expense', 'operating_expense', '6500', 0],
            ['6530', 'Consulting Fees', 'expense', 'operating_expense', '6500', 0],
            
            ['6600', 'Other Expenses', 'expense', 'operating_expense', '6000', 0],
            ['6610', 'Bank Charges', 'expense', 'operating_expense', '6600', 0],
            ['6620', 'Bad Debt Expense', 'expense', 'operating_expense', '6600', 0],
            ['6630', 'Depreciation', 'expense', 'operating_expense', '6600', 0],
            ['6640', 'Interest Expense', 'expense', 'operating_expense', '6600', 0],
        ];
        
        $stmt = $db->prepare("
            INSERT INTO chart_of_accounts (code, name, type, category, parent_id, is_system)
            SELECT :code, :name, :type, :category, 
                   (SELECT id FROM chart_of_accounts WHERE code = :parent_code), 
                   :is_system
            WHERE NOT EXISTS (SELECT 1 FROM chart_of_accounts WHERE code = :code)
        ");
        
        foreach ($accounts as $account) {
            $stmt->execute([
                ':code' => $account[0],
                ':name' => $account[1],
                ':type' => $account[2],
                ':category' => $account[3],
                ':parent_code' => $account[4],
                ':is_system' => $account[5]
            ]);
        }
        
        echo "✓ Chart of accounts created\n";
        
        // ============================================
        // DEFAULT EXPENSE CATEGORIES
        // ============================================
        
        $categories = [
            ['Bandwidth & Transit', 'BW', '5110'],
            ['Starlink Services', 'SL', '5120'],
            ['Equipment Purchase', 'EQ', '5210'],
            ['Network Maintenance', 'NM', '5140'],
            ['Office Expenses', 'OF', '6150'],
            ['Marketing', 'MK', '6210'],
            ['Software & Services', 'SW', '6310'],
            ['Vehicle & Fuel', 'VH', '6410'],
            ['Professional Fees', 'PF', '6500'],
            ['Miscellaneous', 'MS', '6600'],
        ];
        
        $stmt = $db->prepare("
            INSERT INTO expense_categories (name, code, account_id)
            SELECT :name, :code, (SELECT id FROM chart_of_accounts WHERE code = :account_code)
            WHERE NOT EXISTS (SELECT 1 FROM expense_categories WHERE code = :code)
        ");
        
        foreach ($categories as $cat) {
            $stmt->execute([
                ':name' => $cat[0],
                ':code' => $cat[1],
                ':account_code' => $cat[2]
            ]);
        }
        
        echo "✓ Expense categories created\n";
        
        // ============================================
        // DEFAULT RECONCILIATION RULES
        // ============================================
        
        $rules = [
            [
                'name' => 'Exact Amount & Date Match',
                'rule_type' => 'exact_amount',
                'conditions' => json_encode([
                    'amount_tolerance' => 0,
                    'date_range_days' => 3
                ]),
                'priority' => 1,
                'auto_match' => 1
            ],
            [
                'name' => 'Reference Number Match',
                'rule_type' => 'reference_match',
                'conditions' => json_encode([
                    'match_type' => 'contains',
                    'case_sensitive' => false
                ]),
                'priority' => 2,
                'auto_match' => 1
            ],
            [
                'name' => 'Customer Name Similarity',
                'rule_type' => 'customer_name',
                'conditions' => json_encode([
                    'similarity_threshold' => 75,
                    'amount_tolerance' => 0.01
                ]),
                'priority' => 3,
                'auto_match' => 0
            ]
        ];
        
        $stmt = $db->prepare("
            INSERT INTO reconciliation_rules (name, rule_type, conditions, priority, auto_match)
            VALUES (:name, :rule_type, :conditions, :priority, :auto_match)
        ");
        
        foreach ($rules as $rule) {
            $stmt->execute($rule);
        }
        
        echo "✓ Reconciliation rules created\n";
        
        // ============================================
        // DEFAULT SYSTEM CONFIGURATION
        // ============================================
        
        $configs = [
            ['company_name', 'ISP Company', 'string', 'Company name'],
            ['base_currency', 'USD', 'string', 'Base currency code'],
            ['fiscal_year_start', '01-01', 'string', 'Fiscal year start (MM-DD)'],
            ['auto_sync_enabled', 'true', 'boolean', 'Enable automatic UISP sync'],
            ['auto_reconcile_enabled', 'true', 'boolean', 'Enable automatic reconciliation'],
            ['approval_required_expenses', 'true', 'boolean', 'Require approval for expenses'],
            ['approval_threshold_amount', '500.00', 'decimal', 'Auto-approve below this amount'],
            ['sync_interval_invoices', '15', 'integer', 'Invoice sync interval (minutes)'],
            ['sync_interval_payments', '10', 'integer', 'Payment sync interval (minutes)'],
            ['reconcile_interval', '60', 'integer', 'Auto-reconcile interval (minutes)'],
            ['cash_opening_balance', '0.00', 'decimal', 'Initial cash balance'],
            ['dashboard_cache_minutes', '5', 'integer', 'Dashboard cache lifetime'],
        ];
        
        $stmt = $db->prepare("
            INSERT INTO system_config (key, value, type, description)
            VALUES (:key, :value, :type, :description)
        ");
        
        foreach ($configs as $config) {
            $stmt->execute([
                ':key' => $config[0],
                ':value' => $config[1],
                ':type' => $config[2],
                ':description' => $config[3]
            ]);
        }
        
        echo "✓ System configuration created\n";
        
        // ============================================
        // CREATE DEFAULT ADMIN USER
        // ============================================
        
        $db->exec("
            INSERT INTO users (username, email, role, is_active)
            VALUES ('admin', 'admin@isp.local', 'admin', 1)
        ");
        
        echo "✓ Default admin user created\n";
        
        // ============================================
        // CREATE CURRENT ACCOUNTING PERIOD
        // ============================================
        
        $currentYear = date('Y');
        $db->exec("
            INSERT INTO accounting_periods (period_name, start_date, end_date, status)
            VALUES 
                ('Q1 $currentYear', '$currentYear-01-01', '$currentYear-03-31', 'open'),
                ('Q2 $currentYear', '$currentYear-04-01', '$currentYear-06-30', 'open'),
                ('Q3 $currentYear', '$currentYear-07-01', '$currentYear-09-30', 'open'),
                ('Q4 $currentYear', '$currentYear-10-01', '$currentYear-12-31', 'open')
        ");
        
        echo "✓ Accounting periods created\n";
        
        echo "\n✅ All seed data inserted successfully!\n";
    }
];
