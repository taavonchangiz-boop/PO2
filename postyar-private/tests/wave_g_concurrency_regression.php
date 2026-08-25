<?php
/**
 * Wave G — database/transaction concurrency regression.
 * Exercises the atomic SQL invariants introduced in Wave G without requiring the app config.
 */
declare(strict_types=1);

$dbFile = sys_get_temp_dir() . '/postyar_wave_g_' . bin2hex(random_bytes(6)) . '.sqlite';
try {
    $db = new PDO('sqlite:' . $dbFile);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec('PRAGMA foreign_keys = ON');

    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, wallet_balance DECIMAL(15,2) NOT NULL DEFAULT 0, referral_points DECIMAL(15,2) NOT NULL DEFAULT 0)');
    $db->exec('CREATE TABLE wallet_transactions (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, type VARCHAR(30), amount DECIMAL(15,2), balance_after DECIMAL(15,2), description TEXT, reference_type VARCHAR(50), reference_id INTEGER, created_at DATETIME)');
    $db->exec("CREATE TABLE referrals (id INTEGER PRIMARY KEY, referred_id INTEGER, referrer_id INTEGER, status VARCHAR(20), reward_type VARCHAR(20), reward_value DECIMAL(10,2), rewarded_at DATETIME)");
    $db->exec("CREATE TABLE payments (id INTEGER PRIMARY KEY, status VARCHAR(20), verified_at DATETIME)");

    $db->exec('INSERT INTO users(id, wallet_balance, referral_points) VALUES (1, 100, 50)');

    // Invariant 1: two debits cannot both spend the same available balance.
    $stmt = $db->prepare('UPDATE users SET wallet_balance = wallet_balance - ? WHERE id = ? AND wallet_balance >= ?');
    $stmt->execute([80, 1, 80]);
    $first = $stmt->rowCount();
    $stmt->execute([80, 1, 80]);
    $second = $stmt->rowCount();
    $balance = (float)$db->query('SELECT wallet_balance FROM users WHERE id = 1')->fetchColumn();
    assert($first === 1 && $second === 0 && $balance === 20.0, 'wallet overspend invariant failed');

    // Invariant 2: point conversion cannot consume the same points twice.
    $stmt = $db->prepare('UPDATE users SET referral_points = referral_points - ? WHERE id = ? AND referral_points >= ?');
    $stmt->execute([40, 1, 40]);
    $first = $stmt->rowCount();
    $stmt->execute([40, 1, 40]);
    $second = $stmt->rowCount();
    $points = (float)$db->query('SELECT referral_points FROM users WHERE id = 1')->fetchColumn();
    assert($first === 1 && $second === 0 && $points === 10.0, 'points double-spend invariant failed');

    // Invariant 3: first-purchase referral reward has one atomic claimant.
    $db->exec("INSERT INTO referrals(id, referred_id, referrer_id, status) VALUES (10, 2, 1, 'pending')");
    $stmt = $db->prepare("UPDATE referrals SET status='rewarded' WHERE id=? AND status='pending'");
    $stmt->execute([10]);
    $first = $stmt->rowCount();
    $stmt->execute([10]);
    $second = $stmt->rowCount();
    $status = $db->query('SELECT status FROM referrals WHERE id = 10')->fetchColumn();
    assert($first === 1 && $second === 0 && $status === 'rewarded', 'referral claim invariant failed');

    // Invariant 4: payment approval is a one-way atomic state transition.
    $db->exec("INSERT INTO payments(id,status) VALUES (20,'pending')");
    $stmt = $db->prepare("UPDATE payments SET status='approved', verified_at=CURRENT_TIMESTAMP WHERE id=? AND status='pending'");
    $stmt->execute([20]);
    $first = $stmt->rowCount();
    $stmt->execute([20]);
    $second = $stmt->rowCount();
    $status = $db->query('SELECT status FROM payments WHERE id = 20')->fetchColumn();
    assert($first === 1 && $second === 0 && $status === 'approved', 'payment idempotency invariant failed');

    echo "WAVE_G_CONCURRENCY_REGRESSION: PASS\n";
} finally {
    if (isset($db)) {
        $db = null;
    }
    @unlink($dbFile);
}
