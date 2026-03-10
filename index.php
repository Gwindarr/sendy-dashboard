<?php
/**
 * Sendy Dashboard — Subscriber Engagement Tracker
 * A read-only companion dashboard for Sendy (https://sendy.co)
 *
 * Shows autoresponder open tracking, subscriber engagement status,
 * Google Ads click attribution (gclid), and CSV export.
 *
 * See README.md for installation instructions.
 * See config.example.php for configuration options.
 */
declare(strict_types=1);

// ============= HANDLE LOGOUT (must be before any output) =============
if (isset($_GET['logout'])) {
    session_start();
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ============= HANDLE CSV EXPORT FLAG (must be before any output) =============
$is_export = isset($_GET['export']) && $_GET['export'] === 'csv';

// ============= CONFIG =============
// Look for config.php in same directory, or override with SENDY_DASHBOARD_CONFIG env var
$config_path = getenv('SENDY_DASHBOARD_CONFIG') ?: __DIR__ . '/config.php';
if (!file_exists($config_path)) {
    die('Config file not found. Copy config.example.php to config.php and update the values. See README.md for details.');
}
require_once($config_path);

// Validate required constants
if (!defined('DASHBOARD_PASSWORD') || DASHBOARD_PASSWORD === 'change-this-to-something-secure') {
    die('Please set DASHBOARD_PASSWORD in config.php');
}
if (!defined('SENDY_CONFIG_PATH') || !file_exists(SENDY_CONFIG_PATH)) {
    die('SENDY_CONFIG_PATH in config.php points to a file that does not exist: ' . (defined('SENDY_CONFIG_PATH') ? SENDY_CONFIG_PATH : 'undefined'));
}

// ============= AUTH =============
session_start();
if (!isset($_SESSION['dash_auth']) || $_SESSION['dash_auth'] !== true) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
        if ($_POST['password'] === DASHBOARD_PASSWORD) {
            $_SESSION['dash_auth'] = true;
        } else {
            $login_error = 'Wrong password';
        }
    }
    if (!isset($_SESSION['dash_auth']) || $_SESSION['dash_auth'] !== true) {
        ?>
        <!DOCTYPE html>
        <html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex, nofollow">
        <title>Dashboard Login</title>
        <style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f1f5f9;margin:0}
        .login{background:#fff;padding:2rem;border-radius:8px;box-shadow:0 4px 12px rgba(0,0,0,.1);width:300px}
        input[type=password]{width:100%;padding:.75rem;border:1px solid #e2e8f0;border-radius:6px;font-size:1rem;margin:.5rem 0 1rem;box-sizing:border-box}
        button{width:100%;padding:.75rem;background:#2563eb;color:#fff;border:none;border-radius:6px;font-size:1rem;cursor:pointer;font-weight:600}
        button:hover{background:#1d4ed8}.error{color:#dc2626;font-size:.875rem;margin-bottom:.5rem}</style></head>
        <body><div class="login"><h2 style="margin:0 0 1rem">Sendy Dashboard</h2>
        <?php if (isset($login_error)) echo '<p class="error">'.$login_error.'</p>'; ?>
        <form method="POST"><input type="password" name="password" placeholder="Password" autofocus>
        <button type="submit">Login</button></form></div></body></html>
        <?php
        exit;
    }
}

// ============= DB CONNECTION =============
require_once(SENDY_CONFIG_PATH);

mysqli_report(MYSQLI_REPORT_OFF);

if (isset($dbPort)) {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName, $dbPort);
} else {
    $mysqli = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
}

if ($mysqli->connect_error) {
    die('Database connection failed: ' . $mysqli->connect_error);
}
$charset = $charset ?? 'utf8';
mysqli_set_charset($mysqli, $charset);

if (defined('SENDY_SHORT_PATH') && file_exists(SENDY_SHORT_PATH)) {
    require_once(SENDY_SHORT_PATH);
}

// ============= FILTERS =============
$filter_source = $_GET['source'] ?? 'all';
$filter_days = (int)($_GET['days'] ?? 7);
$filter_days = max(1, min(365, $filter_days));
$filter_list = $_GET['list'] ?? 'all';
$filter_status = $_GET['status'] ?? 'all';
$search_email = trim($_GET['email'] ?? '');

// ============= GET LISTS =============
$lists = [];
$r = mysqli_query($mysqli, 'SELECT id, name FROM lists ORDER BY name');
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $lists[$row['id']] = $row['name'];
    }
}

// ============= GET AUTORESPONDER SEQUENCE =============
$track_ares_id = defined('TRACK_ARES_ID') ? (int)TRACK_ARES_ID : 0;

if ($track_ares_id === 0) {
    // Auto-detect: use the sequence with the most emails
    $r = mysqli_query($mysqli, 'SELECT ares_id, COUNT(*) as cnt FROM ares_emails GROUP BY ares_id ORDER BY cnt DESC LIMIT 1');
    if ($r && $row = mysqli_fetch_assoc($r)) {
        $track_ares_id = (int)$row['ares_id'];
    }
}

$ar_emails = [];
if ($track_ares_id > 0) {
    $r = mysqli_query($mysqli, "SELECT id, title, opens, time_condition FROM ares_emails WHERE ares_id = {$track_ares_id} ORDER BY id ASC");
    if ($r) {
        while ($row = mysqli_fetch_assoc($r)) {
            $ar_emails[$row['id']] = [
                'title' => $row['title'],
                'opens' => $row['opens'] ?? '',
                'time_condition' => $row['time_condition'] ?? '',
            ];
        }
    }
}
$total_ar_emails = count($ar_emails);

// ============= CUSTOM CAMPAIGNS =============
$custom_campaigns = isset($CUSTOM_CAMPAIGNS) && is_array($CUSTOM_CAMPAIGNS) ? $CUSTOM_CAMPAIGNS : [];

// ============= BUILD SUBSCRIBER QUERY =============
$cutoff = time() - ($filter_days * 86400);
$where = ["s.join_date >= {$cutoff}", "s.confirmed = 1"];

if ($filter_list !== 'all') {
    $list_id = (int)$filter_list;
    $where[] = "s.list = {$list_id}";
}

if ($search_email !== '') {
    $safe_email = mysqli_real_escape_string($mysqli, $search_email);
    $where[] = "s.email LIKE '%{$safe_email}%'";
}

if ($filter_source === 'ads') {
    $where[] = "s.referrer LIKE '%gclid%'";
} elseif ($filter_source === 'organic') {
    $where[] = "(s.referrer NOT LIKE '%gclid%' OR s.referrer IS NULL OR s.referrer = '')";
} elseif (is_numeric($filter_source)) {
    $campaign_id = mysqli_real_escape_string($mysqli, $filter_source);
    $where[] = "s.referrer LIKE '%campaignid={$campaign_id}%'";
}

$where_sql = implode(' AND ', $where);

$q = "SELECT s.id, s.email, s.name, s.country, s.ip, s.referrer, s.timestamp, s.join_date, s.list, s.last_ares, s.confirmed, s.unsubscribed, s.bounced
      FROM subscribers s
      WHERE {$where_sql}
      ORDER BY s.join_date DESC
      LIMIT 500";

$subscribers = [];
$r = mysqli_query($mysqli, $q);
if ($r) {
    while ($row = mysqli_fetch_assoc($r)) {
        $subscribers[] = $row;
    }
}

// ============= HELPER FUNCTIONS =============

function get_subscriber_opens(int $sub_id, array $ar_emails): array {
    $opened = [];
    foreach ($ar_emails as $ae_id => $ae) {
        if ($ae['opens'] !== '' && $ae['opens'] !== null) {
            if (preg_match('/(^|,)' . $sub_id . ':/', $ae['opens'])) {
                $opened[] = [
                    'id' => $ae_id,
                    'title' => $ae['title'],
                ];
            }
        }
    }
    return $opened;
}

function extract_gclid(string $referrer): string {
    if (preg_match('/gclid=([^&]+)/', $referrer, $m)) {
        return $m[1];
    }
    return '';
}

function extract_campaign_id(string $referrer): string {
    if (preg_match('/campaignid=(\d+)/', $referrer, $m)) {
        return $m[1];
    }
    return '';
}

function get_source_label(string $referrer): string {
    if (strpos($referrer, 'gclid') !== false) {
        $cid = extract_campaign_id($referrer);
        return $cid ? "Google Ads ({$cid})" : "Google Ads";
    }
    if (strpos($referrer, 'utm_') !== false) return 'UTM Tagged';
    if ($referrer === '' || $referrer === null) return 'Direct';
    return 'Organic';
}

function classify_subscriber(array $opens, int $join_ts, int $last_ts, int $total_ar): string {
    $hours_since_signup = (time() - $join_ts) / 3600;
    $opened_count = count($opens);

    if ($opened_count >= 3) return 'engaged';
    if ($opened_count >= 1) return 'active';
    if ($hours_since_signup < 24) return 'pending';
    if ($hours_since_signup < 48) return 'warming';
    return 'dead';
}

function status_badge(string $status): string {
    $colors = [
        'engaged' => '#16a34a',
        'active' => '#2563eb',
        'pending' => '#f59e0b',
        'warming' => '#f97316',
        'dead' => '#dc2626',
    ];
    $labels = [
        'engaged' => 'Engaged',
        'active' => 'Active',
        'pending' => 'Pending (<24h)',
        'warming' => 'Warming (24-48h)',
        'dead' => 'Dead',
    ];
    $c = $colors[$status] ?? '#64748b';
    $l = $labels[$status] ?? $status;
    return "<span style='display:inline-block;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600;color:#fff;background:{$c}'>{$l}</span>";
}

// ============= PROCESS SUBSCRIBERS =============
$processed = [];
$stats = ['engaged' => 0, 'active' => 0, 'pending' => 0, 'warming' => 0, 'dead' => 0];

foreach ($subscribers as $sub) {
    $opens = get_subscriber_opens((int)$sub['id'], $ar_emails);
    $status = classify_subscriber($opens, (int)$sub['join_date'], (int)$sub['timestamp'], $total_ar_emails);
    $stats[$status] = ($stats[$status] ?? 0) + 1;

    $sub['opens'] = $opens;
    $sub['opens_count'] = count($opens);
    $sub['status'] = $status;
    $sub['gclid'] = extract_gclid($sub['referrer'] ?? '');
    $sub['source_label'] = get_source_label($sub['referrer'] ?? '');
    $sub['campaign_id'] = extract_campaign_id($sub['referrer'] ?? '');

    if ($filter_status !== 'all') {
        if ($filter_status === 'engaged' && !in_array($status, ['engaged', 'active'])) continue;
        if ($filter_status === 'dead' && $status !== 'dead') continue;
        if ($filter_status === 'pending' && !in_array($status, ['pending', 'warming'])) continue;
    }

    $processed[] = $sub;
}

$total_shown = count($processed);
$total_all = count($subscribers);
$total_ads = 0;
foreach ($subscribers as $sub) {
    if (strpos($sub['referrer'] ?? '', 'gclid') !== false) {
        $total_ads++;
    }
}

// ============= CSV EXPORT =============
if ($is_export) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="sendy-engagement-' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, ['Email', 'Name', 'Country', 'IP', 'Signed Up', 'Last Activity', 'Source', 'Campaign ID', 'GCLID', 'List', 'Opens Count', 'Status', 'Emails Opened']);

    foreach ($processed as $sub) {
        $opened_titles = array_column($sub['opens'], 'title');
        fputcsv($out, [
            $sub['email'],
            $sub['name'] ?: '',
            $sub['country'] ?: '',
            $sub['ip'] ?: '',
            date('Y-m-d H:i', (int)$sub['join_date']),
            date('Y-m-d H:i', (int)$sub['timestamp']),
            $sub['source_label'],
            $sub['campaign_id'],
            $sub['gclid'],
            $lists[(int)$sub['list']] ?? 'Unknown',
            $sub['opens_count'],
            $sub['status'],
            implode(' | ', $opened_titles),
        ]);
    }

    fclose($out);
    exit;
}

// ============= RENDER =============
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Sendy Dashboard</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; background: #f1f5f9; color: #1e293b; font-size: 14px; }

        .header { background: #1e293b; color: #fff; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .header h1 { font-size: 1.25rem; font-weight: 600; }
        .header-links { display: flex; gap: 1.5rem; }
        .header a { color: #94a3b8; text-decoration: none; font-size: .875rem; }
        .header a:hover { color: #fff; }

        .container { max-width: 1400px; margin: 0 auto; padding: 1.5rem; }

        .stats-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
        .stat-card { background: #fff; border-radius: 8px; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,.08); text-align: center; }
        .stat-card .number { font-size: 2rem; font-weight: 700; }
        .stat-card .label { font-size: .75rem; color: #64748b; text-transform: uppercase; letter-spacing: .05em; margin-top: .25rem; }
        .stat-engaged .number { color: #16a34a; }
        .stat-active .number { color: #2563eb; }
        .stat-pending .number { color: #f59e0b; }
        .stat-dead .number { color: #dc2626; }
        .stat-total .number { color: #1e293b; }
        .stat-ads .number { color: #2563eb; }

        .filters { background: #fff; border-radius: 8px; padding: 1.25rem; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 1.5rem; display: flex; gap: 1rem; flex-wrap: wrap; align-items: end; }
        .filter-group { display: flex; flex-direction: column; gap: .25rem; }
        .filter-group label { font-size: .75rem; font-weight: 600; color: #64748b; text-transform: uppercase; }
        .filter-group select, .filter-group input { padding: .5rem .75rem; border: 1px solid #e2e8f0; border-radius: 6px; font-size: .875rem; background: #fff; }
        .filter-group input { width: 200px; }
        .btn { padding: .5rem 1rem; background: #2563eb; color: #fff; border: none; border-radius: 6px; font-size: .875rem; cursor: pointer; font-weight: 600; }
        .btn:hover { background: #1d4ed8; }

        .table-wrap { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,.08); overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #f8fafc; padding: .75rem 1rem; text-align: left; font-size: .75rem; font-weight: 600; color: #64748b; text-transform: uppercase; letter-spacing: .05em; border-bottom: 2px solid #e2e8f0; white-space: nowrap; position: sticky; top: 0; cursor: pointer; user-select: none; }
        th:hover { color: #1e293b; background: #e2e8f0; }
        th .sort-arrow { font-size: 10px; margin-left: 4px; color: #94a3b8; }
        th.sorted .sort-arrow { color: #2563eb; }
        td { padding: .75rem 1rem; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        tr:hover td { background: #f8fafc; }

        .email-cell { font-weight: 500; max-width: 250px; word-break: break-all; }
        .source-cell { font-size: .8rem; }
        .source-ads { color: #2563eb; font-weight: 600; }
        .source-organic { color: #64748b; }
        .opens-bar { display: flex; gap: 3px; align-items: center; }
        .opens-dot { width: 14px; height: 14px; border-radius: 3px; display: inline-block; }
        .opens-dot.opened { background: #16a34a; }
        .opens-dot.not-opened { background: #e2e8f0; }
        .opens-dot.not-sent { background: #fff; border: 1px dashed #cbd5e1; }

        .tooltip { position: relative; cursor: help; }
        .tooltip:hover::after { content: attr(data-tip); position: absolute; bottom: 120%; left: 50%; transform: translateX(-50%); background: #1e293b; color: #fff; padding: 6px 10px; border-radius: 4px; font-size: 11px; white-space: nowrap; z-index: 10; pointer-events: none; }

        .gclid-badge { display: inline-block; padding: 1px 6px; background: #dbeafe; color: #1d4ed8; border-radius: 3px; font-size: 11px; font-weight: 600; }

        .empty-state { text-align: center; padding: 3rem; color: #64748b; }

        .legend { display: flex; gap: 1.5rem; margin-bottom: 1rem; font-size: .8rem; color: #64748b; flex-wrap: wrap; }
        .legend-item { display: flex; align-items: center; gap: .35rem; }

        .ip-cell { font-size: .75rem; color: #94a3b8; max-width: 120px; overflow: hidden; text-overflow: ellipsis; }

        @media (max-width: 768px) {
            .filters { flex-direction: column; }
            .filter-group input { width: 100%; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .header { flex-direction: column; gap: .5rem; }
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Sendy Dashboard</h1>
        <div class="header-links">
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>">Export CSV</a>
            <a href="?logout=1">Logout</a>
        </div>
    </div>

    <div class="container">
        <!-- Stats -->
        <div class="stats-row">
            <div class="stat-card stat-total">
                <div class="number"><?= $total_all ?></div>
                <div class="label">Total (<?= $filter_days ?>d)</div>
            </div>
            <div class="stat-card stat-engaged">
                <div class="number"><?= ($stats['engaged'] ?? 0) + ($stats['active'] ?? 0) ?></div>
                <div class="label">Engaged</div>
            </div>
            <div class="stat-card stat-pending">
                <div class="number"><?= ($stats['pending'] ?? 0) + ($stats['warming'] ?? 0) ?></div>
                <div class="label">Pending</div>
            </div>
            <div class="stat-card stat-dead">
                <div class="number"><?= $stats['dead'] ?? 0 ?></div>
                <div class="label">Dead</div>
            </div>
            <div class="stat-card stat-ads">
                <div class="number"><?= $total_ads ?></div>
                <div class="label">From Ads</div>
            </div>
        </div>

        <!-- Filters -->
        <form method="GET" class="filters">
            <div class="filter-group">
                <label>Time Range</label>
                <select name="days">
                    <?php foreach ([1, 3, 7, 14, 30, 60, 90, 180, 365] as $d): ?>
                    <option value="<?= $d ?>" <?= $filter_days == $d ? 'selected' : '' ?>><?= $d ?> days</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Source</label>
                <select name="source">
                    <option value="all" <?= $filter_source === 'all' ? 'selected' : '' ?>>All Sources</option>
                    <option value="ads" <?= $filter_source === 'ads' ? 'selected' : '' ?>>Google Ads Only</option>
                    <option value="organic" <?= $filter_source === 'organic' ? 'selected' : '' ?>>Organic Only</option>
                    <?php foreach ($custom_campaigns as $cid => $clabel): ?>
                    <option value="<?= htmlspecialchars((string)$cid) ?>" <?= $filter_source === (string)$cid ? 'selected' : '' ?>>Campaign: <?= htmlspecialchars($clabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>List</label>
                <select name="list">
                    <option value="all">All Lists</option>
                    <?php foreach ($lists as $lid => $lname): ?>
                    <option value="<?= $lid ?>" <?= $filter_list == $lid ? 'selected' : '' ?>><?= htmlspecialchars($lname) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label>Status</label>
                <select name="status">
                    <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>All</option>
                    <option value="engaged" <?= $filter_status === 'engaged' ? 'selected' : '' ?>>Engaged</option>
                    <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="dead" <?= $filter_status === 'dead' ? 'selected' : '' ?>>Dead</option>
                </select>
            </div>
            <div class="filter-group">
                <label>Search Email</label>
                <input type="text" name="email" value="<?= htmlspecialchars($search_email) ?>" placeholder="e.g. gmail.com">
            </div>
            <div class="filter-group">
                <label>&nbsp;</label>
                <button type="submit" class="btn">Filter</button>
            </div>
        </form>

        <!-- Legend -->
        <div class="legend">
            <div class="legend-item"><span class="opens-dot opened" style="width:12px;height:12px"></span> Opened</div>
            <div class="legend-item"><span class="opens-dot not-opened" style="width:12px;height:12px"></span> Sent, not opened</div>
            <div class="legend-item"><span class="opens-dot not-sent" style="width:12px;height:12px"></span> Not yet sent</div>
        </div>

        <!-- Table -->
        <div class="table-wrap">
            <?php if (empty($processed)): ?>
                <div class="empty-state">No subscribers found matching your filters.</div>
            <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th data-col="0" data-type="text">Email <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th data-col="1" data-type="text">Name <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th data-col="2" data-type="text">Country <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th data-col="3" data-type="text">IP <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th data-col="4" data-type="num">Signed Up <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th data-col="5" data-type="num">Last Activity <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th data-col="6" data-type="text">Source <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th data-col="7" data-type="text">List <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th data-col="8" data-type="num">Opens (<?= $total_ar_emails ?>) <span class="sort-arrow">&#9650;&#9660;</span></th>
                        <th data-col="9" data-type="num">Status <span class="sort-arrow">&#9650;&#9660;</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($processed as $sub):
                        $join_date = date('M j, g:ia', (int)$sub['join_date']);
                        $last_date = date('M j, g:ia', (int)$sub['timestamp']);
                        $list_name = $lists[(int)$sub['list']] ?? 'Unknown';
                        $opened_ids = array_column($sub['opens'], 'id');
                        $hours_since = (time() - (int)$sub['join_date']) / 3600;
                        $status_order = ['engaged'=>1,'active'=>2,'pending'=>3,'warming'=>4,'dead'=>5];
                    ?>
                    <tr>
                        <td class="email-cell" data-sort="<?= htmlspecialchars($sub['email']) ?>">
                            <?= htmlspecialchars($sub['email']) ?>
                            <?php if ($sub['gclid']): ?>
                                <br><span class="gclid-badge">gclid</span>
                            <?php endif; ?>
                        </td>
                        <td data-sort="<?= htmlspecialchars($sub['name'] ?: '') ?>"><?= htmlspecialchars($sub['name'] ?: '—') ?></td>
                        <td data-sort="<?= $sub['country'] ?: '' ?>"><?= $sub['country'] ?: '—' ?></td>
                        <td class="ip-cell" data-sort="<?= htmlspecialchars($sub['ip'] ?? '') ?>" title="<?= htmlspecialchars($sub['ip'] ?? '') ?>"><?= htmlspecialchars($sub['ip'] ?? '—') ?></td>
                        <td data-sort="<?= $sub['join_date'] ?>"><?= $join_date ?></td>
                        <td data-sort="<?= $sub['timestamp'] ?>">
                            <?= $last_date ?>
                            <?php if ((int)$sub['timestamp'] === (int)$sub['join_date']): ?>
                                <div style="font-size:11px;color:#dc2626">no activity</div>
                            <?php endif; ?>
                        </td>
                        <td class="source-cell" data-sort="<?= htmlspecialchars($sub['source_label']) ?>">
                            <span class="<?= strpos($sub['referrer'] ?? '', 'gclid') !== false ? 'source-ads' : 'source-organic' ?>">
                                <?= htmlspecialchars($sub['source_label']) ?>
                            </span>
                            <?php if ($sub['campaign_id']): ?>
                                <div style="font-size:11px;color:#94a3b8">Campaign: <?= $sub['campaign_id'] ?></div>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.8rem" data-sort="<?= htmlspecialchars($list_name) ?>"><?= htmlspecialchars($list_name) ?></td>
                        <td data-sort="<?= $sub['opens_count'] ?>">
                            <div class="opens-bar">
                                <?php
                                $email_index = 0;
                                foreach ($ar_emails as $ae_id => $ae):
                                    $email_index++;
                                    $was_opened = in_array($ae_id, $opened_ids);
                                    $likely_sent = ($hours_since >= ($email_index - 1) * 24);

                                    if ($was_opened) {
                                        $class = 'opened';
                                        $tip = htmlspecialchars($ae['title']) . ' — OPENED';
                                    } elseif ($likely_sent) {
                                        $class = 'not-opened';
                                        $tip = htmlspecialchars($ae['title']) . ' — not opened';
                                    } else {
                                        $class = 'not-sent';
                                        $tip = htmlspecialchars($ae['title']) . ' — not sent yet';
                                    }
                                ?>
                                    <span class="opens-dot <?= $class ?> tooltip" data-tip="<?= $tip ?>"></span>
                                <?php endforeach; ?>
                            </div>
                            <div style="font-size:11px;color:#94a3b8;margin-top:3px">
                                <?= $sub['opens_count'] ?> / <?= $total_ar_emails ?> opened
                            </div>
                        </td>
                        <td data-sort="<?= $status_order[$sub['status']] ?? 9 ?>"><?= status_badge($sub['status']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </div>

        <p style="text-align:center;color:#94a3b8;font-size:.8rem;margin-top:1.5rem">
            Showing <?= $total_shown ?> of <?= $total_all ?> subscribers | Read-only — no data modified |
            <?= date('M j, Y g:ia T') ?>
        </p>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var table = document.querySelector('table');
        if (!table) return;

        var headers = table.querySelectorAll('th[data-col]');
        var currentCol = -1;
        var ascending = true;

        headers.forEach(function(th) {
            th.addEventListener('click', function() {
                var col = parseInt(th.dataset.col);
                var type = th.dataset.type;

                if (currentCol === col) {
                    ascending = !ascending;
                } else {
                    currentCol = col;
                    ascending = true;
                }

                headers.forEach(function(h) {
                    h.classList.remove('sorted');
                    h.querySelector('.sort-arrow').textContent = '\u25B2\u25BC';
                });
                th.classList.add('sorted');
                th.querySelector('.sort-arrow').textContent = ascending ? '\u25B2' : '\u25BC';

                var tbody = table.querySelector('tbody');
                var rows = Array.from(tbody.querySelectorAll('tr'));

                rows.sort(function(a, b) {
                    var aVal = a.cells[col].dataset.sort || a.cells[col].textContent.trim();
                    var bVal = b.cells[col].dataset.sort || b.cells[col].textContent.trim();

                    if (type === 'num') {
                        var aNum = parseFloat(aVal) || 0;
                        var bNum = parseFloat(bVal) || 0;
                        return ascending ? aNum - bNum : bNum - aNum;
                    }

                    var cmp = aVal.localeCompare(bVal, undefined, {sensitivity: 'base'});
                    return ascending ? cmp : -cmp;
                });

                rows.forEach(function(row) { tbody.appendChild(row); });
            });
        });
    });
    </script>
</body>
</html>
