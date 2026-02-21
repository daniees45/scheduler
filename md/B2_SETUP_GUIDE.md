# Backblaze B2 Storage Integration

This project now supports **Backblaze B2** cloud storage for storing CSV files instead of the local filesystem.

## Features

✅ **Automatic Fallback**: If B2 is unavailable, files are stored locally  
✅ **Dual Storage**: Files can be stored in both B2 and database  
✅ **Migration Support**: Works with existing local files during transition  
✅ **S3 Compatible**: Uses AWS SDK for PHP via B2's S3-compatible API  
✅ **Cost Effective**: Starting at $6/TB/month (1 cent/GB/month) - best cloud pricing

## Setup Instructions

### 1. Install AWS SDK for PHP (Required for B2)

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

### 2. Create Backblaze B2 Bucket

1. Go to [Backblaze B2 Console](https://secure.backblaze.com)
2. Log in or create account (free tier available)
3. Click **Buckets** in the left sidebar
4. Click **Create a Bucket**
5. Name it `vvu-scheduler` (or your preferred name)
6. Choose **Private** (files only accessible via API/keys)

### 3. Generate B2 App Keys

1. Click your **Account** (top right)
2. Go to **App Keys**
3. Click **Add Application Key**
4. Set:
   - **Key Name**: `vvu-scheduler-api`
   - **Capabilities**: All (or select just "readFiles", "writeFiles", "listFiles")
   - **Bucket Restrictions**: `vvu-scheduler` (or your bucket name)
5. Generate the key and **copy these immediately** (shown only once):
   - **Application Key ID**
   - **Application Key**
6. Note your **Account ID** (shown at top of App Keys page)

### 4. Configure B2 Credentials

**Option A: Environment Variables (Recommended for Security)**

Add to your `.bash_profile`, `.zshrc`, or XAMPP config:

```bash
export B2_ACCOUNT_ID="your_account_id"
export B2_APP_KEY_ID="your_app_key_id"
export B2_APP_KEY="your_app_key"
export B2_BUCKET="vvu-scheduler"
export B2_REGION="us-west-002"
export B2_ENDPOINT="https://s3.backblazeb2.com"
```

Available regions: `us-west-002` (default), `us-east-005`, `eu-central-003`, etc.

Then restart your terminal/XAMPP.

**Option B: Direct Config File**

Edit `config/b2_config.php` and replace the placeholder values:

```php
'account_id' => 'your_account_id',
'app_key_id' => 'your_app_key_id',
'app_key' => 'your_app_key',
'bucket_name' => 'vvu-scheduler',
'region' => 'us-west-002',
'endpoint' => 'https://s3.backblazeb2.com',
```

⚠️ **Security**: If using Option B, add `config/b2_config.php` to `.gitignore` to prevent exposing credentials.

### 5. Test the Integration

1. Go to **PDF to CSV** page in your scheduler
2. Upload a PDF file
3. Select an output folder
4. Submit extraction

If B2 is configured correctly, you'll see: **"Uploaded to B2: csv/general/filename.csv"**

If B2 fails, it will fallback: **"Saved locally to csv/general/filename.csv"**

## How It Works

### Files Updated

- `lib/B2Storage.php` - Main B2 helper class with upload/download/list methods
- `config/b2_config.php` - Configuration file for B2 credentials
- `web/api/extract_pdf.php` - PDF extraction now uploads to B2
- `web/api/cleanup_data.php` - Data cleaning now uploads to B2
- `web/api/get_recent_schedules.php` - Lists schedules from B2 or local

### Storage Priority

1. **B2 Enabled**: Files are uploaded to B2 first
2. **B2 Fails**: Automatic fallback to local filesystem
3. **Database**: All files are still saved to `csv_storage` table as backup

### B2 File Structure

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

Edit `config/b2_config.php`:

| Option | Description | Default |
|--------|-------------|---------|
| `enabled` | Enable/disable B2 (set to `false` to use local only) | `true` |
| `account_id` | Your Backblaze account ID | Required |
| `app_key_id` | B2 application key ID | Required |
| `app_key` | B2 application key | Required |
| `bucket_name` | Your bucket name | `vvu-scheduler` |
| `region` | B2 region | `us-west-002` |
| `endpoint` | B2 endpoint (S3-compatible) | `https://s3.backblazeb2.com` |
| `fallback_to_local` | Save locally if B2 fails | `true` |
| `public_url` | Custom B2 domain (optional) | `null` |

## Disabling B2

To temporarily use local storage only:

1. Edit `config/b2_config.php`
2. Set `'enabled' => false,`
3. All files will now save to local `csv/` directories

## Migration from R2 to B2

To migrate from Cloudflare R2:

1. Download all files from R2 bucket
2. Use B2 web interface to upload to your B2 bucket
3. Update configuration: Replace `require_once 'lib/R2Storage.php'` with `require_once 'lib/B2Storage.php'`
4. Update config from `r2_config.php` to `b2_config.php`

Or use the B2Storage helper programmatically:

```php
require_once 'lib/B2Storage.php';
$b2 = new B2Storage();

// Upload a local file
$result = $b2->upload(
    '/path/to/local/file.csv',
    'csv/final/file.csv'
);
```

## Troubleshooting

### "B2 Client Error" in logs

- Check your credentials in `config/b2_config.php`
- Verify account ID, app key ID, and app key are correct
- Ensure bucket name matches your B2 bucket
- Check that the region is valid (us-west-002, us-east-005, eu-central-003, etc.)

### "B2 Upload Error: NoSuchBucket" or "Access Denied"

- Verify bucket exists in Backblaze B2 console
- Check app key has "writeFiles" capability
- Confirm app key is not restricted to a different bucket
- Ensure app key is enabled (not revoked)

### Files still saving locally

- Check `'enabled' => true` in `config/b2_config.php`
- Look in error logs for B2 connection issues
- Verify environment variables are loaded (run `echo $B2_ACCOUNT_ID`)

### "Class 'Aws\S3\S3Client' not found"

- Install AWS SDK: `composer require aws/aws-sdk-php`
- Check `vendor/autoload.php` exists
- Verify PHP can access the Composer autoloader

## Cost Comparison

### Backblaze B2 (CHEAPEST)
- **Storage**: $0.006/GB/month ($6/TB/month)
- **Download API**: Free
- **Upload API**: $0.004 per 10,000 requests
- **Bandwidth**: Free (egress to internet)

**Example: 10GB + 1K operations/day**
- Storage: $0.06/month
- API calls: $0.01/month
- **Total: ~$0.07/month** 💰

### Comparison with R2
- R2: $0.30/month (storage + operations)
- B2: $0.07/month (4x cheaper!)

### Comparison with AWS S3
- S3: $1.50+/month (standard pricing)
- B2: $0.07/month (20x cheaper!)

## Support

For B2-specific issues:
- [Backblaze B2 Documentation](https://www.backblaze.com/b2/docs/)
- [AWS SDK for PHP Docs](https://docs.aws.amazon.com/sdk-for-php/)

For project issues, check the logs:
- PHP errors: `/Applications/XAMPP/xamppfiles/logs/php_error_log`
- B2 errors: Logged via `error_log()` in PHP
