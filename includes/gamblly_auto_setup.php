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
        $res = @$conn->query("SELECT setting_key, setting_value FROM game_settings WHERE setting_key IN ('game_api_provider','api_token','api_endpoint','gamblly_launch_url')");
        if ($res) {
            while ($row = $res->fetch_assoc()) { $current[$row['setting_key']] = trim((string)$row['setting_value']); }
        }

        $updates = array();
        if (!isset($current['game_api_provider']) || $current['game_api_provider'] !== 'GAMBLLY') { $updates['game_api_provider'] = 'GAMBLLY'; }
        if (!isset($current['api_token']) || $current['api_token'] === '') { $updates['api_token'] = $defaultKey; }
        if (!isset($current['api_endpoint']) || stripos($current['api_endpoint'], 'game.gambllyapi.com') === false) { $updates['api_endpoint'] = $expectedEndpoint; }
        if (isset($current['gamblly_launch_url']) && $current['gamblly_launch_url'] !== '' && $current['gamblly_launch_url'] !== $expectedEndpoint) { $updates['gamblly_launch_url'] = $expectedEndpoint; }
        if (empty($updates)) { return; }

        require_once __DIR__ . '/game_api_helper.php';
        game_api_ensure_schema($conn, false);
        foreach ($updates as $key => $value) { game_api_set_setting($conn, $key, $value); }
        game_api_seed_mappings($conn);
    }
}
?>
