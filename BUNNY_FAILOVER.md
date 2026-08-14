# Bunny API origin failover

This optional layer keeps BunnyCDN pointed at a Primary origin during normal operation and lets the Replica switch Bunny to itself when the Primary is repeatedly unreachable. It does not require Bunny Edge Scripting.

## Recommended two-node design

- **Primary**: authoritative application/database node and normal Bunny origin.
- **Replica**: replicated origin-file node, Primary health monitor, and the **only** node allowed to change Bunny `OriginUrl`.
- The Replica uses the existing authenticated `peer.php?action=health` endpoint to decide whether the Primary is reachable.
- A small public `origin-health.txt` file is checked before Bunny is switched to either origin.
- `peer-sync.php` remains responsible for file reconciliation. The failover controller only changes the Bunny Pull Zone origin.

Keeping the switch authority on one node avoids the Primary and Replica racing to overwrite Bunny during a network partition. A Primary-side failover cron is not required for this design.

## Safety rules

The controller will only change Bunny when the current `OriginUrl` exactly matches the configured Primary or Secondary URL. If Bunny points anywhere else, it refuses to overwrite the setting.

Default behavior:

- fail over after 3 consecutive Primary failures;
- fail back after 5 consecutive Primary successes;
- wait at least 300 seconds between origin switches;
- confirm the Secondary public health file before failover;
- confirm the Primary public health file before failback;
- use a lock file so overlapping cron runs cannot race.

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

`secondary_health_path` is a **path relative to each configured origin**, not a full URL. With the example above the controller checks:

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
[OK] Peer health check succeeded.
[HOLD] Bunny is already using the Primary origin.
```

Runtime counters and the last switch are stored in `data/failover-state.json`.

## Cron

A one-minute interval works with the default thresholds. With three consecutive failures, a failover decision normally occurs after roughly three cron runs plus network/API time.

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
3. Temporarily make the Primary peer endpoint unavailable using a reversible method.
4. Do not run the failover script manually; let cron reach `failure_threshold`.
5. Confirm the Replica log reports `[SWITCH]` and Bunny `OriginUrl` becomes `secondary_origin`.
6. Restore the Primary peer endpoint.
7. Let cron reach `recovery_threshold` and wait for `cooldown_seconds` if necessary.
8. Confirm the log reports `[SWITCH]` back to `primary_origin`.

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
