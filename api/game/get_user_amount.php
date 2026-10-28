<?php
session_start();
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/game_api_helper.php';
require_once __DIR__ . '/../../includes/gamblly_api_helper.php';

if (!isset($_SESSION['user_id'])) {
    gamblly_api_response(array('success' => false, 'code' => 401, 'message' => 'Unauthorized'), 401);
}

$userId = (int)$_SESSION['user_id'];
session_write_close();

// V1 Seamless Wallet: the site wallet is the only balance. Bets/wins already arrive
// through the callback, so pulling a "withdraw" from Gamblly here would double-credit.
$stmt = $conn->prepare("SELECT balance FROM users WHERE id=? LIMIT 1");
$stmt->bind_param('i', $userId);
$stmt->execute();
$res = $stmt->get_result();
if (!$res || $res->num_rows === 0) {
    $stmt->close();
    gamblly_api_response(array('success' => false, 'code' => 404, 'message' => 'Player not found'), 404);
}
$localBalance = (string)$res->fetch_assoc()['balance'];
$stmt->close();

gamblly_api_response(array(
    'success' => true,
    'code' => 0,
    'message' => 'Success',
    'withdraw_amount' => '0.00',
    'local_balance' => number_format((float)$localBalance, 2, '.', '')
));
?>
