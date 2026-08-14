# Bunny API origin failover

This optional layer keeps BunnyCDN pointed at the Primary origin during normal operation and lets the Replica switch Bunny to itself when the Primary is repeatedly unreachable.

## Roles

- **Primary**: monitors Replica health only. It never changes Bunny `OriginUrl`.
- **Replica**: monitors Primary health and is the only node allowed to change Bunny `OriginUrl`.
- The existing authenticated `peer.php?action=health` endpoint is used for peer health.
- `origin-health.txt` is used as a public HTTPS check before switching to an origin.

This asymmetric control avoids two nodes racing to overwrite the Bunny origin during a network partition.

## Safety rules

The controller will only change Bunny when its current `OriginUrl` exactly matches the configured Primary or Secondary URL. If Bunny points anywhere else, it refuses to overwrite the setting.

Default behavior:

- fail over after 3 consecutive Primary failures;
- fail back after 5 consecutive Primary successes;
- wait at least 300 seconds between origin switches;
- before failover, confirm the Secondary public health file is reachable;
- before failback, confirm the Primary public health file is reachable;
- use a lock file so overlapping cron runs cannot race.

Scheduled `peer-sync.php` remains responsible for file reconciliation. The failover controller only changes the Bunny Pull Zone origin.

## Configuration

On each node, copy the example file:

```bash
cp data/failover.example.json data/failover.json
chmod 600 data/failover.json
```

The example is:

```json
{
  "enabled": true,
  "failure_threshold": 3,
  "recovery_threshold": 5,
  "cooldown_seconds": 300,
  "primary_origin": "https://0o0.jp",
  "secondary_origin": "https://cdn.sgnl.top",
  "secondary_health_path": "/origin-health.txt",
  "pull_zone_id": "YOUR_PULL_ZONE_ID",
  "bunny_api_key": "YOUR_BUNNY_ACCOUNT_API_KEY"
}
```

The Replica requires `pull_zone_id` and `bunny_api_key` because it is the switch controller. The Primary can use placeholders for those two fields because it only monitors the Replica.

Do not commit `data/failover.json`. It is ignored by Git.

## Manual check

Run on both nodes:

```bash
php peer-failover.php
```

Normal Primary output resembles:

```text
[OK] Peer health check succeeded.
```

Normal Replica output resembles:

```text
[OK] Peer health check succeeded.
[HOLD] Bunny is already using the Primary origin.
```

Runtime counters and the last switch are stored in `data/failover-state.json`.

## Cron

A one-minute interval works with the default thresholds. With three consecutive failures, the earliest failover decision is roughly three cron runs, plus network/API time.

Primary example:

```cron
* * * * * cd /home/USER/public_html && /usr/bin/php peer-failover.php >> data/failover-monitor.log 2>&1
```

Replica example:

```cron
* * * * * cd /home/USER/domains/REPLICA/public_html && /usr/bin/php peer-failover.php >> data/failover-monitor.log 2>&1
```

Adjust paths to the hosting account. If the PHP binary is not `/usr/bin/php`, use the path returned by `command -v php`.

## Failover test

1. Confirm Bunny currently points to the Primary.
2. Confirm `https://PRIMARY/origin-health.txt` and `https://SECONDARY/origin-health.txt` return HTTP 200.
3. Temporarily make the Primary peer endpoint unavailable using the same reversible method used during peer outage testing.
4. Run `php peer-failover.php` on the Replica enough times to reach `failure_threshold`.
5. Confirm output reports `[SWITCH]` and Bunny `OriginUrl` is now the Secondary.
6. Restore the Primary peer endpoint.
7. Run the Replica check enough times to reach `recovery_threshold`.
8. Confirm output reports `[SWITCH]` back to the Primary.

For production, let cron perform the checks rather than manually looping the command.

## Limitation of a two-node design

If the Replica itself is down, it cannot perform failover. Primary traffic continues normally while the Primary is healthy, but if both nodes fail or the Primary fails while the Replica controller is unavailable, automatic switching cannot occur. A third independent monitor is required to eliminate that limitation.
