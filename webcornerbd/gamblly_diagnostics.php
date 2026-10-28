<?php
require '../includes/auth_session.php';
require '../includes/db.php';
require_once '../includes/game_api_helper.php';
require_once '../includes/gamblly_api_helper.php';
require_once '../includes/propay_gateway_helper.php';
require_once '../includes/akpay_gateway_helper.php';
require_once '../includes/lgpay_gateway_helper.php';
require_once '../includes/nekpay_gateway_helper.php';

if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'admin') {
    die('Access Denied');
}
session_write_close();

// Read-only view of the Gamblly setup and the latest callback / launch log lines.
$settings = game_api_get_settings($conn, true);
$config = gamblly_api_get_config($conn, $settings);

$maskKey = function ($value) {
    $value = (string)$value;
    return strlen($value) > 8 ? substr($value, 0, 4) . '…' . substr($value, -4) : ($value === '' ? '(empty)' : '***');
};

$balanceType = '';
$res = @$conn->query("SHOW COLUMNS FROM users LIKE 'balance'");
if ($res && $res->num_rows > 0) { $balanceType = (string)$res->fetch_assoc()['Type']; }

$nek = function_exists('nekpay_get_settings') ? nekpay_get_settings($conn) : array();
$lg = function_exists('lgpay_get_settings') ? lgpay_get_settings($conn) : array();

// Outgoing IP of this server: the address the payment gateway sees and must whitelist.
$serverIp = '';
if (isset($_GET['check_ip'])) {
    $ch = curl_init('https://api.ipify.org');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 6);
    $serverIp = trim((string)curl_exec($ch));
    $ipError = curl_error($ch);
    curl_close($ch);
    if ($serverIp === '') { $serverIp = 'Could not detect: ' . $ipError; }
}

$callbacks = array();
$launches = array();
$payouts = array();
$logPath = function_exists('game_api_debug_log_path') ? game_api_debug_log_path() : '';
$logSize = ($logPath !== '' && is_file($logPath)) ? filesize($logPath) : 0;
if ($logSize > 0) {
    $fh = fopen($logPath, 'rb');
    $readBytes = min($logSize, 3 * 1024 * 1024);
    fseek($fh, -$readBytes, SEEK_END);
    $chunk = fread($fh, $readBytes);
    fclose($fh);
    foreach (explode("\n", $chunk) as $line) {
        if (preg_match('/^\[([^\]]+)\] NEKPAY_PAYOUT_CALL endpoint=(\S+) params=(\{.*?\}) curlErr=(.*?) rawResponse=(.*)$/', $line, $p)) {
            $payouts[] = array('time' => $p[1], 'endpoint' => $p[2], 'params' => json_decode($p[3], true), 'curl_error' => $p[4], 'response' => $p[5]);
            continue;
        }
        if (!preg_match('/^\[([^\]]+)\] (gamblly_callback|gamblly_launch_attempt) (.*)$/', $line, $m)) { continue; }
        $entry = json_decode($m[3], true);
        if (!is_array($entry)) { continue; }
        $row = array('time' => $m[1], 'ip' => isset($entry['meta']['ip']) ? $entry['meta']['ip'] : '', 'ctx' => isset($entry['context']) ? $entry['context'] : array());
        if ($m[2] === 'gamblly_callback') { $callbacks[] = $row; } else { $launches[] = $row; }
    }
}
$callbacks = array_slice($callbacks, -500);
$launches = array_slice($launches, -15);
$payouts = array_slice($payouts, -15);

$total = count($callbacks);
$failed = 0;
$reasons = array();
$playerFormats = array();
$callerIps = array();
$msList = array();
foreach ($callbacks as $row) {
    $ctx = $row['ctx'];
    $reason = isset($ctx['reason']) ? (string)$ctx['reason'] : '';
    if (empty($ctx['status'])) { $failed++; }
    $reasons[$reason] = isset($reasons[$reason]) ? $reasons[$reason] + 1 : 1;
    $player = isset($ctx['player_uid']) ? (string)$ctx['player_uid'] : '';
    $format = preg_replace('/[0-9]/', '9', $player);
    $playerFormats[$format] = $player;
    $callerIps[$row['ip']] = isset($callerIps[$row['ip']]) ? $callerIps[$row['ip']] + 1 : 1;
    if (isset($ctx['ms'])) { $msList[] = (int)$ctx['ms']; }
}
sort($msList);
$msMedian = $msList ? $msList[(int)(count($msList) / 2)] : 0;
$msMax = $msList ? end($msList) : 0;
arsort($reasons);

$h = function ($value) { return htmlspecialchars(is_scalar($value) || $value === null ? (string)$value : json_encode($value), ENT_QUOTES, 'UTF-8'); };
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gamblly Diagnostics | Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
</head>
<body class="bg-gray-50 font-sans text-slate-800">
    <?php include '../includes/sidebar_admin.php'; ?>
    <main class="lg:ml-64 p-4 lg:p-8 min-h-screen">
        <div class="max-w-6xl mx-auto space-y-6">
            <div class="flex items-center justify-between">
                <h1 class="text-2xl font-bold flex items-center gap-2"><i class="fas fa-stethoscope text-yellow-500"></i> Gamblly Diagnostics</h1>
                <a href="gamblly_diagnostics.php" class="bg-yellow-500 hover:bg-yellow-400 text-black font-bold px-4 py-2 rounded-lg text-sm">Refresh</a>
            </div>
            <p class="text-sm text-gray-500">Server time: <?php echo $h(date('Y-m-d H:i:s')); ?> · Read-only page. Take a screenshot of this whole page after a game disconnects.</p>

            <section class="bg-white rounded-2xl border border-gray-200 p-5">
                <h2 class="font-bold mb-3">Setup</h2>
                <div class="grid sm:grid-cols-2 gap-2 text-sm">
                    <div>Provider: <b><?php echo $h(isset($settings['game_api_provider']) ? $settings['game_api_provider'] : ''); ?></b></div>
                    <div>API key in use: <b class="font-mono"><?php echo $h($maskKey($config['api_key'])); ?></b></div>
                    <div>Launch URL: <b class="font-mono break-all"><?php echo $h($config['launch_url']); ?></b></div>
                    <div>Callback URL to set in Gamblly: <b class="font-mono break-all"><?php echo $h(game_api_site_url('/api/game/callback.php')); ?></b></div>
                    <div>users.balance column: <b class="<?php echo stripos($balanceType, ',6)') !== false ? 'text-green-700' : 'text-red-600'; ?>"><?php echo $h($balanceType); ?></b></div>
                    <div>Amount decimals accepted: <b><?php echo $h(gamblly_api_amount_scale($settings)); ?></b></div>
                    <div>Prefix / suffix stripped from player_uid: <b class="font-mono"><?php echo $h($config['member_prefix'] . ' / ' . $config['member_suffix']); ?></b></div>
                    <div>Log size: <b><?php echo $h(number_format($logSize / 1024, 1)); ?> KB</b></div>
                </div>
            </section>

            <section class="bg-white rounded-2xl border border-gray-200 p-5">
                <h2 class="font-bold mb-3">Last <?php echo $total; ?> callbacks</h2>
                <?php if ($total === 0): ?>
                    <p class="text-red-600 font-bold">No callbacks have reached this server. Gamblly is not calling <?php echo $h(game_api_site_url('/api/game/callback.php')); ?> — check the callback URL in the Gamblly portal.</p>
                <?php else: ?>
                    <div class="grid sm:grid-cols-4 gap-3 text-sm mb-4">
                        <div class="bg-gray-50 rounded-lg p-3">Failed: <b class="<?php echo $failed > 0 ? 'text-red-600' : 'text-green-700'; ?>"><?php echo $failed; ?></b> / <?php echo $total; ?></div>
                        <div class="bg-gray-50 rounded-lg p-3">Median time: <b><?php echo $msMedian; ?> ms</b></div>
                        <div class="bg-gray-50 rounded-lg p-3">Slowest: <b class="<?php echo $msMax > 500 ? 'text-red-600' : ''; ?>"><?php echo $msMax; ?> ms</b></div>
                        <div class="bg-gray-50 rounded-lg p-3">Last callback: <b><?php echo $h($callbacks[$total - 1]['time']); ?></b></div>
                    </div>
                    <div class="grid sm:grid-cols-3 gap-4 text-sm mb-4">
                        <div><div class="font-bold mb-1">Reasons</div><?php foreach ($reasons as $reason => $count): ?><div class="font-mono"><?php echo $h($reason === '' ? '(none)' : $reason); ?>: <?php echo $count; ?></div><?php endforeach; ?></div>
                        <div><div class="font-bold mb-1">player_uid formats</div><?php foreach ($playerFormats as $format => $example): ?><div class="font-mono"><?php echo $h($example); ?></div><?php endforeach; ?></div>
                        <div><div class="font-bold mb-1">Caller IPs</div><?php foreach ($callerIps as $ip => $count): ?><div class="font-mono"><?php echo $h($ip); ?>: <?php echo $count; ?></div><?php endforeach; ?></div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-xs">
                            <thead><tr class="text-left text-gray-500 border-b"><th class="py-1 pr-3">Time</th><th class="pr-3">Action</th><th class="pr-3">Player</th><th class="pr-3">Bet</th><th class="pr-3">Win</th><th class="pr-3">Txn</th><th class="pr-3">Result</th><th class="pr-3">HTTP</th><th class="pr-3">Balance</th><th>ms</th></tr></thead>
                            <tbody>
                            <?php foreach (array_reverse(array_slice($callbacks, -60)) as $row): $c = $row['ctx']; $ok = !empty($c['status']); ?>
                                <tr class="border-b <?php echo $ok ? '' : 'bg-red-50 text-red-700 font-semibold'; ?>">
                                    <td class="py-1 pr-3 whitespace-nowrap"><?php echo $h($row['time']); ?></td>
                                    <td class="pr-3"><?php echo $h(isset($c['action']) ? $c['action'] : ''); ?></td>
                                    <td class="pr-3 font-mono"><?php echo $h(isset($c['player_uid']) ? $c['player_uid'] : ''); ?></td>
                                    <td class="pr-3 font-mono"><?php echo $h(isset($c['bet_amount']) ? $c['bet_amount'] : ''); ?></td>
                                    <td class="pr-3 font-mono"><?php echo $h(isset($c['win_amount']) ? $c['win_amount'] : ''); ?></td>
                                    <td class="pr-3 font-mono"><?php echo $h(isset($c['txn_id']) ? substr((string)$c['txn_id'], 0, 24) : ''); ?></td>
                                    <td class="pr-3 font-mono"><?php echo $h(isset($c['reason']) ? $c['reason'] : ''); ?></td>
                                    <td class="pr-3"><?php echo $h(isset($c['http_code']) ? $c['http_code'] : ''); ?></td>
                                    <td class="pr-3 font-mono"><?php echo $h(isset($c['balance']) ? $c['balance'] : ''); ?></td>
                                    <td><?php echo $h(isset($c['ms']) ? $c['ms'] : ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="bg-white rounded-2xl border border-gray-200 p-5">
                <h2 class="font-bold mb-3">Withdraw payout gateway</h2>
                <div class="grid sm:grid-cols-2 gap-2 text-sm mb-4">
                    <div>NEKpay enabled: <b class="<?php echo !empty($nek['is_enabled']) ? 'text-green-700' : 'text-red-600'; ?>"><?php echo !empty($nek['is_enabled']) ? 'YES' : 'NO'; ?></b></div>
                    <div>NEKpay merchant: <b class="font-mono"><?php echo $h(isset($nek['merchant_code']) ? $nek['merchant_code'] : ''); ?></b> · key <b class="font-mono"><?php echo $h($maskKey(isset($nek['secret_code']) ? $nek['secret_code'] : '')); ?></b></div>
                    <div>NEKpay API: <b class="font-mono break-all"><?php echo $h(isset($nek['api_base_url']) ? $nek['api_base_url'] : ''); ?></b></div>
                    <div>LG Pay enabled: <b><?php echo !empty($lg['is_enabled']) ? 'YES' : 'NO'; ?></b></div>
                    <div>Payout callback URL: <b class="font-mono break-all"><?php echo $h(game_api_site_url('/api/nekpay_payout_callback.php')); ?></b></div>
                    <div>Server outgoing IP (whitelist this in the gateway):
                        <?php if ($serverIp !== ''): ?><b class="font-mono"><?php echo $h($serverIp); ?></b>
                        <?php else: ?><a href="?check_ip=1" class="text-blue-600 underline">Check IP</a><?php endif; ?>
                    </div>
                </div>
                <?php if (!$payouts): ?>
                    <p class="text-sm text-gray-500">No gateway payout attempts logged yet. Payouts are only sent when you press the gateway (NEKpay/LG Pay) approve button in Finance.</p>
                <?php else: ?>
                    <div class="overflow-x-auto">
                    <table class="min-w-full text-xs">
                        <thead><tr class="text-left text-gray-500 border-b"><th class="py-1 pr-3">Time</th><th class="pr-3">Payout ID</th><th class="pr-3">Amount</th><th class="pr-3">Bank</th><th class="pr-3">Connection error</th><th>Gateway response</th></tr></thead>
                        <tbody>
                        <?php foreach (array_reverse($payouts) as $row): $pp = is_array($row['params']) ? $row['params'] : array(); $resp = json_decode($row['response'], true); $ok = is_array($resp) && (strtoupper((string)(isset($resp['respCode']) ? $resp['respCode'] : '')) === 'SUCCESS'); ?>
                            <tr class="border-b <?php echo $ok ? '' : 'bg-red-50 text-red-700'; ?>">
                                <td class="py-1 pr-3 whitespace-nowrap"><?php echo $h($row['time']); ?></td>
                                <td class="pr-3 font-mono"><?php echo $h(isset($pp['mch_transferId']) ? $pp['mch_transferId'] : ''); ?></td>
                                <td class="pr-3"><?php echo $h(isset($pp['transfer_amount']) ? $pp['transfer_amount'] : ''); ?></td>
                                <td class="pr-3"><?php echo $h(isset($pp['bank_code']) ? $pp['bank_code'] : ''); ?></td>
                                <td class="pr-3"><?php echo $h($row['curl_error']); ?></td>
                                <td class="font-mono break-all"><?php echo $h(substr($row['response'], 0, 300)); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                <?php endif; ?>
            </section>

            <section class="bg-white rounded-2xl border border-gray-200 p-5">
                <h2 class="font-bold mb-3">Last game launches</h2>
                <?php if (!$launches): ?>
                    <p class="text-sm text-gray-500">No launches logged yet.</p>
                <?php else: ?>
                    <table class="min-w-full text-xs">
                        <thead><tr class="text-left text-gray-500 border-b"><th class="py-1 pr-3">Time</th><th class="pr-3">User</th><th class="pr-3">Game UID</th><th class="pr-3">Success</th><th>Message</th></tr></thead>
                        <tbody>
                        <?php foreach (array_reverse($launches) as $row): $c = $row['ctx']; ?>
                            <tr class="border-b">
                                <td class="py-1 pr-3 whitespace-nowrap"><?php echo $h($row['time']); ?></td>
                                <td class="pr-3"><?php echo $h(isset($c['user_id']) ? $c['user_id'] : ''); ?></td>
                                <td class="pr-3 font-mono"><?php echo $h(isset($c['gamblly_game_uid']) ? $c['gamblly_game_uid'] : ''); ?></td>
                                <td class="pr-3"><?php echo !empty($c['success']) ? '✅' : '❌'; ?></td>
                                <td><?php echo $h(isset($c['message']) ? $c['message'] : ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </section>
        </div>
    </main>
</body>
</html>
