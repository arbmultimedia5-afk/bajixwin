<?php
/**
 * Gamblly API integration helper.
 *
 * This file is intentionally self-contained and only uses the existing users,
 * games and game_api_* tables already present in the site.
 */

if (!function_exists('gamblly_api_default_config')) {
    function gamblly_api_default_config() {
        return array(
            'launch_url' => 'https://game.gambllyapi.com/production/v1/gameLaunch.php',
            'withdraw_url' => 'https://game.gambllyapi.com/production/v1/getWithdraw.php',
            'balance_url' => 'https://game.gambllyapi.com/production/v1/getBalance.php',
            'callback_pull_url' => 'https://game.gambllyapi.com/production/v1/callback.php',
            'callback_url' => 'https://bajixwin.com/api/game/callback.php',
            'api_key' => 'ee761aea015CodeHub94fbe22b19aa61',
            'member_prefix' => '',
            'member_suffix' => '',
            'currency' => 'BDT',
            'language' => 'bn',
            'platform' => '2',
            'zero_local_on_launch' => '0'
        );
    }
}

if (!function_exists('gamblly_api_get_setting_value')) {
    function gamblly_api_get_setting_value($settings, $keys, $defaultValue) {
        if (!is_array($keys)) { $keys = array($keys); }
        foreach ($keys as $key) {
            if (isset($settings[$key]) && trim((string)$settings[$key]) !== '') {
                return trim((string)$settings[$key]);
            }
        }
        return $defaultValue;
    }
}

if (!function_exists('gamblly_api_get_config')) {
    function gamblly_api_get_config($conn, $settings = null) {
        if ($settings === null) {
            $settings = function_exists('game_api_get_settings') ? game_api_get_settings($conn) : array();
        }
        if (!is_array($settings)) { $settings = array(); }
        $defaults = gamblly_api_default_config();

        $endpoint = gamblly_api_get_setting_value($settings, array('gamblly_launch_url', 'api_endpoint'), $defaults['launch_url']);
        if ($endpoint === '' || (stripos($endpoint, 'gambllyapi.com') === false && stripos($endpoint, 'gamblly-api.com') === false)) {
            $endpoint = $defaults['launch_url'];
        }

        $apiKey = gamblly_api_get_setting_value($settings, array('gamblly_api_key', 'api_token'), $defaults['api_key']);
        $prefix = gamblly_api_get_setting_value($settings, array('gamblly_member_prefix', 'api_prefix', 'agent_code'), $defaults['member_prefix']);
        $suffix = gamblly_api_get_setting_value($settings, array('gamblly_member_suffix', 'api_suffix', 'secret_key'), $defaults['member_suffix']);

        $prefix = preg_replace('/[^A-Za-z0-9_\-]/', '', $prefix);
        $suffix = preg_replace('/[^A-Za-z0-9_\-]/', '', $suffix);

        return array(
            'launch_url' => $endpoint,
            'withdraw_url' => gamblly_api_get_setting_value($settings, 'gamblly_withdraw_url', $defaults['withdraw_url']),
            'balance_url' => gamblly_api_get_setting_value($settings, 'gamblly_balance_url', $defaults['balance_url']),
            'callback_pull_url' => gamblly_api_get_setting_value($settings, 'gamblly_callback_api_url', $defaults['callback_pull_url']),
            'callback_url' => gamblly_api_get_setting_value($settings, 'gamblly_callback_url', $defaults['callback_url']),
            'api_key' => $apiKey,
            'member_prefix' => $prefix,
            'member_suffix' => $suffix,
            'currency' => strtoupper(gamblly_api_get_setting_value($settings, 'currency_code', $defaults['currency'])),
            'language' => strtolower(gamblly_api_get_setting_value($settings, 'gamblly_language', $defaults['language'])),
            'platform' => gamblly_api_get_setting_value($settings, 'gamblly_platform', $defaults['platform']),
            'zero_local_on_launch' => gamblly_api_get_setting_value($settings, 'gamblly_zero_local_on_launch', $defaults['zero_local_on_launch'])
        );
    }
}

if (!function_exists('gamblly_api_is_enabled')) {
    function gamblly_api_is_enabled($conn, $settings = null) {
        if ($settings === null) {
            $settings = function_exists('game_api_get_settings') ? game_api_get_settings($conn) : array();
        }
        if (!is_array($settings)) { $settings = array(); }
        $provider = strtoupper(trim(isset($settings['game_api_provider']) ? (string)$settings['game_api_provider'] : ''));
        $endpoint = trim(isset($settings['api_endpoint']) ? (string)$settings['api_endpoint'] : '');
        if (strpos($provider, 'GAMBLLY') !== false || strpos($provider, 'GAMBLING_API') !== false) { return true; }
        if ($endpoint !== '' && (stripos($endpoint, 'gambllyapi.com') !== false || stripos($endpoint, 'gamblly-api.com') !== false)) { return true; }
        return false;
    }
}

if (!function_exists('gamblly_api_member_account')) {
    function gamblly_api_member_account($userId, $config) {
        return (string)((int)$userId);
    }
}

if (!function_exists('gamblly_api_extract_player_id')) {
    function gamblly_api_extract_player_id($rawValue, $config) {
        // member_account is sent as the plain user id, so player_uid comes back as-is.
        $raw = trim((string)$rawValue);
        if ($raw === '') { return 0; }
        if (ctype_digit($raw)) { return (int)$raw; }
        if (preg_match('/([0-9]+)$/', $raw, $m)) { return (int)$m[1]; }
        return 0;
    }
}

if (!function_exists('gamblly_api_json_encode')) {
    function gamblly_api_json_encode($payload) {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json === false ? '' : $json;
    }
}

if (!function_exists('gamblly_api_send_json')) {
    function gamblly_api_send_json($url, $payload, $timeout = 20) {
        $json = gamblly_api_json_encode($payload);
        if ($json === '') {
            return array('success' => false, 'response' => '', 'decoded' => null, 'http_code' => 0, 'curl_error' => 'JSON encode failed');
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Gamblly-API-Module/1.0');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Accept: application/json',
            'Content-Length: ' . strlen($json)
        ));
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode((string)$response, true);
        return array(
            'success' => ($curlError === '' && $httpCode >= 200 && $httpCode < 300),
            'response' => (string)$response,
            'decoded' => is_array($decoded) ? $decoded : null,
            'http_code' => $httpCode,
            'curl_error' => $curlError
        );
    }
}

if (!function_exists('gamblly_api_send_form')) {
    function gamblly_api_send_form($url, $payload, $timeout = 20) {
        $post_fields = http_build_query($payload);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Gamblly-API-Module/1.0');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/x-www-form-urlencoded'
        ));
        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $decoded = json_decode((string)$response, true);
        return array(
            'success' => ($curlError === '' && $httpCode >= 200 && $httpCode < 300),
            'response' => (string)$response,
            'decoded' => is_array($decoded) ? $decoded : null,
            'http_code' => $httpCode,
            'curl_error' => $curlError
        );
    }
}

if (!function_exists('gamblly_api_pick')) {
    function gamblly_api_pick($data, $keys, $defaultValue = '') {
        if (!is_array($data)) { return $defaultValue; }
        if (!is_array($keys)) { $keys = array($keys); }
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') { return $data[$key]; }
        }
        return $defaultValue;
    }
}

if (!function_exists('gamblly_api_pick_nested')) {
    function gamblly_api_pick_nested($data, $paths, $defaultValue = '') {
        if (!is_array($paths)) { $paths = array($paths); }
        foreach ($paths as $path) {
            $parts = explode('.', $path);
            $node = $data;
            $found = true;
            foreach ($parts as $part) {
                if (!is_array($node) || !array_key_exists($part, $node)) { $found = false; break; }
                $node = $node[$part];
            }
            if ($found && $node !== null && $node !== '') { return $node; }
        }
        return $defaultValue;
    }
}

if (!function_exists('gamblly_api_number')) {
    function gamblly_api_number($value) {
        if (is_array($value) || is_object($value)) { return 0.0; }
        $value = preg_replace('/[^0-9\.\-]/', '', (string)$value);
        if ($value === '' || $value === '-' || $value === '.') { return 0.0; }
        return round((float)$value, 6);
    }
}

if (!function_exists('gamblly_api_extract_launch_url')) {
    function gamblly_api_extract_launch_url($decoded) {
        if (!is_array($decoded)) { return ''; }
        $url = gamblly_api_pick_nested($decoded, array(
            'payload.game_launch_url',
            'payload.game_url',
            'payload.launch_url',
            'payload.url',
            'data.game_launch_url',
            'data.game_url',
            'data.launch_url',
            'data.url',
            'game_launch_url',
            'game_url',
            'launch_url',
            'url'
        ), '');
        $url = trim((string)$url);
        return (preg_match('#^https?://#i', $url)) ? $url : '';
    }
}

if (!function_exists('gamblly_api_launch_game')) {
    function gamblly_api_launch_game($conn, $user, $gameUid, $walletBalance, $currency = '', $localGameUid = '', $game = array()) {
        $config = gamblly_api_get_config($conn);
        $userId = isset($user['id']) ? (int)$user['id'] : 0;
        $gameUid = trim((string)$gameUid);
        if ($userId <= 0) { return array('success' => false, 'message' => 'Invalid player account.'); }
        if ($gameUid === '') { return array('success' => false, 'message' => 'Gamblly game UID is missing.'); }
        if (trim((string)$config['api_key']) === '') { return array('success' => false, 'message' => 'Gamblly API Key is missing.'); }
        if ($currency === '') { $currency = $config['currency']; }

        $transferId = 'GBL' . date('YmdHis') . $userId . mt_rand(1000, 9999);
        
        // V1 Seamless Wallet payload (application/x-www-form-urlencoded format)
        $payload = array(
            'api_key' => $config['api_key'],
            'member_account' => gamblly_api_member_account($userId, $config),
            'game_uid' => $gameUid,
            'home_url' => function_exists('game_api_site_url') ? rtrim(game_api_site_url('/'), '/') : '',
            'credit_amount' => number_format((float)$walletBalance, 2, '.', ''),
            'currency_code' => strtoupper($currency),
            'language' => $config['language'],
            'platform' => (int)$config['platform'],
            'transfer_id' => $transferId
        );

        $result = gamblly_api_send_form($config['launch_url'], $payload, 25);
        $decoded = isset($result['decoded']) ? $result['decoded'] : null;
        $launchUrl = gamblly_api_extract_launch_url($decoded);
        $status = false;
        if (is_array($decoded)) {
            $statusValue = gamblly_api_pick($decoded, array('status', 'success'), null);
            $codeValue = gamblly_api_pick($decoded, array('code', 'errorCode', 'status_code'), null);
            $status = ($statusValue === true || $statusValue === 'true' || $statusValue === 1 || $statusValue === '1' || (string)$codeValue === '0');
        }
        $ok = ($launchUrl !== '' && ($status || (isset($result['success']) && $result['success'])));

        if ($ok && !empty($config['zero_local_on_launch']) && (string)$config['zero_local_on_launch'] === '1') {
            $stmt = @$conn->prepare("UPDATE users SET balance=0 WHERE id=? LIMIT 1");
            if ($stmt) { $stmt->bind_param('i', $userId); $stmt->execute(); $stmt->close(); }
        }

        $message = 'Game launch failed.';
        if (is_array($decoded)) {
            $message = trim((string)gamblly_api_pick($decoded, array('message', 'msg', 'error', 'errorMessage'), $message));
        }
        if (!$ok && is_array($decoded)) {
            $errorCode = (string)gamblly_api_pick($decoded, array('code', 'errorCode', 'error_code'), '');
            $knownCodes = array(
                '10002' => 'Invalid API key',
                '10004' => 'Invalid JSON or missing required fields',
                '10005' => 'Temporary service error, please retry',
                '10008' => 'Game does not exist',
                '10011' => 'Player currency mismatch',
                '10013' => 'Currency is unsupported',
                '10014' => 'Player name is invalid',
                '10015' => 'Insufficient agency balance',
                '10016' => 'Player does not exist',
                '10017' => 'Duplicate transfer_id',
                '10022' => 'Parameter error',
                '10025' => 'Exposure limit exceeded',
                '10035' => 'Provider system error, please retry later',
                '10040' => 'Domain or IP is not allowlisted'
            );
            if (isset($knownCodes[$errorCode])) { $message = $knownCodes[$errorCode] . ' (code ' . $errorCode . ')'; }
        }
        if (!$ok && isset($result['curl_error']) && $result['curl_error'] !== '') { $message = $result['curl_error']; }
        if (!$ok && isset($result['http_code']) && (int)$result['http_code'] > 0) { $message .= ' (HTTP ' . (int)$result['http_code'] . ')'; }

        return array(
            'success' => $ok,
            'url' => $launchUrl,
            'message' => $message,
            'payload' => $payload,
            'response' => isset($result['response']) ? $result['response'] : '',
            'decoded' => $decoded,
            'http_code' => isset($result['http_code']) ? $result['http_code'] : 0,
            'curl_error' => isset($result['curl_error']) ? $result['curl_error'] : '',
            'transfer_id' => $transferId
        );
    }
}

if (!function_exists('gamblly_api_wallet_request')) {
    function gamblly_api_wallet_request($conn, $userId, $endpointKey) {
        $config = gamblly_api_get_config($conn);
        $url = isset($config[$endpointKey]) ? $config[$endpointKey] : '';
        if ($url === '') { return array('success' => false, 'amount' => 0, 'message' => 'Endpoint missing.'); }
        $payload = array(
            'agency_uid' => $config['api_key'],
            'member_account' => gamblly_api_member_account((int)$userId, $config),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'credit_amount' => 0,
            'currency_code' => $config['currency'],
            'language' => $config['language'],
            'platform' => $config['platform'],
            'home_url' => function_exists('game_api_site_url') ? rtrim(game_api_site_url('/'), '/') : '',
            'transfer_id' => 'GBW' . date('YmdHis') . (int)$userId . mt_rand(1000, 9999)
        );
        $result = gamblly_api_send_json($url, $payload, 25);
        $decoded = isset($result['decoded']) ? $result['decoded'] : null;
        $amount = 0.0;
        if (is_array($decoded)) {
            $amount = gamblly_api_number(gamblly_api_pick_nested($decoded, array(
                'payload.amount', 'payload.balance', 'payload.after_amount', 'data.amount', 'data.balance', 'data.after_amount', 'amount', 'balance', 'after_amount'
            ), 0));
        }
        $success = isset($result['success']) ? (bool)$result['success'] : false;
        if (is_array($decoded)) {
            $statusValue = gamblly_api_pick($decoded, array('status', 'success'), null);
            $codeValue = gamblly_api_pick($decoded, array('code', 'errorCode', 'status_code'), null);
            if ($statusValue === true || $statusValue === 'true' || $statusValue === 1 || $statusValue === '1' || (string)$codeValue === '0') { $success = true; }
        }
        $message = is_array($decoded) ? (string)gamblly_api_pick($decoded, array('message','msg','error'), '') : '';
        if (!$success && isset($result['curl_error']) && $result['curl_error'] !== '') { $message = $result['curl_error']; }
        return array('success' => $success, 'amount' => $amount, 'message' => $message, 'payload' => $payload, 'response' => isset($result['response']) ? $result['response'] : '', 'decoded' => $decoded);
    }
}


if (!function_exists('gamblly_api_response')) {
    function gamblly_api_response($payload, $httpCode = 200) {
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

/* ---------------------------------------------------------------------------
 * V1 Seamless Wallet callback (Gamblly -> callback_url)
 *
 * Money is handled as integer minor units (no floats) so amounts are never
 * rounded silently. Only bet_amount / win_amount touch the wallet; round_* and
 * display_* fields are display-only and are ignored here on purpose.
 * ------------------------------------------------------------------------- */

if (!function_exists('gamblly_api_amount_scale')) {
    // One explicit precision rule for provider, wallet and database.
    // users.balance is DECIMAL(10,2) (BDT), so the default scale is 2. Raise the
    // gamblly_amount_scale setting only after widening users.balance as well.
    function gamblly_api_amount_scale($settings) {
        $scale = (int)gamblly_api_get_setting_value($settings, 'gamblly_amount_scale', '2');
        return ($scale < 0 || $scale > 6) ? 2 : $scale;
    }
}

if (!function_exists('gamblly_api_parse_amount')) {
    // Returns the amount as signed integer minor units, or null with $error set.
    function gamblly_api_parse_amount($value, $scale, &$error) {
        $error = '';
        if ($value === null || $value === '') { return 0; }
        if (is_int($value)) {
            $value = (string)$value;
        } elseif (is_float($value)) {
            if (!is_finite($value)) { $error = 'not_finite'; return null; }
            $value = sprintf('%.10F', $value);
        } elseif (is_string($value)) {
            $value = trim($value);
        } else {
            $error = 'not_a_number';
            return null;
        }
        // Plain decimals only: no locale commas, exponents or currency symbols.
        if (!preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $value, $m)) { $error = 'not_a_decimal'; return null; }
        $fraction = isset($m[3]) ? rtrim($m[3], '0') : '';
        if (strlen($fraction) > $scale) { $error = 'precision_exceeds_scale_' . $scale; return null; }
        $integer = ltrim($m[2], '0');
        if ($integer === '') { $integer = '0'; }
        if (strlen($integer) > 12) { $error = 'amount_too_large'; return null; }
        $units = (int)($integer . str_pad($fraction, $scale, '0'));
        return ($m[1] === '-') ? -$units : $units;
    }
}

if (!function_exists('gamblly_api_units_to_decimal')) {
    function gamblly_api_units_to_decimal($units, $scale) {
        $negative = ($units < 0);
        $digits = (string)abs((int)$units);
        if ($scale > 0) {
            $digits = str_pad($digits, $scale + 1, '0', STR_PAD_LEFT);
            $digits = substr($digits, 0, -$scale) . '.' . substr($digits, -$scale);
        }
        return ($negative ? '-' : '') . $digits;
    }
}

if (!function_exists('gamblly_api_callback_respond')) {
    // Sends the JSON reply first, then runs non-critical work (history, logging)
    // so the provider gets its answer as fast as possible.
    function gamblly_api_callback_respond($payload, $httpCode, $log, $afterResponse = null) {
        if (!headers_sent()) {
            http_response_code($httpCode);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (function_exists('fastcgi_finish_request')) { @fastcgi_finish_request(); }
        elseif (function_exists('litespeed_finish_request')) { @litespeed_finish_request(); }

        if (is_callable($afterResponse)) {
            try { call_user_func($afterResponse); }
            catch (Throwable $e) { $log['after_response_error'] = $e->getMessage(); }
        }
        $log['http_code'] = $httpCode;
        $log['status'] = !empty($payload['status']);
        if (isset($payload['balance'])) { $log['balance'] = $payload['balance']; }
        if (isset($log['started_at'])) {
            $log['ms'] = (int)round((microtime(true) - $log['started_at']) * 1000);
            unset($log['started_at']);
        }
        if (function_exists('game_api_debug_log')) { game_api_debug_log('gamblly_callback', $log); }
        exit;
    }
}

if (!function_exists('gamblly_api_callback_ok')) {
    function gamblly_api_callback_ok($balanceUnits, $scale, $log, $afterResponse = null) {
        $payload = array(
            'balance' => (float)gamblly_api_units_to_decimal($balanceUnits, $scale),
            'status' => true
        );
        gamblly_api_callback_respond($payload, 200, $log, $afterResponse);
    }
}

if (!function_exists('gamblly_api_callback_fail')) {
    // Failure reply never carries a balance, so it cannot be mistaken for success.
    function gamblly_api_callback_fail($reason, $message, $httpCode, $log) {
        $log['reason'] = $reason;
        gamblly_api_callback_respond(array('status' => false, 'msg' => $message, 'message' => $message), $httpCode, $log);
    }
}

if (!function_exists('gamblly_api_read_user_balance')) {
    function gamblly_api_read_user_balance($conn, $userId, $forUpdate) {
        $sql = "SELECT balance FROM users WHERE id=? LIMIT 1" . ($forUpdate ? " FOR UPDATE" : "");
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = ($res && $res->num_rows > 0) ? $res->fetch_assoc() : null;
        $stmt->close();
        if ($row === null) { return null; }
        return ($row['balance'] === null) ? '0' : (string)$row['balance'];
    }
}

if (!function_exists('gamblly_api_handle_callback')) {
    function gamblly_api_handle_callback($conn) {
        $log = array('started_at' => microtime(true));
        // Every DB error must throw so the wallet change is rolled back, never silently skipped.
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            gamblly_api_response(array('status' => true));
        }

        $raw = (string)file_get_contents('php://input');
        $data = ($raw !== '') ? json_decode($raw, true) : null;
        if (!is_array($data) && !empty($_POST)) { $data = $_POST; }
        if (!is_array($data) || empty($data)) {
            gamblly_api_callback_fail('invalid_json', 'Invalid JSON body', 400, $log);
        }

        $settings = function_exists('game_api_get_settings') ? game_api_get_settings($conn) : array();
        $config = gamblly_api_get_config($conn, $settings);
        $scale = gamblly_api_amount_scale($settings);

        $action = strtolower(trim((string)gamblly_api_pick($data, 'action', '')));
        $txnId = trim((string)gamblly_api_pick($data, 'txn_id', ''));
        $gameRound = trim((string)gamblly_api_pick($data, 'game_round', ''));
        $gameUid = trim((string)gamblly_api_pick($data, 'game_uid', ''));
        $playerRaw = trim((string)gamblly_api_pick($data, array('player_uid', 'member_account'), ''));
        $log['action'] = $action;
        $log['txn_id'] = $txnId;
        $log['game_round'] = $gameRound;
        $log['player_uid'] = $playerRaw;
        $log['bet_amount'] = isset($data['bet_amount']) ? $data['bet_amount'] : null;
        $log['win_amount'] = isset($data['win_amount']) ? $data['win_amount'] : null;

        $expectedKey = trim((string)$config['api_key']);
        $receivedKey = trim((string)gamblly_api_pick($data, 'api_key', ''));
        if ($expectedKey === '') {
            gamblly_api_callback_fail('api_key_not_configured', 'Callback not configured', 500, $log);
        }
        if ($receivedKey === '' || !hash_equals($expectedKey, $receivedKey)) {
            $log['received_fields'] = implode(',', array_keys($data));
            gamblly_api_callback_fail('invalid_api_key', 'Invalid API key', 401, $log);
        }

        $userId = gamblly_api_extract_player_id($playerRaw, $config);
        if ($userId <= 0) {
            gamblly_api_callback_fail('invalid_player', 'Invalid player', 400, $log);
        }

        $amountError = '';
        $bet = gamblly_api_parse_amount(isset($data['bet_amount']) ? $data['bet_amount'] : 0, $scale, $amountError);
        if ($bet === null) { gamblly_api_callback_fail('invalid_bet_amount:' . $amountError, 'Invalid amount', 400, $log); }
        $win = gamblly_api_parse_amount(isset($data['win_amount']) ? $data['win_amount'] : 0, $scale, $amountError);
        if ($win === null) { gamblly_api_callback_fail('invalid_win_amount:' . $amountError, 'Invalid amount', 400, $log); }

        // Refund normalization: a refund is always a credit, never a new debit.
        if ($action === 'refund') {
            $credit = ($win !== 0) ? abs($win) : abs($bet);
            $bet = 0;
            $win = $credit;
        } elseif ($bet < 0 || $win < 0) {
            if ($bet < 0 && $win === 0) { $action = 'refund'; }
            $net = $win - $bet;
            if ($net >= 0) { $bet = 0; $win = $net; }
            else { $bet = -$net; $win = 0; }
        }
        $log['applied_bet'] = gamblly_api_units_to_decimal($bet, $scale);
        $log['applied_win'] = gamblly_api_units_to_decimal($win, $scale);

        try {
            // deposit_required is an informational notice; balance / zero-zero is a no-op lookup.
            if ($action === 'deposit_required' || ($bet === 0 && $win === 0)) {
                $balanceText = gamblly_api_read_user_balance($conn, $userId, false);
                if ($balanceText === null) { gamblly_api_callback_fail('player_not_found', 'Player not found', 404, $log); }
                $balanceUnits = gamblly_api_parse_amount($balanceText, $scale, $amountError);
                if ($balanceUnits === null) { gamblly_api_callback_fail('balance_precision:' . $amountError, 'Wallet error', 500, $log); }
                if ($action === 'deposit_required') { $log['msg'] = (string)gamblly_api_pick($data, 'msg', ''); }
                $log['reason'] = 'no_op';
                gamblly_api_callback_ok($balanceUnits, $scale, $log);
            }

            // txn_id is the idempotency key (provider serial_number).
            if ($txnId === '') {
                $txnId = 'raw_' . sha1($raw !== '' ? $raw : gamblly_api_json_encode($data));
                $log['txn_id_fallback'] = $txnId;
            }
            $externalId = 'gamblly_' . (strlen($txnId) > 150 ? sha1($txnId) : $txnId);
            $legacyBetId = 'gamblly_bet_' . $txnId;
            $legacyWinId = 'gamblly_win_' . $txnId;

            $conn->begin_transaction();
            $balanceText = gamblly_api_read_user_balance($conn, $userId, true);
            if ($balanceText === null) {
                $conn->rollback();
                gamblly_api_callback_fail('player_not_found', 'Player not found', 404, $log);
            }
            $balanceUnits = gamblly_api_parse_amount($balanceText, $scale, $amountError);
            if ($balanceUnits === null) {
                $conn->rollback();
                gamblly_api_callback_fail('balance_precision:' . $amountError, 'Wallet error', 500, $log);
            }

            $stmt = $conn->prepare("SELECT id FROM game_api_callback_transactions WHERE external_transaction_id IN (?, ?, ?) LIMIT 1");
            $stmt->bind_param('sss', $externalId, $legacyBetId, $legacyWinId);
            $stmt->execute();
            $res = $stmt->get_result();
            $alreadyApplied = ($res && $res->num_rows > 0);
            $stmt->close();
            if ($alreadyApplied) {
                $conn->commit();
                $log['reason'] = 'duplicate_txn_id';
                gamblly_api_callback_ok($balanceUnits, $scale, $log);
            }

            if ($bet > $balanceUnits) {
                $conn->rollback();
                gamblly_api_callback_fail('insufficient_balance', 'Insufficient balance', 200, $log);
            }

            // bet / win / refund / bet_win: one atomic net change.
            $newBalanceUnits = $balanceUnits - $bet + $win;
            $beforeText = gamblly_api_units_to_decimal($balanceUnits, $scale);
            $afterText = gamblly_api_units_to_decimal($newBalanceUnits, $scale);
            $netText = gamblly_api_units_to_decimal($win - $bet, $scale);
            $callbackType = substr($action !== '' ? $action : 'unknown', 0, 40);
            $roundText = substr($gameRound, 0, 120);
            $gameUidText = substr($gameUid, 0, 120);
            $rawSafe = $data;
            unset($rawSafe['api_key']);
            $rawJson = gamblly_api_json_encode($rawSafe);

            $stmt = $conn->prepare("INSERT INTO game_api_callback_transactions (external_transaction_id, player_id, callback_type, api_game_id, round_id, amount, balance_before, balance_after, raw_data) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sisssssss', $externalId, $userId, $callbackType, $gameUidText, $roundText, $netText, $beforeText, $afterText, $rawJson);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE users SET balance=? WHERE id=? LIMIT 1");
            $stmt->bind_param('si', $afterText, $userId);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
        } catch (Throwable $e) {
            try { $conn->rollback(); } catch (Throwable $ignored) {}
            if ($e instanceof mysqli_sql_exception && (int)$e->getCode() === 1062) {
                // Same txn_id committed by a parallel request: report the real balance, apply nothing.
                try {
                    $balanceText = gamblly_api_read_user_balance($conn, $userId, false);
                    $balanceUnits = ($balanceText === null) ? null : gamblly_api_parse_amount($balanceText, $scale, $amountError);
                    if ($balanceUnits !== null) {
                        $log['reason'] = 'duplicate_txn_id_race';
                        gamblly_api_callback_ok($balanceUnits, $scale, $log);
                    }
                } catch (Throwable $ignored) {}
            }
            gamblly_api_callback_fail('exception:' . $e->getMessage(), 'Wallet update failed', 500, $log);
        }

        // Wallet is committed. Bet history / turnover run after the reply is sent.
        $currency = (string)gamblly_api_pick($data, 'currency_code', $config['currency']);
        $afterResponse = function () use ($conn, $userId, $gameUid, $gameRound, $txnId, $bet, $win, $action, $balanceUnits, $newBalanceUnits, $scale, $currency) {
            if (!function_exists('game_api_insert_bet_history')) { return; }
            $row = array(
                'user_id' => $userId,
                'username' => (string)$userId,
                'vendor_code' => 'gamblly',
                'game_uid' => substr($gameUid, 0, 50),
                'round_id' => substr($gameRound, 0, 100),
                'currency' => substr(strtoupper($currency), 0, 10)
            );
            $stmt = $conn->prepare("SELECT username FROM users WHERE id=? LIMIT 1");
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($res && $res->num_rows > 0) { $row['username'] = (string)$res->fetch_assoc()['username']; }
            $stmt->close();

            $txnShort = substr($txnId, 0, 85);
            if ($bet > 0) {
                game_api_insert_bet_history($conn, $row + array(
                    'transaction_id' => 'gamblly_bet_' . $txnShort,
                    'type' => 'bet',
                    'amount' => (float)gamblly_api_units_to_decimal($bet, $scale),
                    'balance_after' => (float)gamblly_api_units_to_decimal($balanceUnits - $bet, $scale)
                ));
            }
            if ($win > 0) {
                game_api_insert_bet_history($conn, $row + array(
                    'transaction_id' => 'gamblly_win_' . $txnShort,
                    'type' => ($action === 'refund') ? 'cancel' : 'win',
                    'amount' => (float)gamblly_api_units_to_decimal($win, $scale),
                    'balance_after' => (float)gamblly_api_units_to_decimal($newBalanceUnits, $scale)
                ));
            }
        };

        $log['reason'] = 'applied';
        gamblly_api_callback_ok($newBalanceUnits, $scale, $log, $afterResponse);
    }
}
?>
