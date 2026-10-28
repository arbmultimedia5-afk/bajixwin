<?php
/**
 * Gamblly Auto Setup & Database Seeding Helper.
 * Fills in the Gamblly provider settings only when they are missing, so the
 * API key saved from Admin Panel > Game API Key is never overwritten.
 */

if (!function_exists('gamblly_auto_setup_run')) {
    function gamblly_auto_setup_run($conn) {
        $defaultKey = 'ee761aea015CodeHub94fbe22b19aa61';
        $expectedEndpoint = 'https://game.gambllyapi.com/production/v1/gameLaunch.php';

        $current = array();
        $res = @$conn->query("SELECT setting_key, setting_value FROM game_settings WHERE setting_key IN ('game_api_provider','api_token','api_endpoint','gamblly_launch_url','gamblly_amount_scale')");
        if ($res) {
            while ($row = $res->fetch_assoc()) { $current[$row['setting_key']] = trim((string)$row['setting_value']); }
        }

        $updates = array();
        if (!isset($current['game_api_provider']) || $current['game_api_provider'] !== 'GAMBLLY') { $updates['game_api_provider'] = 'GAMBLLY'; }
        if (!isset($current['api_token']) || $current['api_token'] === '') { $updates['api_token'] = $defaultKey; }
        if (!isset($current['api_endpoint']) || stripos($current['api_endpoint'], 'game.gambllyapi.com') === false) { $updates['api_endpoint'] = $expectedEndpoint; }
        if (isset($current['gamblly_launch_url']) && $current['gamblly_launch_url'] !== '' && $current['gamblly_launch_url'] !== $expectedEndpoint) { $updates['gamblly_launch_url'] = $expectedEndpoint; }
        if (!isset($current['gamblly_amount_scale']) || $current['gamblly_amount_scale'] !== '6') {
            gamblly_auto_setup_widen_wallet($conn);
        }
        if (empty($updates)) { return; }

        require_once __DIR__ . '/game_api_helper.php';
        game_api_ensure_schema($conn, false);
        foreach ($updates as $key => $value) { game_api_set_setting($conn, $key, $value); }
        game_api_seed_mappings($conn);
    }
}

if (!function_exists('gamblly_auto_setup_widen_wallet')) {
    // Fish games pay fractional wins such as 0.075. Widen the wallet columns once,
    // and raise gamblly_amount_scale to 6 only after users.balance really holds 6 decimals.
    function gamblly_auto_setup_widen_wallet($conn) {
        try {
            $conn->query("ALTER TABLE users MODIFY balance DECIMAL(18,6) DEFAULT 0.000000");
            $conn->query("ALTER TABLE game_api_callback_transactions MODIFY amount DECIMAL(20,6) NOT NULL DEFAULT 0, MODIFY balance_before DECIMAL(20,6) NOT NULL DEFAULT 0, MODIFY balance_after DECIMAL(20,6) NOT NULL DEFAULT 0");
            $conn->query("ALTER TABLE game_bet_history MODIFY amount DECIMAL(20,6) NOT NULL, MODIFY balance_after DECIMAL(20,6) NOT NULL");
            $res = $conn->query("SHOW COLUMNS FROM users LIKE 'balance'");
            $type = ($res && $res->num_rows > 0) ? strtolower((string)$res->fetch_assoc()['Type']) : '';
            if ($type === 'decimal(18,6)') {
                require_once __DIR__ . '/game_api_helper.php';
                game_api_set_setting($conn, 'gamblly_amount_scale', '6');
            }
        } catch (Throwable $e) {
            error_log('gamblly wallet precision migration failed: ' . $e->getMessage());
        }
    }
}
?>
