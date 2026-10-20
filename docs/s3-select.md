# S3 Select

Run SQL queries directly on objects stored in S3, returning only the data you need instead of downloading the entire object.

## Supported Formats

| Format | Input | Output |
|--------|-------|--------|
| CSV | Yes | Yes |
| JSON | Yes | Yes |

Encrypted objects, compressed input, and Parquet input are not supported by the
current S3 Select implementation. The endpoint requires authentication even
when an object has a public read grant. Requests must provide a SQL expression
and explicit CSV or JSON input and output serialization. Unsupported options
such as `ScanRange`, `RequestProgress`, and selecting an explicit `versionId`
are rejected.

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

// Read the returned Records events
foreach ($result['Payload'] as $event) {
    if (isset($event['Records'])) {
        echo $event['Records']['Payload'];
    }
}
```

## SQL Syntax

SQL expressions are limited to 8 KiB and 512 tokens. The `WHERE` clause may
contain at most 128 tokens and 16 nested parentheses. Expressions beyond
these limits return a client error. Comparisons with `NULL` follow SQL's
unknown-result semantics; use `IS NULL` or `IS NOT NULL` to test for null.

### SELECT

```sql
-- Select all columns
SELECT * FROM s3object s

-- Select specific columns
SELECT s.name, s.email FROM s3object s

-- Aliases
SELECT s.name AS full_name FROM s3object s
```

Projection accepts `*`, a field name, or a field path qualified by the table
alias. Unsupported projection expressions return `InvalidArgument` before the
object is read. Scalar functions are supported in `WHERE` predicates only;
they are not evaluated in the selected columns.

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

The following scalar functions apply to `WHERE` operands. `COALESCE` is not
implemented. Use direct field names in the selected columns.

| Function | Example |
|----------|---------|
| `CAST` | `CAST(s.age AS INT)`; targets: `INT`/`INTEGER`, `FLOAT`/`DOUBLE`/`DECIMAL`, `VARCHAR`/`STRING`/`CHAR` |
| `SUBSTRING` | `SUBSTRING(s.name, 1, 3)` |
| `CHAR_LENGTH` | `CHAR_LENGTH(s.name)` |
| `UPPER` / `LOWER` | `UPPER(s.name)` |
| `TRIM` | `TRIM(s.name)` |

### Aggregate Functions

```sql
SELECT COUNT(*) FROM s3object s
SELECT SUM(s.amount) FROM s3object s
SELECT AVG(s.score) FROM s3object s
SELECT MIN(s.date), MAX(s.date) FROM s3object s
SELECT COUNT(*) FROM s3object s WHERE s.status = 'active'
```

Aggregate arguments are `*` for `COUNT`, or a direct field name with an
optional table alias. A query cannot mix aggregate and ordinary selected
columns. Numeric fields are converted for `SUM` and `AVG`; nested paths and
expressions inside aggregates are not supported.

### Operators

| Operator | Example |
|----------|---------|
| Comparison | `=`, `!=`, `<>`, `<`, `>`, `<=`, `>=` |
| Logical | `AND`, `OR`, `NOT` |
| Pattern | `LIKE` (with `%` and `_` wildcards) |
| Membership | `IN (...)` |
| Range | `BETWEEN ... AND ...` |
| Null check | `IS NULL`, `IS NOT NULL` |

## CSV Input Options

| Option | Values | Default | Description |
|--------|--------|---------|-------------|
| `FileHeaderInfo` | `USE`, `IGNORE`, `NONE` | `NONE` | How to handle the first row |
| `FieldDelimiter` | One byte | `,` | Column separator |
| `RecordDelimiter` | Non-empty string | `\n` | Row separator |
| `QuoteCharacter` | One byte | `"` | Quote character |

When `FileHeaderInfo` is `USE`, column names from the header row can be used in SQL. With `NONE`, use positional references: `s._1`, `s._2`, etc.
Quoted fields may contain record delimiters, including CRLF. An unterminated
quoted field, characters between a closing quote and its field or record
delimiter, and delimiter combinations that make record boundaries ambiguous
are rejected as invalid CSV. JSON scalar values can be written to CSV, but
selecting nested JSON values requires JSON output serialization.

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

Document input must be one object or an array containing only objects. Scalar
roots and mixed arrays are rejected as invalid JSON input. The buffered parser
rejects documents above 2 MiB and documents with more than 50,000 structural
tokens to protect the PHP process before JSON decoding.

### Querying JSON

```sql
SELECT s.name, s.address.city FROM s3object s WHERE s.age > 25
```

## Size Limits

```bash
S3_MAX_SELECT_OBJECT_SIZE=67108864   # 64 MiB default and hard maximum
```

Objects larger than the configured limit return an error. The current buffered
implementation enforces a 64-MiB hard maximum even if a larger configured value
is supplied. For large datasets, split the input into smaller objects.
An input or result record whose serialized content exceeds 1 MiB (1,048,576
bytes, excluding the CSV or JSON record delimiter) returns `OverMaxRecordSize`
(400). CSV and JSON Lines input are checked before copying a record. JSON
Document input passes the structure and memory preflight before decoding; each
member is then measured after decoding. AWS describes its
limit as "1 MB" and `maxCharsPerRecord`; exact Unicode and decimal-MB boundary
parity has not been verified.

Select buffers the complete object, parsed rows, filtered rows, projected
rows, formatted result, and EventStream response. Its EventStream encoder splits
the Records payload into 64-KiB frames, but this does not limit total process
memory; clients must concatenate Records payloads before parsing CSV or JSON
because a frame can end within a record. A memory guard now limits actual input
to at most 64 MiB, JSON Document input to 2 MiB, and the formatted result and
encoded EventStream to 32 MiB each. CSV records permit at most 16,384 fields;
quoted field delimiters do not count toward that limit. The guard also rejects
work once Select has added 128 MiB of PHP heap or its reserved headroom under
`memory_limit` is reached. The reserve scales with available memory at the
start of the request, up to 64 MiB; the
effective accepted input may therefore be smaller than these ceilings. A
rejection returns `SlowDown` (503), so callers may retry on a larger node or
with a smaller input. Previously, 500,000 short CSV rows occupying only 2 MB
of input exhausted a 256-MiB PHP process; this now returns 503 in both inline
and worker modes, and the server continues to serve queries.

These checks bound the supported buffered design, but are not a proof of a
fixed worst-case process footprint for every parser or storage adapter. The
default `S3_SELECT_WORKERS=1` isolates SQL parsing, filtering, projection, and
EventStream encoding from the API process. The API process still buffers the
source object before submitting it to the worker.
Reduce `S3_MAX_SELECT_OBJECT_SIZE` for the available memory, and limit Select
callers at the proxy or policy layer. The release gate must use adversarial
small-row and output-expansion profiles with the chosen storage adapter, PHP
limit, and worker count. A streaming row and EventStream pipeline is needed
before increasing the 64-MiB input ceiling.

## Worker failures

When `S3_SELECT_WORKERS` is greater than zero, Select processing runs in a
dedicated worker. A query may wait up to five minutes to acquire a worker and
receive its result; after a processing timeout the server kills that worker
and returns `SlowDown` (503), so the client can retry. Invalid SQL, malformed JSON,
and malformed CSV return `InvalidArgument`
(400). Other worker failures return `InternalError` (500) without exposing the
worker's exception text in the S3 response.

The five-minute timeout starts after the object has been buffered. A busy
buffered-work slot rejects additional requests with `SlowDown` instead of
queuing them. When the HTTP server reports a closed client connection, Select
cancels its object read or worker task. HTTP/1 peer disconnects may be detected
only when the server next reads or writes the socket, so deployment tests still
need to cover proxy disconnects and worker crashes with the chosen storage
adapter.
