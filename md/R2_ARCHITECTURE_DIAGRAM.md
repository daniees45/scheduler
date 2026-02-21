# R2 Storage Integration Architecture

## System Overview

```
┌─────────────────────────────────────────────────────────────────┐
│                     VVU Scheduler System                         │
│                                                                  │
│  ┌────────────┐      ┌────────────┐      ┌────────────┐       │
│  │  Web UI    │──────│ PHP APIs   │──────│  Python    │       │
│  │ (Browser)  │      │ (Backend)  │      │  (AI Core) │       │
│  └────────────┘      └─────┬──────┘      └────────────┘       │
│                             │                                    │
│                             ▼                                    │
│                      ┌─────────────┐                            │
│                      │ R2Storage   │                            │
│                      │   Class     │                            │
│                      └──────┬──────┘                            │
│                             │                                    │
│          ┌──────────────────┼──────────────────┐               │
│          ▼                  ▼                   ▼               │
│   ┌──────────┐      ┌──────────┐      ┌──────────┐            │
│   │ Database │      │    R2    │      │  Local   │            │
│   │  MySQL   │      │  Cloud   │      │  Files   │            │
│   └──────────┘      └──────────┘      └──────────┘            │
│    (Backup)         (Primary)         (Fallback)               │
└─────────────────────────────────────────────────────────────────┘
```

## File Upload Flow

```
User Uploads PDF
      │
      ▼
┌──────────────────┐
│  extract_pdf.php │
└────────┬─────────┘
         │
         ▼
┌──────────────────┐
│ Tabula Extract   │ (Python)
└────────┬─────────┘
         │
         ▼ CSV Content
┌──────────────────┐
│  R2Storage       │
│  uploadContent() │
└────────┬─────────┘
         │
    ┌────┴────┐
    │         │
    ▼         ▼
┌────────┐ ┌────────┐
│   R2   │ │  DB    │
│ Bucket │ │ Backup │
└────────┘ └────────┘
   ✓ PRIMARY  ✓ ALWAYS
```

## File Download Flow

```
User Requests Schedule
         │
         ▼
┌─────────────────────┐
│ get_recent_schedules│
└──────────┬──────────┘
           │
           ▼
    ┌──────────────┐
    │  R2 Enabled? │
    └──────┬───────┘
           │
    ┌──────┴──────┐
    │             │
   YES           NO
    │             │
    ▼             ▼
┌────────┐   ┌────────┐
│   R2   │   │ Local  │
│ List() │   │ scandir│
└───┬────┘   └───┬────┘
    │            │
    └──────┬─────┘
           │
           ▼
    Return JSON List
```

## Storage Strategy Matrix

| Action | R2 Enabled | R2 Fails | R2 Disabled |
|--------|-----------|----------|-------------|
| **Upload** | → R2 + DB | → Local + DB | → Local + DB |
| **Download** | → R2 first | → Local fallback | → Local only |
| **List** | → R2 scan | → Local scan | → Local scan |
| **Delete** | → R2 delete | → Local delete | → Local delete |

## Data Flow Diagram

```
┌────────────────────────────────────────────────────────────┐
│                    User Actions                             │
└───┬────────────────┬───────────────┬────────────────┬──────┘
    │                │               │                │
    ▼                ▼               ▼                ▼
┌─────────┐   ┌──────────┐   ┌──────────┐   ┌──────────┐
│ Upload  │   │ Download │   │   List   │   │  Delete  │
│   PDF   │   │   CSV    │   │  Files   │   │   File   │
└────┬────┘   └─────┬────┘   └─────┬────┘   └─────┬────┘
     │              │              │              │
     ▼              ▼              ▼              ▼
┌─────────────────────────────────────────────────────────┐
│              R2Storage Helper Class                      │
│                                                          │
│  • upload() / uploadContent()                           │
│  • download()                                           │
│  • listFiles()                                          │
│  • delete()                                             │
│  • exists()                                             │
│                                                          │
│  Logic: Try R2 → Fallback to Local if fails            │
└───────────────────────┬─────────────────────────────────┘
                        │
        ┌───────────────┼───────────────┐
        ▼               ▼               ▼
    ┌────────┐     ┌────────┐     ┌────────┐
    │   R2   │     │  Local │     │   DB   │
    │ Bucket │     │  Files │     │ Backup │
    └────────┘     └────────┘     └────────┘
```

## Directory Structure Mapping

```
Local Filesystem              Cloudflare R2 Bucket
────────────────              ────────────────────

vvu-scheduler/                 vvu-scheduler/
├── csv/                      └── csv/
│   ├── general/                  ├── general/
│   │   ├── courses.csv           │   ├── courses.csv
│   │   └── rooms.csv              │   └── rooms.csv
│   ├── department/               ├── department/
│   │   ├── cs_rooms.csv          │   ├── cs_rooms.csv
│   │   └── biz_rooms.csv         │   └── biz_rooms.csv
│   └── final/                    └── final/
│       └── schedule.csv              └── schedule.csv
```

## API Endpoints Modified

```
┌───────────────────────────────────────────────────────┐
│  PDF Extraction Pipeline                              │
│                                                       │
│  extract_pdf.php                                     │
│  ├── Input: PDF file                                 │
│  ├── Process: Tabula extraction                      │
│  └── Output: → R2 (csv/general/file.csv)           │
│              → DB (csv_storage table)                │
└───────────────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────┐
│  Data Cleanup Pipeline                                │
│                                                       │
│  cleanup_data.php                                    │
│  ├── Input: Raw CSV from DB                         │
│  ├── Process: clean_up.py                           │
│  └── Output: → R2 (csv/department/file.csv)        │
│              → DB (csv_storage table)                │
└───────────────────────────────────────────────────────┘

┌───────────────────────────────────────────────────────┐
│  Schedule Listing                                     │
│                                                       │
│  get_recent_schedules.php                            │
│  ├── Input: None                                     │
│  ├── Process: R2 listFiles() or local scandir()     │
│  └── Output: JSON array of schedule files            │
└───────────────────────────────────────────────────────┘
```

## Configuration Hierarchy

```
┌─────────────────────────────────────────────┐
│   Configuration Priority (Highest First)     │
└──────────────┬──────────────────────────────┘
               │
    ┌──────────┼──────────┐
    ▼          ▼          ▼
┌─────────────────┐ ┌──────────────┐ ┌──────────────┐
│  Environment    │ │    Config    │ │   Default    │
│   Variables     │ │     File     │ │    Values    │
│                 │ │              │ │              │
│ R2_ACCOUNT_ID   │ │ r2_config    │ │ Fallback to  │
│ R2_ACCESS_KEY   │ │    .php      │ │   local      │
│ R2_SECRET_KEY   │ │              │ │   storage    │
│ R2_BUCKET       │ │              │ │              │
│ R2_ENDPOINT     │ │              │ │              │
└─────────────────┘ └──────────────┘ └──────────────┘
```

## Error Handling Flow

```
File Upload Request
       │
       ▼
┌─────────────┐
│ R2 Enabled? │
└──────┬──────┘
       │
  ┌────┴────┐
 YES       NO
  │         │
  ▼         ▼
┌─────────────┐   ┌─────────────┐
│ Try R2      │   │ Use Local   │
│ Upload      │   │ Storage     │
└──────┬──────┘   └──────┬──────┘
       │                 │
  ┌────┴────┐           │
SUCCESS   FAIL          │
  │         │           │
  │    ┌────┴─────┐     │
  │    │ Fallback │     │
  │    │ Enabled? │     │
  │    └────┬─────┘     │
  │         │           │
  │    ┌────┴────┐      │
  │   YES       NO      │
  │    │         │      │
  ▼    ▼         ▼      ▼
┌────────────────────────┐
│   Use Local Storage    │
│   + Log Error          │
│   + Return Success     │
└────────────────────────┘
```

## Security Architecture

```
┌─────────────────────────────────────────────────────┐
│                  Security Layers                     │
└───┬─────────────────────────────────────────────────┘
    │
    ├─► File Validation
    │   ├── Sanitize filenames (basename())
    │   ├── Validate extensions (.csv, .pdf)
    │   └── Check folder whitelist
    │
    ├─► Credential Protection
    │   ├── Environment variables (preferred)
    │   ├── Config file outside web root
    │   └── .gitignore for sensitive files
    │
    ├─► API Token Permissions
    │   ├── Read & Write only
    │   ├── Bucket-specific access
    │   └── No admin permissions
    │
    ├─► Error Handling
    │   ├── Don't expose credentials in errors
    │   ├── Log errors server-side
    │   └── User-friendly messages
    │
    └─► Network Security
        ├── HTTPS only (R2 endpoint)
        ├── Token rotation (90 days)
        └── Cloudflare DDoS protection
```

## Performance Optimization

```
┌────────────────────────────────────────────────┐
│          Optimization Strategies                │
└────────────────────────────────────────────────┘

1. Caching Strategy
   ┌────────────────┐
   │  Browser       │ Cache file lists (5 min)
   └────────────────┘
   ┌────────────────┐
   │  PHP Memory    │ R2 client instance (singleton)
   └────────────────┘

2. Batch Operations
   ┌────────────────┐
   │  List Files    │ Fetch 100 at once, paginate in UI
   └────────────────┘

3. Async Uploads (Future Enhancement)
   ┌────────────────┐
   │  Queue         │ Background job for large files
   └────────────────┘

4. CDN Integration (Optional)
   ┌────────────────┐
   │  R2 Public URL │ Serve static files via Cloudflare CDN
   └────────────────┘
```

## Monitoring Dashboard

```
┌─────────────────────────────────────────────────────┐
│  Cloudflare R2 Dashboard Metrics                    │
├─────────────────────────────────────────────────────┤
│                                                     │
│  📊 Storage Used:        5.2 GB                    │
│  📈 Operations (24h):    1,247                     │
│     ├── Class A (Write): 342                       │
│     └── Class B (Read):  905                       │
│  💰 Estimated Cost:      $0.28/month               │
│  🌍 Files Stored:        1,834                     │
│  📅 Last Upload:         2 min ago                 │
│                                                     │
└─────────────────────────────────────────────────────┘
```

---

**Legend:**
- `→` Data flow direction
- `▼` Process/decision flow
- `┌─┐` Container/component
- `├─┤` Connection/relationship
- `✓` Success/confirmation
