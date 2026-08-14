# Peer Redundancy

This branch adds a control-server-less two-node redundancy layer for CDN Drive.

## Topology

```text
BunnyCDN
   |
   +-- Origin A (primary CDN Drive)
   |
   `-- Origin B (replica)

Origin A <---- HMAC authenticated HTTPS ----> Origin B
```

The implementation deliberately uses **primary -> replica** origin replication instead of active-active SQLite writes. This avoids split-brain problems with two independent SQLite databases while still giving BunnyCDN a second copy of every origin object.

## What is replicated

- Files under `origin/objects/...`
- File deletion state: a deleted primary object is moved out of the replica origin into `data/trash-peer/...`
- SHA-256 integrity is checked before an object is considered synchronized

Application metadata such as users, sessions, shares and settings remains authoritative on the primary SQLite database.

## Replica mode

When `data/peer.json` contains:

```json
{
  "role": "replica"
}
```

`index.php` switches the node into origin-only replica mode. Dynamic CDN Drive application routes return HTTP 404, including `/`, `/install`, `/login` and `/api/*`.

The web server still serves existing files/directories directly, so these remain available:

- `/origin/...` for BunnyCDN origin reads
- `/peer.php` for authenticated peer replication
- ordinary static assets/files that physically exist

This prevents a replica from accidentally exposing a second installer, login screen or writable administration panel.

The repository root `.htaccess` routes non-file/non-directory requests to `index.php`, while leaving `peer.php` and `origin/` requests untouched.

## Files

- `.htaccess` - front-controller rewrite rules while preserving direct origin/peer access
- `peer.php` - authenticated peer HTTP endpoint
- `app/PeerNode.php` - peer protocol, HMAC authentication, chunk transfer and checksum verification
- `app/PeerReconciler.php` - shared reconciliation logic
- `peer-setup.php` - interactive CLI setup for `data/peer.json`
- `peer-check.php` - CLI deployment/connectivity check
- `peer-sync.php` - CLI reconciliation/repair command run on the primary node
- `data/peer.example.json` - configuration example

## Configure both servers

The easiest method is the interactive setup command:

```bash
php peer-setup.php
```

Run it once on each node. Set one node to `primary` and the other to `replica`. Use the same shared secret on both nodes. The command creates `data/peer.json`, backs up an existing config before replacing it, and applies restrictive file permissions where supported.

You may also configure manually by copying the example:

```bash
cp data/peer.example.json data/peer.json
```

Generate a secret once and use the same value on both nodes:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Primary example:

```json
{
  "node_name": "origin-a",
  "role": "primary",
  "peer_url": "https://origin-b.example.com",
  "shared_secret": "YOUR_SHARED_SECRET",
  "chunk_size_bytes": 4194304,
  "connect_timeout_seconds": 10,
  "request_timeout_seconds": 120,
  "sync_on_request": true,
  "sync_recent_limit": 100,
  "stale_transfer_max_age_seconds": 86400
}
```

Replica example:

```json
{
  "node_name": "origin-b",
  "role": "replica",
  "peer_url": "https://origin-a.example.com",
  "shared_secret": "YOUR_SHARED_SECRET",
  "chunk_size_bytes": 4194304,
  "connect_timeout_seconds": 10,
  "request_timeout_seconds": 120,
  "sync_on_request": false,
  "sync_recent_limit": 100,
  "stale_transfer_max_age_seconds": 86400
}
```

`data/peer.json` must not be publicly readable. Keep the existing `data/.htaccess` protection enabled. It is also ignored by Git so the shared secret is not accidentally committed.

## Preflight check

After configuring both nodes, run this on **both** servers:

```bash
php peer-check.php
```

It checks PHP 8.2+, cURL, PDO SQLite, writable `origin`/`data`, peer configuration, HTTPS usage, role validity and authenticated connectivity to the other node. Fix every `[NG]` result before enabling scheduled replication.

## Transfer protocol

Large objects are **not** encoded as one base64 JSON request. The primary transfers them in bounded binary chunks:

1. `upload-init` creates a random transfer id and records path, size and SHA-256.
2. `upload-chunk` appends a binary chunk at an exact byte offset.
3. `upload-commit` verifies total size and SHA-256, then atomically moves the completed file into `origin/objects/...`.

The default chunk size is 4 MiB. You can tune `chunk_size_bytes`, but keep it below the request-size limits of both hosting providers.

Interrupted transfers stay in `data/peer-transfers/` and can be safely retried by reconciliation. Completed objects are only exposed from `origin/` after final checksum verification.

## Near-real-time replication

When `sync_on_request` is enabled on the primary, `index.php` performs a best-effort reconciliation after a mutating `POST` request. On FastCGI environments it flushes the user's response first with `fastcgi_finish_request()` when available.

The recent reconciliation checks the most recently updated file rows. `sync_recent_limit` controls the maximum number of rows checked after a request.

This path is deliberately **best effort**: a peer outage must not turn a successful user upload/delete into a failed application response. Errors are written to the PHP error log and the scheduled full reconciliation repairs them later.

## Initial/full sync

On the primary:

```bash
php peer-sync.php
```

For every row in the primary `files` table the command:

1. asks the replica for its SHA-256 state;
2. skips objects that already match;
3. chunk-uploads missing or mismatched objects;
4. propagates `deleted_at` objects as deletions on the replica.

The command exits with code `0` when all objects reconcile, `2` when one or more objects fail, and `1` for a fatal configuration/connection error.

For a quicker manual check of only recently updated rows:

```bash
php peer-sync.php --recent=100
```

## Cron: authoritative repair/retry loop

Even with near-real-time replication enabled, run a full reconciliation periodically on the primary. This is the retry queue and repair mechanism for temporary network/server failures.

Example every five minutes:

```cron
*/5 * * * * cd /path/to/cdn-drive && /usr/bin/php peer-sync.php >> data/peer-sync.log 2>&1
```

Do **not** run `peer-sync.php` on the replica. It checks `role=primary` and refuses to run there.

## Security

Every peer request uses:

- `X-Peer-Timestamp`
- `X-Peer-Signature`

The signature is HMAC-SHA256 over:

```text
<TIMESTAMP>\n<ACTION>\n<RAW REQUEST BODY>
```

Requests older than five minutes are rejected. The shared secret must be at least 32 characters. Object paths are restricted to the `objects/` namespace and traversal using `..` is rejected.

Use HTTPS between the two nodes and never commit `data/peer.json` to source control.

## Failure model

- **Primary down:** the replica still has the synchronized origin files and can serve as a BunnyCDN failover origin.
- **Replica down:** primary keeps serving. Near-real-time replication logs the failure; the next scheduled reconciliation retries it.
- **Network interruption during a large upload:** the replica never publishes a partially transferred file into `origin/`; a later reconciliation starts a new transfer.
- **Corrupt/mismatched replica:** SHA-256 mismatch causes the primary to retransmit the object.
- **Application POST succeeds while replication fails:** the user response remains successful; repair is deferred to scheduled reconciliation.

## Important limitation: metadata is not active-active

The replica is an **origin replica**, not a second writable CDN Drive administration node. Users, sessions, shares and other SQLite metadata are not bidirectionally synchronized.

That choice is intentional. Synchronizing two independently writable SQLite databases would introduce split-brain and conflict-resolution problems. If active-active administration is required later, metadata replication needs a separate conflict model or a database designed for multi-node writes.

## BunnyCDN

The two origin servers only provide storage redundancy. For automatic delivery failover, BunnyCDN must also be configured so that Origin B can be used when Origin A is unavailable. Test that failover path before relying on it in production.
