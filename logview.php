<?php
/**
 * Enhanced Access Log Viewer for Enhance Panel
 *
 * - Drop this into a site's public_html directory.
 * - Expects logs in ../access-logs/YYYY-MM-DD.log
 *
 * Features:
 *  - Multi-file view or single-file view
 *  - Time ranges: hours & days (1h,4h,6h,12h,24h,1d,3d,7d,30d)
 *  - Search, error-only filter
 *  - Human-readable local timestamps
 *  - Column sorting
 *  - Pagination
 *  - Live tail (auto-refresh)
 *  - IP lookup links
 *  - Download raw logs
 *  - Charts: top IPs, top paths, status classes
 *  - Toggle to show raw log line
 *  - Download filtered results as CSV
 */

//////////////////////////////////////
// CONFIG
//////////////////////////////////////

$LOG_DIR       = realpath(__DIR__ . '/../access-logs');
define('DEFAULT_RANGE', '3d');  // Default time range: last 3 days
define('MAX_ROWS', 5000);       // Safety cap on total rows processed
define('PAGE_SIZE', 200);       // Rows per page

//////////////////////////////////////
// VERIFY LOG DIRECTORY
//////////////////////////////////////

if ($LOG_DIR === false || !is_dir($LOG_DIR)) {
    http_response_code(500);
    echo "Log directory not found or not accessible: " . htmlspecialchars($LOG_DIR);
    exit;
}

//////////////////////////////////////
// HELPERS: Timestamp, query builder, range
//////////////////////////////////////

/**
 * Convert raw numeric-ish timestamp to epoch seconds
 * Supports 10-digit seconds and 13-digit milliseconds
 */
function ts_to_epoch($raw) {
    $raw = trim((string)$raw);
    if (!ctype_digit($raw)) {
        return null;
    }

    if (strlen($raw) === 10) {
        return (int)$raw; // seconds
    } elseif (strlen($raw) === 13) {
        return (int)floor(((int)$raw) / 1000); // ms → s
    }

    return null;
}

/**
 * Convert raw timestamp to human-readable local time string
 */
function convert_ts($raw) {
    $epoch = ts_to_epoch($raw);
    if ($epoch === null) {
        return trim((string)$raw);
    }
    return date("Y-m-d H:i:s", $epoch); // local TZ
}

/**
 * Build query string preserving existing filters, overriding with $overrides
 */
function build_query(array $overrides = []) {
    $params = [
        'file'        => $_GET['file']        ?? null,
        'q'           => $_GET['q']           ?? null,
        'range'       => $_GET['range']       ?? null,
        'errors_only' => isset($_GET['errors_only']) ? '1' : null,
        'tail'        => isset($_GET['tail'])        ? '1' : null,
        'show_raw'    => isset($_GET['show_raw'])    ? '1' : null,
        'sort'        => $_GET['sort']        ?? null,
        'dir'         => $_GET['dir']         ?? null,
        'page'        => $_GET['page']        ?? null,
    ];

    foreach ($overrides as $k => $v) {
        $params[$k] = $v;
    }

    // Remove nulls / empty
    $params = array_filter($params, fn($v) => $v !== null && $v !== '');

    return http_build_query($params);
}

/**
 * Return cutoff epoch for a given range string.
 * Accepts formats like "4h", "12h", "1d", "3d", etc.
 */
function cutoff_epoch_for_range($range) {
    $now = time();

    if (preg_match('/^(\d+)(h)$/', $range, $m)) {
        $hours = (int)$m[1];
        if ($hours <= 0) $hours = 3;
        return $now - ($hours * 3600);
    }

    if (preg_match('/^(\d+)(d)$/', $range, $m)) {
        $days = (int)$m[1];
        if ($days <= 0) $days = 3;
        return $now - ($days * 86400);
    }

    // fallback: last 3 days
    return $now - (3 * 86400);
}

/**
 * Human-readable label for the time range
 */
function range_label($range) {
    switch ($range) {
        case '1h':  return 'Last 1 hour';
        case '4h':  return 'Last 4 hours';
        case '6h':  return 'Last 6 hours';
        case '12h': return 'Last 12 hours';
        case '24h': return 'Last 24 hours';
        case '1d':  return 'Last 1 day';
        case '3d':  return 'Last 3 days';
        case '7d':  return 'Last 7 days';
        case '30d': return 'Last 30 days';
        default:    return 'Last 3 days';
    }
}

//////////////////////////////////////
// DISCOVER LOG FILES
//////////////////////////////////////

$allFiles = [];
foreach (glob($LOG_DIR . '/*.log') as $path) {
    $fname = basename($path); // e.g. 2025-12-04.log
    $allFiles[$fname] = $path;
}
// newest first by filename (YYYY-MM-DD.log)
krsort($allFiles);

//////////////////////////////////////
// REQUEST INPUTS
//////////////////////////////////////

$selectedFile = isset($_GET['file']) ? basename($_GET['file']) : '';
$search       = isset($_GET['q']) ? trim($_GET['q']) : '';
$range        = $_GET['range'] ?? DEFAULT_RANGE;
$errorsOnly   = isset($_GET['errors_only']);
$tail         = isset($_GET['tail']);      // live tail
$showRaw      = isset($_GET['show_raw']);  // show raw line column
$sort         = $_GET['sort'] ?? 'time';
$dir          = strtolower($_GET['dir'] ?? 'desc'); // asc|desc
$page         = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$action       = $_GET['action'] ?? '';

if (!in_array($dir, ['asc', 'desc'], true)) {
    $dir = 'desc';
}
if ($page < 1) {
    $page = 1;
}

// Ensure selected file exists
if ($selectedFile && !isset($allFiles[$selectedFile])) {
    $selectedFile = '';
}

//////////////////////////////////////
// HANDLE RAW FILE DOWNLOAD ACTION
//////////////////////////////////////

if ($action === 'download') {
    $fileParam = isset($_GET['file']) ? basename($_GET['file']) : '';
    if ($fileParam && isset($allFiles[$fileParam])) {
        $path = $allFiles[$fileParam];
        header('Content-Type: text/plain');
        header('Content-Disposition: attachment; filename="' . $fileParam . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    } else {
        http_response_code(404);
        echo "Log file not found.";
        exit;
    }
}

//////////////////////////////////////
// SELECT FILES TO READ
//////////////////////////////////////

$filesToRead = [];

if ($selectedFile) {
    // Single-file view: show full file, ignore range
    $filesToRead[$selectedFile] = $allFiles[$selectedFile];
    $applyTimeFilter = false;
    $cutoffEpoch = null;
} else {
    // Multi-file view: use time range for filtering rows
    // For simplicity, read all available logs (typ. <= 30 files) and filter by timestamp
    $filesToRead = $allFiles;
    $applyTimeFilter = true;
    $cutoffEpoch = cutoff_epoch_for_range($range);
}

//////////////////////////////////////
// READ & FILTER LINES, BUILD STATS
//////////////////////////////////////

$rows       = [];
$truncated  = false;
$ipCounts   = [];
$pathCounts = [];
$statusBuckets = [
    '2xx' => 0,
    '3xx' => 0,
    '4xx' => 0,
    '5xx' => 0,
];

foreach ($filesToRead as $fname => $path) {
    $handle = @fopen($path, 'r');
    if (!$handle) {
        continue;
    }

    while (($line = fgets($handle)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }

        // Extract quoted fields
        if (!preg_match_all('/"([^"]*)"/', $line, $m)) {
            continue;
        }
        $fields = $m[1];
        $fields = array_pad($fields, 8, '');

        list($ip, $ts, $request, $status, $extra, $bytes, $referer, $ua) = $fields;

        $statusInt = (int)$status;

        // Time-range filter (only in multi-file mode)
        if ($applyTimeFilter) {
            $epoch = ts_to_epoch($ts);
            if ($epoch !== null && $epoch < $cutoffEpoch) {
                continue;
            }
        }

        // Errors-only filter (4xx, 5xx)
        if ($errorsOnly && $statusInt < 400) {
            continue;
        }

        // Search filter (simple case-insensitive match on the raw line)
        if ($search !== '' && stripos($line, $search) === false) {
            continue;
        }

        // Derive clean path from request, e.g. "GET /foo/bar.php HTTP/2"
        $pathOnly = '';
        if (preg_match('/^\s*\S+\s+(\S+)/', $request, $rm)) {
            $pathOnly = $rm[1];
        }

        // Build row
        $row = [
            'file'     => $fname,
            'ip'       => $ip,
            'ts_raw'   => $ts,
            'ts'       => convert_ts($ts),
            'request'  => $request,
            'path'     => $pathOnly,
            'status'   => $statusInt,
            'bytes'    => $bytes,
            'referer'  => $referer,
            'ua'       => $ua,
            'raw'      => $line,
        ];
        $rows[] = $row;

        // Stats: IP counts
        if ($ip !== '') {
            if (!isset($ipCounts[$ip])) {
                $ipCounts[$ip] = 0;
            }
            $ipCounts[$ip]++;
        }

        // Stats: Path counts
        if ($pathOnly !== '') {
            if (!isset($pathCounts[$pathOnly])) {
                $pathCounts[$pathOnly] = 0;
            }
            $pathCounts[$pathOnly]++;
        }

        // Stats: status buckets
        if ($statusInt >= 200 && $statusInt <= 299) {
            $statusBuckets['2xx']++;
        } elseif ($statusInt >= 300 && $statusInt <= 399) {
            $statusBuckets['3xx']++;
        } elseif ($statusInt >= 400 && $statusInt <= 499) {
            $statusBuckets['4xx']++;
        } elseif ($statusInt >= 500 && $statusInt <= 599) {
            $statusBuckets['5xx']++;
        }

        if (count($rows) >= MAX_ROWS) {
            $truncated = true;
            break 2; // stop reading any more files
        }
    }

    fclose($handle);
}

//////////////////////////////////////
// HANDLE FILTERED CSV DOWNLOAD
//////////////////////////////////////

if ($action === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="access-logs-filtered.csv"');

    $out = fopen('php://output', 'w');

    // CSV header
    fputcsv($out, [
        'file','ip','timestamp','timestamp_raw','request','path',
        'status','bytes','referer','user_agent','raw_line'
    ]);

    foreach ($rows as $r) {
        fputcsv($out, [
            $r['file'],
            $r['ip'],
            $r['ts'],
            $r['ts_raw'],
            $r['request'],
            $r['path'],
            $r['status'],
            $r['bytes'],
            $r['referer'],
            $r['ua'],
            $r['raw'],
        ]);
    }

    fclose($out);
    exit;
}

//////////////////////////////////////
// SORTING
//////////////////////////////////////

$sortField = $sort;
$sortDir   = $dir; // asc|desc

usort($rows, function ($a, $b) use ($sortField, $sortDir) {
    $mult = ($sortDir === 'asc') ? 1 : -1;

    switch ($sortField) {
        case 'ip':
            return $mult * strcmp($a['ip'], $b['ip']);

        case 'status':
            return $mult * ($a['status'] <=> $b['status']);

        case 'bytes':
            $ba = (int)$a['bytes'];
            $bb = (int)$b['bytes'];
            return $mult * ($ba <=> $bb);

        case 'file':
            return $mult * strcmp($a['file'], $b['file']);

        case 'time':
        default:
            $ea = ts_to_epoch($a['ts_raw']) ?? 0;
            $eb = ts_to_epoch($b['ts_raw']) ?? 0;
            return $mult * ($ea <=> $eb);
    }
});

//////////////////////////////////////
// PAGINATION
//////////////////////////////////////

$totalRows   = count($rows);
$totalPages  = max(1, (int)ceil($totalRows / PAGE_SIZE));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset      = ($page - 1) * PAGE_SIZE;
$rowsPage    = array_slice($rows, $offset, PAGE_SIZE);

//////////////////////////////////////
// CHART DATA (TOP IPs, TOP PATHs)
//////////////////////////////////////

arsort($ipCounts);
$topIps = array_slice($ipCounts, 0, 10, true);

arsort($pathCounts);
$topPaths = array_slice($pathCounts, 0, 10, true);

// Encode for JS
$chartIpLabels   = json_encode(array_keys($topIps), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$chartIpValues   = json_encode(array_values($topIps), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$chartPathLabels = json_encode(array_keys($topPaths), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$chartPathValues = json_encode(array_values($topPaths), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

$chartStatusLabels = json_encode(array_keys($statusBuckets), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
$chartStatusValues = json_encode(array_values($statusBuckets), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Access Log Viewer</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if ($tail): ?>
        <!-- Live tail: auto-refresh every 5 seconds -->
        <meta http-equiv="refresh" content="5">
    <?php endif; ?>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #0f172a;
            color: #f3f4f6;
            margin: 0;
        }
        header {
            background: #020617;
            padding: 12px 20px;
            border-bottom: 1px solid #1f2937;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        h1 { margin: 0; font-size: 18px; }

        .container { padding: 16px 20px 30px; }
        .flex { display:flex; gap:20px; align-items:flex-start; }

        .sidebar {
            width: 260px;
            background:#111827;
            border:1px solid #1f2937;
            border-radius:8px;
            padding:10px;
            max-height:80vh;
            overflow-y:auto;
        }
        .sidebar h2 { font-size:14px; margin-top:0; }
        .file-list { list-style:none; padding:0; margin:0; font-size:13px; }
        .file-list li { margin-bottom:4px; }
        .file-list a { color:#60a5fa; text-decoration:none; }
        .file-list a.active { color:#93c5fd; font-weight:bold; }
        .file-list a.small-link { font-size:11px; }

        .small { font-size:11px; color:#cbd5e1; }

        .filters {
            margin-bottom:12px;
            padding:10px;
            background:#020617;
            border-radius:8px;
            border:1px solid #1f2937;
            font-size:13px;
        }
        .filters input[type="text"],
        .filters select {
            background:#020617;
            color:#f3f4f6;
            border:1px solid #4b5563;
            border-radius:4px;
            padding:4px 6px;
            margin-right:6px;
        }
        .filters input[type="checkbox"] {
            vertical-align:middle;
            margin-left:4px;
            margin-right:2px;
        }
        .filters button {
            padding:4px 10px;
            border-radius:4px;
            border:1px solid #4b5563;
            background:#1d4ed8;
            color:#f3f4f6;
            cursor:pointer;
            font-size:13px;
        }
        .filters button:hover { background:#2563eb; }
        .filters .csv-button {
            background:#059669;
            margin-left:10px;
        }
        .filters a.reset-link {
            color:#60a5fa;
            margin-left:8px;
            text-decoration:none;
        }

        table {
            width:100%;
            border-collapse:collapse;
            font-size:12px;
        }
        th, td {
            border-bottom:1px solid #1f2937;
            padding:4px 6px;
            vertical-align:top;
        }
        th {
            background:#020617;
            position:sticky;
            top:0;
            z-index:10;
        }
        th a {
            color:#f3f4f6;
            text-decoration:none;
        }
        th a span.sort-indicator {
            font-size:10px;
            opacity:0.8;
            margin-left:2px;
        }

        tr.error-row {
            background:#7f1d1d !important;
            color:#f3f4f6 !important;
        }
        table tr:nth-child(even):not(.error-row) {
            background:#111827;
        }
        table tr:nth-child(odd):not(.error-row) {
            background:#1e293b;
        }

        .status-ok { color:#22c55e; font-weight:bold; }
        .status-redirect { color:#eab308; font-weight:bold; }
        .status-error { color:#f97316; font-weight:bold; }

        .pagination {
            margin:8px 0;
            font-size:12px;
        }
        .pagination a {
            color:#60a5fa;
            text-decoration:none;
            margin-right:6px;
        }
        .pagination .current {
            font-weight:bold;
            color:#f3f4f6;
        }

        .charts {
            margin-top:16px;
            display:grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap:16px;
        }
        .chart-card {
            background:#020617;
            border-radius:8px;
            border:1px solid #1f2937;
            padding:10px;
        }
        .chart-card h3 {
            font-size:13px;
            margin:0 0 6px 0;
        }

        code {
            font-family: ui-monospace, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;
            color:#d1d5db;
        }
        td.small {
            color:#d1d5db;
        }

        a {
            color:#60a5fa;
        }

        @media (max-width: 900px) {
            .flex { flex-direction:column; }
            .sidebar { width:100%; max-height:200px; }
        }
    </style>
</head>
<body>

<header>
    <h1>Access Log Viewer</h1>
    <div class="small">
        Log dir: <code><?= htmlspecialchars($LOG_DIR) ?></code><br>
        <?php if ($tail): ?>
            Live tail: <span style="color:#22c55e;">ON (5s refresh)</span>
        <?php else: ?>
            Live tail: <span style="color:#6b7280;">OFF</span>
        <?php endif; ?>
    </div>
</header>

<div class="container">
    <div class="flex">

        <!-- SIDEBAR -->
        <aside class="sidebar">
            <h2>Log Files</h2>
            <ul class="file-list">
                <?php foreach ($allFiles as $fname => $path): ?>
                    <li>
                        <a href="?<?= htmlspecialchars(build_query(['file' => $fname, 'page' => 1])) ?>"
                           class="<?= $selectedFile === $fname ? 'active' : '' ?>">
                            <?= htmlspecialchars($fname) ?>
                        </a>
                        <a href="?<?= htmlspecialchars(build_query(['action' => 'download', 'file' => $fname])) ?>"
                           class="small small-link">[dl]</a>
                    </li>
                <?php endforeach; ?>
            </ul>
        </aside>

        <!-- MAIN AREA -->
        <main style="flex:1; min-width:0;">

            <!-- FILTERS -->
            <form method="get" class="filters">
                <?php if ($selectedFile): ?>
                    <input type="hidden" name="file" value="<?= htmlspecialchars($selectedFile) ?>">
                <?php endif; ?>

                <label>
                    Search:
                    <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="IP, path, UA…">
                </label>

                <?php if (!$selectedFile): ?>
                    <label>
                        Time Range:
                        <?php $currentRange = $range ?? DEFAULT_RANGE; ?>
                        <select name="range">
                            <option value="1h"  <?= $currentRange === '1h'  ? 'selected' : '' ?>>Last 1 hour</option>
                            <option value="4h"  <?= $currentRange === '4h'  ? 'selected' : '' ?>>Last 4 hours</option>
                            <option value="6h"  <?= $currentRange === '6h'  ? 'selected' : '' ?>>Last 6 hours</option>
                            <option value="12h" <?= $currentRange === '12h' ? 'selected' : '' ?>>Last 12 hours</option>
                            <option value="24h" <?= $currentRange === '24h' ? 'selected' : '' ?>>Last 24 hours</option>

                            <option value="1d"  <?= $currentRange === '1d'  ? 'selected' : '' ?>>Last 1 day</option>
                            <option value="3d"  <?= $currentRange === '3d'  ? 'selected' : '' ?>>Last 3 days</option>
                            <option value="7d"  <?= $currentRange === '7d'  ? 'selected' : '' ?>>Last 7 days</option>
                            <option value="30d" <?= $currentRange === '30d' ? 'selected' : '' ?>>Last 30 days</option>
                        </select>
                    </label>
                <?php endif; ?>

                <label>
                    <input type="checkbox" name="errors_only" value="1" <?= $errorsOnly ? 'checked' : '' ?>>
                    Errors only (4xx/5xx)
                </label>

                <label>
                    <input type="checkbox" name="tail" value="1" <?= $tail ? 'checked' : '' ?>>
                    Live tail
                </label>

                <label>
                    <input type="checkbox" name="show_raw" value="1" <?= $showRaw ? 'checked' : '' ?>>
                    Show raw line
                </label>

                <button type="submit">Apply</button>
                <button type="submit" name="action" value="csv" class="csv-button">Download CSV</button>
                <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="reset-link">Reset</a>
            </form>

            <!-- RESULTS INFO -->
            <div class="small" style="margin-bottom:6px;">
                Showing <strong><?= count($rowsPage) ?></strong> of <strong><?= $totalRows ?></strong> matching row(s)
                from <?= htmlspecialchars(implode(', ', array_keys($filesToRead))) ?>
                <?php if (!$selectedFile): ?>
                    (<?= htmlspecialchars(range_label($range)) ?>)
                <?php endif; ?>
                <?php if ($truncated): ?>
                    <br><span style="color:#f97316;">Results truncated at <?= MAX_ROWS ?> rows. Narrow your filters for more precise output.</span>
                <?php endif; ?>
            </div>

            <!-- PAGINATION TOP -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    Page:
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <?php if ($p === $page): ?>
                            <span class="current"><?= $p ?></span>
                        <?php else: ?>
                            <a href="?<?= htmlspecialchars(build_query(['page' => $p])) ?>"><?= $p ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

            <!-- TABLE -->
            <div style="overflow-x:auto; max-height:60vh; border:1px solid #1f2937; border-radius:8px;">
                <table>
                    <thead>
                        <tr>
                            <?php
                            // Helper for sortable headers
                            function sort_header($label, $field, $currentSort, $currentDir) {
                                $dir = 'desc';
                                $indicator = '';
                                if ($currentSort === $field) {
                                    if ($currentDir === 'desc') {
                                        $dir = 'asc';
                                        $indicator = '▼';
                                    } else {
                                        $dir = 'desc';
                                        $indicator = '▲';
                                    }
                                }
                                $query = build_query(['sort' => $field, 'dir' => $dir, 'page' => 1]);
                                echo '<th><a href="?' . htmlspecialchars($query) . '">' . htmlspecialchars($label);
                                if ($currentSort === $field) {
                                    echo ' <span class="sort-indicator">' . $indicator . '</span>';
                                }
                                echo '</a></th>';
                            }
                            sort_header('File', 'file', $sort, $dir);
                            sort_header('IP', 'ip', $sort, $dir);
                            sort_header('Time', 'time', $sort, $dir);
                            ?>
                            <th>Request</th>
                            <?php
                            sort_header('Status', 'status', $sort, $dir);
                            sort_header('Bytes', 'bytes', $sort, $dir);
                            ?>
                            <th>Referer</th>
                            <th>User Agent</th>
                            <?php if ($showRaw): ?>
                                <th>Raw</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($rowsPage)): ?>
                        <tr><td colspan="<?= $showRaw ? 9 : 8 ?>" style="text-align:center; padding:10px;">No matching entries.</td></tr>
                    <?php else: ?>
                        <?php foreach ($rowsPage as $r): ?>
                            <?php
                            if ($r['status'] >= 400) {
                                $rowClass = 'error-row';
                                $statusClass = 'status-error';
                            } elseif ($r['status'] >= 300) {
                                $rowClass = '';
                                $statusClass = 'status-redirect';
                            } else {
                                $rowClass = '';
                                $statusClass = 'status-ok';
                            }
                            ?>
                            <tr class="<?= $rowClass ?>">
                                <td class="small"><?= htmlspecialchars($r['file']) ?></td>
                                <td>
                                    <?php if ($r['ip'] !== ''): ?>
                                        <a href="https://ipinfo.io/<?= urlencode($r['ip']) ?>"
                                           target="_blank" rel="noopener noreferrer">
                                            <code><?= htmlspecialchars($r['ip']) ?></code>
                                        </a>
                                    <?php else: ?>
                                        <code><?= htmlspecialchars($r['ip']) ?></code>
                                    <?php endif; ?>
                                </td>
                                <td class="small">
                                    <code title="Raw: <?= htmlspecialchars($r['ts_raw']) ?>">
                                        <?= htmlspecialchars($r['ts']) ?>
                                    </code>
                                </td>
                                <td><code><?= htmlspecialchars($r['request']) ?></code></td>
                                <td class="<?= $statusClass ?>"><?= htmlspecialchars($r['status']) ?></td>
                                <td class="small"><?= htmlspecialchars($r['bytes']) ?></td>
                                <td class="small"><code><?= htmlspecialchars($r['referer']) ?></code></td>
                                <td class="small"><code><?= htmlspecialchars($r['ua']) ?></code></td>
                                <?php if ($showRaw): ?>
                                    <td class="small"><code><?= htmlspecialchars($r['raw']) ?></code></td>
                                <?php endif; ?>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- PAGINATION BOTTOM -->
            <?php if ($totalPages > 1): ?>
                <div class="pagination">
                    Page:
                    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                        <?php if ($p === $page): ?>
                            <span class="current"><?= $p ?></span>
                        <?php else: ?>
                            <a href="?<?= htmlspecialchars(build_query(['page' => $p])) ?>"><?= $p ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>

            <!-- CHARTS -->
            <div class="charts">
                <div class="chart-card">
                    <h3>Top IPs (by hits)</h3>
                    <canvas id="chartIps"></canvas>
                </div>
                <div class="chart-card">
                    <h3>Top Paths (by hits)</h3>
                    <canvas id="chartPaths"></canvas>
                </div>
                <div class="chart-card">
                    <h3>Status Classes</h3>
                    <canvas id="chartStatus"></canvas>
                </div>
            </div>

        </main>
    </div>
</div>

<script>
    // Chart data from PHP
    const ipLabels   = <?= $chartIpLabels ?>;
    const ipValues   = <?= $chartIpValues ?>;
    const pathLabels = <?= $chartPathLabels ?>;
    const pathValues = <?= $chartPathValues ?>;
    const statusLabels = <?= $chartStatusLabels ?>;
    const statusValues = <?= $chartStatusValues ?>;

    function makeBarChart(ctxId, labels, data, title) {
        const ctx = document.getElementById(ctxId);
        if (!ctx || labels.length === 0) return;
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: title,
                    data: data
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { ticks: { autoSkip: false, maxRotation: 60, minRotation: 30 } },
                    y: { beginAtZero: true, precision: 0 }
                }
            }
        });
    }

    function makePieChart(ctxId, labels, data, title) {
        const ctx = document.getElementById(ctxId);
        if (!ctx || data.reduce((a,b)=>a+b,0) === 0) return;
        new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        makeBarChart('chartIps', ipLabels, ipValues, 'Hits');
        makeBarChart('chartPaths', pathLabels, pathValues, 'Hits');
        makePieChart('chartStatus', statusLabels, statusValues, 'Status');
    });
</script>

</body>
</html>
