<?php
// includes/attention_helper.php
// Unified "needs attention" feed used by dashboard + notification bell.

function getAttentionList(PDO $pdo): array {
    $items = [];

    // Alert thresholds
    try {
        $cfg = $pdo->query("SELECT setting_key, setting_value FROM system_settings WHERE setting_key LIKE '%_alert_days'")->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) { $cfg = []; }
    $leaseDays    = (int)($cfg['lease_alert_days']    ?? 30);
    $warrantyDays = (int)($cfg['warranty_alert_days'] ?? 30);
    $swDays       = (int)($cfg['software_alert_days'] ?? 30);

    // 1) Lease (rent) expiring
    $rows = $pdo->prepare("
        SELECT a.asset_id, a.hostname, a.serial_number, a.category, a.lease_start, a.lease_expiry, u.full_name, a.status
        FROM assets a LEFT JOIN users u ON a.user_id=u.user_id
        WHERE a.ownership_type='Rent' AND a.lease_expiry IS NOT NULL
          AND a.lease_expiry <= date('now', :p)
          AND a.status NOT IN ('retired','lost')
        ORDER BY a.lease_expiry ASC
    ");
    $rows->execute(['p' => "+{$leaseDays} days"]);
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = _attRow(
            'lease', 'bi-calendar-event', '#dc2626', '#fef2f2',
            $r['hostname'] ?: $r['serial_number'] ?: '—',
            ($r['category'] ?? '—') . ' · Lease' . ($r['full_name'] ? ' · ' . $r['full_name'] : ''),
            $r['lease_expiry'],
            'history.php?asset_id=' . (int)$r['asset_id'],
            [
                'item_id' => $r['asset_id'],
                'lease_start' => $r['lease_start'] ?? '',
                'lease_expiry' => $r['lease_expiry'],
                'status' => $r['status']
            ]
        );
    }

    // 2) Warranty (buy) expiring
    $rows = $pdo->prepare("
        SELECT a.asset_id, a.hostname, a.serial_number, a.category, a.end_of_life_date, u.full_name, a.status
        FROM assets a LEFT JOIN users u ON a.user_id=u.user_id
        WHERE a.ownership_type='Buy' AND a.end_of_life_date IS NOT NULL
          AND a.end_of_life_date <= date('now', :p)
          AND a.status NOT IN ('retired','lost')
        ORDER BY a.end_of_life_date ASC
    ");
    $rows->execute(['p' => "+{$warrantyDays} days"]);
    foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $items[] = _attRow(
            'warranty', 'bi-shield-check', '#d97706', '#fffbeb',
            $r['hostname'] ?: $r['serial_number'] ?: '—',
            ($r['category'] ?? '—') . ' · Warranty' . ($r['full_name'] ? ' · ' . $r['full_name'] : ''),
            $r['end_of_life_date'],
            'history.php?asset_id=' . (int)$r['asset_id'],
            [
                'item_id' => $r['asset_id'],
                'end_of_life_date' => $r['end_of_life_date'],
                'status' => $r['status']
            ]
        );
    }

    // 3) Software license expiring
    try {
        $rows = $pdo->prepare("
            SELECT s.license_id, s.batch_id, s.expiry_date, sw.name AS sw_name, sw.category, u.full_name
            FROM software_licenses s
            JOIN software_batches sb ON s.batch_id    = sb.batch_id
            JOIN software         sw ON sb.software_id = sw.software_id
            LEFT JOIN users       u  ON s.user_id      = u.user_id
            WHERE s.status NOT IN ('revoked','expired')
              AND s.expiry_date IS NOT NULL
              AND s.expiry_date <= date('now', :p)
            ORDER BY s.expiry_date ASC
        ");
        $rows->execute(['p' => "+{$swDays} days"]);
        foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $items[] = _attRow(
                'software', 'bi-key', '#c026d3', '#fdf4ff',
                $r['sw_name'] ?: '—',
                ($r['category'] ?? '—') . ' · License' . ($r['full_name'] ? ' · ' . $r['full_name'] : ''),
                $r['expiry_date'],
                'software.php',
                [
                    'item_id' => $r['license_id'],
                    'batch_id' => $r['batch_id']
                ]
            );
        }
    } catch (Exception $e) { /* tables not ready */ }

    // 4) Borrow overdue
    try {
        $rows = $pdo->query("
            SELECT a.asset_id, a.hostname, a.serial_number, a.category, a.borrow_due_date, u.full_name
            FROM assets a LEFT JOIN users u ON a.user_id=u.user_id
            WHERE a.status='deployed' AND a.borrow_due_date IS NOT NULL
              AND a.borrow_due_date < date('now')
            ORDER BY a.borrow_due_date ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $items[] = _attRow(
                'borrow', 'bi-arrow-return-left', '#dc2626', '#fef2f2',
                $r['hostname'] ?: $r['serial_number'] ?: '—',
                ($r['category'] ?? '—') . ' · ' . __('Overdue Return') . ($r['full_name'] ? ' · ' . $r['full_name'] : ''),
                $r['borrow_due_date'],
                'history.php?asset_id=' . (int)$r['asset_id'],
                [
                    'item_id' => $r['asset_id']
                ]
            );
        }
    } catch (Exception $e) { /* column not migrated */ }

    // 5) Repair overdue
    try {
        $rows = $pdo->query("
            SELECT asset_id, hostname, serial_number, category, repair_eta, repair_shop
            FROM assets
            WHERE status='repair' AND repair_eta IS NOT NULL AND repair_eta < date('now')
            ORDER BY repair_eta ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $items[] = _attRow(
                'repair', 'bi-wrench', '#d97706', '#fffbeb',
                $r['hostname'] ?: $r['serial_number'] ?: '—',
                ($r['category'] ?? '—') . ' · ' . __('Overdue ETA') . ($r['repair_shop'] ? ' · ' . $r['repair_shop'] : ''),
                $r['repair_eta'],
                'history.php?asset_id=' . (int)$r['asset_id'],
                [
                    'item_id' => $r['asset_id']
                ]
            );
        }
    } catch (Exception $e) { /* column not migrated */ }

    // 6) Consumable low stock
    try {
        $lowQty = (int)($cfg['consumable_alert_qty'] ?? 5);
        $stmt = $pdo->prepare("
            SELECT item_name,
                   SUM(CASE WHEN action='in' THEN quantity WHEN action='out' THEN -quantity ELSE 0 END) AS balance
            FROM consumable_logs
            GROUP BY item_name
            HAVING balance <= CAST(:threshold AS INTEGER)
            ORDER BY balance ASC
        ");
        $stmt->execute(['threshold' => $lowQty]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $bal = (int)$r['balance'];
            $items[] = [
                'type'  => 'consumable',
                'icon'  => 'bi-box-seam',
                'color' => $bal <= 0 ? '#dc2626' : '#d97706',
                'bg'    => $bal <= 0 ? '#fef2f2' : '#fffbeb',
                'title' => $r['item_name'],
                'sub'   => $bal <= 0 
                            ? __('Out of Stock') 
                            : sprintf(__('Remaining %d item(s) — Below threshold %d'), $bal, $lowQty),
                'date'  => date('Y-m-d'), // today (used for sort, no real expiry)
                'days'  => $bal <= 0 ? -999 : 999, // out-of-stock sorts first, low-stock sorts last
                'url'   => 'consumables.php',
                'extra' => ['item_id' => 0, 'balance' => $bal],
            ];
        }
    } catch (Exception $e) { /* silent */ }

    // Sort: most overdue first → soonest expiring
    usort($items, fn($a, $b) => $a['days'] <=> $b['days']);
    return $items;
}

function _attRow($type, $icon, $color, $bg, $title, $sub, $date, $url, $extra = []) {
    $days = (int)floor((strtotime($date) - strtotime('today')) / 86400);
    return [
        'type'  => $type,
        'icon'  => $icon,
        'color' => $color,
        'bg'    => $bg,
        'title' => $title,
        'sub'   => $sub,
        'date'  => $date,
        'days'  => $days,
        'url'   => $url,
        'extra' => $extra,
    ];
}

// Format days-left helper → returns [cssClass, displayText]
function attDaysLabel(int $days): array {
    if ($days === 999)   return ['later', __('เหลือน้อย')];
    if ($days === -999)  return ['overdue', __('หมดสต็อก')];
    if ($days < 0)       return ['overdue', __('เลย') . ' ' . abs($days) . ' ' . __('วัน')];
    if ($days === 0)     return ['overdue', __('วันนี้')];
    if ($days <= 7)      return ['soon',    $days . ' ' . __('วัน')];
    return ['later', $days . ' ' . __('วัน')];
}
