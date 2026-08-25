# Wave G Verification Report

**Wave:** G — Database / Transaction Integrity & Concurrency

## Gate results

| Gate | Result | Evidence |
|---|---|---|
| Wallet overspend invariant | PASS (static) | `Wallet.php`, conditional UPDATE + rowCount check |
| Points double-spend invariant | PASS (static) | `Wallet.php`, conditional UPDATE + rowCount check |
| Referral reward double-claim invariant | PASS (static) | `Referral.php`, pending-state claim |
| Payment double-approval invariant | PASS (static) | 3 approval paths, pending-state transition |
| Concurrency indexes migration | PASS | `v13_concurrency_indexes` |
| PHP syntax | 70/70 PASS | PHP CLI 8.4.23 |
| Dynamic SQLite concurrency | BLOCKED | PDO SQLite driver unavailable in current sandbox |
| Real MySQL/InnoDB concurrency | NOT RUN | Requires deployment/CI DB |
| 5k/10k load | NOT RUN | Requires real PHP-FPM + MySQL environment |

## Security posture

No credential-bearing runtime `config/config.php` is included in the Wave G release archive. It is generated from the placeholder example and is ignored by `.gitignore`.

## Release discipline

A PASS in this wave means the invariant is enforced by the current code path and has passed the available static/syntax gates. It does not mean production-scale concurrency has been proven.
