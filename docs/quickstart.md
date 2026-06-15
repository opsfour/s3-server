# Quick Start

Get a working S3-compatible server in 5 minutes.

## 1. Install

```bash
composer require opsfour/s3-server
```

## 2. Start the Server

### Standalone (no framework)

```bash
php vendor/bin/s3-server \
  --storage-path=./storage/s3 \
  --access-key=myAccessKey \
  --secret-key=mySecretKey \
  --port=9000
```

### Laravel

```bash
php artisan s3:serve --storage-path=./storage/s3 --access-key=myAccessKey --secret-key=mySecretKey
```

### Symfony

Register `OpsFour\S3Server\Symfony\S3ServerBundle` in `config/bundles.php`,
then create `config/packages/opsfour_s3_server.yaml`:

```yaml
opsfour_s3_server:
  storage:
    driver: filesystem
    path: '%kernel.project_dir%/var/s3'
  credentials:
    driver: memory
    access_key: myAccessKey
    secret_key: mySecretKey
    owner_id: default-owner
    display_name: Default User
```

Start it:

```bash
php bin/console opsfour:s3:serve
```

The server is now listening on `http://localhost:9000`.

## 3. Use It

### AWS CLI

```bash
# Configure credentials
export AWS_ACCESS_KEY_ID=myAccessKey
export AWS_SECRET_ACCESS_KEY=mySecretKey
export AWS_DEFAULT_REGION=us-east-1

# Create a bucket
aws --endpoint-url http://localhost:9000 s3 mb s3://my-bucket

# Upload a file
aws --endpoint-url http://localhost:9000 s3 cp photo.jpg s3://my-bucket/photos/photo.jpg

# List objects
aws --endpoint-url http://localhost:9000 s3 ls s3://my-bucket/photos/

# Download a file
aws --endpoint-url http://localhost:9000 s3 cp s3://my-bucket/photos/photo.jpg downloaded.jpg

# Delete a file
aws --endpoint-url http://localhost:9000 s3 rm s3://my-bucket/photos/photo.jpg
```

### PHP (AWS SDK)

```php
use Aws\S3\S3Client;

$s3 = new S3Client([
    'version'                 => 'latest',
    'region'                  => 'us-east-1',
    'endpoint'                => 'http://localhost:9000',
    'use_path_style_endpoint' => true,
    'credentials'             => [
        'key'    => 'myAccessKey',
        'secret' => 'mySecretKey',
    ],
]);

// Create bucket
$s3->createBucket(['Bucket' => 'my-bucket']);

// Upload
$s3->putObject([
    'Bucket' => 'my-bucket',
    'Key'    => 'hello.txt',
    'Body'   => 'Hello, S3!',
]);

// Download
$result = $s3->getObject([
    'Bucket' => 'my-bucket',
    'Key'    => 'hello.txt',
]);
echo $result['Body']; // "Hello, S3!"
```

### Python (boto3)

```python
import boto3

s3 = boto3.client(
    's3',
    endpoint_url='http://localhost:9000',
    aws_access_key_id='myAccessKey',
    aws_secret_access_key='mySecretKey',
    region_name='us-east-1',
)

s3.create_bucket(Bucket='my-bucket')
s3.put_object(Bucket='my-bucket', Key='hello.txt', Body=b'Hello from Python!')
obj = s3.get_object(Bucket='my-bucket', Key='hello.txt')
print(obj['Body'].read().decode())  # "Hello from Python!"
```

### JavaScript (AWS SDK v3)

```javascript
import { S3Client, CreateBucketCommand, PutObjectCommand } from '@aws-sdk/client-s3';

const client = new S3Client({
  endpoint: 'http://localhost:9000',
  region: 'us-east-1',
  credentials: { accessKeyId: 'myAccessKey', secretAccessKey: 'mySecretKey' },
  forcePathStyle: true,
});

await client.send(new CreateBucketCommand({ Bucket: 'my-bucket' }));
await client.send(new PutObjectCommand({
  Bucket: 'my-bucket',
  Key: 'hello.txt',
  Body: 'Hello from JavaScript!',
}));
```

## 4. Health Check

```bash
curl http://localhost:9000/.health
# {"status":"ok"}
```

## Next Steps

- [Configuration Reference](configuration.md) — Tune storage paths, rate limits, TLS, and more
- [Authentication](authentication.md) — Set up multi-user credential providers
- [Symfony Integration](symfony-integration.md) — Bundle config, services, and console commands
- [Production Deployment](deployment.md) — TLS, PostgreSQL, monitoring
