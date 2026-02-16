<?php
/**
 * ISP ERP Platform - Public Interface
 * 
 * Web interface for the accounting system
 * This file will contain the complete UI implementation
 */

// Bootstrap application
$app = require __DIR__ . '/main.php';

// Handle API requests
if (isset($_GET['api'])) {
    $app->handleRequest();
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ISP ERP Platform - Dashboard</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: #f5f7fa;
            color: #2c3e50;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .header {
            background: white;
            padding: 20px 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            border-radius: 8px;
        }
        
        .header h1 {
            color: #2c3e50;
            font-size: 28px;
            font-weight: 600;
        }
        
        .header .subtitle {
            color: #7f8c8d;
            font-size: 14px;
            margin-top: 5px;
        }
        
        .nav-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .nav-tab {
            padding: 12px 24px;
            background: #ecf0f1;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #2c3e50;
            transition: all 0.2s;
        }
        
        .nav-tab:hover {
            background: #3498db;
            color: white;
        }
        
        .nav-tab.active {
            background: #3498db;
            color: white;
        }
        
        .content {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            min-height: 600px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 25px;
            border-radius: 8px;
            color: white;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .stat-card.green {
            background: linear-gradient(135deg, #56ab2f 0%, #a8e063 100%);
        }
        
        .stat-card.blue {
            background: linear-gradient(135deg, #2193b0 0%, #6dd5ed 100%);
        }
        
        .stat-card.orange {
            background: linear-gradient(135deg, #f2994a 0%, #f2c94c 100%);
        }
        
        .stat-card h3 {
            font-size: 14px;
            font-weight: 500;
            opacity: 0.9;
            margin-bottom: 10px;
        }
        
        .stat-card .value {
            font-size: 32px;
            font-weight: 700;
        }
        
        .loading {
            text-align: center;
            padding: 50px;
            color: #7f8c8d;
        }
        
        .welcome-message {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 40px;
            border-radius: 12px;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .welcome-message h2 {
            font-size: 32px;
            margin-bottom: 15px;
        }
        
        .welcome-message p {
            font-size: 16px;
            opacity: 0.95;
            line-height: 1.6;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-top: 30px;
        }
        
        .quick-action {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.2s;
            border: 2px solid #ecf0f1;
        }
        
        .quick-action:hover {
            border-color: #3498db;
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .quick-action h4 {
            color: #2c3e50;
            margin-bottom: 8px;
        }
        
        .quick-action p {
            color: #7f8c8d;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🚀 ISP ERP Platform</h1>
            <div class="subtitle">Enterprise Accounting System - Version 2.0.0</div>
        </div>
        
        <div class="welcome-message">
            <h2>Welcome to ISP ERP Platform</h2>
            <p>
                Your enterprise-grade accounting system is ready!<br>
                Complete UISP integration, automated reconciliation, and financial intelligence.
            </p>
        </div>
        
        <div class="nav-tabs">
            <button class="nav-tab active" data-tab="dashboard">📊 Dashboard</button>
            <button class="nav-tab" data-tab="cashbook">💵 Cashbook</button>
            <button class="nav-tab" data-tab="banks">🏦 Banks</button>
            <button class="nav-tab" data-tab="expenses">📝 Expenses</button>
            <button class="nav-tab" data-tab="reconciliation">🔄 Reconciliation</button>
            <button class="nav-tab" data-tab="ledger">📚 Ledger</button>
            <button class="nav-tab" data-tab="reports">📈 Reports</button>
            <button class="nav-tab" data-tab="settings">⚙️ Settings</button>
        </div>
        
        <div class="content" id="content-area">
            <div class="stats-grid">
                <div class="stat-card blue">
                    <h3>Cash Balance</h3>
                    <div class="value">$0.00</div>
                </div>
                <div class="stat-card green">
                    <h3>Bank Balance</h3>
                    <div class="value">$0.00</div>
                </div>
                <div class="stat-card">
                    <h3>Accounts Receivable</h3>
                    <div class="value">$0.00</div>
                </div>
                <div class="stat-card orange">
                    <h3>Pending Expenses</h3>
                    <div class="value">0</div>
                </div>
            </div>
            
            <h2 style="margin-bottom: 20px; color: #2c3e50;">Quick Actions</h2>
            <div class="quick-actions">
                <div class="quick-action">
                    <h4>🔄 Sync UISP</h4>
                    <p>Import latest data</p>
                </div>
                <div class="quick-action">
                    <h4>📝 New Expense</h4>
                    <p>Record expense</p>
                </div>
                <div class="quick-action">
                    <h4>🏦 Bank Import</h4>
                    <p>Upload statement</p>
                </div>
                <div class="quick-action">
                    <h4>🔍 Reconcile</h4>
                    <p>Match transactions</p>
                </div>
                <div class="quick-action">
                    <h4>📊 Run Report</h4>
                    <p>Generate reports</p>
                </div>
                <div class="quick-action">
                    <h4>⚙️ Settings</h4>
                    <p>Configure system</p>
                </div>
            </div>
            
            <div style="margin-top: 40px; padding: 20px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #3498db;">
                <h3 style="color: #2c3e50; margin-bottom: 10px;">📖 Getting Started</h3>
                <ul style="color: #7f8c8d; line-height: 2; margin-left: 20px;">
                    <li>System is initialized with default chart of accounts</li>
                    <li>UISP sync will run automatically every 15 minutes</li>
                    <li>Access comprehensive documentation in the README.md file</li>
                    <li>Check PROJECT_ARCHITECTURE.md for system design</li>
                    <li>Review IMPLEMENTATION_GUIDE.md for phase-by-phase development</li>
                </ul>
            </div>
            
            <div style="margin-top: 20px; padding: 20px; background: #fff3cd; border-radius: 8px; border-left: 4px solid #ffc107;">
                <h3 style="color: #856404; margin-bottom: 10px;">⚠️ Development Status</h3>
                <p style="color: #856404; line-height: 1.6;">
                    <strong>Phase 1 Complete:</strong> Core database schema, UISP sync service, and application architecture are implemented.<br>
                    <strong>Phase 2 In Progress:</strong> Complete service layer, repositories, controllers, and full UI implementation.<br>
                    <strong>See IMPLEMENTATION_GUIDE.md</strong> for detailed next steps and remaining work.
                </p>
            </div>
        </div>
    </div>
    
    <script>
        // Tab switching
        document.querySelectorAll('.nav-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                document.querySelectorAll('.nav-tab').forEach(t => t.classList.remove('active'));
                this.classList.add('active');
                
                const tabName = this.dataset.tab;
                loadTab(tabName);
            });
        });
        
        function loadTab(tabName) {
            const content = document.getElementById('content-area');
            content.innerHTML = `<div class="loading">Loading ${tabName}...</div>`;
            
            // Placeholder for future implementation
            setTimeout(() => {
                content.innerHTML = `
                    <h2 style="color: #2c3e50; margin-bottom: 20px;">${tabName.charAt(0).toUpperCase() + tabName.slice(1)}</h2>
                    <div style="padding: 40px; background: #f8f9fa; border-radius: 8px; text-align: center;">
                        <p style="color: #7f8c8d; font-size: 16px;">
                            This module will be implemented in Phase 2.<br>
                            See IMPLEMENTATION_GUIDE.md for details.
                        </p>
                    </div>
                `;
            }, 300);
        }
        
        console.log('ISP ERP Platform v2.0 - Ready');
        console.log('Phase 1: Core architecture complete');
        console.log('Phase 2: Service layer and UI in progress');
    </script>
</body>
</html>
