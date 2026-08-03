<?php
// includes/column_helper.php
// Provides functions for dynamic column management in the Assets table.

/**
 * Returns all column definitions ordered by col_order.
 * Each row: col_key, col_label, col_visible, col_order, is_system, col_type, is_advanced
 */
function getColumnSettings(PDO $pdo): array {
    return $pdo->query("SELECT * FROM column_settings ORDER BY col_order ASC, col_key ASC")
               ->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Returns only visible columns, ordered.
 */
function getVisibleColumns(PDO $pdo): array {
    return $pdo->query("SELECT * FROM column_settings WHERE col_visible=1 OR is_advanced=1 ORDER BY col_order ASC, col_key ASC")
               ->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Seed the column_settings table with defaults if empty.
 * Call this from init_db.php after creating the table.
 */
function seedColumnSettings(PDO $pdo): void {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM column_settings")->fetchColumn();
    if ($count > 0) return; // Already seeded

    $defaults = [
        // [col_key, col_label, col_visible, col_order, is_system, col_type, is_advanced]
        ['category',              'Category',       1, 10, 1, 'text',   0],
        ['hostname',              'Hostname',        1, 20, 1, 'text',   0],
        ['serial_number',         'Serial Number',   1, 30, 1, 'text',   0],
        ['model',                 'Model',           1, 40, 1, 'text',   0],
        ['purchase_year',         'Purchase Year',   0, 50, 1, 'number', 1],
        ['service_tag',           'Service Tag',     1, 60, 1, 'text',   0],
        ['mac_address',           'MAC Address',     0, 70, 1, 'text',   1],
        ['purchase_date',         'Purchase Date',   0, 80, 1, 'date',   1],
        ['end_of_life_date',      'End of Life',     0, 90, 1, 'date',   1],
        ['vendor_return_date',    'Return Date',     0,100, 1, 'date',   1],
        ['vendor_return_to',      'Return To',       0,110, 1, 'text',   1],
        ['vendor_return_method',  'Return Method',   0,120, 1, 'text',   1],
        ['ownership_type',        'Ownership',       1,130, 1, 'text',   0],
        ['po_number',             'Batch / PO Number',0,131, 1, 'text',   1],
        ['lease_start',           'Lease Start',     0,132, 1, 'date',   1],
        ['lease_expiry',          'Lease Expiry',    0,134, 1, 'date',   1],
        ['note',                  'Note',            0,136, 1, 'text',   1],
        ['full_name',             'Assigned To',     1,140, 1, 'text',   0],
        ['location_name',         'Location',        1,150, 1, 'text',   0],
        ['status',                'Status',          1,160, 1, 'text',   0],
    ];

    $stmt = $pdo->prepare("
        INSERT INTO column_settings (col_key, col_label, col_visible, col_order, is_system, col_type, is_advanced)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    foreach ($defaults as $row) {
        $stmt->execute($row);
    }
}

/**
 * Check whether a physical column exists in the assets table.
 * Used before rendering custom column data.
 */
function assetColumnExists(PDO $pdo, string $colKey): bool {
    $cols = $pdo->query("PRAGMA table_info(assets)")->fetchAll(PDO::FETCH_COLUMN, 1);
    return in_array($colKey, $cols, true);
}

/**
 * Validate a custom column key: must be alphanumeric/underscores only,
 * prefixed with custom_ to avoid conflicts.
 */
function sanitizeCustomColKey(string $label): string {
    $key = 'custom_' . strtolower(trim($label));
    $key = preg_replace('/[^a-z0-9_]/', '_', $key);
    $key = preg_replace('/_+/', '_', $key);
    return rtrim($key, '_');
}
