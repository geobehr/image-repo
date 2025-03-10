# Image Migrator

A Laravel-based tool for migrating images between cloud storage providers, with initial support for Google Cloud Storage.

## Features

- List contents of Google Cloud Storage buckets
- Upload files to specific paths
- Delete files
- Support for both root and subdirectory operations
- Error handling for missing configurations

## Requirements

- PHP 8.1+
- Laravel 10.x
- Google Cloud Storage account and credentials

## Installation

1. Clone the repository:
```bash
git clone [your-repo-url]
cd image-migrator
```

2. Install dependencies:
```bash
composer install
```

3. Configure environment:
```bash
cp .env.example .env
```

4. Set up Google Cloud Storage:
- Place your Google Cloud key file in `storage/app/google-cloud-key.json`
- Update your `.env` file with the appropriate bucket name:
```
GOOGLE_CLOUD_STORAGE_BUCKET=your-bucket-name
```

## API Endpoints

### List Contents
```
GET /api/gcs/list?path=/
```
Lists contents of the root directory or a specified path.

### Upload File
```
POST /api/gcs/upload
Content-Type: multipart/form-data

file: <file>
path: <target-path>
```

### Delete Files
```
DELETE /api/gcs/delete
Content-Type: application/json

{
    "files": ["path/to/file1", "path/to/file2"]
}
```

## Testing

Run the test suite using PEST:
```bash
./vendor/bin/pest
```

## License

MIT
