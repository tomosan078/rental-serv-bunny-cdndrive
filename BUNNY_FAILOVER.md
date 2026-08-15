# Bunny API origin failover

This optional layer keeps BunnyCDN pointed at a Primary origin during normal operation and lets the Replica switch Bunny to itself when the Primary public origin is repeatedly unreachable. It does not require Bunny Edge Scripting.

## Recommended two-node design

- **Primary**: authoritative application/database node and normal Bunny origin.
- **Replica**: replicated origin-file node, Primary health monitor, and the **only** node allowed to change Bunny `OriginUrl`.
- The Replica uses the public static `origin-health.txt` endpoint as the authoritative signal for failover and failback.
- The authenticated `peer.php?action=health` endpoint is still checked for diagnostics, but a peer/PHP/firewall failure alone does **not** trigger Bunny failover while the public Primary origin remains healthy.
- The Secondary public health file is checked before Bunny is switched to the Replica.
- `peer-sync.php` remains responsible for file reconciliation. The failover controller only changes the Bunny Pull Zone origin.

Keeping the switch authority on one node avoids the Primary and Replica racing to overwrite Bunny during a network partition. A Primary-side failover cron is not required for this design.

## Safety rules

The controller will only change Bunny when the current `OriginUrl` exactly matches the configured Primary or Secondary URL. If Bunny points anywhere else, it refuses to overwrite the setting.

Default behavior:

- fail over after 3 consecutive **Primary public origin** failures;
- fail back after 5 consecutive **Primary public origin** successes;
- wait at least 300 seconds between origin switches;
- confirm the Secondary public health file before failover;
- keep peer API health as diagnostic-only information;
- use a lock file so overlapping cron runs cannot race.

This separation is important: `peer.php` depends on PHP, peer authentication, and related routing/firewall rules, while Bunny serves static origin content. If only the peer API is unavailable but `origin-health.txt` still returns a healthy response, the controller holds the Primary origin instead of performing a false failover.

## Configuration

On the Replica, copy the example file:

```bash
cp data/failover.example.json data/failover.json
chmod 600 data/failover.json
```

Example:

```json
{
  "enabled": true,
  "failure_threshold": 3,
  "recovery_threshold": 5,
  "cooldown_seconds": 300,
  "primary_origin": "https://primary.example.com/origin",
  "secondary_origin": "https://secondary.example.com/origin",
  "secondary_health_path": "/origin-health.txt",
  "pull_zone_id": "YOUR_PULL_ZONE_ID",
  "bunny_api_key": "YOUR_BUNNY_ACCOUNT_API_KEY"
}
```

`primary_origin` and `secondary_origin` must match the Bunny Pull Zone `OriginUrl` values **exactly**. If your Bunny origin points at an `/origin` directory, include `/origin` in both values.

`secondary_health_path` is retained for configuration compatibility and is used as the health path relative to **both** configured origins. With the example above the controller checks:

```text
https://primary.example.com/origin/origin-health.txt
https://secondary.example.com/origin/origin-health.txt
```

Place a copy of `origin-health.txt` at that relative path on both nodes. For example, when Bunny points at `/origin`:

```bash
cp origin-health.txt origin/origin-health.txt
```

The Replica requires `pull_zone_id` and `bunny_api_key` because it is the switch controller. Never commit `data/failover.json`; it is ignored by Git and should remain mode `0600`.

## Manual check

Run on the Replica:

```bash
php peer-failover.php
```

Normal output while the Primary is healthy resembles:

```text
[OK] Peer API health check succeeded.
[OK] Primary public origin health check succeeded.
[HOLD] Bunny is already using the Primary origin.
```

If the peer API is unavailable but the static origin is still healthy, output resembles:

```text
[WARN] Peer API health check failed; public origin health remains authoritative.
[OK] Primary public origin health check succeeded.
[HOLD] Bunny is already using the Primary origin.
```

Runtime counters and the last switch are stored in `data/failover-state.json`.

## Cron

A one-minute interval works with the default thresholds. With three consecutive public-origin failures, a failover decision normally occurs after roughly three cron runs plus network/API time.

Only the Replica needs the production failover cron:

```cron
* * * * * cd /home/USER/domains/REPLICA/public_html && /path/to/php peer-failover.php >> data/failover-monitor.log 2>&1
```

Use the PHP CLI path returned by:

```bash
command -v php
```

Confirm the selected CLI binary provides the extensions used by the application, especially cURL and PHP's filter functions.

## Failover test

1. Confirm Bunny currently points to `primary_origin`.
2. Confirm both origin-relative health URLs return HTTP 200.
3. Temporarily make only the Primary peer endpoint unavailable using a reversible method.
4. Let cron run past `failure_threshold` and confirm Bunny **does not** switch while the Primary static health URL remains HTTP 200.
5. Restore the Primary peer endpoint.
6. Simulate a failure of the Primary public health URL using a reversible method that does not expose credentials or destroy data.
7. Let cron reach `failure_threshold` and confirm the Replica log reports `[SWITCH]` and Bunny `OriginUrl` becomes `secondary_origin`.
8. Restore the Primary public health URL.
9. Let cron reach `recovery_threshold` and wait for `cooldown_seconds` if necessary.
10. Confirm the log reports `[SWITCH]` back to `primary_origin`.

Useful log command:

```bash
tail -f data/failover-monitor.log
```

## Security notes

- Use an Account API key with care. Keep it only in the Replica's untracked `data/failover.json`.
- Do not place the Bunny API key, peer shared secret, production hostnames, server paths, or other credentials in example files or issue reports.
- Keep HTTPS certificate verification enabled.
- The controller intentionally refuses to overwrite an unexpected Bunny origin.

## Limitation of a two-node design

If the Replica itself is down, it cannot perform failover. Primary traffic continues normally while the Primary is healthy, but if the Primary fails while the Replica controller is unavailable, automatic switching cannot occur. A third independent monitor is required to eliminate that limitation.
