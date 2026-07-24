# S3 Select

Run SQL queries directly on objects stored in S3, returning only the data you need instead of downloading the entire object.

## Supported Formats

| Format | Input | Output |
|--------|-------|--------|
| CSV | Yes | Yes |
| JSON | Yes | Yes |

Encrypted objects and Parquet input are not supported by the current S3 Select
implementation.

## Basic Usage

```php
$result = $s3->selectObjectContent([
    'Bucket' => 'my-bucket',
    'Key'    => 'data.csv',
    'Expression' => "SELECT s.name, s.age FROM s3object s WHERE s.age > '30'",
    'ExpressionType' => 'SQL',
    'InputSerialization' => [
        'CSV' => [
            'FileHeaderInfo' => 'USE',        // USE, IGNORE, or NONE
            'FieldDelimiter' => ',',
            'RecordDelimiter' => "\n",
            'QuoteCharacter' => '"',
        ],
    ],
    'OutputSerialization' => [
        'CSV' => [
            'FieldDelimiter' => ',',
            'RecordDelimiter' => "\n",
        ],
    ],
]);

// Read the streamed results
foreach ($result['Payload'] as $event) {
    if (isset($event['Records'])) {
        echo $event['Records']['Payload'];
    }
}
```

## SQL Syntax

### SELECT

```sql
-- Select all columns
SELECT * FROM s3object s

-- Select specific columns
SELECT s.name, s.email FROM s3object s

-- Aliases
SELECT s.name AS full_name FROM s3object s
```

### WHERE

```sql
SELECT * FROM s3object s WHERE s.status = 'active'
SELECT * FROM s3object s WHERE s.age > '25' AND s.country = 'US'
SELECT * FROM s3object s WHERE s.name LIKE 'John%'
SELECT * FROM s3object s WHERE s.category IN ('A', 'B', 'C')
SELECT * FROM s3object s WHERE s.deleted IS NULL
SELECT * FROM s3object s WHERE s.score BETWEEN '10' AND '100'
```

### Functions

| Function | Example |
|----------|---------|
| `CAST` | `CAST(s.age AS INT)` |
| `SUBSTRING` | `SUBSTRING(s.name FROM 1 FOR 3)` |
| `CHAR_LENGTH` | `CHAR_LENGTH(s.name)` |
| `UPPER` / `LOWER` | `UPPER(s.name)` |
| `TRIM` | `TRIM(s.name)` |
| `COALESCE` | `COALESCE(s.nickname, s.name)` |

### Aggregate Functions

```sql
SELECT COUNT(*) FROM s3object s
SELECT SUM(CAST(s.amount AS FLOAT)) FROM s3object s
SELECT AVG(CAST(s.score AS FLOAT)) FROM s3object s
SELECT MIN(s.date), MAX(s.date) FROM s3object s
SELECT COUNT(*) FROM s3object s WHERE s.status = 'active'
```

### Operators

| Operator | Example |
|----------|---------|
| Comparison | `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=` |
| Logical | `AND`, `OR`, `NOT` |
| Pattern | `LIKE` (with `%` and `_` wildcards) |
| Membership | `IN (...)` |
| Range | `BETWEEN ... AND ...` |
| Null check | `IS NULL`, `IS NOT NULL` |
| Arithmetic | `+`, `-`, `*`, `/` |

## CSV Input Options

| Option | Values | Default | Description |
|--------|--------|---------|-------------|
| `FileHeaderInfo` | `USE`, `IGNORE`, `NONE` | `NONE` | How to handle the first row |
| `FieldDelimiter` | Any character | `,` | Column separator |
| `RecordDelimiter` | Any string | `\n` | Row separator |
| `QuoteCharacter` | Any character | `"` | Quote character |

When `FileHeaderInfo` is `USE`, column names from the header row can be used in SQL. With `NONE`, use positional references: `s._1`, `s._2`, etc.

## JSON Input

### JSON Lines (one JSON object per line)

```json
{"name": "Alice", "age": 30}
{"name": "Bob", "age": 25}
```

```php
'InputSerialization' => [
    'JSON' => [
        'Type' => 'LINES',
    ],
],
```

### JSON Document (single array)

```json
[
  {"name": "Alice", "age": 30},
  {"name": "Bob", "age": 25}
]
```

```php
'InputSerialization' => [
    'JSON' => [
        'Type' => 'DOCUMENT',
    ],
],
```

### Querying JSON

```sql
SELECT s.name, s.address.city FROM s3object s WHERE s.age > 25
```

## Size Limits

```bash
S3_MAX_SELECT_OBJECT_SIZE=268435456   # 256 MiB default
```

Objects larger than this limit return an error. For large datasets, consider splitting files or using multipart uploads.
