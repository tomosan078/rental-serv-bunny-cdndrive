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

The first implementation deliberately uses **primary -> replica** reconciliation instead of active-active metadata writes. This avoids split-brain problems with two independent SQLite databases while still giving BunnyCDN a second copy of every origin object.

## Files

- `peer.php` - authenticated peer HTTP endpoint
- `app/PeerNode.php` - peer protocol, HMAC authentication, checksum verification and file operations
- `peer-sync.php` - CLI reconciliation command run on the primary node
- `data/peer.example.json` - configuration example

## Configure both servers

Copy the example:

```bash
cp data/peer.example.json data/peer.json
```

Generate a secret (run once and use the same value on both nodes):

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Primary example:

```json
{
  "node_name": "origin-a",
  "role": "primary",
  "peer_url": "https://origin-b.example.com",
  "shared_secret": "YOUR_SHARED_SECRET"
}
```

Replica example:

```json
{
  "node_name": "origin-b",
  "role": "replica",
  "peer_url": "https://origin-a.example.com",
  "shared_secret": "YOUR_SHARED_SECRET"
}
```

`data/peer.json` must not be publicly readable. The existing `data/.htaccess` protection should remain enabled.

## Initial sync

On the primary:

```bash
php peer-sync.php
```

For every row in the primary `files` table the command:

1. asks the replica for the SHA-256 checksum;
2. skips objects that already match;
3. uploads missing or mismatched objects;
4. propagates `deleted_at` objects as deletions on the replica.

The command exits with code `0` when all objects reconcile and `2` if one or more objects fail.

## Cron

After the first manual test, run reconciliation periodically on the primary. Example every five minutes:

```cron
*/5 * * * * cd /path/to/cdn-drive && /usr/bin/php peer-sync.php >> data/peer-sync.log 2>&1
```

Do **not** run `peer-sync.php` on the replica. It checks `role=primary` and refuses to run there.

## Security

Peer requests use these headers:

- `X-Peer-Timestamp`
- `X-Peer-Signature`

The signature is HMAC-SHA256 of:

```text
<TIMESTAMP>\n<RAW JSON BODY>
```

Requests older than five minutes are rejected. Paths are restricted to the `objects/` namespace and `..` traversal is rejected.

Use HTTPS between the two nodes and keep the shared secret out of source control.

## Failure model

This implementation focuses on **origin-file redundancy**, not active-active application state.

- Primary down: the replica still contains synchronized files and can be used as BunnyCDN failover origin.
- Replica down: primary keeps serving; the next successful reconciliation repairs missed files.
- Network failure during sync: the command reports a failure and leaves the primary copy untouched.
- Corrupt/mismatched replica: checksum mismatch causes the primary to send the object again.

## Current limitation

The web application's SQLite metadata is not replicated in this first step. This is intentional: copying two live SQLite databases bidirectionally risks split brain and lost writes. The replica is an origin replica, not a second writable admin panel.

A future phase can add signed event replication for metadata if active-active administration is required.

## BunnyCDN

Configure BunnyCDN so Origin A is the normal origin and Origin B is the failover/secondary origin using the failover mechanism available on your BunnyCDN configuration. Test failover before relying on it in production.
