# Security policy

## Reporting a security issue

Please do not publish secrets, credentials, private URLs, server paths, or exploit details in a public GitHub issue. If a report contains sensitive information, use a private contact method made available by the repository owner instead of posting it publicly.

## Secrets that must stay out of Git

The following runtime values are intentionally local and should never be committed:

- `data/peer.json` — contains the peer shared secret.
- `data/failover.json` — may contain the Bunny Account API key and Pull Zone ID.
- SQLite databases, session data, transfer state, trash files, and runtime logs.

Example configuration files contain placeholders only. Generate a unique peer secret for each deployment and keep runtime configuration files restricted to the hosting account (for example, mode `0600` where supported).

## Deployment guidance

- Use HTTPS between peer nodes and keep certificate verification enabled.
- Use a long random peer shared secret (at least 32 characters).
- Do not expose `data/` publicly.
- Keep PHP and the hosting environment updated.
- Back up the SQLite database with SQLite's backup mechanism rather than copying a live database file while it is being written.
- Test failover and failback with reversible outage simulations before enabling unattended cron operation.

## Scope

The two-node failover design improves origin availability but does not protect against simultaneous failure of both nodes or failure of the Replica while it is acting as the failover controller. A third independent monitor is required to remove that limitation.
