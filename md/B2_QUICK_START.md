# Backblaze B2 - Quick Start Guide

## 🚀 60-Second Setup

```
1. composer require aws/aws-sdk-php           (30 sec)
2. Create B2 bucket at backblaze.com         (20 sec)
3. Generate app key in B2 console            (5 sec)
4. Edit config/b2_config.php                 (5 sec)
5. Run: php test_b2.php                      (✓ Done!)
```

## 📋 Create B2 Bucket

```
https://secure.backblaze.com
    ↓
Buckets > Create Bucket
    ↓
Name: vvu-scheduler
Type: Private (restrict to API)
    ↓
✓ Created!
```

## 🔑 Generate App Key

```
Account (top right) > App Keys
    ↓
Add Application Key
    ↓
Name: vvu-scheduler-api
Capabilities: (all 4 checked)
  ✓ listFiles
  ✓ readFiles
  ✓ writeFiles
  ✓ listBuckets
Bucket: vvu-scheduler
    ↓
Create & Copy Credentials:
  - Master Application Key ID
  - Master Application Key
  - Account ID
```

## ⚙️ Configure (Choose One)

### Option 1: Edit File (Easiest)
```bash
vi config/b2_config.php
  ↓
Replace:
  'account_id' => 'YOUR_ACCOUNT_ID'
  'app_key_id' => 'YOUR_KEY_ID'
  'app_key' => 'YOUR_KEY'
  ↓
Save & Done!
```

### Option 2: Environment Variables (Secure)
```bash
# Add to ~/.zshrc or ~/.bash_profile
export B2_ACCOUNT_ID="abc123"
export B2_APP_KEY_ID="key123"
export B2_APP_KEY="secret123"
export B2_BUCKET="vvu-scheduler"
export B2_REGION="us-west-002"

# Reload:
source ~/.zshrc
```

## ✅ Test

```bash
php test_b2.php

# Expected output:
✓ B2Storage class loaded successfully
✓ B2 is enabled
✓ Upload successful
✓ Download successful
✓ All Tests Passed!
```

## 🎯 Used Automatically

```
PDF Upload → extract_pdf.php → B2Storage → Backblaze B2
Data Cleanup → cleanup_data.php → B2Storage → Backblaze B2
Schedule Gen → main_web.py → B2Storage → Backblaze B2
Recent Files → get_recent_schedules.php → B2Storage → Backblaze B2
```

## 💰 See Your Costs

```
https://secure.backblaze.com > Account Settings > Billing
    ↓
View:
  - Storage used (GB)
  - API calls
  - Estimated cost
```

## 🔧 Common Commands

```bash
# Test connection
php test_b2.php

# Check B2 console
open https://secure.backblaze.com

# View storage usage
# https://secure.backblaze.com > Buckets > vvu-scheduler

# Disable B2 (use local)
# Edit config/b2_config.php: 'enabled' => false

# View logs
tail -f /Applications/XAMPP/xamppfiles/logs/php_error_log | grep B2
```

## 🆘 Quick Troubleshooting

| Problem | Fix |
|---------|-----|
| "Class S3Client not found" | `composer require aws/aws-sdk-php` |
| "Access Denied" | Check app key capabilities (all 4 checked?) |
| "NoSuchBucket" | Verify bucket name matches exactly |
| Still saving local | Set `'enabled' => true` in config |
| "Unknown region" | Use: us-west-002, us-east-005, or eu-central-003 |

## 🌍 Available Regions

```
• us-west-002 (Oregon) ← DEFAULT
• us-east-005 (South Carolina)
• eu-central-003 (Zurich)
• ap-southeast-001 (Singapore)
```

## 📊 Cost Estimation

```
10GB storage:              $0.06/month
1000 API calls/day:        $0.01/month
                          ──────────────
Total:                     $0.07/month 💰

(vs R2: $0.30/month)
(vs AWS S3: $1.23/month)
```

## ✨ Features

✅ Automatic failover to local storage  
✅ All files backed up in database  
✅ Free bandwidth (no egress charges)  
✅ 10GB free tier available  
✅ Works with existing files  
✅ Can switch anytime  

## 📚 Full Docs

- **Setup**: [B2_SETUP_GUIDE.md](B2_SETUP_GUIDE.md)
- **Commands**: [B2_QUICK_REFERENCE.md](B2_QUICK_REFERENCE.md)
- **Checklist**: [B2_SETUP_CHECKLIST.md](B2_SETUP_CHECKLIST.md)
- **Technical**: [B2_IMPLEMENTATION_SUMMARY.md](B2_IMPLEMENTATION_SUMMARY.md)

---

**Status**: ✅ Ready to use  
**Cost Savings**: $0.23/month vs R2  
**Reliability**: S3-compatible with automatic fallback
