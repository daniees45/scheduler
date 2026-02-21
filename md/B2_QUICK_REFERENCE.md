# Backflaze B2 - Quick Reference

## Installation (One-Time Setup)

```bash
# Install AWS SDK
cd /Applications/XAMPP/xamppfiles/htdocs/vvu-scheduler
composer require aws/aws-sdk-php
```

## Configuration

Edit `config/b2_config.php`:

```php
'enabled' => true,              // Set to false to disable B2
'account_id' => 'YOUR_ACCOUNT_ID',
'app_key_id' => 'YOUR_APP_KEY_ID',
'app_key' => 'YOUR_APP_KEY',
'bucket_name' => 'vvu-scheduler',
'region' => 'us-west-002',      // or us-east-005, eu-central-003, etc.
'endpoint' => 'https://s3.backblazeb2.com',
```

## Testing

```bash
# Test B2 connection
php test_b2.php

# Should output:
# ✓ B2Storage class loaded successfully
# ✓ B2 is enabled
# ✓ Upload successful
# ✓ All Tests Passed!
```

## Usage in Code

```php
require_once 'lib/B2Storage.php';
$b2 = new B2Storage();

// Upload file
$result = $b2->upload('/path/to/local.csv', 'csv/final/schedule.csv');

// Upload content directly
$result = $b2->uploadContent($csvData, 'csv/general/courses.csv');

// Download file
$result = $b2->download('csv/final/schedule.csv');
$content = $result['content'];

// List files
$result = $b2->listFiles('csv/final/', 10);
foreach ($result['files'] as $file) {
    echo $file['key'] . "\n";
}

// Delete file
$result = $b2->delete('csv/test/old_file.csv');

// Check if file exists
if ($b2->exists('csv/final/schedule.csv')) {
    // File exists
}
```

## Updated Endpoints

These endpoints now use B2 automatically:

- `web/api/extract_pdf.php` - PDF extraction → B2
- `web/api/cleanup_data.php` - Data cleaning → B2
- `web/api/get_recent_schedules.php` - Lists from B2

## Quick Commands

```bash
# Disable B2 (use local storage)
# Edit config/b2_config.php: 'enabled' => false,

# Check B2 storage in Backblaze Console
# Visit: https://secure.backblaze.com > Buckets > vvu-scheduler

# View upload logs
tail -f /Applications/XAMPP/xamppfiles/logs/php_error_log | grep "B2"

# Test specific endpoint
curl -X POST http://localhost/vvu-scheduler/web/api/extract_pdf.php \
  -F "pdf_file=@test.pdf" \
  -F "output_folder=csv/general/"
```

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Class S3Client not found | Run: `composer require aws/aws-sdk-php` |
| B2 Upload Error: Access Denied | Check app key has "writeFiles" capability |
| NoSuchBucket error | Verify bucket name and account ID match |
| Files still saving locally | Set `'enabled' => true` in config |
| Region connection failed | Try different region: us-east-005, eu-central-003 |

## File Structure

```
vvu-scheduler/
├── config/
│   └── b2_config.php           # B2 credentials and settings
├── lib/
│   └── B2Storage.php           # B2 helper class
├── vendor/                     # Composer dependencies (AWS SDK)
├── test_b2.php                 # Test B2 connection
└── B2_SETUP_GUIDE.md          # Full documentation
```

## Cost Estimate

**10GB storage + 1K operations/day**:
- Storage: $0.06/month
- Operations: $0.01/month
- **Total: ~$0.07/month** 💰 (4x cheaper than R2!)

## Regions Available

- `us-west-002` - Oregon, USA (default)
- `us-east-005` - South Carolina, USA
- `eu-central-003` - Zurich, Switzerland
- `ap-southeast-001` - Singapore
- `b2-private` - Private (requires explicit connection)

## Security Checklist

- ✅ Add `config/b2_config.php` to `.gitignore`
- ✅ Use environment variables for production
- ✅ Limit app key to specific bucket
- ✅ Enable fallback_to_local for redundancy
- ✅ Rotate app keys every 90 days
