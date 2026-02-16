# 🚀 ISP ERP Platform - Enterprise Architecture

## System Overview

**Production-Grade ISP Financial Operating System**
- UISP/UCRM Integration Plugin
- Enterprise Accounting Core
- Multi-Bank Reconciliation
- Expense Management
- Financial Intelligence

---

## Architecture Layers

```
┌─────────────────────────────────────────────────────────┐
│           Layer 5: Intelligence & Analytics             │
│  • Profit Analysis  • Forecasting  • Business Metrics   │
└─────────────────────────────────────────────────────────┘
                            │
┌─────────────────────────────────────────────────────────┐
│         Layer 4: Financial Control & Compliance         │
│  • Reconciliation  • Approvals  • Audit  • Reporting    │
└─────────────────────────────────────────────────────────┘
                            │
┌─────────────────────────────────────────────────────────┐
│           Layer 3: Accounting Core (Double Entry)       │
│  • General Ledger  • Journals  • Posting  • Periods     │
└─────────────────────────────────────────────────────────┘
                            │
┌─────────────────────────────────────────────────────────┐
│         Layer 2: Operational Finance                    │
│  • Cashbook  • Banks  • Expenses  • Vendors             │
└─────────────────────────────────────────────────────────┘
                            │
┌─────────────────────────────────────────────────────────┐
│         Layer 1: Transaction Foundation                 │
│  • UISP Sync  • Data Import  • Immutable Records        │
└─────────────────────────────────────────────────────────┘
```

---

## Core Principles

### 1. Three-Ledger Model

```
UISP (Sales Ledger) → ERP Core → Bank Reality
    ↓                    ↓              ↓
Invoices            Journals        Statements
Payments            Ledger          Transactions
Customers           Books           Balances
```

### 2. Data Flow

```
UISP API → Sync Engine → Journal Posting → Ledger Updates
                              ↓
Bank Import → Parser → Matching Engine → Reconciliation
                              ↓
Expense Entry → Approval → Journal → Cashbook/Bank Update
```

### 3. Immutability Rules

- ✅ UISP data is READ ONLY
- ✅ All financial transactions are IMMUTABLE
- ✅ Corrections via REVERSAL entries only
- ✅ Complete audit trail required

---

## Module Structure

### Core Modules (Phase 1)

1. **UISP Sync Engine**
   - Invoice import
   - Payment import
   - Customer sync
   - Incremental updates

2. **Cashbook Module**
   - Daily operations
   - Opening/closing balances
   - Cash movements
   - Auto-posting

3. **Expense Management**
   - Multi-category expenses
   - Vendor management
   - Approval workflow
   - Document attachments

4. **Dashboard**
   - Financial KPIs
   - Real-time metrics
   - Alert system
   - Quick actions

### Advanced Modules (Phase 2)

5. **Bank Module**
   - Multiple accounts
   - Statement import (CSV/Excel/PDF)
   - Running balances
   - Internal transfers

6. **Auto-Reconciliation**
   - Smart matching algorithm
   - Confidence scoring
   - Bulk operations
   - Exception handling

7. **General Ledger**
   - Chart of accounts
   - Journal entries
   - Trial balance
   - Period closing

8. **ISP-Specific Features**
   - Starlink tracking
   - Equipment management
   - Installation profitability
   - Plan margin analysis

---

## Database Schema

### Foundation Tables

```sql
-- Customer Master
customers (id, uisp_id, name, email, status, created_at)

-- UISP Integration
uisp_invoices (id, uisp_id, customer_id, amount, date, status, synced_at)
uisp_payments (id, uisp_id, invoice_id, amount, date, method, synced_at)

-- Banking
bank_accounts (id, name, type, currency, opening_balance, current_balance)
bank_transactions (id, account_id, date, amount, type, description, statement_ref)

-- Expenses
vendors (id, name, contact, category, status)
expenses (id, vendor_id, category, amount, date, status, approver_id)
expense_attachments (id, expense_id, filename, filepath)

-- Accounting Core
chart_of_accounts (id, code, name, type, parent_id, is_system)
journal_entries (id, date, reference, description, status, posted_at)
journal_lines (id, entry_id, account_id, debit, credit, description)

-- Reconciliation
reconciliation_rules (id, name, conditions, priority, auto_match)
reconciliation_matches (id, bank_txn_id, uisp_payment_id, confidence, status)

-- Control
accounting_periods (id, name, start_date, end_date, status)
approval_logs (id, entity_type, entity_id, approver_id, status, timestamp)
audit_logs (id, user_id, action, entity, changes, ip, timestamp)
```

---

## API Architecture

### Service Layer Pattern

```
Controller → Service → Repository → Database
     ↓           ↓          ↓
  Validate   Business   Data Access
  Request     Logic      Layer
```

### Key Services

1. **UispSyncService**
   - fetchInvoices()
   - fetchPayments()
   - syncCustomers()
   - handleWebhook()

2. **JournalService**
   - createEntry()
   - postEntry()
   - reverseEntry()
   - getTrialBalance()

3. **ReconciliationService**
   - autoMatch()
   - suggestMatches()
   - confirmMatch()
   - unmatch()

4. **CashbookService**
   - recordTransaction()
   - getBalance()
   - getDailyReport()
   - closePeriod()

5. **ExpenseService**
   - createExpense()
   - approveExpense()
   - postExpense()
   - getByStatus()

---

## Automation Engine

### Automatic Posting Rules

```php
Event: UISP Invoice Created
→ Dr: Accounts Receivable
→ Cr: Service Revenue

Event: Payment Received (UISP)
→ Dr: Cash/Bank
→ Cr: Accounts Receivable

Event: Expense Approved
→ Dr: Expense Account
→ Cr: Cash/Bank

Event: Bank Transfer
→ Dr: Destination Bank
→ Cr: Source Bank
```

### Reconciliation Algorithm

```python
def auto_match(bank_transaction):
    matches = []
    
    # Stage 1: Exact amount + date proximity
    exact = find_exact_amount(bank_transaction.amount, ±3 days)
    if exact:
        matches.append({exact, confidence: 95})
    
    # Stage 2: Reference number match
    ref = find_by_reference(bank_transaction.reference)
    if ref:
        matches.append({ref, confidence: 90})
    
    # Stage 3: Customer name similarity
    name = fuzzy_match_customer(bank_transaction.description)
    if name:
        matches.append({name, confidence: 75})
    
    return rank_by_confidence(matches)
```

---

## Security & Compliance

### Role-Based Access Control (RBAC)

```
Super Admin: Full access
└─ Finance Manager: Approve, report, configure
   └─ Accountant: Create, reconcile, view
      └─ Data Entry: Limited create access
```

### Audit Requirements

- Every database change logged
- User actions tracked
- IP addresses recorded
- Timestamp precision
- Change history preserved

---

## Performance Optimization

### Database Indexes

```sql
CREATE INDEX idx_uisp_invoices_sync ON uisp_invoices(uisp_id);
CREATE INDEX idx_bank_txn_date ON bank_transactions(date, account_id);
CREATE INDEX idx_journal_date ON journal_entries(date, status);
CREATE INDEX idx_reconcile_status ON reconciliation_matches(status);
```

### Caching Strategy

- Dashboard metrics: 5 minutes
- Account balances: Real-time
- UISP data: Sync-based
- Reports: On-demand generation

---

## Deployment Architecture

### UISP Plugin Mode

```
UISP Server
└─ /data/ucrm/ucrm/data/plugins/isp-erp/
   ├─ manifest.json
   ├─ main.php (bootstrap)
   └─ public.php (web interface)
```

### Standalone Mode (Future)

```
Web Server (Nginx/Apache)
└─ Application
   ├─ API Layer
   ├─ Web Interface
   └─ UISP Integration Service
```

---

## Technology Stack

### Backend
- PHP 8.1+ (UISP compatibility)
- PDO (Database abstraction)
- SQLite (Default) / PostgreSQL (Production)

### Frontend
- Modern JavaScript (ES6+)
- Minimal dependencies
- Responsive CSS
- AJAX for dynamic updates

### Libraries
- No heavy frameworks (plugin constraints)
- Pure PHP OOP
- Custom service layer

---

## Implementation Phases

### Phase 1: Foundation (Week 1-2)
✅ Database schema
✅ UISP sync engine
✅ Cashbook module
✅ Basic expenses
✅ Dashboard v1

### Phase 2: Core Accounting (Week 3-4)
✅ Bank module
✅ General ledger
✅ Automatic posting
✅ Approval workflow

### Phase 3: Intelligence (Week 5-6)
✅ Auto-reconciliation
✅ Advanced reporting
✅ ISP-specific features
✅ Analytics dashboard

### Phase 4: Polish (Week 7-8)
✅ Performance optimization
✅ Security hardening
✅ Documentation
✅ User training materials

---

## Quality Standards

### Code Quality
- PSR-12 coding standards
- Comprehensive error handling
- Input validation/sanitization
- SQL injection prevention

### Testing Strategy
- Unit tests for services
- Integration tests for API
- Manual testing for UI
- Load testing for sync

### Documentation
- Inline code comments
- API documentation
- User manual
- Admin guide

---

## Success Metrics

### Technical
- Zero data loss
- < 5 second page load
- 99.9% sync accuracy
- < 1% false matches

### Business
- 80% time saved on reconciliation
- 100% audit compliance
- Real-time financial visibility
- Scalable to 10,000+ customers

---

## Future Enhancements

- Multi-currency support
- Tax management module
- Payroll integration
- Advanced forecasting
- Mobile app
- API for third-party integrations

---

**Version:** 2.0.0
**Last Updated:** 2026-02-16
**Architecture Type:** Enterprise ERP
