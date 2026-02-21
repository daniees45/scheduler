# Backblaze B2 - Setup Checklist

Use this checklist to set up B2 storage for your VVU Scheduler.

## 📋 Pre-Setup Requirements

- [ ] PHP 7.4+ installed (check with: `php -v`)
- [ ] Composer installed (check with: `composer --version`)
- [ ] Backblaze account created at https://secure.backblaze.com
- [ ] XAMPP running (for local testing)

---

## 🔧 Step 1: Install Dependencies

- [ ] Navigate to project directory:
  ```bash
  cd /Applications/XAMPP/xamppfiles/htdocs/vvu-scheduler
  ```

- [ ] Install AWS SDK:
  ```bash
  composer require aws/aws-sdk-php
  ```

- [ ] Verify `vendor/` directory created
- [ ] Verify `vendor/autoload.php` exists

---

## ☁️ Step 2: Configure Backblaze B2

### 2.1 Create B2 Bucket

- [ ] Go to https://secure.backblaze.com
- [ ] Click **Buckets** in the left sidebar
- [ ] Click **Create a Bucket**
- [ ] Enter bucket name: `vvu-scheduler` (or your preference)
- [ ] Select **Private** (restrict to API only)
- [ ] Click **Create Bucket**

### 2.2 Generate App Key

- [ ] Click your **Account** (top right corner)
- [ ] Go to **App Keys** or **Account > App Keys**
- [ ] Click **Add Application Key**
- [ ] Set options:
  - [ ] **Key Name**: `vvu-scheduler-api`
  - [ ] **Capabilities**: Select:
    - [ ] ✓ listBuckets
    - [ ] ✓ readFiles
    - [ ] ✓ writeFiles
    - [ ] ✓ listFiles
  - [ ] **Bucket Restrictions**: Select `vvu-scheduler`
- [ ] Click **Create Application Key**
- [ ] **⚠️ IMPORTANT:** Copy these credentials NOW (shown only once):
  - [ ] Master Application Key ID: `____________________________________`
  - [ ] Master Application Key: `____________________________________`
  - [ ] Account ID: `____________________________________`

---

## ⚙️ Step 3: Configure Application

Choose ONE method:

### Method A: Edit Config File (Easier)

- [ ] Open `config/b2_config.php` in editor
- [ ] Replace placeholders:
  ```php
  'account_id' => 'YOUR_ACTUAL_ACCOUNT_ID',
  'app_key_id' => 'YOUR_ACTUAL_APP_KEY_ID',
  'app_key' => 'YOUR_ACTUAL_APP_KEY',
  'bucket_name' => 'vvu-scheduler',
  'region' => 'us-west-002',  // or your preferred region
  'endpoint' => 'https://s3.backblazeb2.com',
  ```
- [ ] Save file
- [ ] Add to `.gitignore`:
  ```bash
  echo "config/b2_config.php" >> .gitignore
  ```

### Method B: Use Environment Variables (More Secure)

- [ ] Edit your shell profile (`~/.zshrc` or `~/.bash_profile`):
  ```bash
  export B2_ACCOUNT_ID="your_account_id"
  export B2_APP_KEY_ID="your_app_key_id"
  export B2_APP_KEY="your_app_key"
  export B2_BUCKET="vvu-scheduler"
  export B2_REGION="us-west-002"
  export B2_ENDPOINT="https://s3.backblazeb2.com"
  ```
- [ ] Save and reload shell:
  ```bash
  source ~/.zshrc
  ```
- [ ] Verify variables set:
  ```bash
  echo $B2_ACCOUNT_ID
  ```

---

## ✅ Step 4: Test Configuration

- [ ] Run test script:
  ```bash
  php test_b2.php
  ```

- [ ] Expected output:
  ```
  ✓ B2Storage class loaded successfully
  ✓ B2 is enabled
  Testing upload...
  ✓ Upload successful
  ✓ File exists in B2
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
- [ ] Look for success message: **"Uploaded to B2: csv/general/filename.csv"**

### 5.2 Verify in Backblaze Console

- [ ] Go to https://secure.backblaze.com
- [ ] Navigate to **Buckets > vvu-scheduler**
- [ ] Click into `csv/general/` folder
- [ ] Verify your uploaded file appears

### 5.3 Test Schedule Generation

- [ ] Go to http://localhost/vvu-scheduler/web/generate.php
- [ ] Select "General Schedule" type
- [ ] Click **Generate Schedule**
- [ ] After generation completes, check B2 bucket for new file in `csv/final/`

---

## 🔍 Troubleshooting

### Issue: "Class S3Client not found"

- [ ] Run: `composer require aws/aws-sdk-php`
- [ ] Check `vendor/aws/` directory exists
- [ ] Verify `require_once __DIR__ . '/../../vendor/autoload.php';` in B2Storage.php

### Issue: "B2 Upload Error: Access Denied" or "NoSuchBucket"

- [ ] Verify app key has all required capabilities (listFiles, readFiles, writeFiles)
- [ ] Check app key is not expired or revoked in Backblaze console
- [ ] Ensure bucket name matches exactly in config file
- [ ] Verify app key is restricted to the correct bucket

### Issue: "B2 Client Error" in logs

- [ ] Verify `account_id` matches your Backblaze account ID (usually 12 alphanumeric characters)
- [ ] Check app key ID format (usually starts with "app_")
- [ ] Ensure no extra spaces in credentials
- [ ] Test credentials with: `php test_b2.php`

### Issue: Files still saving locally

- [ ] Check `'enabled' => true,` in `config/b2_config.php`
- [ ] Restart XAMPP/Apache
- [ ] Clear PHP opcache: `php -r "opcache_reset();"`
- [ ] Check error logs: `tail -f /Applications/XAMPP/xamppfiles/logs/php_error_log`

### Issue: Composer not found

- [ ] Install Composer:
  ```bash
  brew install composer
  ```
  **OR** download from https://getcomposer.org/download/

### Issue: "Unknown region" error

- [ ] Valid regions: `us-west-002` (default), `us-east-005`, `eu-central-003`, `ap-southeast-001`
- [ ] Check spelling exactly (case-sensitive)
- [ ] Contact Backblaze support for available regions in your area

---

## 📊 Post-Setup Validation

- [ ] B2 bucket contains test files
- [ ] No errors in PHP error log
- [ ] File uploads show "Uploaded to B2" message
- [ ] Recent schedules dropdown populates from B2
- [ ] Backblaze B2 console shows activity in bucket

---

## 🔐 Security Checklist

- [ ] `config/b2_config.php` added to `.gitignore`
- [ ] App key has minimal permissions (only needed capabilities selected)
- [ ] Key limited to specific bucket (not all buckets)
- [ ] Credentials not committed to Git
- [ ] Environment variables used (production)
- [ ] Plan to rotate app keys every 90 days

---

## 📈 Monitoring Setup

- [ ] Bookmark Backblaze B2 console
- [ ] Check storage usage weekly
- [ ] Monitor API operation count
- [ ] Review cost estimates monthly
- [ ] Set up email alerts in account settings (optional)

---

## 🚀 Optional Enhancements

- [ ] Set up custom B2 domain for public access
- [ ] Enable B2 access logs
- [ ] Implement file lifecycle policies (auto-delete old files)
- [ ] Add B2 metrics to admin dashboard
- [ ] Create backup script to sync B2 → Local

---

## 📝 Documentation Review

- [ ] Read [B2_SETUP_GUIDE.md](B2_SETUP_GUIDE.md) for detailed instructions
- [ ] Review [B2_QUICK_REFERENCE.md](B2_QUICK_REFERENCE.md) for common commands
- [ ] Bookmark [Backblaze B2 Docs](https://www.backblaze.com/b2/docs/)
- [ ] Save [AWS SDK for PHP Docs](https://docs.aws.amazon.com/sdk-for-php/)

---

## ✨ You're Done!

**B2 integration is complete when:**
- ✅ All tests pass
- ✅ Files upload to B2 successfully
- ✅ Backblaze console shows your files
- ✅ No errors in logs

**Estimated Monthly Cost:** $0.07 - $0.50 (depending on usage)

**Savings vs R2:** ~75% cheaper (B2: $0.07/mo vs R2: $0.30/mo)

**Next Steps:**
1. Start using the scheduler normally
2. Monitor B2 storage usage in Backblaze console
3. Consider migrating existing local files to B2
4. Review and rotate app keys in 90 days

---

## 📞 Support Resources

- **B2 Documentation:** https://www.backblaze.com/b2/docs/
- **Implementation Docs:** See `B2_*.md` files in project root
- **AWS SDK for PHP:** https://docs.aws.amazon.com/sdk-for-php/
- **Backblaze Support:** https://support.backblaze.com/

---

**Setup Completed:** ☐ Date: _______________  
**Tested By:** _______________  
**Status:** ☐ Production Ready | ☐ Testing | ☐ Issues Found
