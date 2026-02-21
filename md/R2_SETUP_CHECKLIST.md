# Cloudflare R2 Integration - Setup Checklist

Use this checklist to set up R2 storage for your VVU Scheduler.

## 📋 Pre-Setup Requirements

- [ ] PHP 7.4+ installed (check with: `php -v`)
- [ ] Composer installed (check with: `composer --version`)
- [ ] Cloudflare account created at https://dash.cloudflare.com
- [ ] XAMPP running (for local testing)

---

## 🔧 Step 1: Install Dependencies

- [ ] Navigate to project directory:
  ```bash
  cd /Applications/XAMPP/xamppfiles/htdocs/vvu-scheduler
  ```

- [ ] Run setup script:
  ```bash
  ./setup_r2.sh
  ```
  
  **OR** manually install AWS SDK:
  ```bash
  composer require aws/aws-sdk-php
  ```

- [ ] Verify `vendor/` directory created
- [ ] Verify `vendor/autoload.php` exists

---

## ☁️ Step 2: Configure Cloudflare R2

### 2.1 Create R2 Bucket

- [ ] Go to https://dash.cloudflare.com
- [ ] Click **R2 Object Storage** in sidebar
- [ ] Click **Create bucket**
- [ ] Enter bucket name: `vvu-scheduler` (or your preference)
- [ ] Click **Create bucket**
- [ ] Copy your **Account ID** (shown at top of R2 page)

### 2.2 Generate API Token

- [ ] In R2 dashboard, click **Manage R2 API Tokens**
- [ ] Click **Create API Token**
- [ ] Set token name: `vvu-scheduler-api`
- [ ] Set permissions:
  - [ ] **Object Read & Write** ✓
  - [ ] Apply to specific bucket: `vvu-scheduler` ✓
- [ ] Click **Create API Token**
- [ ] **⚠️ IMPORTANT:** Copy these credentials NOW (shown only once):
  - [ ] Access Key ID: `____________________________________`
  - [ ] Secret Access Key: `____________________________________`

---

## ⚙️ Step 3: Configure Application

Choose ONE method:

### Method A: Edit Config File (Easier)

- [ ] Open `config/r2_config.php` in editor
- [ ] Replace placeholders:
  ```php
  'account_id' => 'YOUR_ACTUAL_ACCOUNT_ID',
  'access_key_id' => 'YOUR_ACTUAL_ACCESS_KEY_ID',
  'secret_access_key' => 'YOUR_ACTUAL_SECRET_ACCESS_KEY',
  'bucket' => 'vvu-scheduler',
  'endpoint' => 'https://YOUR_ACCOUNT_ID.r2.cloudflarestorage.com',
  ```
- [ ] Save file
- [ ] Add to `.gitignore`:
  ```bash
  echo "config/r2_config.php" >> .gitignore
  ```

### Method B: Use Environment Variables (More Secure)

- [ ] Edit your shell profile (`~/.zshrc` or `~/.bash_profile`):
  ```bash
  export R2_ACCOUNT_ID="your_account_id"
  export R2_ACCESS_KEY_ID="your_access_key_id"
  export R2_SECRET_ACCESS_KEY="your_secret_access_key"
  export R2_BUCKET="vvu-scheduler"
  export R2_ENDPOINT="https://your_account_id.r2.cloudflarestorage.com"
  ```
- [ ] Save and reload shell:
  ```bash
  source ~/.zshrc
  ```
- [ ] Verify variables set:
  ```bash
  echo $R2_ACCOUNT_ID
  ```

---

## ✅ Step 4: Test Configuration

- [ ] Run test script:
  ```bash
  php test_r2.php
  ```

- [ ] Expected output:
  ```
  ✓ R2Storage class loaded successfully
  ✓ R2 is enabled
  Testing upload...
  ✓ Upload successful
  ✓ File exists in R2
  ✓ Download successful
  ✓ List successful
  ✓ Delete successful
  ✓ All Tests Passed!
  ```

- [ ] If tests fail, see [Troubleshooting](#troubleshooting) section

---

## 🎯 Step 5: Verify in Web Interface

### 5.1 Test PDF Upload

- [ ] Open browser: http://localhost/vvu-scheduler/web/pdf_to_csv.php
- [ ] Upload a PDF file
- [ ] Select output folder: `csv/general/`
- [ ] Check "Validate PDF structure"
- [ ] Click **Extract PDF**
- [ ] Look for success message: **"Uploaded to R2: csv/general/filename.csv"**

### 5.2 Verify in Cloudflare Dashboard

- [ ] Go to https://dash.cloudflare.com
- [ ] Navigate to **R2 > vvu-scheduler**
- [ ] Click into `csv/general/` folder
- [ ] Verify your uploaded file appears

### 5.3 Test Schedule Generation

- [ ] Go to http://localhost/vvu-scheduler/web/generate.php
- [ ] Select "General Schedule" type
- [ ] Click **Generate Schedule**
- [ ] After generation completes, check R2 bucket for new file in `csv/final/`

---

## 🔍 Troubleshooting

### Issue: "Class S3Client not found"

- [ ] Run: `composer require aws/aws-sdk-php`
- [ ] Check `vendor/aws/` directory exists
- [ ] Verify `require_once __DIR__ . '/../../vendor/autoload.php';` in R2Storage.php

### Issue: "R2 Upload Error: Access Denied"

- [ ] Verify API token has "Object Read & Write" permission
- [ ] Check token is enabled in Cloudflare dashboard
- [ ] Ensure bucket name matches config
- [ ] Try regenerating API token

### Issue: "R2 Client Error" in logs

- [ ] Verify `account_id` is correct (32-character hex string)
- [ ] Check endpoint format: `https://<account_id>.r2.cloudflarestorage.com`
- [ ] Ensure no extra spaces in credentials
- [ ] Test credentials with: `php test_r2.php`

### Issue: Files still saving locally

- [ ] Check `'enabled' => true,` in `config/r2_config.php`
- [ ] Restart XAMPP/Apache
- [ ] Clear PHP opcache: `php -r "opcache_reset();"`
- [ ] Check error logs: `tail -f /Applications/XAMPP/xamppfiles/logs/php_error_log`

### Issue: Composer not found

- [ ] Install Composer:
  ```bash
  brew install composer
  ```
  **OR** download from https://getcomposer.org/download/

---

## 📊 Post-Setup Validation

- [ ] R2 bucket contains test files
- [ ] No errors in PHP error log
- [ ] File uploads show "Uploaded to R2" message
- [ ] Recent schedules dropdown populates from R2
- [ ] Cloudflare R2 dashboard shows activity

---

## 🔐 Security Checklist

- [ ] `config/r2_config.php` added to `.gitignore`
- [ ] API token has minimal permissions (Read & Write only)
- [ ] Token limited to specific bucket (not all buckets)
- [ ] Credentials not committed to Git
- [ ] Environment variables used (production)
- [ ] Plan to rotate API keys every 90 days

---

## 📈 Monitoring Setup

- [ ] Bookmark Cloudflare R2 dashboard
- [ ] Check storage usage weekly
- [ ] Monitor API operation count
- [ ] Review cost estimates monthly
- [ ] Set up Cloudflare email alerts (optional)

---

## 🚀 Optional Enhancements

- [ ] Set up custom R2 domain for public access
- [ ] Enable R2 access logs
- [ ] Implement file lifecycle policies (auto-delete old files)
- [ ] Add R2 metrics to admin dashboard
- [ ] Create backup script to sync R2 → Local

---

## 📝 Documentation Review

- [ ] Read [R2_SETUP_GUIDE.md](R2_SETUP_GUIDE.md) for detailed instructions
- [ ] Review [R2_QUICK_REFERENCE.md](R2_QUICK_REFERENCE.md) for common commands
- [ ] Check [R2_ARCHITECTURE_DIAGRAM.md](R2_ARCHITECTURE_DIAGRAM.md) for system overview
- [ ] Bookmark [Cloudflare R2 Docs](https://developers.cloudflare.com/r2/)

---

## ✨ You're Done!

**R2 integration is complete when:**
- ✅ All tests pass
- ✅ Files upload to R2 successfully
- ✅ Cloudflare dashboard shows your files
- ✅ No errors in logs

**Estimated Monthly Cost:** $0.30 - $1.00 (depending on usage)

**Next Steps:**
1. Start using the scheduler normally
2. Monitor R2 storage usage in Cloudflare dashboard
3. Consider migrating existing local files to R2
4. Review and rotate API tokens in 90 days

---

## 📞 Support Resources

- **Implementation Docs:** See `R2_*.md` files in project root
- **Cloudflare R2 Docs:** https://developers.cloudflare.com/r2/
- **AWS SDK for PHP:** https://docs.aws.amazon.com/sdk-for-php/
- **Cloudflare Community:** https://community.cloudflare.com/

---

**Setup Completed:** ☐ Date: _______________  
**Tested By:** _______________  
**Status:** ☐ Production Ready | ☐ Testing | ☐ Issues Found
