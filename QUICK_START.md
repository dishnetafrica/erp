# ⚡ QUICK START GUIDE

## Get Up and Running in 5 Minutes

---

## 📦 What You Have

✅ **Complete ISP ERP Platform v2.0**
- 14 files
- Enterprise architecture
- Production-ready foundation
- Full UISP integration

---

## 🚀 Option 1: UISP Plugin Installation (Recommended)

### Step 1: Upload to UISP Server
```bash
# Extract the archive
tar -xzf isp-erp-platform-v2.0.tar.gz

# Upload to UISP
scp -r isp-erp-platform user@uisp-server:/data/ucrm/ucrm/data/plugins/

# OR use the install script
cd isp-erp-platform
chmod +x install.sh
./install.sh
```

### Step 2: Enable in UISP
1. Login to UISP web interface
2. Go to: **System → Plugins**
3. Find: **ISP ERP Platform**
4. Click: **Enable**

### Step 3: Access
- Click on the plugin in UISP menu
- Dashboard loads automatically
- UISP sync runs every 15 minutes
- Done! 🎉

---

## 🖥️ Option 2: Standalone Installation

### Step 1: Extract
```bash
tar -xzf isp-erp-platform-v2.0.tar.gz
cd isp-erp-platform
```

### Step 2: Run Installer
```bash
chmod +x install.sh
./install.sh
# Choose option 2: Standalone
```

### Step 3: Configure Web Server

**Apache:**
```apache
<VirtualHost *:80>
    DocumentRoot /path/to/isp-erp-platform
    DirectoryIndex public.php
</VirtualHost>
```

**Nginx:**
```nginx
server {
    root /path/to/isp-erp-platform;
    index public.php;
    
    location / {
        try_files $uri $uri/ /public.php?$query_string;
    }
}
```

### Step 4: Access
Open: http://your-server/public.php

---

## 🔧 Configuration

### UISP Credentials (Required for sync)

Set environment variables:
```bash
export UCRM_PUBLIC_URL="https://your-uisp.com"
export PLUGIN_APP_KEY="your-api-key"
```

Or configure in Settings after installation.

---

## 📋 First Steps After Installation

1. **Review Dashboard**
   - All metrics show $0.00 initially
   - Normal for fresh installation

2. **Run Initial Sync**
   - Settings → Sync → Run Now
   - Imports all UISP data
   - May take 1-5 minutes

3. **Verify Data**
   - Check customers imported
   - Review invoices
   - Confirm payments

4. **Configure**
   - Add bank accounts
   - Set up expense categories
   - Configure approval workflow

---

## 📚 Essential Documentation

### Must Read First:
1. **README.md** - Complete user manual
2. **PROJECT_SUMMARY.md** - What's included

### For Development:
3. **PROJECT_ARCHITECTURE.md** - System design
4. **IMPLEMENTATION_GUIDE.md** - Phase 2 roadmap

---

## 🎯 Key Features Available Now

✅ **Automatic UISP Sync**
- Runs every 15 minutes
- Imports invoices & payments
- Creates journal entries automatically

✅ **Chart of Accounts**
- 75 pre-configured accounts
- ISP-optimized structure
- Ready to use

✅ **Dashboard**
- Cash balance
- Bank balance
- Accounts receivable
- Quick actions

✅ **Audit System**
- All actions logged
- Complete history
- User tracking

---

## ⚠️ Important Notes

### Database
- Created automatically on first run
- Location: `data/isp_erp.db`
- SQLite format
- Backup regularly!

### Logs
- Application log: `data/app.log`
- Review for sync status
- Check for errors

### Permissions
- Data directory needs write access
- Run as web server user
- Don't run as root

---

## 🔍 Troubleshooting

### "Plugin won't enable in UISP"
- Check file permissions (755 for directories, 644 for files)
- Verify all files uploaded correctly
- Check UISP logs

### "Sync not working"
- Verify UISP credentials
- Check network connectivity
- Review `data/app.log`

### "Database error"
- Ensure `data/` directory writable
- Check disk space
- Verify PHP SQLite extension

### "Blank page"
- Check PHP error log
- Verify PHP version (7.4+)
- Ensure all extensions installed

---

## 💡 Pro Tips

1. **Backup Before Updates**
   ```bash
   cp data/isp_erp.db data/backups/backup_$(date +%Y%m%d).db
   ```

2. **Monitor Sync**
   ```bash
   tail -f data/app.log
   ```

3. **Check Database**
   ```bash
   sqlite3 data/isp_erp.db "SELECT COUNT(*) FROM uisp_invoices"
   ```

4. **Reset if Needed**
   ```bash
   rm data/isp_erp.db
   # Will auto-recreate on next access
   ```

---

## 🎓 Learning Path

### Week 1: Setup & Basics
- Install system
- Run initial sync
- Explore dashboard
- Review chart of accounts

### Week 2: Daily Operations
- Record expenses
- Manage cashbook
- Import bank statements
- Generate reports

### Week 3: Advanced
- Configure reconciliation
- Set up workflows
- Customize categories
- Optimize automation

### Week 4: Mastery
- Financial analysis
- Custom reports
- Period closing
- System optimization

---

## 📞 Getting Help

### Self-Help Resources
1. Check README.md
2. Review error logs
3. Search project docs
4. Test in development mode

### System Information
```bash
# Get version
cat manifest.json | grep version

# Check PHP
php -v

# Check extensions
php -m

# Database info
sqlite3 data/isp_erp.db ".tables"
```

---

## 🎉 You're Ready!

### Next Actions:
1. ☐ Install the system
2. ☐ Configure UISP connection
3. ☐ Run initial sync
4. ☐ Review imported data
5. ☐ Start using features

### Expected Timeline:
- Installation: 5 minutes
- Initial sync: 2-5 minutes
- Learning basics: 30 minutes
- Full proficiency: 1 week

---

## 📊 Success Indicators

After 24 hours, you should see:
- ✅ Invoices synced from UISP
- ✅ Payments imported
- ✅ Dashboard showing real data
- ✅ Journal entries created
- ✅ Cashbook updated

---

## 🚀 Ready to Transform Your ISP Accounting?

**Everything you need is in this package.**
**The system works out of the box.**
**Just install and go!**

---

**Questions? Check the comprehensive documentation in README.md**

**Good luck! 🎯**
