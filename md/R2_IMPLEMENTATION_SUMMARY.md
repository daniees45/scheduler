# Cloudflare R2 Integration - Implementation Summary

## ✅ Completed Implementation

I've successfully integrated **Cloudflare R2** cloud storage into your VVU Scheduler project. Here's what was implemented:

### Files Created

1. **[config/r2_config.php](config/r2_config.php)** - R2 configuration file with credentials and settings
2. **[lib/R2Storage.php](lib/R2Storage.php)** - Complete R2 storage helper class with S3-compatible operations
3. **[R2_SETUP_GUIDE.md](R2_SETUP_GUIDE.md)** - Comprehensive setup and configuration guide
4. **[R2_QUICK_REFERENCE.md](R2_QUICK_REFERENCE.md)** - Quick reference for common operations
5. **[setup_r2.sh](setup_r2.sh)** - Automated setup script
6. **[test_r2.php](test_r2.php)** - Test script to verify R2 configuration
7. **[.env.r2.example](.env.r2.example)** - Environment variable template

### Files Modified

1. **[web/api/extract_pdf.php](web/api/extract_pdf.php)** - Now uploads PDF extractions to R2
2. **[web/api/cleanup_data.php](web/api/cleanup_data.php)** - Now uploads cleaned data to R2
3. **[web/api/get_recent_schedules.php](web/api/get_recent_schedules.php)** - Lists schedules from R2 or local

### Key Features

✅ **Automatic Fallback** - If R2 is unavailable, files are automatically saved locally  
✅ **Dual Storage** - Files can be stored in both R2 and database simultaneously  
✅ **Migration Support** - Works seamlessly with existing local files  
✅ **S3 Compatible** - Uses industry-standard AWS SDK for PHP  
✅ **Secure** - Supports environment variables for credential management  
✅ **Cost Effective** - Estimated $0.30/month for typical usage  

## 🚀 How to Get Started

### Quick Setup (5 minutes)

1. **Install AWS SDK:**
   ```bash
   cd /Applications/XAMPP/xamppfiles/htdocs/vvu-scheduler
   ./setup_r2.sh
   ```

2. **Get Cloudflare R2 Credentials:**
   - Go to https://dash.cloudflare.com
   - Navigate to R2 Object Storage
   - Create a bucket named `vvu-scheduler`
   - Go to "Manage R2 API Tokens"
   - Create token with "Object Read & Write" permissions
   - Copy: Account ID, Access Key ID, Secret Access Key

3. **Configure:**
   - Edit `config/r2_config.php`
   - Replace placeholder values with your credentials
   - Or set environment variables (see [.env.r2.example](.env.r2.example))

4. **Test:**
   ```bash
   php test_r2.php
   ```

### Usage

Once configured, R2 is used automatically:

- **PDF Extraction** - Files uploaded via "PDF to CSV" page → R2
- **Data Cleanup** - Cleaned CSV files → R2
- **Schedule Generation** - Generated schedules → R2
- **Recent Schedules** - Listed from R2 in dropdowns

### Disable R2 (Use Local Storage Only)

If you want to use local storage instead:

1. Edit `config/r2_config.php`
2. Set `'enabled' => false,`
3. All files will save to local `csv/` directories

## 📊 Storage Architecture

```
┌─────────────────┐
│  Upload File    │
└────────┬────────┘
         │
         ▼
┌─────────────────┐      YES     ┌─────────────────┐
│  R2 Enabled?    │─────────────▶│  Upload to R2   │
└────────┬────────┘              └────────┬────────┘
         │                                 │
         │ NO                          SUCCESS
         │                                 │
         ▼                                 ▼
┌─────────────────┐              ┌─────────────────┐
│  Save Locally   │              │  Save to DB     │
└─────────────────┘              │   (Backup)      │
                                 └─────────────────┘
```

## 🔑 Key Classes and Methods

### R2Storage Class

```php
$r2 = new R2Storage();

// Core methods
$r2->upload($filePath, $key, $metadata)
$r2->uploadContent($content, $key, $metadata)
$r2->download($key, $savePath)
$r2->delete($key)
$r2->listFiles($prefix, $maxKeys)
$r2->exists($key)
$r2->getPublicUrl($key)
$r2->getSignedUrl($key, $expiresIn)
$r2->isEnabled()
```

## 📝 Configuration Options

| Option | Description | Default |
|--------|-------------|---------|
| `enabled` | Enable/disable R2 | `true` |
| `account_id` | Cloudflare account ID | Required |
| `access_key_id` | R2 access key | Required |
| `secret_access_key` | R2 secret key | Required |
| `bucket` | R2 bucket name | `vvu-scheduler` |
| `endpoint` | R2 endpoint URL | Auto-constructed |
| `public_url` | Custom domain (optional) | `null` |
| `fallback_to_local` | Save locally if R2 fails | `true` |
| `local_path` | Local storage directory | `../csv/` |

## 🛡️ Security Best Practices

✅ **Implemented:**
- Credential sanitization
- Fallback mechanisms
- Error logging (not credential exposure)
- File path validation

⚠️ **Recommendations:**
1. Add `config/r2_config.php` to `.gitignore`
2. Use environment variables in production
3. Rotate API tokens every 90 days
4. Limit token permissions to specific bucket
5. Enable R2 access logs in Cloudflare dashboard

## 💰 Cost Analysis

**Cloudflare R2 Pricing (2024):**
- Storage: $0.015 per GB/month
- Class A (writes): $4.50 per million
- Class B (reads): $0.36 per million
- Egress: **FREE** (no bandwidth charges!)

**Example Usage:**
- 10GB storage
- 1,000 writes/day (~30K/month)
- 5,000 reads/day (~150K/month)

**Monthly Cost:**
- Storage: $0.15
- Writes: $0.14
- Reads: $0.05
- **Total: ~$0.34/month** 💵

## 🔍 Testing

Run the test script to verify everything works:

```bash
php test_r2.php
```

**Expected output:**
```
✓ R2Storage class loaded successfully
✓ R2 is enabled
✓ Upload successful
✓ File exists in R2
✓ Download successful
✓ List successful
✓ Delete successful
✓ All Tests Passed!
```

## 📚 Documentation

- **[R2_SETUP_GUIDE.md](R2_SETUP_GUIDE.md)** - Complete setup instructions
- **[R2_QUICK_REFERENCE.md](R2_QUICK_REFERENCE.md)** - Quick command reference
- **[Cloudflare R2 Docs](https://developers.cloudflare.com/r2/)** - Official documentation
- **[AWS SDK for PHP](https://docs.aws.amazon.com/sdk-for-php/)** - SDK documentation

## 🐛 Troubleshooting

| Issue | Solution |
|-------|----------|
| "Class S3Client not found" | Run: `composer require aws/aws-sdk-php` |
| "R2 Upload Error: Access Denied" | Check API token has "Object Read & Write" |
| "R2 Client Error" in logs | Verify credentials in config file |
| Files still saving locally | Set `'enabled' => true` in config |
| Endpoint connection timeout | Check firewall, verify endpoint URL format |

## 🔄 Migration from Local to R2

Existing local files will continue to work. To migrate:

**Option 1: Manual (Cloudflare Dashboard)**
1. Go to https://dash.cloudflare.com > R2 > vvu-scheduler
2. Click "Upload" and select files from `csv/` directories
3. Maintain directory structure (csv/general/, csv/department/, csv/final/)

**Option 2: Programmatic**
```php
require_once 'lib/R2Storage.php';
$r2 = new R2Storage();

$localFiles = glob('csv/final/*.csv');
foreach ($localFiles as $file) {
    $key = str_replace('../', '', $file);
    $result = $r2->upload($file, $key);
    echo $result['success'] ? "✓ " : "✗ ";
    echo basename($file) . "\n";
}
```

## ✨ Next Steps

1. **Run setup:** `./setup_r2.sh`
2. **Test connection:** `php test_r2.php`
3. **Try uploading** a PDF via the scheduler UI
4. **Check Cloudflare** dashboard to see your file in R2
5. **(Optional) Migrate** existing local files to R2

## 📞 Support

- **R2 Issues:** Check [R2_SETUP_GUIDE.md](R2_SETUP_GUIDE.md) troubleshooting section
- **Code Issues:** Review error logs at `/Applications/XAMPP/xamppfiles/logs/php_error_log`
- **Cloudflare Support:** https://developers.cloudflare.com/r2/

---

**Implementation Date:** $(date +%Y-%m-%d)  
**Status:** ✅ Complete and Ready to Use  
**Backward Compatible:** Yes - Existing local storage still works
