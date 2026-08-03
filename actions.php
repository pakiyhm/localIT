<?php
// actions.php — Secure form handler
require_once 'includes/db_connect.php';
require_once 'includes/auth.php';
require_once 'includes/csrf.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); exit;
}

// All mutating actions require edit mode (PIN unlock).
require_edit();

// Verify CSRF token on all POST requests.
require_csrf();

$action  = $_POST['action'] ?? '';
$referer = $_SERVER['HTTP_REFERER'] ?? 'index.php';

function redirect($url, $params = []) {
    $parts = parse_url($url);
    $path  = $parts['path'] ?? 'index.php';
    $q     = [];
    if (!empty($parts['query'])) parse_str($parts['query'], $q);
    unset($q['msg'], $q['error']);
    $q  = array_merge($q, $params);
    $qs = http_build_query($q);
    header('Location: ' . $path . ($qs ? '?' . $qs : ''));
    exit;
}

try {
    $pdo->beginTransaction();

    // ── ASSIGN ────────────────────────────────────────────────
    if ($action === 'assign') {
        $user_id    = (int)($_POST['user_id'] ?? 0);
        $identifier = trim($_POST['asset_identifier'] ?? '');
        $location_id = (int)($_POST['location_id'] ?? 0);
        if (!$user_id || !$identifier) throw new Exception("Missing user ID or asset identifier.");

        $s = $pdo->prepare("SELECT a.asset_id, a.status, a.user_id AS cur_user, u.full_name AS cur_name
                            FROM assets a LEFT JOIN users u ON a.user_id=u.user_id
                            WHERE a.hostname=:i OR a.serial_number=:i OR a.service_tag=:i LIMIT 1");
        $s->execute(['i' => $identifier]);
        $asset = $s->fetch(PDO::FETCH_ASSOC);

        if (!$asset) throw new Exception("Asset not found: \"$identifier\"");
        if (in_array($asset['status'], ['repair','retired','lost'])) throw new Exception("Cannot assign an asset that is under repair, retired, or lost.");

        // Log reassignment if currently held by someone else
        $prevUserId = (int)$asset['cur_user'];
        $note = ($prevUserId && $prevUserId !== $user_id)
              ? "Reassigned from {$asset['cur_name']} via Quick Assign"
              : 'Assigned via Quick Assign';

        // Get user's location
        $uStmt = $pdo->prepare("SELECT location_id FROM users WHERE user_id = :u");
        $uStmt->execute(['u' => $user_id]);
        $userLoc = $uStmt->fetchColumn();

        $finalLoc = $location_id ? $location_id : ($userLoc ?: null);

        $pdo->prepare("UPDATE assets SET user_id=:u, status='deployed', location_id=:loc, repair_shop=NULL, repair_sent_at=NULL, repair_eta=NULL, repair_note=NULL WHERE asset_id=:a")
            ->execute(['u' => $user_id, 'a' => $asset['asset_id'], 'loc' => $finalLoc]);

        // If reassigning, log the implicit return-from-previous-user first
        if ($prevUserId && $prevUserId !== $user_id) {
            $pdo->prepare("INSERT INTO logs (asset_id,action_type,from_user_id,note) VALUES (:a,'return',:u,'Implicit return on reassignment')")
                ->execute(['a' => $asset['asset_id'], 'u' => $prevUserId]);
        }
        $pdo->prepare("INSERT INTO logs (asset_id,action_type,to_user_id,note) VALUES (:a,'assign',:u,:n)")
            ->execute(['a' => $asset['asset_id'], 'u' => $user_id, 'n' => $note]);

        $pdo->commit();
        redirect($referer, ['msg' => 'assign_success']);

    // ── RETURN ────────────────────────────────────────────────
    } elseif ($action === 'return') {
        $asset_id    = (int)($_POST['asset_id'] ?? 0);
        $from_user   = (int)($_POST['user_id']  ?? 0);
        $location_id = (int)($_POST['location_id'] ?? 0);
        if (!$asset_id) throw new Exception("Asset ID missing.");

        $sql = "UPDATE assets SET user_id=NULL, status='in_stock', location_id=:loc,
                       borrow_due_date=NULL, borrowed_at=NULL,
                       repair_shop=NULL, repair_sent_at=NULL, repair_eta=NULL, repair_note=NULL
                 WHERE asset_id=:a";
        $pdo->prepare($sql)->execute(['a' => $asset_id, 'loc' => $location_id ?: null]);

        $pdo->prepare("INSERT INTO logs (asset_id,action_type,from_user_id,note) VALUES (:a,'return',:u,'Returned to stock')")
            ->execute(['a' => $asset_id, 'u' => $from_user ?: null]);

        $pdo->commit();
        redirect($referer, ['msg' => 'return_success']);

    // ── RETURN TO VENDOR ──────────────────────────────────────
    } elseif ($action === 'return_to_vendor') {
        $asset_ids_raw        = $_POST['asset_ids'] ?? '';
        $vendor_return_date   = $_POST['vendor_return_date'] ?? date('Y-m-d');
        $vendor_return_to     = trim($_POST['vendor_return_to'] ?? '');
        $vendor_return_method = trim($_POST['vendor_return_method'] ?? '');
        $note                 = trim($_POST['note'] ?? '');
        
        $asset_ids = array_filter(array_map('intval', explode(',', $asset_ids_raw)));
        if (empty($asset_ids)) throw new Exception("Asset IDs missing.");

        $logNote = "Returned to vendor. " . ($vendor_return_to ? "To: $vendor_return_to. " : "") . ($vendor_return_method ? "Via: $vendor_return_method. " : "") . $note;

        foreach ($asset_ids as $aid) {
            $pdo->prepare("UPDATE assets SET user_id=NULL, status='retired', vendor_return_date=:vrd, vendor_return_to=:vrt, vendor_return_method=:vrm WHERE asset_id=:a")
                ->execute([
                    'vrd' => $vendor_return_date,
                    'vrt' => $vendor_return_to,
                    'vrm' => $vendor_return_method,
                    'a'   => $aid
                ]);

            $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, 'retire', :n)")
                ->execute(['a' => $aid, 'n' => trim($logNote)]);
        }

        $pdo->commit();
        redirect($referer, ['msg' => 'return_vendor_success']);

    // ── RMA REPLACE ───────────────────────────────────────────
    } elseif ($action === 'rma_replace') {
        $old_asset_id = (int)($_POST['old_asset_id'] ?? 0);
        $hostname     = trim($_POST['hostname'] ?? '');
        $serial_number= trim($_POST['serial_number'] ?? '');
        $service_tag  = trim($_POST['service_tag'] ?? '');
        $mac_address  = trim($_POST['mac_address'] ?? '');
        $note         = trim($_POST['note'] ?? '');

        if (!$old_asset_id || empty($serial_number)) throw new Exception("Old Asset ID and new Serial Number are required.");

        $s = $pdo->prepare("SELECT * FROM assets WHERE asset_id=:a LIMIT 1");
        $s->execute(['a' => $old_asset_id]);
        $old = $s->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new Exception("Old asset not found.");

        if (!empty($hostname)) {
            $s = $pdo->prepare("SELECT asset_id FROM assets WHERE hostname = :h AND asset_id != :a");
            $s->execute(['h' => $hostname, 'a' => $old_asset_id]);
            if ($s->fetchColumn()) throw new Exception("Hostname '$hostname' already exists.");
        }
        $s = $pdo->prepare("SELECT asset_id FROM assets WHERE serial_number = :sn");
        $s->execute(['sn' => $serial_number]);
        if ($s->fetchColumn()) throw new Exception("Serial Number '$serial_number' already exists.");

        // Insert new
        $pdo->prepare("
            INSERT INTO assets (category, hostname, serial_number, status, ownership_type, lease_start, lease_expiry, model, purchase_year, service_tag, mac_address, purchase_date, end_of_life_date, user_id, location_id, borrow_due_date, borrowed_at)
            VALUES (:cat, :host, :sn, :status, :own, :ls, :le, :mod, :py, :stag, :mac, :pd, :eol, :uid, :loc, :due, :bat)
        ")->execute([
            'cat' => $old['category'],
            'host' => $hostname ?: null,
            'sn' => $serial_number,
            'status' => $old['status'] === 'deployed' ? 'deployed' : 'in_stock',
            'own' => $old['ownership_type'],
            'ls' => $old['lease_start'],
            'le' => $old['lease_expiry'],
            'mod' => $old['model'],
            'py' => $old['purchase_year'],
            'stag' => $service_tag ?: null,
            'mac' => $mac_address ?: null,
            'pd' => $old['purchase_date'],
            'eol' => $old['end_of_life_date'],
            'uid' => $old['status'] === 'deployed' ? $old['user_id'] : null,
            'loc' => $old['location_id'],
            'due' => $old['borrow_due_date'] ?? null,
            'bat' => $old['borrowed_at'] ?? null
        ]);
        
        $new_asset_id = $pdo->lastInsertId();

        // Update Old
        $pdo->prepare("UPDATE assets SET status='retired', user_id=NULL, vendor_return_date=DATE('now'), vendor_return_to='RMA Replacement', vendor_return_method=:rm WHERE asset_id=:a")
            ->execute(['rm' => 'RMA for SN: ' . $serial_number, 'a' => $old_asset_id]);

        // Logs
        $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, 'retire', :n)")
            ->execute(['a' => $old_asset_id, 'n' => "RMA Replacement. Replaced by new SN: $serial_number. " . $note]);
        
        $pdo->prepare("INSERT INTO logs (asset_id, action_type, to_user_id, note) VALUES (:a, 'import', :u, :n)")
            ->execute(['a' => $new_asset_id, 'u' => ($old['status'] === 'deployed' ? $old['user_id'] : null), 'n' => "Received as RMA replacement for old SN: {$old['serial_number']}. " . $note]);

        $pdo->commit();
        redirect($referer, ['msg' => 'rma_success']);

    // ── STATUS UPDATES (REPAIR/RETIRE) ────────────────────────
    } elseif ($action === 'set_repair') {
        $asset_id = (int)($_POST['asset_id'] ?? 0);
        $shop     = trim($_POST['repair_shop'] ?? '');
        $eta      = !empty($_POST['repair_eta']) ? $_POST['repair_eta'] : null;
        $rnote    = trim($_POST['repair_note'] ?? '');
        $pdo->prepare("UPDATE assets SET status='repair', user_id=NULL,
                       repair_shop=:s, repair_sent_at=DATE('now'), repair_eta=:e, repair_note=:n
                       WHERE asset_id=:a")
            ->execute(['s' => $shop ?: null, 'e' => $eta, 'n' => $rnote ?: null, 'a' => $asset_id]);
        $logNote = 'Moved to repair status' .
                   ($shop ? " — ร้าน: $shop" : '') .
                   ($eta  ? " — ETA: $eta"   : '') .
                   ($rnote? " — $rnote"      : '');
        $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, 'repair', :n)")
            ->execute(['a' => $asset_id, 'n' => $logNote]);
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    } elseif ($action === 'set_fixed') {
        $asset_id = (int)($_POST['asset_id'] ?? 0);
        $cnote    = trim($_POST['completion_note'] ?? '');
        $pdo->prepare("UPDATE assets SET status='in_stock',
                       repair_shop=NULL, repair_sent_at=NULL, repair_eta=NULL, repair_note=NULL
                       WHERE asset_id=:a")->execute(['a' => $asset_id]);
        $logNote = 'Repair completed. Moved to stock.' . ($cnote ? " — $cnote" : '');
        $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, 'update', :n)")
            ->execute(['a' => $asset_id, 'n' => $logNote]);
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    } elseif ($action === 'set_retired') {
        $asset_id = (int)($_POST['asset_id'] ?? 0);
        $pdo->prepare("UPDATE assets SET status='retired', user_id=NULL,
                       borrow_due_date=NULL, borrowed_at=NULL,
                       repair_shop=NULL, repair_sent_at=NULL, repair_eta=NULL, repair_note=NULL
                       WHERE asset_id=:a")->execute(['a' => $asset_id]);
        $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, 'retire', 'Asset manually retired')")->execute(['a' => $asset_id]);
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── QUICK RENEW (DASHBOARD) ───────────────────────────────
    } elseif ($action === 'renew_lease') {
        $asset_id = (int)($_POST['asset_id'] ?? 0);
        $start    = !empty($_POST['lease_start']) ? $_POST['lease_start'] : null;
        $expiry   = !empty($_POST['lease_expiry']) ? $_POST['lease_expiry'] : null;
        if (!$asset_id) throw new Exception("Asset ID missing.");

        $pdo->prepare("UPDATE assets SET lease_start=:s, lease_expiry=:e WHERE asset_id=:a")
            ->execute(['s' => $start, 'e' => $expiry, 'a' => $asset_id]);
        
        $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, 'update', 'Lease renewed. New expiry: ' || COALESCE(:e, 'N/A'))")
            ->execute(['a' => $asset_id, 'e' => $expiry]);
            
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    } elseif ($action === 'renew_warranty') {
        $asset_id = (int)($_POST['asset_id'] ?? 0);
        $eol      = !empty($_POST['end_of_life_date']) ? $_POST['end_of_life_date'] : null;
        if (!$asset_id) throw new Exception("Asset ID missing.");

        $pdo->prepare("UPDATE assets SET end_of_life_date=:eol WHERE asset_id=:a")
            ->execute(['eol' => $eol, 'a' => $asset_id]);
        
        $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, 'update', 'Warranty extended. New EOL: ' || COALESCE(:eol, 'N/A'))")
            ->execute(['a' => $asset_id, 'eol' => $eol]);
            
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── BORROW (ASSIGN) ───────────────────────────────────────
    } elseif ($action === 'assign_borrow') {
        $user_id    = (int)($_POST['user_id_direct'] ?? 0);
        $asset_id   = (int)($_POST['asset_id'] ?? 0);
        $due_date   = !empty($_POST['borrow_due_date']) ? $_POST['borrow_due_date'] : null;
        if (!$user_id || !$asset_id) throw new Exception("Missing user ID or asset ID.");

        $s = $pdo->prepare("SELECT a.status, a.user_id AS cur_user, u.full_name AS cur_name
                            FROM assets a LEFT JOIN users u ON a.user_id=u.user_id
                            WHERE a.asset_id=:a LIMIT 1");
        $s->execute(['a' => $asset_id]);
        $asset = $s->fetch(PDO::FETCH_ASSOC);

        if (!$asset) throw new Exception("Asset not found.");
        if (in_array($asset['status'], ['retired','lost'])) throw new Exception("Cannot assign a retired or lost asset.");

        $prevUserId = (int)$asset['cur_user'];
        $uStmt = $pdo->prepare("SELECT location_id FROM users WHERE user_id = :u");
        $uStmt->execute(['u' => $user_id]);
        $userLoc = $uStmt->fetchColumn();

        $pdo->prepare("UPDATE assets SET user_id=:u, status='deployed', ownership_type='Borrow', location_id=:loc, borrowed_at=CURRENT_TIMESTAMP, borrow_due_date=:due WHERE asset_id=:a")
            ->execute(['u' => $user_id, 'a' => $asset_id, 'loc' => $userLoc ?: null, 'due' => $due_date]);

        if ($prevUserId && $prevUserId !== $user_id) {
            $pdo->prepare("INSERT INTO logs (asset_id,action_type,from_user_id,note) VALUES (:a,'return',:u,'Implicit return on reassignment')")
                ->execute(['a' => $asset_id, 'u' => $prevUserId]);
        }
        $reassignTag = ($prevUserId && $prevUserId !== $user_id) ? " (reassigned from {$asset['cur_name']})" : '';
        $logNote = 'Borrowed via Assets Portal' . ($due_date ? " (กำหนดคืน: $due_date)" : '') . $reassignTag;
        $pdo->prepare("INSERT INTO logs (asset_id,action_type,to_user_id,note) VALUES (:a,'assign',:u,:n)")
            ->execute(['a' => $asset_id, 'u' => $user_id, 'n' => $logNote]);

        $pdo->commit();
        redirect($referer, ['msg' => 'assign_success']);

    // ── DIRECT DEPLOY (OUT) ───────────────────────────────────
    } elseif ($action === 'assign_direct') {
        $user_id    = (int)($_POST['user_id_direct'] ?? 0);
        $asset_id   = (int)($_POST['asset_id'] ?? 0);
        if (!$user_id || !$asset_id) throw new Exception("Missing user ID or asset ID.");

        $s = $pdo->prepare("SELECT a.status, a.user_id AS cur_user, u.full_name AS cur_name
                            FROM assets a LEFT JOIN users u ON a.user_id=u.user_id
                            WHERE a.asset_id=:a LIMIT 1");
        $s->execute(['a' => $asset_id]);
        $asset = $s->fetch(PDO::FETCH_ASSOC);

        if (!$asset) throw new Exception("Asset not found.");
        if (in_array($asset['status'], ['retired','lost'])) throw new Exception("Cannot assign a retired or lost asset.");

        $prevUserId = (int)$asset['cur_user'];

        // Get user's location
        $uStmt = $pdo->prepare("SELECT location_id FROM users WHERE user_id = :u");
        $uStmt->execute(['u' => $user_id]);
        $userLoc = $uStmt->fetchColumn();

        $pdo->prepare("UPDATE assets SET user_id=:u, status='deployed', location_id=:loc WHERE asset_id=:a")
            ->execute(['u' => $user_id, 'a' => $asset_id, 'loc' => $userLoc ?: null]);

        if ($prevUserId && $prevUserId !== $user_id) {
            $pdo->prepare("INSERT INTO logs (asset_id,action_type,from_user_id,note) VALUES (:a,'return',:u,'Implicit return on reassignment')")
                ->execute(['a' => $asset_id, 'u' => $prevUserId]);
        }
        $note = ($prevUserId && $prevUserId !== $user_id)
              ? "Reassigned from {$asset['cur_name']} via Assets Portal"
              : 'Assigned via Assets Portal';
        $pdo->prepare("INSERT INTO logs (asset_id,action_type,to_user_id,note) VALUES (:a,'assign',:u,:n)")
            ->execute(['a' => $asset_id, 'u' => $user_id, 'n' => $note]);

        $pdo->commit();
        redirect($referer, ['msg' => 'assign_success']);

    // ── ADD ASSET ─────────────────────────────────────────────
    } elseif ($action === 'add_asset') {
        $category       = trim($_POST['category'] ?? '');
        $hostname       = trim($_POST['hostname'] ?? '');
        $serial_number  = trim($_POST['serial_number'] ?? '');
        $ownership_type = $_POST['ownership_type'] ?? 'Buy';
        $status         = $_POST['status'] ?? 'in_stock';
        $location_id    = (int)($_POST['location_id'] ?? 0);
        
        $lease_start    = !empty($_POST['lease_start']) ? $_POST['lease_start'] : null;
        $lease_expiry   = !empty($_POST['lease_expiry']) ? $_POST['lease_expiry'] : null;

        $model          = trim($_POST['model'] ?? '');
        $purchase_year  = !empty($_POST['purchase_year']) ? (int)$_POST['purchase_year'] : null;
        $service_tag    = trim($_POST['service_tag'] ?? '');
        $mac_address    = trim($_POST['mac_address'] ?? '');
        $purchase_date  = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $end_of_life_date = !empty($_POST['end_of_life_date']) ? $_POST['end_of_life_date'] : null;
        $po_number      = trim($_POST['po_number'] ?? '');
        $note           = trim($_POST['note'] ?? '');

        if (empty($category) || empty($ownership_type)) throw new Exception("Category and Ownership are required.");

        // Check unique constraints if provided
        if (!empty($hostname)) {
            $s = $pdo->prepare("SELECT asset_id FROM assets WHERE hostname = :h");
            $s->execute(['h' => $hostname]);
            if ($s->fetchColumn()) throw new Exception("Hostname '$hostname' already exists.");
        }
        if (!empty($serial_number)) {
            $s = $pdo->prepare("SELECT asset_id FROM assets WHERE serial_number = :sn");
            $s->execute(['sn' => $serial_number]);
            if ($s->fetchColumn()) throw new Exception("Serial Number '$serial_number' already exists.");
        }

        $s = $pdo->prepare("
            INSERT INTO assets (category, hostname, serial_number, status, ownership_type, lease_start, lease_expiry, model, purchase_year, service_tag, mac_address, purchase_date, end_of_life_date, po_number, location_id, note)
            VALUES (:c, :h, :sn, :st, :o, :ls, :le, :mod, :py, :stag, :mac, :pdate, :eol, :po, :loc, :note)
        ");
        $s->execute([
            'c' => $category, 'h' => $hostname ?: null, 'sn' => $serial_number ?: null,
            'st' => $status, 'o' => $ownership_type, 'ls' => $lease_start, 'le' => $lease_expiry,
            'mod' => $model ?: null, 'py' => $purchase_year, 'stag' => $service_tag ?: null,
            'mac' => $mac_address ?: null, 'pdate' => $purchase_date, 'eol' => $end_of_life_date,
            'po' => $po_number ?: null, 'loc' => $location_id ?: null, 'note' => $note ?: null
        ]);
        
        $newAssetId = $pdo->lastInsertId();
        
        $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, 'import', 'Asset manually created')")
            ->execute(['a' => $newAssetId]);

        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── LOCATIONS ─────────────────────────────────────────────
    } elseif ($action === 'add_location') {
        $location_name = trim($_POST['location_name'] ?? '');
        if (empty($location_name)) throw new Exception("Location name required.");
        
        $s = $pdo->prepare("SELECT location_id FROM locations WHERE location_name = :n");
        $s->execute(['n' => $location_name]);
        if ($s->fetchColumn()) throw new Exception("Location '$location_name' already exists.");
        
        $pdo->prepare("INSERT INTO locations (location_name) VALUES (:n)")->execute(['n' => $location_name]);
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    } elseif ($action === 'edit_asset') {
        $asset_id       = (int)($_POST['asset_id'] ?? 0);
        $category       = trim($_POST['category'] ?? '');
        $hostname       = trim($_POST['hostname'] ?? '');
        $serial_number  = trim($_POST['serial_number'] ?? '');
        $ownership_type = $_POST['ownership_type'] ?? 'Buy';
        $status         = $_POST['status'] ?? 'in_stock';
        $location_id    = (int)($_POST['location_id'] ?? 0);
        $note           = trim($_POST['note'] ?? '');

        $model          = trim($_POST['model'] ?? '');
        $purchase_year  = !empty($_POST['purchase_year']) ? (int)$_POST['purchase_year'] : null;
        $service_tag    = trim($_POST['service_tag'] ?? '');
        $mac_address    = trim($_POST['mac_address'] ?? '');
        $purchase_date  = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $end_of_life_date = !empty($_POST['end_of_life_date']) ? $_POST['end_of_life_date'] : null;
        $po_number      = trim($_POST['po_number'] ?? '');

        if (!$asset_id || empty($category)) throw new Exception("Asset ID and Category are required.");

        // Side-effect rules: when status changes away from deployed, clear assignment fields.
        // When status changes away from repair, clear repair fields.
        $cur = $pdo->prepare("SELECT status, location_id FROM assets WHERE asset_id=:a");
        $cur->execute(['a' => $asset_id]);
        $oldData = $cur->fetch(PDO::FETCH_ASSOC);
        $oldStatus = $oldData['status'] ?? '';
        $oldLoc    = (int)($oldData['location_id'] ?? 0);

        $extraSets = '';
        if ($status !== 'deployed') {
            $extraSets .= ', user_id=NULL, borrow_due_date=NULL, borrowed_at=NULL';
        }
        if ($status !== 'repair') {
            $extraSets .= ', repair_shop=NULL, repair_sent_at=NULL, repair_eta=NULL, repair_note=NULL';
        }

        $pdo->prepare("
            UPDATE assets
            SET    category=:c, hostname=:h, serial_number=:sn, status=:st, ownership_type=:o,
                   model=:mod, purchase_year=:py, service_tag=:stag, mac_address=:mac,
                   purchase_date=:pdate, end_of_life_date=:eol, po_number=:po, location_id=:loc, note=:note
                   $extraSets
            WHERE  asset_id=:a
        ")->execute([
            'c' => $category, 'h' => $hostname ?: null, 'sn' => $serial_number ?: null,
            'st' => $status, 'o' => $ownership_type,
            'mod' => $model ?: null, 'py' => $purchase_year, 'stag' => $service_tag ?: null,
            'mac' => $mac_address ?: null, 'pdate' => $purchase_date, 'eol' => $end_of_life_date,
            'po' => $po_number ?: null, 'loc' => $location_id ?: null, 'note' => $note ?: null, 'a' => $asset_id
        ]);
        
        if ($oldLoc !== $location_id) {
            $locName = 'Unknown Location';
            if ($location_id) {
                $ls = $pdo->prepare("SELECT location_name FROM locations WHERE location_id = :l");
                $ls->execute(['l' => $location_id]);
                $locName = $ls->fetchColumn() ?: 'Unknown Location';
            }
            $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, 'update', :n)")
                ->execute(['a' => $asset_id, 'n' => "Location manually updated to '$locName' via Edit Profile"]);
        }

        $statusTag = ($oldStatus !== $status) ? " (status: $oldStatus → $status)" : '';
        $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, 'update', 'Asset details manually updated.' || :n)")
            ->execute(['a' => $asset_id, 'n' => $statusTag . ($note ? ' ' . $note : '')]);

        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    } elseif ($action === 'delete_location') {
        $location_id = (int)($_POST['location_id'] ?? 0);
        
        $s = $pdo->prepare("SELECT COUNT(*) FROM assets WHERE location_id = :id");
        $s->execute(['id' => $location_id]);
        if ($s->fetchColumn() > 0) throw new Exception("Cannot delete location in use by assets.");

        $pdo->prepare("DELETE FROM locations WHERE location_id = :id")->execute(['id' => $location_id]);
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── USERS ─────────────────────────────────────────────────
    } elseif ($action === 'add_user') {
        $emp_id     = trim($_POST['emp_id'] ?? '');
        $full_name  = trim($_POST['full_name'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $email      = trim($_POST['email'] ?? '');

        if (empty($full_name)) throw new Exception("Full name required.");
        
        if (!empty($emp_id)) {
            $s = $pdo->prepare("SELECT user_id FROM users WHERE emp_id = :e");
            $s->execute(['e' => $emp_id]);
            if ($s->fetchColumn()) throw new Exception("Employee ID '$emp_id' already exists.");
        }

        $pdo->prepare("INSERT INTO users (emp_id, full_name, department, email) VALUES (:e, :f, :d, :em)")
            ->execute(['e' => $emp_id ?: null, 'f' => $full_name, 'd' => $department ?: null, 'em' => $email ?: null]);
        
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    } elseif ($action === 'edit_user') {
        $user_id    = (int)($_POST['user_id'] ?? 0);
        $emp_id     = trim($_POST['emp_id'] ?? '');
        $full_name  = trim($_POST['full_name'] ?? '');
        $department = trim($_POST['department'] ?? '');
        $email      = trim($_POST['email'] ?? '');
        $location_id = (int)($_POST['location_id'] ?? 0);

        if (!$user_id || empty($full_name)) throw new Exception("User ID and Full Name required.");
        
        if (!empty($emp_id)) {
            $s = $pdo->prepare("SELECT user_id FROM users WHERE emp_id = :e AND user_id != :u");
            $s->execute(['e' => $emp_id, 'u' => $user_id]);
            if ($s->fetchColumn()) throw new Exception("Employee ID '$emp_id' is used by someone else.");
        }

        // Fetch new location name for logging
        $locName = 'Unknown Location';
        if ($location_id) {
            $ls = $pdo->prepare("SELECT location_name FROM locations WHERE location_id = :l");
            $ls->execute(['l' => $location_id]);
            $locName = $ls->fetchColumn() ?: 'Unknown Location';
        }

        // Fetch affected items BEFORE updating
        $affectedAssets = $pdo->prepare("SELECT asset_id FROM assets WHERE user_id = :u AND status = 'deployed' AND (location_id != :loc OR location_id IS NULL)");
        $affectedAssets->execute(['u' => $user_id, 'loc' => $location_id ?: 0]);
        $affectedAssets = $affectedAssets->fetchAll(PDO::FETCH_COLUMN);

        $affectedAcc = $pdo->prepare("SELECT accessory_id FROM accessories WHERE user_id = :u AND status = 'deployed' AND (location_id != :loc OR location_id IS NULL)");
        $affectedAcc->execute(['u' => $user_id, 'loc' => $location_id ?: 0]);
        $affectedAcc = $affectedAcc->fetchAll(PDO::FETCH_COLUMN);

        $pdo->prepare("UPDATE users SET emp_id=:e, full_name=:f, department=:d, email=:em, location_id=:loc WHERE user_id=:u")
            ->execute(['e' => $emp_id ?: null, 'f' => $full_name, 'd' => $department ?: null, 'em' => $email ?: null, 'loc' => $location_id ?: null, 'u' => $user_id]);
        
        // Bonus: Update all deployed assets/accessories for this user to match the new user location
        $pdo->prepare("UPDATE assets SET location_id = :loc WHERE user_id = :u AND status = 'deployed'")
            ->execute(['loc' => $location_id ?: null, 'u' => $user_id]);
        $pdo->prepare("UPDATE accessories SET location_id = :loc WHERE user_id = :u AND status = 'deployed'")
            ->execute(['loc' => $location_id ?: null, 'u' => $user_id]);

        // Insert logs for cascaded location updates
        if (!empty($affectedAssets) || !empty($affectedAcc)) {
            $note = "Location automatically synced to '$locName' due to user profile change";
            $logAst = $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, 'update', :n)");
            foreach ($affectedAssets as $aid) { $logAst->execute(['a' => $aid, 'n' => $note]); }
            
            $logAcc = $pdo->prepare("INSERT INTO logs (accessory_id, action_type, note) VALUES (:a, 'update', :n)");
            foreach ($affectedAcc as $aid) { $logAcc->execute(['a' => $aid, 'n' => $note]); }
        }
        
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── RESIGN USER (per-item disposition) ────────────────
    } elseif ($action === 'resign_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        $resDate = $_POST['resignation_date'] ?? date('Y-m-d');
        $note    = trim($_POST['note'] ?? '');
        if (!$user_id) throw new Exception("User ID missing.");
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $resDate)) throw new Exception("Invalid resignation date.");

        $s = $pdo->prepare("SELECT full_name FROM users WHERE user_id = :u");
        $s->execute(['u' => $user_id]);
        $userName = $s->fetchColumn();
        if (!$userName) throw new Exception("User not found.");

        $tagBase = "Resigned $resDate" . ($note ? " — $note" : "");

        // ── Assets ──
        $assetDisp = $_POST['asset_disposition'] ?? [];
        $heldAssets = $pdo->prepare("SELECT asset_id FROM assets WHERE user_id = :u");
        $heldAssets->execute(['u' => $user_id]);
        foreach ($heldAssets->fetchAll(PDO::FETCH_COLUMN) as $aid) {
            $d = $assetDisp[$aid] ?? 'returned';
            switch ($d) {
                case 'lost':
                    $pdo->prepare("UPDATE assets SET user_id=NULL, status='lost',
                                   borrow_due_date=NULL, borrowed_at=NULL,
                                   ownership_type=CASE WHEN ownership_type='Borrow' THEN 'Buy' ELSE ownership_type END
                                   WHERE asset_id=:a")->execute(['a' => $aid]);
                    $pdo->prepare("INSERT INTO logs (asset_id, action_type, from_user_id, note) VALUES (:a, 'retire', :u, :n)")
                        ->execute(['a' => $aid, 'u' => $user_id, 'n' => "Reported lost on resignation. $tagBase"]);
                    break;
                case 'broken':
                    $pdo->prepare("UPDATE assets SET user_id=NULL, status='repair',
                                   borrow_due_date=NULL, borrowed_at=NULL,
                                   repair_sent_at=:d, repair_note='Reported broken on resignation',
                                   ownership_type=CASE WHEN ownership_type='Borrow' THEN 'Buy' ELSE ownership_type END
                                   WHERE asset_id=:a")->execute(['a' => $aid, 'd' => $resDate]);
                    $pdo->prepare("INSERT INTO logs (asset_id, action_type, from_user_id, note) VALUES (:a, 'repair', :u, :n)")
                        ->execute(['a' => $aid, 'u' => $user_id, 'n' => "Reported broken on resignation. $tagBase"]);
                    break;
                case 'returned':
                default:
                    $pdo->prepare("UPDATE assets SET user_id=NULL, status='in_stock',
                                   borrow_due_date=NULL, borrowed_at=NULL, location_id=NULL,
                                   ownership_type=CASE WHEN ownership_type='Borrow' THEN 'Buy' ELSE ownership_type END
                                   WHERE asset_id=:a")->execute(['a' => $aid]);
                    $pdo->prepare("INSERT INTO logs (asset_id, action_type, from_user_id, note) VALUES (:a, 'return', :u, :n)")
                        ->execute(['a' => $aid, 'u' => $user_id, 'n' => "Returned on resignation. $tagBase"]);
            }
        }

        // ── Accessories ──
        $accDisp = $_POST['acc_disposition'] ?? [];
        $heldAcc = $pdo->prepare("SELECT accessory_id FROM accessories WHERE user_id = :u");
        $heldAcc->execute(['u' => $user_id]);
        foreach ($heldAcc->fetchAll(PDO::FETCH_COLUMN) as $acid) {
            $d = $accDisp[$acid] ?? 'returned';
            switch ($d) {
                case 'lost':
                    $pdo->prepare("UPDATE accessories SET user_id=NULL, status='lost',
                                   borrow_due_date=NULL, borrowed_at=NULL,
                                   ownership_type=CASE WHEN ownership_type='Borrow' THEN 'Buy' ELSE ownership_type END
                                   WHERE accessory_id=:a")->execute(['a' => $acid]);
                    $pdo->prepare("INSERT INTO logs (accessory_id, action_type, from_user_id, note) VALUES (:a, 'retire', :u, :n)")
                        ->execute(['a' => $acid, 'u' => $user_id, 'n' => "Reported lost on resignation. $tagBase"]);
                    break;
                case 'broken':
                    $pdo->prepare("UPDATE accessories SET user_id=NULL, status='repair',
                                   borrow_due_date=NULL, borrowed_at=NULL,
                                   repair_sent_at=:d, repair_note='Reported broken on resignation',
                                   ownership_type=CASE WHEN ownership_type='Borrow' THEN 'Buy' ELSE ownership_type END
                                   WHERE accessory_id=:a")->execute(['a' => $acid, 'd' => $resDate]);
                    $pdo->prepare("INSERT INTO logs (accessory_id, action_type, from_user_id, note) VALUES (:a, 'repair', :u, :n)")
                        ->execute(['a' => $acid, 'u' => $user_id, 'n' => "Reported broken on resignation. $tagBase"]);
                    break;
                case 'returned':
                default:
                    $pdo->prepare("UPDATE accessories SET user_id=NULL, status='in_stock',
                                   borrow_due_date=NULL, borrowed_at=NULL, location_id=NULL,
                                   ownership_type=CASE WHEN ownership_type='Borrow' THEN 'Buy' ELSE ownership_type END
                                   WHERE accessory_id=:a")->execute(['a' => $acid]);
                    $pdo->prepare("INSERT INTO logs (accessory_id, action_type, from_user_id, note) VALUES (:a, 'return', :u, :n)")
                        ->execute(['a' => $acid, 'u' => $user_id, 'n' => "Returned on resignation. $tagBase"]);
            }
        }

        // ── Licenses ──
        $licDisp = $_POST['lic_disposition'] ?? [];
        try {
            $heldLic = $pdo->prepare("SELECT license_id FROM software_licenses WHERE user_id = :u AND status = 'assigned'");
            $heldLic->execute(['u' => $user_id]);
            foreach ($heldLic->fetchAll(PDO::FETCH_COLUMN) as $lid) {
                $d = $licDisp[$lid] ?? 'revoke';
                if ($d === 'keep') {
                    // Keep license but unassign user; mark active for re-assignment later
                    $pdo->prepare("UPDATE software_licenses SET user_id=NULL, status='active' WHERE license_id=:l")->execute(['l' => $lid]);
                    $pdo->prepare("INSERT INTO software_license_logs (license_id, action, field_name, old_value, new_value, note) VALUES (:l, 'unassign', 'user_id', :u, NULL, :n)")
                        ->execute(['l' => $lid, 'u' => (string)$user_id, 'n' => "Unassigned on resignation (kept for re-assignment). $tagBase"]);
                } else {
                    // Revoke
                    $pdo->prepare("UPDATE software_licenses SET user_id=NULL, status='revoked' WHERE license_id=:l")->execute(['l' => $lid]);
                    $pdo->prepare("INSERT INTO software_license_logs (license_id, action, field_name, old_value, new_value, note) VALUES (:l, 'revoke', 'status', 'assigned', 'revoked', :n)")
                        ->execute(['l' => $lid, 'n' => "Revoked on resignation. $tagBase"]);
                }
            }
        } catch (Exception $e) { /* software tables not ready */ }

        // Soft-delete: mark user as resigned but keep the record so audit logs still resolve names.
        $pdo->prepare("UPDATE users SET resigned_at=:d, resign_note=:n WHERE user_id=:u")
            ->execute(['d' => $resDate, 'n' => $note ?: null, 'u' => $user_id]);

        $pdo->commit();
        redirect('users.php', ['msg' => 'resigned']);

    } elseif ($action === 'delete_user') {
        $user_id = (int)($_POST['user_id'] ?? 0);
        if (!$user_id) throw new Exception("User ID missing.");

        // Fetch held items to log their return
        $heldAssets = $pdo->prepare("SELECT asset_id FROM assets WHERE user_id = :u");
        $heldAssets->execute(['u' => $user_id]);
        $heldAssets = $heldAssets->fetchAll(PDO::FETCH_COLUMN);

        $heldAcc = $pdo->prepare("SELECT accessory_id FROM accessories WHERE user_id = :u");
        $heldAcc->execute(['u' => $user_id]);
        $heldAcc = $heldAcc->fetchAll(PDO::FETCH_COLUMN);

        $heldLic = $pdo->prepare("SELECT license_id FROM software_licenses WHERE user_id = :u");
        $heldLic->execute(['u' => $user_id]);
        $heldLic = $heldLic->fetchAll(PDO::FETCH_COLUMN);

        // Update items: remove user, return to stock/active
        $pdo->prepare("UPDATE assets SET user_id = NULL, status = 'in_stock' WHERE user_id = :u")->execute(['u' => $user_id]);
        $pdo->prepare("UPDATE accessories SET user_id = NULL, status = 'in_stock' WHERE user_id = :u")->execute(['u' => $user_id]);
        $pdo->prepare("UPDATE software_licenses SET user_id = NULL, status = 'active' WHERE user_id = :u")->execute(['u' => $user_id]);

        // Insert Logs
        $note = "Returned to stock due to user profile deletion";
        $licNote = "Unassigned due to user profile deletion";
        $logAst = $pdo->prepare("INSERT INTO logs (asset_id, action_type, from_user_id, note) VALUES (:a, 'return', :u, :n)");
        foreach ($heldAssets as $aid) { $logAst->execute(['a' => $aid, 'u' => $user_id, 'n' => $note]); }
        
        $logAcc = $pdo->prepare("INSERT INTO logs (accessory_id, action_type, from_user_id, note) VALUES (:a, 'return', :u, :n)");
        foreach ($heldAcc as $aid) { $logAcc->execute(['a' => $aid, 'u' => $user_id, 'n' => $note]); }
        
        $logLic = $pdo->prepare("INSERT INTO software_license_logs (license_id, action, field_name, old_value, new_value, note) VALUES (:l, 'unassign', 'status', 'assigned', 'active', :n)");
        foreach ($heldLic as $lid) { $logLic->execute(['l' => $lid, 'n' => $licNote]); }
        
        $pdo->prepare("DELETE FROM users WHERE user_id = :u")->execute(['u' => $user_id]);

        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── PERMANENT DELETE ASSET ────────────────────────────────
    } elseif ($action === 'delete_asset') {
        $asset_id      = (int)($_POST['asset_id'] ?? 0);
        $delete_reason = trim($_POST['delete_reason'] ?? '');

        if (!$asset_id)      throw new Exception("Asset ID missing.");
        if (!$delete_reason) throw new Exception("กรุณาระบุเหตุผลในการลบ");

        // 1. Fetch full asset info before deleting (for snapshot log)
        $s = $pdo->prepare("SELECT a.*, u.full_name, l.location_name
                            FROM assets a
                            LEFT JOIN users     u ON a.user_id     = u.user_id
                            LEFT JOIN locations l ON a.location_id = l.location_id
                            WHERE a.asset_id = :a LIMIT 1");
        $s->execute(['a' => $asset_id]);
        $asset = $s->fetch(PDO::FETCH_ASSOC);
        if (!$asset) throw new Exception("Asset not found (ID: $asset_id).");

        // 3. Write a pre-delete audit log entry to the logs table (permanent record)
        $snapshot = sprintf(
            "[PERMANENT DELETE] Category: %s | Hostname: %s | Serial: %s | Model: %s | Status: %s | Ownership: %s | Location: %s | User: %s | Reason: %s",
            $asset['category']      ?? '—',
            $asset['hostname']      ?? '—',
            $asset['serial_number'] ?? '—',
            $asset['model']         ?? '—',
            $asset['status']        ?? '—',
            $asset['ownership_type'] ?? '—',
            $asset['location_name'] ?? '—',
            $asset['full_name']     ?? '—',
            $delete_reason
        );
        // Insert with NULL asset_id so it doesn't block deletion
        $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (NULL, 'retire', :n)")
            ->execute(['n' => $snapshot]);

        // 4. Remove child/parent relations and other dependencies to avoid FK violation (Error 23000)
        $pdo->prepare("DELETE FROM asset_relations WHERE parent_asset_id = :a OR child_asset_id = :a")
            ->execute(['a' => $asset_id]);
            
        // Unlink accessories that have this asset as parent
        $pdo->prepare("UPDATE accessories SET parent_asset_id = NULL WHERE parent_asset_id = :a")
            ->execute(['a' => $asset_id]);
            
        // Delete attachments (both files and DB rows)
        $atts = $pdo->prepare("SELECT stored_name FROM asset_attachments WHERE asset_id = :a");
        $atts->execute(['a' => $asset_id]);
        foreach($atts->fetchAll(PDO::FETCH_ASSOC) as $r) {
            @unlink(__DIR__ . '/uploads/attachments/' . $r['stored_name']);
        }
        $pdo->prepare("DELETE FROM asset_attachments WHERE asset_id = :a")->execute(['a' => $asset_id]);
        
        // Unlink old logs (keep log text but remove FK)
        $pdo->prepare("UPDATE logs SET asset_id = NULL WHERE asset_id = :a")->execute(['a' => $asset_id]);

        // 5. Physically delete the asset row
        $pdo->prepare("DELETE FROM assets WHERE asset_id = :a")->execute(['a' => $asset_id]);

        $pdo->commit();
        redirect('assets.php', ['msg' => 'deleted']);

    // ── PERMANENT DELETE ACCESSORY ────────────────────────────
    } elseif ($action === 'delete_accessory') {
        $accessory_id  = (int)($_POST['accessory_id'] ?? 0);
        $delete_reason = trim($_POST['delete_reason'] ?? '');

        if (!$accessory_id) throw new Exception("Accessory ID missing.");
        if (!$delete_reason) throw new Exception("กรุณาระบุเหตุผลในการลบ");

        // 1. Fetch full info before deleting
        $s = $pdo->prepare("SELECT ac.*, u.full_name, l.location_name, pa.hostname as parent_hostname
                            FROM accessories ac
                            LEFT JOIN users     u  ON ac.user_id        = u.user_id
                            LEFT JOIN locations l  ON ac.location_id    = l.location_id
                            LEFT JOIN assets    pa ON ac.parent_asset_id = pa.asset_id
                            WHERE ac.accessory_id = :a LIMIT 1");
        $s->execute(['a' => $accessory_id]);
        $acc = $s->fetch(PDO::FETCH_ASSOC);
        if (!$acc) throw new Exception("Accessory not found (ID: $accessory_id).");

        // 2. Write pre-delete audit log (insert with NULL accessory_id)
        $snapshot = sprintf(
            "[PERMANENT DELETE] Category: %s | Name: %s | Serial: %s | Model: %s | Status: %s | Ownership: %s | Location: %s | User: %s | Parent: %s | Reason: %s",
            $acc['category']       ?? '—',
            $acc['name']           ?? '—',
            $acc['serial_number']  ?? '—',
            $acc['model']          ?? '—',
            $acc['status']         ?? '—',
            $acc['ownership_type'] ?? '—',
            $acc['location_name']  ?? '—',
            $acc['full_name']      ?? '—',
            $acc['parent_hostname'] ?? '—',
            $delete_reason
        );
        $pdo->prepare("INSERT INTO logs (accessory_id, action_type, note) VALUES (NULL, 'retire', :n)")
            ->execute(['n' => $snapshot]);

        // 3. Remove dependencies to avoid FK violation (Error 23000)
        // Delete attachments (both files and DB rows)
        $atts = $pdo->prepare("SELECT stored_name FROM asset_attachments WHERE accessory_id = :a");
        $atts->execute(['a' => $accessory_id]);
        foreach($atts->fetchAll(PDO::FETCH_ASSOC) as $r) {
            @unlink(__DIR__ . '/uploads/attachments/' . $r['stored_name']);
        }
        $pdo->prepare("DELETE FROM asset_attachments WHERE accessory_id = :a")->execute(['a' => $accessory_id]);
        
        // Unlink old logs
        $pdo->prepare("UPDATE logs SET accessory_id = NULL WHERE accessory_id = :a")->execute(['a' => $accessory_id]);

        // 4. Physically delete
        $pdo->prepare("DELETE FROM accessories WHERE accessory_id = :a")->execute(['a' => $accessory_id]);

        $pdo->commit();
        redirect('accessories.php', ['msg' => 'deleted']);

    // ── CONSUMABLE LOG ────────────────────────────────────────
    } elseif ($action === 'consumable_log') {
        $item_name  = trim($_POST['item_name']  ?? '');
        $log_action = $_POST['log_action']      ?? '';
        $quantity   = (int)($_POST['quantity']  ?? 0);
        $note       = trim($_POST['note']       ?? '');

        if (!$item_name || !in_array($log_action, ['in','out','audit']) || $quantity <= 0)
            throw new Exception("Invalid consumable data.");

        $pdo->prepare("INSERT INTO consumable_logs (item_name,action,quantity,note) VALUES (:i,:a,:q,:n)")
            ->execute(['i'=>$item_name,'a'=>$log_action,'q'=>$quantity,'n'=>$note]);

        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── MAP MANAGER ───────────────────────────────────────────
    } elseif ($action === 'upload_map') {
        $name = trim($_POST['map_name'] ?? '');
        if (empty($name)) throw new Exception("Map name is required.");
        
        if (!isset($_FILES['map_image']) || $_FILES['map_image']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Please select a valid image file to upload.");
        }
        
        $file = $_FILES['map_image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
            throw new Exception("Only JPG, PNG, GIF, and WEBP images are allowed.");
        }
        
        $filename = uniqid('map_') . '.' . $ext;
        $destPath = 'uploads/maps/' . $filename;
        
        if (!move_uploaded_file($file['tmp_name'], __DIR__ . '/' . $destPath)) {
            throw new Exception("Failed to save uploaded image.");
        }
        
        $pdo->prepare("INSERT INTO maps (name, image_path) VALUES (:n, :p)")
            ->execute(['n' => $name, 'p' => $destPath]);
            
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    } elseif ($action === 'delete_map') {
        $map_id = (int)($_POST['map_id'] ?? 0);
        if (!$map_id) throw new Exception("Map ID missing.");
        
        $s = $pdo->prepare("SELECT image_path FROM maps WHERE map_id = :id");
        $s->execute(['id' => $map_id]);
        $map = $s->fetch(PDO::FETCH_ASSOC);
        
        if ($map) {
            $path = __DIR__ . '/' . $map['image_path'];
            if (file_exists($path)) {
                unlink($path);
            }
            $pdo->prepare("DELETE FROM map_zones WHERE map_id = :id")->execute(['id' => $map_id]);
            $pdo->prepare("DELETE FROM maps WHERE map_id = :id")->execute(['id' => $map_id]);
        }
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    } elseif ($action === 'save_zone') {
        $map_id = (int)($_POST['map_id'] ?? 0);
        $location_id = (int)($_POST['location_id'] ?? 0);
        $polygon_json = $_POST['polygon_json'] ?? '';
        
        if (!$map_id || !$location_id || empty($polygon_json)) {
            throw new Exception("Map, Location, and Polygon data are required.");
        }
        
        // Ensure location isn't already mapped on this map
        $s = $pdo->prepare("SELECT zone_id FROM map_zones WHERE map_id = :m AND location_id = :l");
        $s->execute(['m' => $map_id, 'l' => $location_id]);
        if ($s->fetchColumn()) {
            throw new Exception("This location already has a zone on this map.");
        }
        
        $pdo->prepare("INSERT INTO map_zones (map_id, location_id, polygon_json) VALUES (:m, :l, :p)")
            ->execute(['m' => $map_id, 'l' => $location_id, 'p' => $polygon_json]);
            
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    } elseif ($action === 'delete_zone') {
        $zone_id = (int)($_POST['zone_id'] ?? 0);
        if (!$zone_id) throw new Exception("Zone ID missing.");
        
        $pdo->prepare("DELETE FROM map_zones WHERE zone_id = :z")->execute(['z' => $zone_id]);
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    } elseif ($action === 'edit_accessory') {
        $acc_id         = (int)($_POST['accessory_id'] ?? 0);
        $category       = trim($_POST['category'] ?? '');
        $name           = trim($_POST['name'] ?? '');
        $serial_number  = trim($_POST['serial_number'] ?? '');
        $ownership_type = $_POST['ownership_type'] ?? 'Buy';
        $status         = $_POST['status'] ?? 'in_stock';
        $location_id    = (int)($_POST['location_id'] ?? 0);
        $parent_asset_id = (int)($_POST['parent_asset_id'] ?? 0);
        $note           = trim($_POST['note'] ?? '');

        $model          = trim($_POST['model'] ?? '');
        $purchase_year  = !empty($_POST['purchase_year']) ? (int)$_POST['purchase_year'] : null;
        $service_tag    = trim($_POST['service_tag'] ?? '');
        $mac_address    = trim($_POST['mac_address'] ?? '');
        $purchase_date  = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $end_of_life_date = !empty($_POST['end_of_life_date']) ? $_POST['end_of_life_date'] : null;
        $po_number      = trim($_POST['po_number'] ?? '');

        if (!$acc_id || empty($category) || empty($name)) throw new Exception("ID, Category and Name are required.");

        $extraSets = '';
        if ($status !== 'deployed') {
            $extraSets .= ', user_id=NULL, borrow_due_date=NULL, borrowed_at=NULL';
        }
        if ($status !== 'repair') {
            $extraSets .= ', repair_shop=NULL, repair_sent_at=NULL, repair_eta=NULL, repair_note=NULL';
        }

        $pdo->prepare("
            UPDATE accessories
            SET    category=:c, name=:n, serial_number=:sn, status=:st, ownership_type=:o,
                   model=:mod, purchase_year=:py, service_tag=:stag, mac_address=:mac,
                   purchase_date=:pdate, end_of_life_date=:eol, po_number=:po, location_id=:loc, parent_asset_id=:pa
                   $extraSets
            WHERE  accessory_id=:a
        ")->execute([
            'c' => $category, 'n' => $name, 'sn' => $serial_number ?: null,
            'st' => $status, 'o' => $ownership_type,
            'mod' => $model ?: null, 'py' => $purchase_year, 'stag' => $service_tag ?: null,
            'mac' => $mac_address ?: null, 'pdate' => $purchase_date, 'eol' => $end_of_life_date,
            'po' => $po_number ?: null, 'loc' => $location_id ?: null, 'pa' => $parent_asset_id ?: null, 'a' => $acc_id
        ]);

        $pdo->prepare("INSERT INTO logs (accessory_id, action_type, note) VALUES (:a, 'update', 'Accessory details manually updated. ' || :note)")
            ->execute(['a' => $acc_id, 'note' => $note]);

        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── ADD ACCESSORY ─────────────────────────────────────────
    } elseif ($action === 'add_accessory') {
        $category       = trim($_POST['category'] ?? '');
        $name           = trim($_POST['name'] ?? '');
        $serial_number  = trim($_POST['serial_number'] ?? '');
        $ownership_type = $_POST['ownership_type'] ?? 'Buy';
        $status         = $_POST['status'] ?? 'in_stock';
        $location_id    = (int)($_POST['location_id'] ?? 0);
        $parent_asset_id = (int)($_POST['parent_asset_id'] ?? 0);
        $note           = trim($_POST['note'] ?? '');

        $model          = trim($_POST['model'] ?? '');
        $purchase_year  = !empty($_POST['purchase_year']) ? (int)$_POST['purchase_year'] : null;
        $service_tag    = trim($_POST['service_tag'] ?? '');
        $mac_address    = trim($_POST['mac_address'] ?? '');
        $purchase_date  = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $end_of_life_date = !empty($_POST['end_of_life_date']) ? $_POST['end_of_life_date'] : null;
        $po_number      = trim($_POST['po_number'] ?? '');

        if (empty($category) || empty($name)) throw new Exception("Category and Name are required.");

        if (!empty($serial_number)) {
            $s = $pdo->prepare("SELECT accessory_id FROM accessories WHERE serial_number = :sn");
            $s->execute(['sn' => $serial_number]);
            if ($s->fetchColumn()) throw new Exception("Serial Number '$serial_number' already exists.");
        }

        $s = $pdo->prepare("
            INSERT INTO accessories (category, name, serial_number, status, ownership_type, model, purchase_year, service_tag, mac_address, purchase_date, end_of_life_date, po_number, location_id, parent_asset_id, note)
            VALUES (:c, :n, :sn, :st, :o, :mod, :py, :stag, :mac, :pdate, :eol, :po, :loc, :pa, :note)
        ");
        $s->execute([
            'c' => $category, 'n' => $name, 'sn' => $serial_number ?: null,
            'st' => $status, 'o' => $ownership_type,
            'mod' => $model ?: null, 'py' => $purchase_year, 'stag' => $service_tag ?: null,
            'mac' => $mac_address ?: null, 'pdate' => $purchase_date, 'eol' => $end_of_life_date,
            'po' => $po_number ?: null, 'loc' => $location_id ?: null, 'pa' => $parent_asset_id ?: null,
            'note' => $note ?: null
        ]);

        $newId = $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO logs (accessory_id, action_type, note) VALUES (:a, 'import', 'Accessory manually created')")
            ->execute(['a' => $newId]);

        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── ASSIGN ACCESSORY (OUT) ────────────────────────────────
    } elseif ($action === 'assign_accessory') {
        $user_id      = (int)($_POST['user_id'] ?? $_POST['user_id_direct'] ?? 0);
        $accessory_id = (int)($_POST['accessory_id'] ?? 0);
        $location_id  = (int)($_POST['location_id'] ?? 0);
        if (!$user_id || !$accessory_id) throw new Exception("Missing user ID or accessory ID.");

        $s = $pdo->prepare("SELECT status FROM accessories WHERE accessory_id=:a LIMIT 1");
        $s->execute(['a' => $accessory_id]);
        $acc = $s->fetch(PDO::FETCH_ASSOC);

        if (!$acc) throw new Exception("Accessory not found.");
        if (in_array($acc['status'], ['repair','retired','lost'])) throw new Exception("Cannot assign an accessory that is under repair, retired, or lost.");

        // Get user's location
        $uStmt = $pdo->prepare("SELECT location_id FROM users WHERE user_id = :u");
        $uStmt->execute(['u' => $user_id]);
        $userLoc = $uStmt->fetchColumn();

        $finalLoc = $location_id ? $location_id : ($userLoc ?: null);

        $pdo->prepare("UPDATE accessories SET user_id=:u, status='deployed', location_id=:loc, repair_shop=NULL, repair_sent_at=NULL, repair_eta=NULL, repair_note=NULL WHERE accessory_id=:a")
            ->execute(['u' => $user_id, 'a' => $accessory_id, 'loc' => $finalLoc]);

        $pdo->prepare("INSERT INTO logs (accessory_id, action_type, to_user_id, note) VALUES (:a, 'assign', :u, 'Assigned via Accessories Portal')")
            ->execute(['a' => $accessory_id, 'u' => $user_id]);

        $pdo->commit();
        redirect($referer, ['msg' => 'assign_success']);

    // ── RETURN ACCESSORY (IN) ─────────────────────────────────
    } elseif ($action === 'return_accessory') {
        $accessory_id = (int)($_POST['accessory_id'] ?? 0);
        $from_user    = (int)($_POST['user_id'] ?? 0);
        $location_id  = (int)($_POST['location_id'] ?? 0);
        if (!$accessory_id) throw new Exception("Accessory ID missing.");

        $sql = "UPDATE accessories SET user_id=NULL, status='in_stock', location_id=:loc,
                       borrow_due_date=NULL, borrowed_at=NULL,
                       repair_shop=NULL, repair_sent_at=NULL, repair_eta=NULL, repair_note=NULL
                 WHERE accessory_id=:a";
        $pdo->prepare($sql)->execute(['a' => $accessory_id, 'loc' => $location_id ?: null]);

        $pdo->prepare("INSERT INTO logs (accessory_id, action_type, from_user_id, note) VALUES (:a, 'return', :u, 'Returned to stock')")
            ->execute(['a' => $accessory_id, 'u' => $from_user ?: null]);

        $pdo->commit();
        redirect($referer, ['msg' => 'return_success']);

    // ── RMA ACCESSORY REPLACE ─────────────────────────────────
    } elseif ($action === 'rma_accessory_replace') {
        $old_accessory_id = (int)($_POST['old_accessory_id'] ?? 0);
        $serial_number    = trim($_POST['serial_number'] ?? '');
        $service_tag      = trim($_POST['service_tag'] ?? '');
        $mac_address      = trim($_POST['mac_address'] ?? '');
        $note             = trim($_POST['note'] ?? '');

        if (!$old_accessory_id) throw new Exception("Old Accessory ID is required.");

        $s = $pdo->prepare("SELECT * FROM accessories WHERE accessory_id=:a LIMIT 1");
        $s->execute(['a' => $old_accessory_id]);
        $old = $s->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new Exception("Old accessory not found.");

        if (!empty($serial_number)) {
            $s = $pdo->prepare("SELECT accessory_id FROM accessories WHERE serial_number = :sn");
            $s->execute(['sn' => $serial_number]);
            if ($s->fetchColumn()) throw new Exception("Serial Number '$serial_number' already exists.");
        }

        // Insert new
        $pdo->prepare("
            INSERT INTO accessories (category, name, serial_number, status, ownership_type, model, purchase_year, service_tag, mac_address, purchase_date, end_of_life_date, location_id, parent_asset_id)
            VALUES (:cat, :name, :sn, :status, :own, :mod, :py, :stag, :mac, :pd, :eol, :loc, :pa)
        ")->execute([
            'cat' => $old['category'],
            'name' => $old['name'],
            'sn' => $serial_number ?: null,
            'status' => $old['status'] === 'deployed' ? 'deployed' : 'in_stock',
            'own' => $old['ownership_type'],
            'mod' => $old['model'],
            'py' => $old['purchase_year'],
            'stag' => $service_tag ?: null,
            'mac' => $mac_address ?: null,
            'pd' => $old['purchase_date'],
            'eol' => $old['end_of_life_date'],
            'loc' => $old['location_id'],
            'pa' => $old['parent_asset_id']
        ]);
        
        $new_accessory_id = $pdo->lastInsertId();
        
        // Re-assign user if deployed
        if ($old['status'] === 'deployed' && $old['user_id']) {
            $pdo->prepare("UPDATE accessories SET user_id=:u WHERE accessory_id=:a")->execute(['u' => $old['user_id'], 'a' => $new_accessory_id]);
        }

        // Update Old
        $pdo->prepare("UPDATE accessories SET status='retired', user_id=NULL, vendor_return_date=DATE('now'), vendor_return_to='RMA Replacement', vendor_return_method=:rm WHERE accessory_id=:a")
            ->execute(['rm' => 'RMA for SN: ' . ($serial_number ?: 'Unknown'), 'a' => $old_accessory_id]);

        // Logs
        $pdo->prepare("INSERT INTO logs (accessory_id, action_type, note) VALUES (:a, 'retire', :n)")
            ->execute(['a' => $old_accessory_id, 'n' => "RMA Replacement. Replaced by new SN: $serial_number. " . $note]);
        
        $pdo->prepare("INSERT INTO logs (accessory_id, action_type, to_user_id, note) VALUES (:a, 'update', :u, :n)")
            ->execute(['a' => $new_accessory_id, 'u' => ($old['status'] === 'deployed' ? $old['user_id'] : null), 'n' => "Received as RMA replacement for old SN: {$old['serial_number']}. " . $note]);

        $pdo->commit();
        redirect($referer, ['msg' => 'rma_success']);

    // ── RETURN ACCESSORY TO VENDOR ────────────────────────────
    } elseif ($action === 'return_accessory_to_vendor') {
        $accessory_ids_raw    = $_POST['accessory_ids'] ?? '';
        $vendor_return_date   = $_POST['vendor_return_date'] ?? date('Y-m-d');
        $vendor_return_to     = trim($_POST['vendor_return_to'] ?? '');
        $vendor_return_method = trim($_POST['vendor_return_method'] ?? '');
        $note                 = trim($_POST['note'] ?? '');
        
        $accessory_ids = array_filter(array_map('intval', explode(',', $accessory_ids_raw)));
        if (empty($accessory_ids)) throw new Exception("Accessory IDs missing.");

        $logNote = "Returned to vendor. " . ($vendor_return_to ? "To: $vendor_return_to. " : "") . ($vendor_return_method ? "Via: $vendor_return_method. " : "") . $note;

        foreach ($accessory_ids as $aid) {
            $pdo->prepare("UPDATE accessories SET user_id=NULL, status='retired', vendor_return_date=:vrd, vendor_return_to=:vrt, vendor_return_method=:vrm WHERE accessory_id=:a")
                ->execute([
                    'vrd' => $vendor_return_date,
                    'vrt' => $vendor_return_to,
                    'vrm' => $vendor_return_method,
                    'a'   => $aid
                ]);

            $pdo->prepare("INSERT INTO logs (accessory_id, action_type, note) VALUES (:a, 'retire', :n)")
                ->execute(['a' => $aid, 'n' => trim($logNote)]);
        }

        $pdo->commit();
        redirect($referer, ['msg' => 'return_vendor_success']);

    // ── BORROW ACCESSORY ──────────────────────────────────────
    } elseif ($action === 'borrow_accessory') {
        $user_id      = (int)($_POST['user_id_direct'] ?? 0);
        $accessory_id = (int)($_POST['accessory_id'] ?? 0);
        $due_date     = !empty($_POST['borrow_due_date']) ? $_POST['borrow_due_date'] : null;
        if (!$user_id || !$accessory_id) throw new Exception("Missing user ID or accessory ID.");

        $s = $pdo->prepare("SELECT status FROM accessories WHERE accessory_id=:a LIMIT 1");
        $s->execute(['a' => $accessory_id]);
        $acc = $s->fetch(PDO::FETCH_ASSOC);

        if (!$acc) throw new Exception("Accessory not found.");
        if (in_array($acc['status'], ['retired','lost'])) throw new Exception("Cannot assign a retired or lost accessory.");

        $uStmt = $pdo->prepare("SELECT location_id FROM users WHERE user_id = :u");
        $uStmt->execute(['u' => $user_id]);
        $userLoc = $uStmt->fetchColumn();

        $pdo->prepare("UPDATE accessories SET user_id=:u, status='deployed', ownership_type='Borrow', location_id=:loc, borrowed_at=CURRENT_TIMESTAMP, borrow_due_date=:due WHERE accessory_id=:a")
            ->execute(['u' => $user_id, 'a' => $accessory_id, 'loc' => $userLoc ?: null, 'due' => $due_date]);

        $logNote = 'Borrowed via Accessories Portal' . ($due_date ? " (กำหนดคืน: $due_date)" : '');
        $pdo->prepare("INSERT INTO logs (accessory_id, action_type, to_user_id, note) VALUES (:a, 'assign', :u, :n)")
            ->execute(['a' => $accessory_id, 'u' => $user_id, 'n' => $logNote]);

        $pdo->commit();
        redirect($referer, ['msg' => 'assign_success']);

    // ── QUICK ASSIGN ACCESSORY (from Users page) ──────────────
    } elseif ($action === 'assign_accessory_quick') {
        $user_id    = (int)($_POST['user_id'] ?? 0);
        $identifier = trim($_POST['accessory_identifier'] ?? '');
        if (!$user_id || !$identifier) throw new Exception("Missing user ID or accessory identifier.");

        $s = $pdo->prepare("SELECT accessory_id, status FROM accessories WHERE serial_number=:i OR name=:i LIMIT 1");
        $s->execute(['i' => $identifier]);
        $acc = $s->fetch(PDO::FETCH_ASSOC);

        if (!$acc) throw new Exception("Accessory not found: \"$identifier\"");
        if (in_array($acc['status'], ['retired','lost'])) throw new Exception("Cannot assign a retired or lost accessory.");

        // Get user's location
        $uStmt = $pdo->prepare("SELECT location_id FROM users WHERE user_id = :u");
        $uStmt->execute(['u' => $user_id]);
        $userLoc = $uStmt->fetchColumn();

        $pdo->prepare("UPDATE accessories SET user_id=:u, status='deployed', location_id=:loc WHERE accessory_id=:a")
            ->execute(['u' => $user_id, 'a' => $acc['accessory_id'], 'loc' => $userLoc ?: null]);

        $pdo->prepare("INSERT INTO logs (accessory_id, action_type, to_user_id, note) VALUES (:a, 'assign', :u, 'Assigned via Quick Assign')")
            ->execute(['a' => $acc['accessory_id'], 'u' => $user_id]);

        $pdo->commit();
        redirect($referer, ['msg' => 'assign_success']);

    // ── ACCESSORY STATUS UPDATES ──────────────────────────────
    } elseif ($action === 'set_accessory_repair') {
        $id    = (int)($_POST['accessory_id'] ?? 0);
        $shop  = trim($_POST['repair_shop'] ?? '');
        $eta   = !empty($_POST['repair_eta']) ? $_POST['repair_eta'] : null;
        $rnote = trim($_POST['repair_note'] ?? '');
        $pdo->prepare("UPDATE accessories SET status='repair', user_id=NULL,
                       repair_shop=:s, repair_sent_at=DATE('now'), repair_eta=:e, repair_note=:n
                       WHERE accessory_id=:a")
            ->execute(['s' => $shop ?: null, 'e' => $eta, 'n' => $rnote ?: null, 'a' => $id]);
        $logNote = 'Moved to repair status' .
                   ($shop ? " — ร้าน: $shop" : '') .
                   ($eta  ? " — ETA: $eta"   : '') .
                   ($rnote? " — $rnote"      : '');
        $pdo->prepare("INSERT INTO logs (accessory_id, action_type, note) VALUES (:a, 'repair', :n)")
            ->execute(['a' => $id, 'n' => $logNote]);
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    } elseif ($action === 'set_accessory_fixed') {
        $id    = (int)($_POST['accessory_id'] ?? 0);
        $cnote = trim($_POST['completion_note'] ?? '');
        $pdo->prepare("UPDATE accessories SET status='in_stock',
                       repair_shop=NULL, repair_sent_at=NULL, repair_eta=NULL, repair_note=NULL
                       WHERE accessory_id=:a")->execute(['a' => $id]);
        $logNote = 'Repair completed. Moved to stock.' . ($cnote ? " — $cnote" : '');
        $pdo->prepare("INSERT INTO logs (accessory_id, action_type, note) VALUES (:a, 'update', :n)")
            ->execute(['a' => $id, 'n' => $logNote]);
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    } elseif ($action === 'set_accessory_retired') {
        $id = (int)($_POST['accessory_id'] ?? 0);
        $pdo->prepare("UPDATE accessories SET status='retired', user_id=NULL,
                       borrow_due_date=NULL, borrowed_at=NULL,
                       repair_shop=NULL, repair_sent_at=NULL, repair_eta=NULL, repair_note=NULL
                       WHERE accessory_id=:a")->execute(['a' => $id]);
        $pdo->prepare("INSERT INTO logs (accessory_id, action_type, note) VALUES (:a, 'retire', 'Accessory manually retired')")->execute(['a' => $id]);
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── FACTORY RESET (DANGER ZONE) ───────────────────────────
    } elseif ($action === 'factory_reset') {
        $pin = trim($_POST['reset_pin'] ?? '');
        if (!attempt_login($pin)) {
            redirect('settings.php', ['tab' => 'access', 'error' => 'PIN สำหรับ Factory Reset ไม่ถูกต้อง']);
        }
        
        $pdo->commit();
        $pdo = null;
        
        $db_file = __DIR__ . '/db/it_assets.db';
        if (file_exists($db_file)) {
            unlink($db_file);
        }
        
        header("Location: init_db.php");
        exit;

    // ── COLUMN DESIGNER: SAVE VISIBILITY + ORDER ─────────────
    } elseif ($action === 'save_column_settings') {
        require_once 'includes/column_helper.php';
        // Receives: columns[] = array of col_key in display order, visible[] = array of visible col_keys, advanced[] = array of advanced col_keys
        $orderedKeys = $_POST['columns'] ?? [];
        $visibleKeys = $_POST['visible']  ?? [];
        $advancedKeys = $_POST['advanced'] ?? [];

        if (!is_array($orderedKeys)) throw new Exception("Invalid column data.");

        $stmt = $pdo->prepare("UPDATE column_settings SET col_visible=:v, is_advanced=:a, col_order=:o WHERE col_key=:k");
        foreach ($orderedKeys as $idx => $key) {
            $key = trim($key);
            if (empty($key)) continue;
            $stmt->execute([
                'v' => in_array($key, $visibleKeys) ? 1 : 0,
                'a' => in_array($key, $advancedKeys) ? 1 : 0,
                'o' => ($idx + 1) * 10,
                'k' => $key,
            ]);
        }

        $pdo->commit();
        redirect('settings.php', ['tab' => 'columns', 'msg' => 'success']);

    // ── COLUMN DESIGNER: RENAME COLUMN LABEL ─────────────────
    } elseif ($action === 'rename_column') {
        require_once 'includes/column_helper.php';
        $col_key   = trim($_POST['col_key']   ?? '');
        $col_label = trim($_POST['col_label'] ?? '');

        if (empty($col_key) || empty($col_label)) throw new Exception("Key and label are required.");
        if (strlen($col_label) > 60) throw new Exception("Label too long (max 60 chars).");

        $s = $pdo->prepare("SELECT col_key FROM column_settings WHERE col_key = :k");
        $s->execute(['k' => $col_key]);
        if (!$s->fetchColumn()) throw new Exception("Column not found.");

        $pdo->prepare("UPDATE column_settings SET col_label=:l WHERE col_key=:k")
            ->execute(['l' => $col_label, 'k' => $col_key]);

        $pdo->commit();
        redirect('settings.php', ['tab' => 'columns', 'msg' => 'success']);

    // ── COLUMN DESIGNER: ADD CUSTOM COLUMN ───────────────────
    } elseif ($action === 'add_custom_column') {
        require_once 'includes/column_helper.php';
        $raw_label = trim($_POST['col_label'] ?? '');
        $col_type  = $_POST['col_type'] ?? 'text';

        if (empty($raw_label)) throw new Exception("Column label is required.");
        if (!in_array($col_type, ['text','number','date'])) throw new Exception("Invalid column type.");

        $col_key = sanitizeCustomColKey($raw_label);

        // Check uniqueness
        $s = $pdo->prepare("SELECT col_key FROM column_settings WHERE col_key = :k");
        $s->execute(['k' => $col_key]);
        if ($s->fetchColumn()) throw new Exception("A column with that name already exists (key: $col_key).");

        // Check if physical column already exists in assets table
        if (assetColumnExists($pdo, $col_key)) throw new Exception("A physical column '$col_key' already exists in the database.");

        // Add physical column to assets table
        $safeKey = preg_replace('/[^a-z0-9_]/', '', $col_key); // Double-sanitize before DDL
        $colDef  = ($col_type === 'number') ? 'NUMERIC' : 'TEXT';
        $pdo->exec("ALTER TABLE assets ADD COLUMN {$safeKey} {$colDef}");

        // Get max order
        $maxOrder = (int)$pdo->query("SELECT MAX(col_order) FROM column_settings")->fetchColumn();

        // Register in column_settings
        $pdo->prepare("INSERT INTO column_settings (col_key, col_label, col_visible, col_order, is_system, col_type, is_advanced) VALUES (:k,:l,1,:o,0,:t,0)")
            ->execute(['k' => $col_key, 'l' => $raw_label, 'o' => $maxOrder + 10, 't' => $col_type]);

        $pdo->commit();
        redirect('settings.php', ['tab' => 'columns', 'msg' => 'success']);

    // ── COLUMN DESIGNER: DELETE CUSTOM COLUMN ────────────────
    } elseif ($action === 'delete_custom_column') {
        require_once 'includes/column_helper.php';
        $col_key = trim($_POST['col_key'] ?? '');

        if (empty($col_key)) throw new Exception("Column key missing.");

        // Verify it is a custom column (not system)
        $s = $pdo->prepare("SELECT is_system FROM column_settings WHERE col_key = :k");
        $s->execute(['k' => $col_key]);
        $row = $s->fetch(PDO::FETCH_ASSOC);

        if (!$row) throw new Exception("Column not found.");
        if ((int)$row['is_system'] === 1) throw new Exception("Cannot delete a system column.");

        // Remove from column_settings (the physical column stays but becomes unreferenced — SQLite doesn't support DROP COLUMN easily in older versions)
        // For SQLite >= 3.35 we can DROP, otherwise we just hide it.
        $sqliteVersion = $pdo->query("SELECT sqlite_version()")->fetchColumn();
        $canDrop = version_compare($sqliteVersion, '3.35.0', '>=');

        if ($canDrop) {
            $safeKey = preg_replace('/[^a-z0-9_]/', '', $col_key);
            $pdo->exec("ALTER TABLE assets DROP COLUMN {$safeKey}");
        }
        // Whether or not we dropped the physical column, remove from settings
        $pdo->prepare("DELETE FROM column_settings WHERE col_key = :k AND is_system = 0")
            ->execute(['k' => $col_key]);

        $pdo->commit();
        redirect('settings.php', ['tab' => 'columns', 'msg' => 'success']);

    // ── BULK: UPDATE LOCATION ─────────────────────────────────
    } elseif ($action === 'bulk_update_location') {
        $asset_ids_raw = $_POST['asset_ids'] ?? '';
        $location_id   = (int)($_POST['location_id'] ?? 0);
        $asset_ids = array_filter(array_map('intval', explode(',', $asset_ids_raw)));
        if (empty($asset_ids)) throw new Exception("No assets selected.");

        $locName = 'Unknown Location';
        if ($location_id) {
            $ls = $pdo->prepare("SELECT location_name FROM locations WHERE location_id = :l");
            $ls->execute(['l' => $location_id]);
            $locName = $ls->fetchColumn() ?: 'Unknown Location';
        }
        $note = "Bulk location updated to '$locName'";

        $stmt = $pdo->prepare("UPDATE assets SET location_id=:loc WHERE asset_id=:a");
        $logStmt = $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, 'update', :n)");
        foreach ($asset_ids as $aid) {
            $stmt->execute(['loc' => $location_id ?: null, 'a' => $aid]);
            $logStmt->execute(['a' => $aid, 'n' => $note]);
        }
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── BULK: UPDATE STATUS ───────────────────────────────────
    } elseif ($action === 'bulk_update_status') {
        $asset_ids_raw = $_POST['asset_ids'] ?? '';
        $status        = $_POST['status'] ?? '';
        $asset_ids = array_filter(array_map('intval', explode(',', $asset_ids_raw)));
        if (empty($asset_ids)) throw new Exception("No assets selected.");
        if (!in_array($status, ['in_stock','deployed','repair','retired','lost'])) throw new Exception("Invalid status.");
        if ($status === 'deployed') throw new Exception("ใช้ Bulk → Assign User แทน (ต้องระบุผู้ใช้)");

        // Clear assignment/borrow when leaving deployed; clear repair fields when leaving repair.
        $resetCols = "status=:st, user_id=NULL, borrow_due_date=NULL, borrowed_at=NULL";
        if ($status !== 'repair') {
            $resetCols .= ", repair_shop=NULL, repair_sent_at=NULL, repair_eta=NULL, repair_note=NULL";
        }
        $stmt    = $pdo->prepare("UPDATE assets SET $resetCols WHERE asset_id=:a");
        $actionType = match($status) { 'repair'=>'repair', 'retired'=>'retire', 'lost'=>'retire', default=>'update' };
        $logStmt = $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, :act, :n)");
        foreach ($asset_ids as $aid) {
            $stmt->execute(['st' => $status, 'a' => $aid]);
            $logStmt->execute(['a' => $aid, 'act' => $actionType, 'n' => "Bulk status → $status"]);
        }
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── BULK ACCESSORY: UPDATE LOCATION ───────────────────────
    } elseif ($action === 'bulk_update_accessory_location') {
        $ids_raw = $_POST['accessory_ids'] ?? '';
        $location_id = (int)($_POST['location_id'] ?? 0);
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));
        if (empty($ids)) throw new Exception("No accessories selected.");

        $locName = 'Unknown Location';
        if ($location_id) {
            $ls = $pdo->prepare("SELECT location_name FROM locations WHERE location_id = :l");
            $ls->execute(['l' => $location_id]);
            $locName = $ls->fetchColumn() ?: 'Unknown Location';
        }
        $note = "Bulk location updated to '$locName'";

        $stmt = $pdo->prepare("UPDATE accessories SET location_id=:loc WHERE accessory_id=:a");
        $logStmt = $pdo->prepare("INSERT INTO logs (accessory_id, action_type, note) VALUES (:a, 'update', :n)");
        foreach ($ids as $aid) {
            $stmt->execute(['loc' => $location_id ?: null, 'a' => $aid]);
            $logStmt->execute(['a' => $aid, 'n' => $note]);
        }
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── BULK ACCESSORY: UPDATE STATUS ─────────────────────────
    } elseif ($action === 'bulk_update_accessory_status') {
        $ids_raw = $_POST['accessory_ids'] ?? '';
        $status  = $_POST['status'] ?? '';
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));
        if (empty($ids)) throw new Exception("No accessories selected.");
        if (!in_array($status, ['in_stock','deployed','repair','retired','lost'])) throw new Exception("Invalid status.");
        if ($status === 'deployed') throw new Exception("ใช้ Bulk → Assign User แทน (ต้องระบุผู้ใช้)");

        $resetCols = "status=:st, user_id=NULL, borrow_due_date=NULL, borrowed_at=NULL";
        if ($status !== 'repair') {
            $resetCols .= ", repair_shop=NULL, repair_sent_at=NULL, repair_eta=NULL, repair_note=NULL";
        }
        $stmt = $pdo->prepare("UPDATE accessories SET $resetCols WHERE accessory_id=:a");
        $actionType = match($status) { 'repair'=>'repair', 'retired'=>'retire', 'lost'=>'retire', default=>'update' };
        $logStmt = $pdo->prepare("INSERT INTO logs (accessory_id, action_type, note) VALUES (:a, :act, :n)");
        foreach ($ids as $aid) {
            $stmt->execute(['st' => $status, 'a' => $aid]);
            $logStmt->execute(['a' => $aid, 'act' => $actionType, 'n' => "Bulk status → $status"]);
        }
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── BULK ACCESSORY: ASSIGN USER ───────────────────────────
    } elseif ($action === 'bulk_assign_accessory_user') {
        $ids_raw = $_POST['accessory_ids'] ?? '';
        $user_id = (int)($_POST['user_id'] ?? 0);
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));
        if (empty($ids)) throw new Exception("No accessories selected.");
        if (!$user_id) throw new Exception("User required.");
        
        $uStmt = $pdo->prepare("SELECT location_id FROM users WHERE user_id = :u");
        $uStmt->execute(['u' => $user_id]);
        $userLoc = $uStmt->fetchColumn();
        
        $stmt = $pdo->prepare("UPDATE accessories SET user_id=:u, status='deployed', location_id=:loc WHERE accessory_id=:a");
        $logStmt = $pdo->prepare("INSERT INTO logs (accessory_id, action_type, to_user_id, note) VALUES (:a, 'assign', :u, 'Bulk assigned via Accessories table')");
        $retLogStmt = $pdo->prepare("INSERT INTO logs (accessory_id, action_type, from_user_id, note) VALUES (:a, 'return', :old_u, 'Implicit return on bulk reassignment')");
        
        foreach ($ids as $aid) {
            // Check if held by someone else
            $checkStmt = $pdo->prepare("SELECT user_id FROM accessories WHERE accessory_id = :a");
            $checkStmt->execute(['a' => $aid]);
            $oldUser = $checkStmt->fetchColumn();
            
            $stmt->execute(['u' => $user_id, 'loc' => $userLoc ?: null, 'a' => $aid]);
            
            if ($oldUser && $oldUser != $user_id) {
                $retLogStmt->execute(['a' => $aid, 'old_u' => $oldUser]);
            }
            $logStmt->execute(['a' => $aid, 'u' => $user_id]);
        }
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── BULK: ASSIGN USER ─────────────────────────────────────
    } elseif ($action === 'bulk_assign_user') {
        $asset_ids_raw = $_POST['asset_ids'] ?? '';
        $user_id       = (int)($_POST['user_id'] ?? 0);
        $asset_ids = array_filter(array_map('intval', explode(',', $asset_ids_raw)));
        if (empty($asset_ids)) throw new Exception("No assets selected.");
        if (!$user_id) throw new Exception("User required.");

        $uStmt = $pdo->prepare("SELECT location_id FROM users WHERE user_id = :u");
        $uStmt->execute(['u' => $user_id]);
        $userLoc = $uStmt->fetchColumn();

        $stmt = $pdo->prepare("UPDATE assets SET user_id=:u, status='deployed', location_id=:loc WHERE asset_id=:a");
        $logStmt = $pdo->prepare("INSERT INTO logs (asset_id, action_type, to_user_id, note) VALUES (:a, 'assign', :u, 'Bulk assigned via Assets table')");
        $retLogStmt = $pdo->prepare("INSERT INTO logs (asset_id, action_type, from_user_id, note) VALUES (:a, 'return', :old_u, 'Implicit return on bulk reassignment')");
        
        foreach ($asset_ids as $aid) {
            // Check if held by someone else
            $checkStmt = $pdo->prepare("SELECT user_id FROM assets WHERE asset_id = :a");
            $checkStmt->execute(['a' => $aid]);
            $oldUser = $checkStmt->fetchColumn();
            
            $stmt->execute(['u' => $user_id, 'loc' => $userLoc ?: null, 'a' => $aid]);
            
            if ($oldUser && $oldUser != $user_id) {
                $retLogStmt->execute(['a' => $aid, 'old_u' => $oldUser]);
            }
            $logStmt->execute(['a' => $aid, 'u' => $user_id]);
        }
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── BULK: BATCH EDIT ASSETS ───────────────────────────────
    } elseif ($action === 'bulk_edit_assets') {
        $ids_raw = $_POST['asset_ids'] ?? '';
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));
        if (empty($ids)) throw new Exception("No assets selected.");
        
        $category      = trim($_POST['category'] ?? '');
        $purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $eol_date      = !empty($_POST['end_of_life_date']) ? $_POST['end_of_life_date'] : null;
        $po_number     = trim($_POST['po_number'] ?? '');
        
        $updates = [];
        $params = [];
        if ($category !== '') { $updates[] = "category=:c"; $params['c'] = $category; }
        if ($purchase_date !== null) { $updates[] = "purchase_date=:pd"; $params['pd'] = $purchase_date; }
        if ($eol_date !== null) { $updates[] = "end_of_life_date=:eol"; $params['eol'] = $eol_date; }
        if ($po_number !== '') { $updates[] = "po_number=:po"; $params['po'] = $po_number; }
        
        if (empty($updates)) throw new Exception("No fields to update.");
        
        $setClause = implode(', ', $updates);
        $stmt = $pdo->prepare("UPDATE assets SET $setClause WHERE asset_id=:a");
        $logStmt = $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, 'update', 'Batch Edit applied: updated fields')");
        
        foreach ($ids as $aid) {
            $p = $params;
            $p['a'] = $aid;
            $stmt->execute($p);
            $logStmt->execute(['a' => $aid]);
        }
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── BULK: BATCH EDIT ACCESSORIES ──────────────────────────
    } elseif ($action === 'bulk_edit_accessories') {
        $ids_raw = $_POST['accessory_ids'] ?? '';
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));
        if (empty($ids)) throw new Exception("No accessories selected.");
        
        $category      = trim($_POST['category'] ?? '');
        $purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $eol_date      = !empty($_POST['end_of_life_date']) ? $_POST['end_of_life_date'] : null;
        $po_number     = trim($_POST['po_number'] ?? '');
        
        $updates = [];
        $params = [];
        if ($category !== '') { $updates[] = "category=:c"; $params['c'] = $category; }
        if ($purchase_date !== null) { $updates[] = "purchase_date=:pd"; $params['pd'] = $purchase_date; }
        if ($eol_date !== null) { $updates[] = "end_of_life_date=:eol"; $params['eol'] = $eol_date; }
        if ($po_number !== '') { $updates[] = "po_number=:po"; $params['po'] = $po_number; }
        
        if (empty($updates)) throw new Exception("No fields to update.");
        
        $setClause = implode(', ', $updates);
        $stmt = $pdo->prepare("UPDATE accessories SET $setClause WHERE accessory_id=:a");
        $logStmt = $pdo->prepare("INSERT INTO logs (accessory_id, action_type, note) VALUES (:a, 'update', 'Batch Edit applied: updated fields')");
        
        foreach ($ids as $aid) {
            $p = $params;
            $p['a'] = $aid;
            $stmt->execute($p);
            $logStmt->execute(['a' => $aid]);
        }
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── BULK: DELETE ASSETS ───────────────────────────────────
    } elseif ($action === 'bulk_delete_assets') {
        $ids_raw = $_POST['asset_ids'] ?? '';
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));
        if (empty($ids)) throw new Exception("No assets selected.");
        
        $reason = trim($_POST['delete_reason'] ?? '');
        if (empty($reason)) throw new Exception("Delete reason is required.");
        
        // Use same robust deletion logic
        $sel = $pdo->prepare("SELECT * FROM assets WHERE asset_id = :a");
        $logIns = $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (NULL, 'retire', :n)");
        $clearLogs = $pdo->prepare("UPDATE logs SET asset_id = NULL WHERE asset_id = :a");
        $clearAccRel = $pdo->prepare("UPDATE accessories SET parent_asset_id = NULL WHERE parent_asset_id = :a");
        
        $getAtt = $pdo->prepare("SELECT stored_name FROM asset_attachments WHERE asset_id = :a");
        $delAttDb = $pdo->prepare("DELETE FROM asset_attachments WHERE asset_id = :a");
        
        $clearRelPar = $pdo->prepare("DELETE FROM asset_relations WHERE parent_asset_id = :a");
        $clearRelChi = $pdo->prepare("DELETE FROM asset_relations WHERE child_asset_id = :a");
        
        $delAsset = $pdo->prepare("DELETE FROM assets WHERE asset_id = :a");
        
        foreach ($ids as $aid) {
            $sel->execute(['a' => $aid]);
            $a = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$a) continue;
            
            $snapshotInfo = "[PERMANENT DELETE] Bulk deleted Asset #{$a['asset_id']} | Hostname: {$a['hostname']} | SN: {$a['serial_number']} | Model: {$a['model']} | Owner: {$a['user_id']} | Reason: $reason";
            $logIns->execute(['n' => $snapshotInfo]);
            
            $clearLogs->execute(['a' => $aid]);
            $clearAccRel->execute(['a' => $aid]);
            $clearRelPar->execute(['a' => $aid]);
            $clearRelChi->execute(['a' => $aid]);
            
            $getAtt->execute(['a' => $aid]);
            $files = $getAtt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($files as $f) {
                $p = __DIR__ . '/uploads/attachments/' . $f;
                if (file_exists($p)) @unlink($p);
            }
            $delAttDb->execute(['a' => $aid]);
            $delAsset->execute(['a' => $aid]);
        }
        $pdo->commit();
        redirect($referer, ['msg' => 'deleted']);

    // ── BULK: DELETE ACCESSORIES ──────────────────────────────
    } elseif ($action === 'bulk_delete_accessories') {
        $ids_raw = $_POST['accessory_ids'] ?? '';
        $ids = array_filter(array_map('intval', explode(',', $ids_raw)));
        if (empty($ids)) throw new Exception("No accessories selected.");
        
        $reason = trim($_POST['delete_reason'] ?? '');
        if (empty($reason)) throw new Exception("Delete reason is required.");
        
        $sel = $pdo->prepare("SELECT * FROM accessories WHERE accessory_id = :a");
        $logIns = $pdo->prepare("INSERT INTO logs (accessory_id, action_type, note) VALUES (NULL, 'retire', :n)");
        $clearLogs = $pdo->prepare("UPDATE logs SET accessory_id = NULL WHERE accessory_id = :a");
        
        $getAtt = $pdo->prepare("SELECT stored_name FROM asset_attachments WHERE accessory_id = :a");
        $delAttDb = $pdo->prepare("DELETE FROM asset_attachments WHERE accessory_id = :a");
        
        $delAcc = $pdo->prepare("DELETE FROM accessories WHERE accessory_id = :a");
        
        foreach ($ids as $aid) {
            $sel->execute(['a' => $aid]);
            $a = $sel->fetch(PDO::FETCH_ASSOC);
            if (!$a) continue;
            
            $snapshotInfo = "[PERMANENT DELETE] Bulk deleted Accessory #{$a['accessory_id']} | Name: {$a['name']} | SN: {$a['serial_number']} | Model: {$a['model']} | Owner: {$a['user_id']} | Reason: $reason";
            $logIns->execute(['n' => $snapshotInfo]);
            
            $clearLogs->execute(['a' => $aid]);
            
            $getAtt->execute(['a' => $aid]);
            $files = $getAtt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($files as $f) {
                $p = __DIR__ . '/uploads/attachments/' . $f;
                if (file_exists($p)) @unlink($p);
            }
            $delAttDb->execute(['a' => $aid]);
            $delAcc->execute(['a' => $aid]);
        }
        $pdo->commit();
        redirect($referer, ['msg' => 'deleted']);

    // ── MAP: SAVE PIN POSITION ────────────────────────────────
    } elseif ($action === 'save_pin_position') {
        $map_id      = (int)($_POST['map_id']      ?? 0);
        $group_key   = trim($_POST['group_key']    ?? '');
        $location_id = (int)($_POST['location_id'] ?? 0);
        $pin_x       = (float)($_POST['pin_x']     ?? 0);
        $pin_y       = (float)($_POST['pin_y']     ?? 0);

        if (!$map_id || !$group_key || !$location_id) throw new Exception("Missing pin data.");

        $pdo->prepare("
            INSERT INTO map_pin_positions (map_id, group_key, location_id, pin_x, pin_y)
            VALUES (:m, :g, :l, :x, :y)
            ON CONFLICT(map_id, group_key) DO UPDATE SET pin_x=:x, pin_y=:y
        ")->execute(['m'=>$map_id,'g'=>$group_key,'l'=>$location_id,'x'=>$pin_x,'y'=>$pin_y]);

        $pdo->commit();
        // JSON response for AJAX
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    // ── SOFTWARE: ADD SOFTWARE ────────────────────────────────
    } elseif ($action === 'add_software') {
        $name     = trim($_POST['name'] ?? '');
        $vendor   = trim($_POST['vendor'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $sw_note  = trim($_POST['software_note'] ?? '');
        
        $batch_name     = trim($_POST['batch_name'] ?? '');
        $license_type   = $_POST['license_type'] ?? 'Per User';
        $purchase_order = trim($_POST['purchase_order'] ?? '');
        $purchase_date  = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $batch_note     = trim($_POST['batch_note'] ?? '');
        
        $qty            = max(1, (int)($_POST['qty'] ?? 1));
        $start_date     = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $expiry_date    = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $keys_raw       = trim($_POST['license_keys'] ?? '');
        
        if (empty($name) || empty($batch_name)) throw new Exception("Software name and batch name are required.");
        
        // Split keys by newline, ignore empty
        $keys = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $keys_raw))));
        
        $pdo->prepare("INSERT INTO software (name, vendor, category, note) VALUES (:n, :v, :c, :note)")
            ->execute(['n'=>$name, 'v'=>$vendor, 'c'=>$category, 'note'=>$sw_note]);
        $software_id = $pdo->lastInsertId();
        
        $pdo->prepare("INSERT INTO software_batches (software_id, batch_name, license_type, purchase_order, purchase_date, note) VALUES (:s, :b, :t, :po, :pd, :note)")
            ->execute(['s'=>$software_id, 'b'=>$batch_name, 't'=>$license_type, 'po'=>$purchase_order, 'pd'=>$purchase_date, 'note'=>$batch_note]);
        $batch_id = $pdo->lastInsertId();
        
        $licStmt = $pdo->prepare("INSERT INTO software_licenses (batch_id, license_key, start_date, expiry_date) VALUES (:b, :k, :sd, :ed)");
        $logStmt = $pdo->prepare("INSERT INTO software_license_logs (license_id, action, note) VALUES (:l, 'create', 'Initial creation')");
        
        for ($i = 0; $i < $qty; $i++) {
            $key = $keys[$i] ?? null; // Use provided key if available, else null
            $licStmt->execute(['b'=>$batch_id, 'k'=>$key, 'sd'=>$start_date, 'ed'=>$expiry_date]);
            $logStmt->execute(['l'=>$pdo->lastInsertId()]);
        }
        
        $pdo->commit();
        redirect('software.php', ['msg' => 'success']);

    // ── SOFTWARE: ADD BATCH ───────────────────────────────────
    } elseif ($action === 'add_batch') {
        $software_id    = (int)($_POST['software_id'] ?? 0);
        $batch_name     = trim($_POST['batch_name'] ?? '');
        $license_type   = $_POST['license_type'] ?? 'Per User';
        $purchase_order = trim($_POST['purchase_order'] ?? '');
        $purchase_date  = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $batch_note     = trim($_POST['batch_note'] ?? '');
        
        $qty            = max(1, (int)($_POST['qty'] ?? 1));
        $start_date     = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $expiry_date    = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $keys_raw       = trim($_POST['license_keys'] ?? '');
        
        if (!$software_id || empty($batch_name)) throw new Exception("Software and batch name are required.");
        
        $keys = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $keys_raw))));
        
        $pdo->prepare("INSERT INTO software_batches (software_id, batch_name, license_type, purchase_order, purchase_date, note) VALUES (:s, :b, :t, :po, :pd, :note)")
            ->execute(['s'=>$software_id, 'b'=>$batch_name, 't'=>$license_type, 'po'=>$purchase_order, 'pd'=>$purchase_date, 'note'=>$batch_note]);
        $batch_id = $pdo->lastInsertId();
        
        $licStmt = $pdo->prepare("INSERT INTO software_licenses (batch_id, license_key, start_date, expiry_date) VALUES (:b, :k, :sd, :ed)");
        $logStmt = $pdo->prepare("INSERT INTO software_license_logs (license_id, action, note) VALUES (:l, 'create', 'Added in new batch')");
        
        for ($i = 0; $i < $qty; $i++) {
            $key = $keys[$i] ?? null;
            $licStmt->execute(['b'=>$batch_id, 'k'=>$key, 'sd'=>$start_date, 'ed'=>$expiry_date]);
            $logStmt->execute(['l'=>$pdo->lastInsertId()]);
        }
        
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── SOFTWARE: ADD LICENSES ────────────────────────────────
    } elseif ($action === 'add_licenses') {
        $batch_id       = (int)($_POST['batch_id'] ?? 0);
        $qty            = max(1, (int)($_POST['qty'] ?? 1));
        $start_date     = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $expiry_date    = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $keys_raw       = trim($_POST['license_keys'] ?? '');
        
        if (!$batch_id) throw new Exception("Batch required.");
        
        $keys = array_filter(array_map('trim', explode("\n", str_replace("\r", "", $keys_raw))));
        
        $licStmt = $pdo->prepare("INSERT INTO software_licenses (batch_id, license_key, start_date, expiry_date) VALUES (:b, :k, :sd, :ed)");
        $logStmt = $pdo->prepare("INSERT INTO software_license_logs (license_id, action, note) VALUES (:l, 'create', 'Added to existing batch')");
        
        for ($i = 0; $i < $qty; $i++) {
            $key = $keys[$i] ?? null;
            $licStmt->execute(['b'=>$batch_id, 'k'=>$key, 'sd'=>$start_date, 'ed'=>$expiry_date]);
            $logStmt->execute(['l'=>$pdo->lastInsertId()]);
        }
        
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── SOFTWARE: EDIT LICENSE ────────────────────────────────
    } elseif ($action === 'edit_license') {
        $license_id = (int)($_POST['license_id'] ?? 0);
        if (!$license_id) throw new Exception("License ID required.");
        
        $s = $pdo->prepare("SELECT * FROM software_licenses WHERE license_id = :l");
        $s->execute(['l' => $license_id]);
        $old = $s->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new Exception("License not found.");
        
        $new_key    = trim($_POST['license_key'] ?? '');
        $new_status = $_POST['status'] ?? 'active';
        $new_start  = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
        $new_expiry = !empty($_POST['expiry_date']) ? $_POST['expiry_date'] : null;
        $new_user   = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : null;
        $new_note   = trim($_POST['note'] ?? '');
        
        $updates = [];
        $logStmt = $pdo->prepare("INSERT INTO software_license_logs (license_id, action, field_name, old_value, new_value) VALUES (:l, 'edit', :f, :ov, :nv)");
        
        // Helper to check and log changes
        $checkChange = function($field, $oldVal, $newVal) use (&$updates, $logStmt, $license_id) {
            if ($oldVal !== $newVal) {
                $updates[$field] = $newVal;
                $logStmt->execute(['l'=>$license_id, 'f'=>$field, 'ov'=>(string)$oldVal, 'nv'=>(string)$newVal]);
            }
        };
        
        $checkChange('license_key', $old['license_key'], $new_key);
        $checkChange('status', $old['status'], $new_status);
        $checkChange('start_date', $old['start_date'], $new_start);
        $checkChange('expiry_date', $old['expiry_date'], $new_expiry);
        $checkChange('user_id', $old['user_id'], $new_user);
        $checkChange('note', $old['note'], $new_note);
        
        // Action-specific log for assignments if user changed
        if ($old['user_id'] !== $new_user) {
            $act = $new_user ? 'assign' : 'unassign';
            $pdo->prepare("INSERT INTO software_license_logs (license_id, action) VALUES (:l, :a)")->execute(['l'=>$license_id, 'a'=>$act]);
            // If newly assigned, make sure status is 'assigned' if it was 'active'
            // Note: status change is already logged by $checkChange('status', ...) above
            if ($new_user && $new_status === 'active') {
                $new_status = 'assigned';
                $updates['status'] = 'assigned'; // Ensure DB update reflects auto-status
            }
        }
        
        if (!empty($updates)) {
            $setClause = implode(', ', array_map(function($f){ return "$f=:$f"; }, array_keys($updates)));
            $stmt = $pdo->prepare("UPDATE software_licenses SET $setClause WHERE license_id=:id");
            $updates['id'] = $license_id;
            $stmt->execute($updates);
        }
        
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── SOFTWARE: RENEW BATCH ─────────────────────────────────
    } elseif ($action === 'renew_batch') {
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        $new_expiry = !empty($_POST['new_expiry_date']) ? $_POST['new_expiry_date'] : null;
        if (!$batch_id || !$new_expiry) throw new Exception("Batch and new expiry date required.");
        
        $s = $pdo->prepare("SELECT license_id, expiry_date FROM software_licenses WHERE batch_id = :b");
        $s->execute(['b' => $batch_id]);
        $licenses = $s->fetchAll(PDO::FETCH_ASSOC);
        
        $updateStmt = $pdo->prepare("UPDATE software_licenses SET expiry_date = :e WHERE license_id = :l");
        $logStmt = $pdo->prepare("INSERT INTO software_license_logs (license_id, action, field_name, old_value, new_value) VALUES (:l, 'renew', 'expiry_date', :ov, :nv)");
        
        foreach ($licenses as $lic) {
            if ($lic['expiry_date'] !== $new_expiry) {
                $updateStmt->execute(['e'=>$new_expiry, 'l'=>$lic['license_id']]);
                $logStmt->execute(['l'=>$lic['license_id'], 'ov'=>(string)$lic['expiry_date'], 'nv'=>$new_expiry]);
            }
        }
        
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── SOFTWARE: EDIT SOFTWARE ───────────────────────────────
    } elseif ($action === 'edit_software') {
        $software_id = (int)($_POST['software_id'] ?? 0);
        if (!$software_id) throw new Exception("Software ID required.");
        
        $name     = trim($_POST['name'] ?? '');
        $vendor   = trim($_POST['vendor'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $note     = trim($_POST['note'] ?? '');
        
        if (empty($name)) throw new Exception("Software name is required.");
        
        $pdo->prepare("UPDATE software SET name=:n, vendor=:v, category=:c, note=:nt WHERE software_id=:id")
            ->execute(['n'=>$name, 'v'=>$vendor, 'c'=>$category, 'nt'=>$note, 'id'=>$software_id]);
        
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── SOFTWARE: EDIT BATCH ──────────────────────────────────
    } elseif ($action === 'edit_batch') {
        $batch_id = (int)($_POST['batch_id'] ?? 0);
        if (!$batch_id) throw new Exception("Batch ID required.");
        
        $batch_name     = trim($_POST['batch_name'] ?? '');
        $license_type   = $_POST['license_type'] ?? 'Per User';
        $purchase_order = trim($_POST['purchase_order'] ?? '');
        $purchase_date  = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
        $note           = trim($_POST['note'] ?? '');
        
        if (empty($batch_name)) throw new Exception("Batch name is required.");
        
        $pdo->prepare("UPDATE software_batches SET batch_name=:b, license_type=:t, purchase_order=:po, purchase_date=:pd, note=:nt WHERE batch_id=:id")
            ->execute(['b'=>$batch_name, 't'=>$license_type, 'po'=>$purchase_order, 'pd'=>$purchase_date, 'nt'=>$note, 'id'=>$batch_id]);
        
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── SOFTWARE: DELETE SOFTWARE ─────────────────────────────
    } elseif ($action === 'delete_software') {
        $software_id = (int)($_POST['software_id'] ?? 0);
        if (!$software_id) throw new Exception("Software ID required.");
        
        // Find licenses to delete logs
        $s = $pdo->prepare("SELECT license_id FROM software_licenses WHERE batch_id IN (SELECT batch_id FROM software_batches WHERE software_id = :s)");
        $s->execute(['s' => $software_id]);
        $lics = $s->fetchAll(PDO::FETCH_COLUMN);
        
        if (!empty($lics)) {
            $in = str_repeat('?,', count($lics) - 1) . '?';
            $pdo->prepare("DELETE FROM software_license_logs WHERE license_id IN ($in)")->execute($lics);
            $pdo->prepare("DELETE FROM software_licenses WHERE license_id IN ($in)")->execute($lics);
        }
        $pdo->prepare("DELETE FROM software_batches WHERE software_id = :s")->execute(['s' => $software_id]);
        $pdo->prepare("DELETE FROM software WHERE software_id = :s")->execute(['s' => $software_id]);
        
        $pdo->commit();
        redirect('software.php', ['msg' => 'success']);

    // ── SOFTWARE: RETURN LICENSE ──────────────────────────────
    } elseif ($action === 'return_license') {
        $license_id = (int)($_POST['license_id'] ?? 0);
        if (!$license_id) throw new Exception("License ID required.");
        
        $pdo->prepare("UPDATE software_licenses SET status = 'active', user_id = NULL WHERE license_id = :id")
            ->execute(['id' => $license_id]);
            
        $pdo->prepare("INSERT INTO software_license_logs (license_id, action, field_name, old_value, new_value) VALUES (:id, 'unassign', 'status', 'assigned', 'active')")
            ->execute(['id' => $license_id]);
            
        $pdo->commit();
        redirect($referer, ['msg' => 'return_success']);
        
    // ── INCOMING: ADD ORDER ──────────────────────────────────
    } elseif ($action === 'add_incoming') {
        $category   = trim($_POST['category'] ?? '');
        $model      = trim($_POST['model'] ?? '');
        $qty        = max(1, (int)($_POST['qty_expected'] ?? 1));
        $vendor     = trim($_POST['vendor'] ?? '');
        $po         = trim($_POST['purchase_order'] ?? '');
        $ownership  = $_POST['ownership_type'] ?? 'Buy';
        $orderedDt  = !empty($_POST['ordered_date']) ? $_POST['ordered_date'] : null;
        $etaDt      = !empty($_POST['eta_date']) ? $_POST['eta_date'] : null;
        $note       = trim($_POST['note'] ?? '');
        if (empty($category)) throw new Exception("Category required.");
        if (!in_array($ownership, ['Buy','Rent','Borrow'])) throw new Exception("Invalid ownership.");

        $pdo->prepare("INSERT INTO incoming_orders (category, model, qty_expected, vendor, purchase_order, ownership_type, ordered_date, eta_date, note)
                       VALUES (:c, :m, :q, :v, :po, :o, :od, :eta, :n)")
            ->execute([
                'c' => $category, 'm' => $model ?: null, 'q' => $qty,
                'v' => $vendor ?: null, 'po' => $po ?: null, 'o' => $ownership,
                'od' => $orderedDt, 'eta' => $etaDt, 'n' => $note ?: null
            ]);
        $pdo->commit();
        redirect('incoming.php', ['msg' => 'incoming_added']);

    // ── INCOMING: EDIT ORDER ─────────────────────────────────
    } elseif ($action === 'edit_incoming') {
        $oid = (int)($_POST['order_id'] ?? 0);
        if (!$oid) throw new Exception("Order ID missing.");
        $cur = $pdo->prepare("SELECT * FROM incoming_orders WHERE order_id=:o");
        $cur->execute(['o' => $oid]);
        $row = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new Exception("Order not found.");
        if ($row['status'] === 'received') throw new Exception("Order already fully received — cannot edit.");

        $pdo->prepare("UPDATE incoming_orders SET
                       category=:c, model=:m, qty_expected=:q,
                       vendor=:v, purchase_order=:po, ownership_type=:o,
                       ordered_date=:od, eta_date=:eta, note=:n
                       WHERE order_id=:oid")
            ->execute([
                'c' => trim($_POST['category'] ?? ''),
                'm' => trim($_POST['model'] ?? '') ?: null,
                'q' => max(1, (int)($_POST['qty_expected'] ?? 1)),
                'v' => trim($_POST['vendor'] ?? '') ?: null,
                'po' => trim($_POST['purchase_order'] ?? '') ?: null,
                'o' => $_POST['ownership_type'] ?? 'Buy',
                'od' => !empty($_POST['ordered_date']) ? $_POST['ordered_date'] : null,
                'eta' => !empty($_POST['eta_date']) ? $_POST['eta_date'] : null,
                'n' => trim($_POST['note'] ?? '') ?: null,
                'oid' => $oid,
            ]);
        $pdo->commit();
        redirect('incoming.php', ['msg' => 'success']);

    // ── INCOMING: PROCEED (receive items, push into stock) ───
    } elseif ($action === 'incoming_proceed') {
        $oid = (int)($_POST['order_id'] ?? 0);
        if (!$oid) throw new Exception("Order ID missing.");

        $cur = $pdo->prepare("SELECT * FROM incoming_orders WHERE order_id=:o");
        $cur->execute(['o' => $oid]);
        $order = $cur->fetch(PDO::FETCH_ASSOC);
        if (!$order) throw new Exception("Order not found.");
        if ($order['status'] === 'received' || $order['status'] === 'cancelled') {
            throw new Exception("Order already closed.");
        }

        $serials   = $_POST['serial'] ?? [];   // optional list
        $hostnames = $_POST['hostname'] ?? []; // optional list
        $count     = 0;
        $maxRows   = max(count($serials), count($hostnames));
        if ($maxRows === 0) throw new Exception("กรุณากรอกอย่างน้อย 1 ชิ้น");

        $insert = $pdo->prepare("INSERT INTO assets (category, hostname, serial_number, service_tag, status, ownership_type, model, purchase_date, po_number)
                                 VALUES (:c, :h, :s, :st, 'in_stock', :o, :m, :pd, :po)");
        $logIns = $pdo->prepare("INSERT INTO logs (asset_id, action_type, note) VALUES (:a, 'import', :n)");

        for ($i = 0; $i < $maxRows; $i++) {
            $sn = trim($serials[$i]   ?? '');
            $hn = trim($hostnames[$i] ?? '');
            if ($sn === '' && $hn === '') continue;
            try {
                $cat = $order['category'];
                $real_sn = $sn ?: null;
                $real_st = null;

                $insert->execute([
                    'c'  => $cat,
                    'h'  => $hn ?: null,
                    's'  => $real_sn,
                    'st' => $real_st,
                    'o'  => $order['ownership_type'],
                    'm'  => $order['model'],
                    'pd' => $order['ordered_date'] ?: $order['eta_date'] ?: date('Y-m-d'),
                    'po' => $order['purchase_order'] ?: null
                ]);
                $newId = $pdo->lastInsertId();
                $logIns->execute(['a' => $newId, 'n' => "Received from incoming order #$oid" . ($order['vendor'] ? " ({$order['vendor']})" : '')]);
                $count++;
            } catch (Exception $e) {
                throw new Exception("Asset #" . ($i+1) . " ผิดพลาด: " . $e->getMessage());
            }
        }
        if ($count === 0) throw new Exception("ไม่มีรายการถูกสร้าง");

        $newReceived = (int)$order['qty_received'] + $count;
        $newStatus = $newReceived >= (int)$order['qty_expected'] ? 'received' : 'partial';
        $pdo->prepare("UPDATE incoming_orders SET qty_received=:q, status=:s WHERE order_id=:o")
            ->execute(['q' => $newReceived, 's' => $newStatus, 'o' => $oid]);

        $pdo->commit();
        redirect('incoming.php', ['msg' => 'received_' . $count]);

    // ── INCOMING: CANCEL ─────────────────────────────────────
    } elseif ($action === 'incoming_cancel') {
        $oid = (int)($_POST['order_id'] ?? 0);
        if (!$oid) throw new Exception("Order ID missing.");
        $pdo->prepare("UPDATE incoming_orders SET status='cancelled' WHERE order_id=:o AND status IN ('ordered','partial')")
            ->execute(['o' => $oid]);
        $pdo->commit();
        redirect('incoming.php', ['msg' => 'success']);

    // ── INCOMING: DELETE ─────────────────────────────────────
    } elseif ($action === 'incoming_delete') {
        $oid = (int)($_POST['order_id'] ?? 0);
        if (!$oid) throw new Exception("Order ID missing.");
        $pdo->prepare("DELETE FROM incoming_orders WHERE order_id=:o")->execute(['o' => $oid]);
        $pdo->commit();
        redirect('incoming.php', ['msg' => 'success']);

    // ── SYSTEM: SAVE ACCESS / QR SETTINGS ────────────────────
    } elseif ($action === 'save_access_settings') {
        $pin     = preg_replace('/[^0-9]/', '', $_POST['edit_pin'] ?? '');
        $minutes = max(5, min(720, (int)($_POST['session_minutes'] ?? 60)));
        $qrUrl   = trim($_POST['qr_base_url'] ?? '');
        // Strip trailing slash for cleanliness
        $qrUrl   = rtrim($qrUrl, '/');
        // Basic sanity on URL (if provided)
        if ($qrUrl !== '' && !preg_match('#^https?://#i', $qrUrl)) {
            throw new Exception('QR Base URL ต้องขึ้นต้นด้วย http:// หรือ https://');
        }
        if (strlen($pin) < 4 || strlen($pin) > 8) {
            throw new Exception('PIN ต้อง 4-8 หลัก (ตัวเลขเท่านั้น)');
        }
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (setting_key, setting_value) VALUES (:k, :v)");
        $stmt->execute(['k' => 'edit_pin',        'v' => $pin]);
        $stmt->execute(['k' => 'session_minutes', 'v' => (string)$minutes]);
        $stmt->execute(['k' => 'qr_base_url',     'v' => $qrUrl]);
        $pdo->commit();
        redirect('settings.php?tab=access', ['msg' => 'success']);

    // ── SYSTEM: SAVE ALERT SETTINGS ──────────────────────────
    } elseif ($action === 'save_alert_settings') {
        $keys = ['lease_alert_days', 'warranty_alert_days', 'software_alert_days', 'consumable_alert_qty'];
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (setting_key, setting_value) VALUES (:k, :v)");
        foreach ($keys as $k) {
            $minVal = ($k === 'consumable_alert_qty') ? 0 : 1;
            $maxVal = ($k === 'consumable_alert_qty') ? 999 : 365;
            $default = ($k === 'consumable_alert_qty') ? 5 : 30;
            $v = max($minVal, min($maxVal, (int)($_POST[$k] ?? $default)));
            $stmt->execute(['k' => $k, 'v' => (string)$v]);
        }
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    // ── HANDOVER PORTAL: CREATE SLIP ──────────────────────────
    } elseif ($action === 'create_handover_slip') {
        $user_id   = (int)($_POST['user_id'] ?? 0);
        $form_type = $_POST['form_type'] ?? 'handover';
        $ticket_no = trim($_POST['ticket_no'] ?? '');
        if (!$user_id) throw new Exception("Missing user ID.");

        $docNo = "IT-" . ($form_type === 'return' ? "RET" : ($form_type === 'resignation' ? "RES" : "ACT")) . "-" . date('Ymd') . "-" . str_pad($user_id, 4, '0', STR_PAD_LEFT);

        $stmt = $pdo->prepare("INSERT OR REPLACE INTO handover_slips (doc_no, user_id, form_type, ticket_no, status) VALUES (:d, :u, :ft, :tk, 'pending_signature')");
        $stmt->execute([
            'd'  => $docNo,
            'u'  => $user_id,
            'ft' => $form_type,
            'tk' => $ticket_no ?: null
        ]);

        $pdo->commit();
        redirect('handover_portal.php', ['msg' => 'created']);

    // ── HANDOVER PORTAL: MARK SIGNED & UPLOAD ─────────────────
    } elseif ($action === 'mark_slip_signed') {
        $slip_id = (int)($_POST['slip_id'] ?? 0);
        $user_id = (int)($_POST['user_id'] ?? 0);
        $note    = trim($_POST['note'] ?? '');
        if (!$slip_id) throw new Exception("Missing slip ID.");

        if (empty($_FILES['file']['tmp_name'])) throw new Exception("No signed document file selected.");
        if ($_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new Exception("Upload failed.");

        $uploadDir = __DIR__ . '/uploads/attachments';
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

        $orig = $_FILES['file']['name'];
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        $allowedExts = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'docx'];
        if (!in_array($ext, $allowedExts, true)) throw new Exception("Invalid file extension.");

        $safe = bin2hex(random_bytes(8)) . '_' . time() . '.' . $ext;
        $dest = $uploadDir . '/' . $safe;
        if (!move_uploaded_file($_FILES['file']['tmp_name'], $dest)) throw new Exception("Cannot save uploaded file.");

        $mime = mime_content_type($dest) ?: ($_FILES['file']['type'] ?? '');
        $size = (int)$_FILES['file']['size'];

        $attStmt = $pdo->prepare("INSERT INTO asset_attachments (user_id, filename, stored_name, mime_type, size_bytes, note) VALUES (:u, :fn, :sn, :mt, :sz, :n)");
        $attStmt->execute(['u' => $user_id ?: null, 'fn' => $orig, 'sn' => $safe, 'mt' => $mime, 'sz' => $size, 'n' => $note ?: 'Signed Handover Slip']);
        $attId = $pdo->lastInsertId();

        $pdo->prepare("UPDATE handover_slips SET status = 'signed', signed_at = CURRENT_TIMESTAMP, signed_file_id = :att WHERE slip_id = :s")
            ->execute(['att' => $attId, 's' => $slip_id]);

        $pdo->commit();
        redirect('handover_portal.php', ['msg' => 'signed']);

    // ── HANDOVER PORTAL: DELETE SLIP ──────────────────────────
    } elseif ($action === 'delete_handover_slip') {
        $slip_id = (int)($_POST['slip_id'] ?? 0);
        if (!$slip_id) throw new Exception("Missing slip ID.");
        $pdo->prepare("DELETE FROM handover_slips WHERE slip_id = :s")->execute(['s' => $slip_id]);
        $pdo->commit();
        redirect('handover_portal.php', ['msg' => 'deleted']);

    // ── BRANDING & LOGO UPLOAD PORTAL ─────────────────────────
    } elseif ($action === 'save_branding_settings') {
        $company_name = trim($_POST['company_name'] ?? 'COMPANY NAME');
        $company_dept = trim($_POST['company_dept'] ?? 'Information Technology Department');

        $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (setting_key, setting_value) VALUES (:k, :v)");
        $stmt->execute(['k' => 'company_name', 'v' => $company_name]);
        $stmt->execute(['k' => 'company_dept', 'v' => $company_dept]);

        // Upload Logo File if provided
        if (!empty($_FILES['logo_file']['tmp_name']) && $_FILES['logo_file']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = __DIR__ . '/uploads/logo';
            if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

            $ext = strtolower(pathinfo($_FILES['logo_file']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
                throw new Exception("Invalid image type for logo (Allowed: PNG, JPG, WEBP, SVG)");
            }

            $logoFileName = 'company_logo_' . time() . '.' . $ext;
            $dest = $uploadDir . '/' . $logoFileName;
            if (move_uploaded_file($_FILES['logo_file']['tmp_name'], $dest)) {
                $stmt->execute(['k' => 'company_logo', 'v' => 'uploads/logo/' . $logoFileName]);
            }
        }

        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    } elseif ($action === 'delete_company_logo') {
        $stmt = $pdo->prepare("INSERT OR REPLACE INTO system_settings (setting_key, setting_value) VALUES ('company_logo', '')");
        $stmt->execute();
        $pdo->commit();
        redirect($referer, ['msg' => 'success']);

    } else {
        throw new Exception("Unknown action: $action");
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    redirect($referer, ['error' => $e->getMessage()]);
}
