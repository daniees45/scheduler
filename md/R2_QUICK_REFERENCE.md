# Cloudflare R2 - Quick Reference

## Installation (One-Time Setup)

```bash
# 1. Run the setup script
cd /Applications/XAMPP/xamppfiles/htdocs/vvu-scheduler
./setup_r2.sh

# OR manually:
composer require aws/aws-sdk-php
```

## Configuration

Edit `config/r2_config.php`:

```php
'enabled' => true,              // Set to false to disable R2
'account_id' => 'YOUR_ID',
'access_key_id' => 'YOUR_KEY',
'secret_access_key' => 'YOUR_SECRET',
'bucket' => 'vvu-scheduler',
'endpoint' => 'https://YOUR_ID.r2.cloudflarestorage.com',
```

## Testing

```bash
# Test R2 connection
php test_r2.php

# Should output:
# ✓ R2Storage class loaded successfully
# ✓ R2 is enabled
# ✓ Upload successful
# ✓ All Tests Passed!
```

## Usage in Code

```php
require_once 'lib/R2Storage.php';
$r2 = new R2Storage();

// Upload file
$result = $r2->upload('/path/to/local.csv', 'csv/final/schedule.csv');

// Upload content directly
$result = $r2->uploadContent($csvData, 'csv/general/courses.csv');

// Download file
$result = $r2->download('csv/final/schedule.csv');
$content = $result['content'];

// List files
$result = $r2->listFiles('csv/final/', 10);
foreach ($result['files'] as $file) {
    echo $file['key'] . "\n";
}

// Delete file
$result = $r2->delete('csv/test/old_file.csv');

// Check if file exists
if ($r2->exists('csv/final/schedule.csv')) {
    // File exists
}
```

## Updated Endpoints

These endpoints now use R2 automatically:

- `web/api/extract_pdf.php` - PDF extraction → R2
- `web/api/cleanup_data.php` - Data cleaning → R2
- `web/api/get_recent_schedules.php` - Lists from R2

## Quick Commands

```bash
# Disable R2 (use local storage)
# Edit config/r2_config.php: 'enabled' => false,

# Check R2 storage in Cloudflare
# Visit: https://dash.cloudflare.com > R2 > vvu-scheduler

# View upload logs
tail -f /Applications/XAMPP/xamppfiles/logs/php_error_log | grep "R2"

# Test specific endpoint
curl -X POST http://localhost/vvu-scheduler/web/api/extract_pdf.php \
  -F "pdf_file=@test.pdf" \
  -F "output_folder=csv/general/"
```

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Class S3Client not found | Run: `composer require aws/aws-sdk-php` |
| R2 Upload Error: Access Denied | Check API token permissions (Read & Write) |
| Files still saving locally | Set `'enabled' => true` in config |
| Endpoint connection failed | Verify endpoint format includes account ID |

## File Structure

```
vvu-scheduler/
├── config/
│   └── r2_config.php         # R2 credentials and settings
├── lib/
│   └── R2Storage.php         # R2 helper class
├── vendor/                   # Composer dependencies (AWS SDK)
├── setup_r2.sh              # Setup script
├── test_r2.php              # Test R2 connection
└── R2_SETUP_GUIDE.md        # Full documentation
```

## Cost Estimate

**10GB storage + 1K operations/day**:
- Storage: $0.15/month
- Operations: $0.15/month
- **Total: ~$0.30/month** 💰

## Security Checklist

- ✅ Add `config/r2_config.php` to `.gitignore`
- ✅ Use environment variables for production
- ✅ Limit API token to specific bucket
- ✅ Enable fallback_to_local for redundancy
- ✅ Rotate API keys every 90 days
