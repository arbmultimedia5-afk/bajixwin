<?php
// webcornerbd/finance.php
// Fast Deposit & Withdrawal Controller (Dedicated Module Views)

mysqli_report(MYSQLI_REPORT_OFF); 
session_start();

require '../includes/auth_session.php';
require '../includes/db.php';
require '../includes/functions.php';
require_once '../includes/propay_gateway_helper.php';
require_once '../includes/akpay_gateway_helper.php';
require_once '../includes/lgpay_gateway_helper.php';
require_once '../includes/nekpay_gateway_helper.php';
require_once '../includes/referral_system_helper.php';
require_once '../includes/withdrawal_system_helper.php';

// =========================================================
// 1. SERVICE & TAB SELECTION
// =========================================================
$service = $_GET['service'] ?? 'deposit'; // 'deposit', 'withdraw', 'all'
$tab = $_GET['tab'] ?? 'pending'; // 'pending', 'approved', 'rejected', 'today', 'all'

if ($service === 'deposit') {
    $type_filter = 'deposit';
} elseif ($service === 'withdraw') {
    $type_filter = 'withdraw';
} else {
    $type_filter = 'all';
}

if ($tab === 'today') {
    $status_filter = 'all';
    $filter_period = 'today';
} elseif (in_array($tab, ['pending', 'approved', 'rejected', 'processing'], true)) {
    $status_filter = $tab;
    $filter_period = 'lifetime';
} else {
    $status_filter = $_GET['status'] ?? 'all';
    $filter_period = $_GET['period'] ?? 'lifetime';
}

$todayDate = date('Y-m-d');
$start_date = $_GET['start'] ?? $todayDate;
$end_date = $_GET['end'] ?? $todayDate;

if ($filter_period === 'today') {
    $start_date = $todayDate;
    $end_date = $todayDate;
}

// =========================================================
// 2. ACTION HANDLERS
// =========================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['trx_id'])) {
    $tid = intval($_POST['trx_id']);
    $action = (string)$_POST['action'];
    $admin_id = intval($_SESSION['admin_id'] ?? 0);
    $t_query = $conn->query("SELECT * FROM transactions_fake WHERE id=$tid LIMIT 1");
    $t = $t_query ? $t_query->fetch_assoc() : null;

    if (!$t) {
        $_SESSION['finance_flash'] = array('type' => 'error', 'message' => 'Transaction not found.');
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit();
    }

    // --- A. WITHDRAWAL ACTIONS ---
    if ($t['type'] === 'withdraw') {
        if ($action === 'approve_manual' || $action === 'approve') {
            $note = 'Approved manually by admin #' . $admin_id . ' (' . date('Y-m-d H:i') . ')';
            $stmtUp = $conn->prepare("UPDATE transactions_fake SET status='approved', agent_id=?, admin_note=? WHERE id=? AND status='pending'");
            if ($stmtUp) {
                $stmtUp->bind_param('isi', $admin_id, $note, $tid);
                $stmtUp->execute();
                $stmtUp->close();
                $_SESSION['finance_flash'] = array('type' => 'success', 'message' => 'Withdrawal request approved successfully (Manual Payment).');
            }
        } elseif ($action === 'approve_gateway') {
            $result = wcb_process_pending_withdrawal($conn, $tid, $admin_id);
            $_SESSION['finance_flash'] = array('type' => !empty($result['success']) ? 'success' : 'error', 'message' => $result['message'] ?? 'Unable to process gateway payout.');
        } elseif ($action === 'reject') {
            $result = wcb_reject_pending_withdrawal($conn, $tid, $admin_id);
            $_SESSION['finance_flash'] = array('type' => !empty($result['success']) ? 'success' : 'error', 'message' => $result['message'] ?? 'Unable to reject withdrawal.');
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit();
    }

    // --- B. DEPOSIT ACTIONS ---
    if ($t['type'] === 'deposit') {
        if (($action === 'approve' || $action === 'approve_deposit') && $t['status'] === 'pending') {
            $conn->query("UPDATE transactions_fake SET status='approved', agent_id=$admin_id WHERE id=$tid AND status='pending'");
            if ($conn->affected_rows > 0) {
                $user_id = intval($t['user_id']);
                $amount = (float)$t['amount'];
                $promo_id = isset($t['promo_id']) ? intval($t['promo_id']) : 0;
                $credit = propay_calculate_deposit_credit($conn, $user_id, $amount, $promo_id, $t['method'] ?? '');
                $total_money = (float)$credit['total_money'];
                $target_add = (float)$credit['target_add'];
                $stmtCredit = $conn->prepare("UPDATE users SET balance=balance+?, turnover_target=GREATEST(COALESCE(turnover_target,0),COALESCE(turnover_completed,0))+? WHERE id=?");
                $stmtCredit->bind_param('ddi', $total_money, $target_add, $user_id);
                $stmtCredit->execute();
                $stmtCredit->close();
                wcb_referral_award_for_deposit($conn, $user_id, $tid, $amount);
                $_SESSION['finance_flash'] = array('type' => 'success', 'message' => 'Deposit approved and ৳' . number_format($total_money, 2) . ' credited successfully!');
            }
        } elseif ($action === 'reject' && $t['status'] === 'pending') {
            $conn->query("UPDATE transactions_fake SET status='rejected', agent_id=$admin_id WHERE id=$tid AND status='pending'");
            $_SESSION['finance_flash'] = array('type' => 'success', 'message' => 'Deposit request rejected.');
        }
        header('Location: ' . $_SERVER['REQUEST_URI']);
        exit();
    }

    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit();
}

// =========================================================
// 3. STATS CALCULATION SPECIFIC TO CURRENT VIEW
// =========================================================
$dep_counts_q = $conn->query("SELECT 
    COUNT(CASE WHEN type='deposit' AND status='pending' THEN 1 END) AS dep_pending_count,
    SUM(CASE WHEN type='deposit' AND status='pending' THEN amount ELSE 0 END) AS dep_pending_sum,
    COUNT(CASE WHEN type='deposit' AND status='approved' THEN 1 END) AS dep_approved_count,
    SUM(CASE WHEN type='deposit' AND status='approved' THEN amount ELSE 0 END) AS dep_approved_sum,
    COUNT(CASE WHEN type='deposit' AND status='rejected' THEN 1 END) AS dep_rejected_count,
    SUM(CASE WHEN type='deposit' AND status='rejected' THEN amount ELSE 0 END) AS dep_rejected_sum,
    COUNT(CASE WHEN type='deposit' AND DATE(created_at)='$todayDate' THEN 1 END) AS dep_today_count,
    SUM(CASE WHEN type='deposit' AND status='approved' AND DATE(created_at)='$todayDate' THEN amount ELSE 0 END) AS dep_today_sum
    FROM transactions_fake");
$dep_stats = $dep_counts_q ? $dep_counts_q->fetch_assoc() : [];

$wd_counts_q = $conn->query("SELECT 
    COUNT(CASE WHEN type='withdraw' AND (status='pending' OR status='processing') THEN 1 END) AS wd_pending_count,
    SUM(CASE WHEN type='withdraw' AND (status='pending' OR status='processing') THEN amount ELSE 0 END) AS wd_pending_sum,
    COUNT(CASE WHEN type='withdraw' AND status='approved' THEN 1 END) AS wd_approved_count,
    SUM(CASE WHEN type='withdraw' AND status='approved' THEN amount ELSE 0 END) AS wd_approved_sum,
    COUNT(CASE WHEN type='withdraw' AND status='rejected' THEN 1 END) AS wd_rejected_count,
    SUM(CASE WHEN type='withdraw' AND status='rejected' THEN amount ELSE 0 END) AS wd_rejected_sum,
    COUNT(CASE WHEN type='withdraw' AND DATE(created_at)='$todayDate' THEN 1 END) AS wd_today_count,
    SUM(CASE WHEN type='withdraw' AND status='approved' AND DATE(created_at)='$todayDate' THEN amount ELSE 0 END) AS wd_today_sum
    FROM transactions_fake");
$wd_stats = $wd_counts_q ? $wd_counts_q->fetch_assoc() : [];

// Determine page titles, subtitles and badges
$page_meta = [
    'title' => 'Transactions Ledger',
    'subtitle' => 'View all system transactions and histories.',
    'icon' => 'fa-list',
    'theme_color' => 'indigo',
    'stat1_label' => 'Total Transactions',
    'stat1_val' => '0',
    'stat2_label' => 'Total Volume',
    'stat2_val' => '৳0'
];

if ($service === 'deposit') {
    if ($tab === 'pending') {
        $page_meta = [
            'title' => 'Deposit Requests (Pending)',
            'subtitle' => 'Review and instantly approve or reject incoming player deposit requests.',
            'icon' => 'fa-inbox',
            'theme_color' => 'amber',
            'stat1_label' => 'Pending Requests',
            'stat1_val' => number_format($dep_stats['dep_pending_count'] ?? 0) . ' Pending',
            'stat2_label' => 'Total Pending Amount',
            'stat2_val' => '৳' . number_format($dep_stats['dep_pending_sum'] ?? 0, 2)
        ];
    } elseif ($tab === 'approved') {
        $page_meta = [
            'title' => 'Deposit Accepted (Approved)',
            'subtitle' => 'List of successfully verified and credited player deposits.',
            'icon' => 'fa-check-circle',
            'theme_color' => 'emerald',
            'stat1_label' => 'Accepted Deposits',
            'stat1_val' => number_format($dep_stats['dep_approved_count'] ?? 0) . ' Total',
            'stat2_label' => 'Total Credited Amount',
            'stat2_val' => '৳' . number_format($dep_stats['dep_approved_sum'] ?? 0, 2)
        ];
    } elseif ($tab === 'rejected') {
        $page_meta = [
            'title' => 'Deposit Rejected',
            'subtitle' => 'List of declined or failed deposit requests.',
            'icon' => 'fa-times-circle',
            'theme_color' => 'red',
            'stat1_label' => 'Rejected Deposits',
            'stat1_val' => number_format($dep_stats['dep_rejected_count'] ?? 0) . ' Total',
            'stat2_label' => 'Total Rejected Amount',
            'stat2_val' => '৳' . number_format($dep_stats['dep_rejected_sum'] ?? 0, 2)
        ];
    } elseif ($tab === 'today') {
        $page_meta = [
            'title' => 'Today Deposits (' . date('d M Y') . ')',
            'subtitle' => 'All deposits received and processed today.',
            'icon' => 'fa-calendar-day',
            'theme_color' => 'emerald',
            'stat1_label' => 'Today Requests',
            'stat1_val' => number_format($dep_stats['dep_today_count'] ?? 0) . ' Requests',
            'stat2_label' => 'Today Approved Volume',
            'stat2_val' => '৳' . number_format($dep_stats['dep_today_sum'] ?? 0, 2)
        ];
    }
} elseif ($service === 'withdraw') {
    if ($tab === 'pending') {
        $page_meta = [
            'title' => 'Withdrawal Requests (Pending)',
            'subtitle' => 'Player withdrawal requests waiting for manual payout or auto-gateway processing.',
            'icon' => 'fa-paper-plane',
            'theme_color' => 'amber',
            'stat1_label' => 'Pending Withdrawals',
            'stat1_val' => number_format($wd_stats['wd_pending_count'] ?? 0) . ' Waiting',
            'stat2_label' => 'Total Payout Amount',
            'stat2_val' => '৳' . number_format($wd_stats['wd_pending_sum'] ?? 0, 2)
        ];
    } elseif ($tab === 'approved') {
        $page_meta = [
            'title' => 'Withdrawal Accepted (Paid)',
            'subtitle' => 'Completed and paid withdrawal records.',
            'icon' => 'fa-check-circle',
            'theme_color' => 'emerald',
            'stat1_label' => 'Paid Withdrawals',
            'stat1_val' => number_format($wd_stats['wd_approved_count'] ?? 0) . ' Completed',
            'stat2_label' => 'Total Amount Paid',
            'stat2_val' => '৳' . number_format($wd_stats['wd_approved_sum'] ?? 0, 2)
        ];
    } elseif ($tab === 'rejected') {
        $page_meta = [
            'title' => 'Withdrawal Rejected (Refunded)',
            'subtitle' => 'Cancelled withdrawal requests with balance refunded back to player account.',
            'icon' => 'fa-times-circle',
            'theme_color' => 'red',
            'stat1_label' => 'Rejected Withdrawals',
            'stat1_val' => number_format($wd_stats['wd_rejected_count'] ?? 0) . ' Rejected',
            'stat2_label' => 'Total Refunded Amount',
            'stat2_val' => '৳' . number_format($wd_stats['wd_rejected_sum'] ?? 0, 2)
        ];
    } elseif ($tab === 'today') {
        $page_meta = [
            'title' => 'Today Withdrawals (' . date('d M Y') . ')',
            'subtitle' => 'All withdrawal requests and payouts processed today.',
            'icon' => 'fa-calendar-day',
            'theme_color' => 'rose',
            'stat1_label' => 'Today Requests',
            'stat1_val' => number_format($wd_stats['wd_today_count'] ?? 0) . ' Requests',
            'stat2_label' => 'Today Paid Volume',
            'stat2_val' => '৳' . number_format($wd_stats['wd_today_sum'] ?? 0, 2)
        ];
    }
}

// =========================================================
// 4. BUILD TABLE QUERY
// =========================================================
$search_query = isset($_GET['q']) ? $conn->real_escape_string($_GET['q']) : '';
$where_clauses = [];

if (!empty($search_query)) {
    $where_clauses[] = "(t.transaction_id LIKE '%$search_query%' OR t.wallet_number LIKE '%$search_query%' OR u.username LIKE '%$search_query%' OR a.name LIKE '%$search_query%')";
}

if ($type_filter !== 'all') {
    $where_clauses[] = "t.type = '$type_filter'";
}

if ($status_filter !== 'all') {
    if ($status_filter === 'pending') {
        $where_clauses[] = "(t.status = 'pending' OR t.status = 'processing')";
    } else {
        $where_clauses[] = "t.status = '$status_filter'";
    }
}

if ($filter_period !== 'lifetime' && empty($search_query)) {
    $where_clauses[] = "DATE(t.created_at) BETWEEN '$start_date' AND '$end_date'";
}

$where_sql = count($where_clauses) > 0 ? "WHERE " . implode(' AND ', $where_clauses) : "";

$sql = "SELECT t.*, u.username, u.custom_wager_ratio, a.name as agent_name, 
        p.title as promo_title, p.bonus_percent as promo_percent, p.bonus_amount as promo_desc, p.wager_multiplier as promo_wager,
        pgo.status AS gateway_order_status, pgo.gateway_status, pgo.gateway_order_no, pgo.gateway
        FROM transactions_fake t 
        LEFT JOIN users u ON t.user_id = u.id 
        LEFT JOIN agents a ON t.agent_id = a.id 
        LEFT JOIN promotions p ON (t.promo_id IS NOT NULL AND t.promo_id = p.id)
        LEFT JOIN payment_gateway_orders pgo ON pgo.id=(SELECT MAX(pg2.id) FROM payment_gateway_orders pg2 WHERE pg2.transaction_id=t.id AND pg2.type='withdraw')
        $where_sql 
        ORDER BY FIELD(t.status, 'processing', 'pending', 'approved', 'rejected'), t.created_at DESC LIMIT 300";

$result = $conn->query($sql);

$finance_flash = $_SESSION['finance_flash'] ?? null;
unset($_SESSION['finance_flash']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_meta['title']); ?> | Admin Panel</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <style>body { font-family: 'Inter', sans-serif; }</style>
</head>
<body class="bg-gray-100 text-slate-800">

    <?php include '../includes/sidebar_admin.php'; ?>

    <main class="lg:ml-64 p-4 lg:p-8 min-h-screen pt-24 lg:pt-28 transition-all duration-300">
        <?php include '../includes/header.php'; ?>

        <div class="max-w-7xl mx-auto space-y-6 mt-2">

            <!-- DYNAMIC PAGE HEADER SPECIFIC TO CURRENT MODULE -->
            <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-sm flex flex-col md:flex-row justify-between items-start md:items-center gap-4">
                <div>
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-xl bg-<?php echo $page_meta['theme_color']; ?>-100 text-<?php echo $page_meta['theme_color']; ?>-600 flex items-center justify-center text-xl font-bold">
                            <i class="fas <?php echo $page_meta['icon']; ?>"></i>
                        </div>
                        <div>
                            <h1 class="text-2xl font-black text-gray-900"><?php echo htmlspecialchars($page_meta['title']); ?></h1>
                            <p class="text-xs text-gray-500 font-bold mt-0.5"><?php echo htmlspecialchars($page_meta['subtitle']); ?></p>
                        </div>
                    </div>
                </div>

                <!-- RIGHT SIDE SEARCH & STATS BADGES -->
                <div class="flex flex-wrap items-center gap-4 w-full md:w-auto">
                    <!-- Stat 1 -->
                    <div class="bg-gray-50 border border-gray-200 px-4 py-2 rounded-xl">
                        <div class="text-[10px] font-black uppercase text-gray-400"><?php echo $page_meta['stat1_label']; ?></div>
                        <div class="text-base font-black text-gray-900"><?php echo $page_meta['stat1_val']; ?></div>
                    </div>
                    <!-- Stat 2 -->
                    <div class="bg-gray-50 border border-gray-200 px-4 py-2 rounded-xl">
                        <div class="text-[10px] font-black uppercase text-gray-400"><?php echo $page_meta['stat2_label']; ?></div>
                        <div class="text-base font-black text-emerald-600"><?php echo $page_meta['stat2_val']; ?></div>
                    </div>

                    <!-- Search Box -->
                    <form method="GET" class="relative w-full md:w-64">
                        <input type="hidden" name="service" value="<?php echo htmlspecialchars($service); ?>">
                        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
                        <input type="text" name="q" value="<?php echo htmlspecialchars($search_query); ?>" placeholder="Search User, TrxID..." 
                               class="w-full pl-9 pr-3 py-2 rounded-xl border border-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 shadow-sm text-xs font-semibold bg-white">
                        <i class="fas fa-search absolute left-3 top-3 text-gray-400 text-xs"></i>
                    </form>
                </div>
            </div>

            <!-- FLASH ALERTS -->
            <?php if($finance_flash): ?>
                <div class="p-4 rounded-xl border-l-4 shadow-sm flex items-center gap-3 text-sm font-bold <?php echo $finance_flash['type']==='success' ? 'bg-emerald-50 border-emerald-500 text-emerald-800' : 'bg-red-50 border-red-500 text-red-800'; ?>">
                    <i class="fas <?php echo $finance_flash['type']==='success' ? 'fa-check-circle text-lg' : 'fa-exclamation-circle text-lg'; ?>"></i>
                    <span><?php echo htmlspecialchars($finance_flash['message'], ENT_QUOTES, 'UTF-8'); ?></span>
                </div>
            <?php endif; ?>

            <!-- DEDICATED TRANSACTION DATA TABLE -->
            <div class="bg-white rounded-2xl border border-gray-200 shadow-sm overflow-hidden">
                <div class="px-6 py-4 border-b border-gray-100 bg-gray-50/50 flex justify-between items-center">
                    <h3 class="font-black text-gray-800 text-xs uppercase tracking-wide flex items-center gap-2">
                        <i class="fas fa-table text-indigo-600"></i> Records (<?php echo $result ? $result->num_rows : 0; ?>)
                    </h3>
                    <div class="flex items-center gap-2">
                        <a href="?service=<?php echo urlencode($service); ?>&tab=<?php echo urlencode($tab); ?>" class="text-xs font-bold text-gray-500 hover:text-indigo-600 flex items-center gap-1">
                            <i class="fas fa-sync-alt"></i> Refresh
                        </a>
                    </div>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead class="bg-gray-50 border-b text-gray-500 font-black uppercase text-[11px] tracking-wider">
                            <tr>
                                <th class="px-6 py-4">User Details</th>
                                <th class="px-6 py-4">Amount & Method</th>
                                <th class="px-6 py-4">Account / TrxID</th>
                                <th class="px-6 py-4">Status & Date</th>
                                <th class="px-6 py-4 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if($result && $result->num_rows > 0): ?>
                                <?php while($row = $result->fetch_assoc()): ?>
                                
                                <?php
                                    $is_dep = ($row['type'] === 'deposit');
                                    $is_pending = ($row['status'] === 'pending' || $row['status'] === 'processing');
                                ?>

                                <tr class="hover:bg-gray-50/80 transition <?php echo $is_pending ? 'bg-amber-50/30' : ''; ?>">
                                    <!-- User Info -->
                                    <td class="px-6 py-4">
                                        <div class="font-black text-gray-900"><?php echo htmlspecialchars($row['username'] ?? 'User #' . $row['user_id']); ?></div>
                                        <div class="text-xs text-gray-500 font-semibold mt-0.5">UID: <span class="text-indigo-600 font-bold"><?php echo $row['user_id']; ?></span></div>
                                    </td>

                                    <!-- Amount & Method -->
                                    <td class="px-6 py-4">
                                        <div class="flex items-center gap-2">
                                            <span class="uppercase text-[10px] font-black px-2 py-0.5 rounded <?php echo $is_dep ? 'bg-emerald-100 text-emerald-800 border border-emerald-200' : 'bg-rose-100 text-rose-800 border border-rose-200'; ?>">
                                                <?php echo $row['type']; ?>
                                            </span>
                                            <span class="text-base font-black text-gray-900">৳<?php echo number_format($row['amount'], 2); ?></span>
                                        </div>
                                        <div class="text-xs font-bold text-gray-600 mt-1 uppercase">
                                            <?php echo htmlspecialchars($row['method'] ?: 'Payment'); ?>
                                        </div>
                                    </td>

                                    <!-- Wallet & Trx Details -->
                                    <td class="px-6 py-4">
                                        <div class="font-mono text-xs font-bold text-gray-800 flex items-center gap-1.5">
                                            <i class="far fa-credit-card text-gray-400"></i>
                                            <span><?php echo htmlspecialchars($row['wallet_number'] ?: 'N/A'); ?></span>
                                        </div>
                                        <?php if(!empty($row['transaction_id'])): ?>
                                            <div class="text-[11px] font-mono text-gray-500 mt-0.5">Trx: <?php echo htmlspecialchars($row['transaction_id']); ?></div>
                                        <?php endif; ?>
                                    </td>

                                    <!-- Status & Date -->
                                    <td class="px-6 py-4">
                                        <?php 
                                            $statusBadge = match($row['status']) {
                                                'approved'   => 'bg-emerald-100 text-emerald-800 border-emerald-300',
                                                'rejected'   => 'bg-red-100 text-red-800 border-red-300',
                                                'processing' => 'bg-blue-100 text-blue-800 border-blue-300 animate-pulse',
                                                'pending'    => 'bg-amber-100 text-amber-800 border-amber-300 animate-pulse',
                                                default      => 'bg-gray-100 text-gray-800 border-gray-300'
                                            };
                                        ?>
                                        <span class="px-2.5 py-1 rounded-lg text-xs font-black uppercase border inline-flex items-center gap-1.5 <?php echo $statusBadge; ?>">
                                            <?php if($row['status']==='pending'): ?><span class="w-1.5 h-1.5 rounded-full bg-amber-600 animate-ping"></span><?php endif; ?>
                                            <?php echo $row['status']; ?>
                                        </span>
                                        <div class="text-[11px] text-gray-400 mt-1"><?php echo date('d M Y, h:i A', strtotime($row['created_at'])); ?></div>
                                    </td>

                                    <!-- Quick Actions -->
                                    <td class="px-6 py-4 text-right">
                                        <?php if($row['status'] === 'pending'): ?>
                                            
                                            <?php if ($row['type'] === 'deposit'): ?>
                                                <!-- DEPOSIT APPROVAL -->
                                                <form method="POST" class="inline-flex items-center justify-end gap-2">
                                                    <input type="hidden" name="trx_id" value="<?php echo $row['id']; ?>">
                                                    <button type="submit" name="action" value="approve_deposit" onclick="return confirm('Confirm deposit approval and credit ৳<?php echo $row['amount']; ?> to user?')"
                                                            class="bg-emerald-600 hover:bg-emerald-700 text-white font-black px-3.5 py-2 rounded-xl text-xs shadow-sm transition active:scale-95 flex items-center gap-1.5">
                                                        <i class="fas fa-check"></i> Approve & Credit
                                                    </button>
                                                    <button type="submit" name="action" value="reject" onclick="return confirm('Reject this deposit request?')"
                                                            class="bg-red-500 hover:bg-red-600 text-white font-black px-3.5 py-2 rounded-xl text-xs shadow-sm transition active:scale-95 flex items-center gap-1.5">
                                                        <i class="fas fa-times"></i> Reject
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <!-- WITHDRAWAL APPROVAL -->
                                                <form method="POST" class="inline-flex items-center justify-end gap-2">
                                                    <input type="hidden" name="trx_id" value="<?php echo $row['id']; ?>">
                                                    
                                                    <!-- Option 1: Manual Approve -->
                                                    <button type="submit" name="action" value="approve_manual" onclick="return confirm('Confirm MANUAL payment of ৳<?php echo $row['amount']; ?> to <?php echo $row['wallet_number']; ?>?')"
                                                            class="bg-emerald-600 hover:bg-emerald-700 text-white font-black px-3.5 py-2 rounded-xl text-xs shadow-sm transition active:scale-95 flex items-center gap-1.5" title="Approve as paid manually via bKash/Nagad">
                                                        <i class="fas fa-check-double"></i> Manual Approve (Paid)
                                                    </button>

                                                    <!-- Option 2: Auto Payout Gateway -->
                                                    <button type="submit" name="action" value="approve_gateway" onclick="return confirm('Send automated API payout of ৳<?php echo $row['amount']; ?> via NEKpay/LG Pay?')"
                                                            class="bg-indigo-600 hover:bg-indigo-700 text-white font-black px-3 py-2 rounded-xl text-xs shadow-sm transition active:scale-95 flex items-center gap-1.5" title="Send auto payout via payment gateway">
                                                        <i class="fas fa-bolt"></i> Auto Gateway
                                                    </button>

                                                    <!-- Option 3: Reject & Refund -->
                                                    <button type="submit" name="action" value="reject" onclick="return confirm('Reject withdrawal and REFUND ৳<?php echo $row['amount']; ?> to player?')"
                                                            class="bg-red-500 hover:bg-red-600 text-white font-black px-3 py-2 rounded-xl text-xs shadow-sm transition active:scale-95 flex items-center gap-1.5" title="Reject and refund user balance">
                                                        <i class="fas fa-times"></i> Reject & Refund
                                                    </button>
                                                </form>
                                            <?php endif; ?>

                                        <?php else: ?>
                                            <span class="text-xs text-gray-400 font-bold italic inline-flex items-center gap-1">
                                                <i class="fas fa-check"></i> Completed
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="5" class="p-12 text-center text-gray-400 font-bold">
                                        <i class="far fa-folder-open text-3xl mb-2 block text-gray-300"></i>
                                        No records found for <?php echo htmlspecialchars($page_meta['title']); ?>.
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

</body>
</html>