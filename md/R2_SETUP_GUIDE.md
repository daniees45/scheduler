# Cloudflare R2 Storage Integration

This project now supports **Cloudflare R2** for storing CSV files instead of the local filesystem.

## Features

✅ **Automatic Fallback**: If R2 is unavailable, files are stored locally  
✅ **Dual Storage**: Files can be stored in both R2 and database  
✅ **Migration Support**: Works with existing local files during transition  
✅ **S3 Compatible**: Uses AWS SDK for PHP via R2's S3-compatible API  

## Setup Instructions

### 1. Install AWS SDK for PHP (Required for R2)

Run this command in your project root:

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/vvu-scheduler
composer require aws/aws-sdk-php
```

If you don't have Composer installed:

```bash
# macOS
brew install composer

# Or download from https://getcomposer.org/download/
```

### 2. Create Cloudflare R2 Bucket

1. Go to [Cloudflare Dashboard](https://dash.cloudflare.com)
2. Navigate to **R2 Object Storage**
3. Click **Create bucket**
4. Name it `vvu-scheduler` (or your preferred name)
5. Choose a region (automatic is fine)

### 3. Generate R2 API Tokens

1. In R2 dashboard, click **Manage R2 API Tokens**
2. Click **Create API Token**
3. Set permissions:
   - **Object Read & Write** (for full access)
   - Select your bucket or allow all buckets
4. Copy the credentials:
   - **Access Key ID**
   - **Secret Access Key**
   - **Account ID** (shown in R2 dashboard)

### 4. Configure R2 Credentials

**Option A: Environment Variables (Recommended for Security)**

Add to your `.bash_profile`, `.zshrc`, or XAMPP config:

```bash
export R2_ACCOUNT_ID="your_cloudflare_account_id"
export R2_ACCESS_KEY_ID="your_r2_access_key_id"
export R2_SECRET_ACCESS_KEY="your_r2_secret_access_key"
export R2_BUCKET="vvu-scheduler"
export R2_ENDPOINT="https://your_account_id.r2.cloudflarestorage.com"
```

Then restart your terminal/XAMPP.

**Option B: Direct Config File**

Edit `config/r2_config.php` and replace the placeholder values:

```php
'account_id' => 'abc123def456',
'access_key_id' => 'your_actual_access_key',
'secret_access_key' => 'your_actual_secret_key',
'bucket' => 'vvu-scheduler',
'endpoint' => 'https://abc123def456.r2.cloudflarestorage.com',
```

⚠️ **Security**: If using Option B, add `config/r2_config.php` to `.gitignore` to prevent exposing credentials.

### 5. Test the Integration

1. Go to **PDF to CSV** page in your scheduler
2. Upload a PDF file
3. Select an output folder
4. Submit extraction

If R2 is configured correctly, you'll see: **"Uploaded to R2: csv/general/filename.csv"**

If R2 fails, it will fallback: **"Saved locally to csv/general/filename.csv"**

## How It Works

### Files Updated

- `lib/R2Storage.php` - Main R2 helper class with upload/download/list methods
- `config/r2_config.php` - Configuration file for R2 credentials
- `web/api/extract_pdf.php` - PDF extraction now uploads to R2
- `web/api/cleanup_data.php` - Data cleaning now uploads to R2
- `web/api/get_recent_schedules.php` - Lists schedules from R2 or local

### Storage Priority

1. **R2 Enabled**: Files are uploaded to R2 first
2. **R2 Fails**: Automatic fallback to local filesystem
3. **Database**: All files are still saved to `csv_storage` table as backup

### R2 File Structure

Files mirror the local directory structure:

```
bucket: vvu-scheduler
├── csv/
│   ├── general/
│   │   └── general_courses.csv
│   ├── department/
│   │   ├── computing_science_rooms.csv
│   │   └── business_rooms.csv
│   └── final/
│       └── schedule_20240101_123456.csv
```

## Configuration Options

Edit `config/r2_config.php`:

| Option | Description | Default |
|--------|-------------|---------|
| `enabled` | Enable/disable R2 (set to `false` to use local only) | `true` |
| `fallback_to_local` | Save locally if R2 fails | `true` |
| `public_url` | Custom R2 domain (optional for public access) | `null` |
| `paths` | Directory structure in R2 bucket | See config file |

## Disabling R2

To temporarily use local storage only:

1. Edit `config/r2_config.php`
2. Set `'enabled' => false,`
3. All files will now save to local `csv/` directories

## Migration from Local to R2

Existing local files will continue to work. To migrate:

1. Use R2's web interface to upload files from `csv/` directories
2. Or use the R2Storage helper:

```php
require_once 'lib/R2Storage.php';
$r2 = new R2Storage();

// Upload a local file
$result = $r2->upload(
    '/path/to/local/file.csv',
    'csv/final/file.csv'
);
```

## Troubleshooting

### "R2 Client Error" in logs

- Check your credentials in `config/r2_config.php`
- Verify endpoint format: `https://<account_id>.r2.cloudflarestorage.com`
- Ensure AWS SDK is installed: `composer require aws/aws-sdk-php`

### "R2 Upload Error: Access Denied"

- Verify API token has **Object Read & Write** permissions
- Check bucket name matches your configuration
- Confirm token is enabled in Cloudflare dashboard

### Files still saving locally

- Check `'enabled' => true` in `config/r2_config.php`
- Look in error logs for R2 connection issues
- Verify environment variables are loaded (run `echo $R2_ACCOUNT_ID`)

### "Class 'Aws\S3\S3Client' not found"

- Install AWS SDK: `composer require aws/aws-sdk-php`
- Check `vendor/autoload.php` exists
- Verify PHP can access the Composer autoloader

## Cost Considerations

Cloudflare R2 pricing (as of 2024):

- **Storage**: $0.015 per GB/month
- **Class A Operations** (writes): $4.50 per million requests
- **Class B Operations** (reads): $0.36 per million requests
- **Egress**: **FREE** (no bandwidth charges)

Example: Storing 10GB with 1000 reads/writes per day:
- Storage: $0.15/month
- Operations: ~$0.15/month
- **Total: ~$0.30/month**

## Support

For R2-specific issues:
- [Cloudflare R2 Documentation](https://developers.cloudflare.com/r2/)
- [AWS SDK for PHP Docs](https://docs.aws.amazon.com/sdk-for-php/)

For project issues, check the logs:
- PHP errors: `/Applications/XAMPP/xamppfiles/logs/php_error_log`
- R2 errors: Logged via `error_log()` in PHP
