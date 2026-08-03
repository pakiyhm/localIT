<?php
// scanner.php — Web-based QR Scanner using device camera
require_once 'includes/db_connect.php';

$pageTitle = 'QR Scanner';
// Standard layout or standalone? Let's use standard layout so it feels integrated
require_once 'includes/layout.php';
?>

<style>
#reader video { object-fit: cover; width: 100%; }
.scanner-container { max-width: 500px; margin: 0 auto; }
.scanner-card { background: #fff; border: 1px solid var(--color-border); border-radius: var(--radius-lg); box-shadow: var(--shadow-sm); margin-bottom: 24px; overflow: hidden; }
.scanner-card-header { background: var(--color-surface); padding: 16px 20px; border-bottom: 1px solid var(--color-border); font-weight: 600; font-size: 1.1rem; display: flex; align-items: center; gap: 8px; }
.info-row { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.9rem; }
.info-label { color: var(--color-text-muted); }
.info-value { font-weight: 600; color: var(--color-text); font-family: monospace; font-size: 1.05rem; }
.info-value.normal-font { font-family: inherit; font-size: 0.95rem; }
.btn-block { display: flex; width: 100%; justify-content: center; align-items: center; padding: 12px; border-radius: var(--radius-md); font-weight: 600; text-decoration: none; margin-bottom: 12px; cursor: pointer; gap: 8px; border: none; font-size: 1rem; transition: background 0.2s;}
.btn-primary-block { background: var(--color-primary); color: #fff; }
.btn-primary-block:hover { background: #1d4ed8; color: #fff; }
.btn-secondary-block { background: var(--color-bg); color: var(--color-text); border: 1px solid var(--color-border); }
.btn-secondary-block:hover { background: var(--color-surface); }
.badge-status { font-size: 0.75rem; font-weight: 700; padding: 4px 10px; border-radius: 99px; text-transform: uppercase; }
.badge-deployed { background: #eff6ff; color: #2563eb; border: 1px solid #bfdbfe; }
.badge-instock { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
.badge-repair { background: #fefce8; color: #ca8a04; border: 1px solid #fef08a; }
.badge-lost { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }
.badge-retired { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
</style>
<div class="scanner-container">
        
    <!-- Manual Input Form -->
    <div class="scanner-card" style="border: 2px solid var(--color-accent);">
        <div class="scanner-card-header" style="background: var(--color-accent); color: white; justify-content: center;">
            <i class="bi bi-search"></i> <?= htmlspecialchars(__('Search Asset ID / Serial')) ?>
        </div>
        <div style="padding: 20px;">
            <form id="manualScanForm" onsubmit="return handleManualScan(event)" data-no-block="1">
                <div style="display: flex; gap: 10px;">
                    <input type="text" id="manualInput" class="form-control" placeholder="<?= htmlspecialchars(__('Enter ID, Serial, or scan label...')) ?>" autofocus style="flex: 1; padding: 12px; font-size: 1.1rem; border-radius: var(--radius-md); border: 2px solid var(--color-border);">
                    <button type="submit" class="btn btn-primary" style="padding: 0 24px; border-radius: var(--radius-md); font-weight: 600;">
                        <?= htmlspecialchars(__('Search')) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Result Container (Hidden by default) -->
    <div id="scan-result" style="display: none;">
        
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h4 id="res-hostname" style="margin: 0; font-weight: 700; color: var(--color-text); font-size: 1.25rem;"></h4>
            <span id="res-status" class="badge-status"></span>
        </div>

        <!-- Asset Details -->
        <div class="scanner-card">
            <div style="padding: 20px;">
                <h6 style="text-transform: uppercase; color: var(--color-text-muted); font-size: 0.8rem; font-weight: 700; letter-spacing: 0.5px; margin: 0 0 15px 0;"><?= htmlspecialchars(__('Asset Details')) ?></h6>
                
                <div class="info-row">
                    <span class="info-label"><?= htmlspecialchars(__('Serial No.')) ?></span>
                    <strong id="res-sn" class="info-value"></strong>
                </div>
                <div class="info-row">
                    <span class="info-label"><?= htmlspecialchars(__('Service Tag')) ?></span>
                    <strong id="res-stag" class="info-value"></strong>
                </div>
                <div class="info-row">
                    <span class="info-label"><?= htmlspecialchars(__('Category')) ?></span>
                    <strong id="res-cat" class="info-value normal-font"></strong>
                </div>
                <div class="info-row">
                    <span class="info-label"><?= htmlspecialchars(__('Ownership')) ?></span>
                    <strong id="res-ownership" class="info-value normal-font"></strong>
                </div>
                <div class="info-row" style="margin-bottom: 0;">
                    <span class="info-label"><?= htmlspecialchars(__('Warranty EOL')) ?></span>
                    <strong id="res-eol" class="info-value normal-font"></strong>
                </div>
            </div>
        </div>

        <!-- Owner Details -->
        <div id="owner-card" class="scanner-card" style="border: 2px solid var(--color-accent);">
            <div style="padding: 20px;">
                <h6 style="text-transform: uppercase; color: var(--color-text-muted); font-size: 0.8rem; font-weight: 700; letter-spacing: 0.5px; margin: 0 0 15px 0;"><?= htmlspecialchars(__('Owner Details')) ?></h6>
                
                <div style="display: flex; align-items: center; margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid var(--color-border-soft);">
                    <div id="res-avatar" style="width: 45px; height: 45px; background: var(--color-accent-lite); color: var(--color-accent); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; font-weight: 700; margin-right: 15px; flex-shrink: 0;">
                        U
                    </div>
                    <div>
                        <div id="res-owner-name" style="font-weight: 700; font-size: 1.1rem; color: var(--color-text);">Name</div>
                        <div id="res-owner-emp" style="color: var(--color-text-muted); font-size: 0.85rem;">EMP000</div>
                    </div>
                </div>

                <div class="info-row">
                    <span class="info-label"><?= htmlspecialchars(__('Department')) ?></span>
                    <strong id="res-dept" class="info-value normal-font"></strong>
                </div>
                <div class="info-row">
                    <span class="info-label"><?= htmlspecialchars(__('Location')) ?></span>
                    <strong id="res-loc" class="info-value normal-font"></strong>
                </div>
                <div class="info-row" style="margin-bottom: 0;">
                    <span class="info-label"><?= htmlspecialchars(__('Start Using')) ?></span>
                    <strong id="res-start" class="info-value normal-font"></strong>
                </div>
            </div>
        </div>

        <!-- No Owner Warning -->
        <div id="no-owner-card" class="scanner-card" style="background: var(--color-bg); border: 1px dashed var(--color-border); text-align: center; display: none;">
            <div style="padding: 30px 20px;">
                <i class="bi bi-person-x" style="font-size: 2.5rem; color: var(--color-text-muted); margin-bottom:10px; display:inline-block;"></i>
                <h5 style="color: var(--color-text); font-weight: 600; margin: 0 0 5px 0;"><?= htmlspecialchars(__('Not Assigned')) ?></h5>
                <p style="color: var(--color-text-muted); font-size: 0.85rem; margin: 0;"><?= htmlspecialchars(__('This asset is currently in stock or under repair.')) ?></p>
            </div>
        </div>

        <!-- Actions -->
        <a href="#" id="btn-history" class="btn-block btn-primary-block">
            <i class="bi bi-clock-history"></i> <?= htmlspecialchars(__('View Full History')) ?>
        </a>
        
        <button onclick="resumeScan()" class="btn-block btn-secondary-block">
            <i class="bi bi-qr-code-scan"></i> <?= htmlspecialchars(__('Scan Another')) ?>
        </button>

    </div>

</div>

<script>

function handleManualScan(e) {
    e.preventDefault();
    const inputVal = document.getElementById('manualInput').value.trim();
    if (!inputVal) return false;
    onScanSuccess(inputVal);
    return false;
}

function resumeScan() {
    document.getElementById('scan-result').style.display = 'none';
    const input = document.getElementById('manualInput');
    input.value = '';
    input.focus();
}

function onScanSuccess(decodedText) {
    let assetId = null;
    
    try {
        let url = new URL(decodedText);
        assetId = url.searchParams.get("asset_id") || url.searchParams.get("id");
    } catch (e) {
        assetId = decodedText;
    }

    if (!assetId) {
        alert("Invalid Input Format.");
        resumeScan();
        return;
    }

    // Fetch data via AJAX
    fetch('ajax_get_asset_details.php?id=' + assetId)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                renderResult(data.data);
            } else {
                alert(data.error || "Asset not found.");
                resumeScan();
            }
        })
        .catch(error => {
            alert("Error fetching asset details.");
            resumeScan();
        });
}

function renderResult(data) {
    document.getElementById('scan-result').style.display = 'block';

    // Populate Asset Info
    document.getElementById('res-hostname').textContent = data.model || data.hostname || data.category;
    document.getElementById('res-sn').textContent = data.serial_number || '-';
    document.getElementById('res-stag').textContent = data.service_tag || '-';
    document.getElementById('res-cat').textContent = data.category || '-';
    document.getElementById('res-ownership').textContent = data.ownership_type || '-';
    
    let eol = data.end_of_life_date ? data.end_of_life_date : '-';
    if (data.is_expired) {
        eol = '<span style="color:#dc2626;"><i class="bi bi-exclamation-circle"></i> Expired</span>';
    }
    document.getElementById('res-eol').innerHTML = eol;

    // Badge
    let badge = document.getElementById('res-status');
    badge.textContent = data.status.replace('_', ' ');
    
    badge.className = 'badge-status ';
    if (data.status === 'in_stock') badge.classList.add('badge-instock');
    else if (data.status === 'deployed') badge.classList.add('badge-deployed');
    else if (data.status === 'repair') badge.classList.add('badge-repair');
    else if (data.status === 'lost') badge.classList.add('badge-lost');
    else badge.classList.add('badge-retired');

    // Populate Owner Info
    if (data.owner) {
        document.getElementById('owner-card').style.display = 'block';
        document.getElementById('no-owner-card').style.display = 'none';

        document.getElementById('res-avatar').textContent = data.owner.full_name.charAt(0).toUpperCase();
        document.getElementById('res-owner-name').textContent = data.owner.full_name;
        document.getElementById('res-owner-emp').textContent = data.owner.emp_id;
        document.getElementById('res-dept').textContent = data.owner.department || '-';
        document.getElementById('res-loc').textContent = data.owner.location || '-';
        document.getElementById('res-start').textContent = data.owner.start_using || '-';
    } else {
        document.getElementById('owner-card').style.display = 'none';
        document.getElementById('no-owner-card').style.display = 'block';
    }

    let btnHistory = document.getElementById('btn-history');
    if (data.is_accessory && !data.parent_asset_id) {
        btnHistory.style.display = 'none';
    } else {
        btnHistory.style.display = 'flex';
        let hid = data.is_accessory ? data.parent_asset_id : data.asset_id;
        btnHistory.href = 'history.php?asset_id=' + hid;
        btnHistory.innerHTML = '<i class="bi bi-clock-history"></i> ' + (data.is_accessory ? '<?= htmlspecialchars(__('View Parent History')) ?>' : '<?= htmlspecialchars(__('View Full History')) ?>');
    }
}
</script>

<?php require_once 'includes/layout_end.php'; ?>
