<?php
// export.php — Centralized Reporting & Export Portal
require_once 'includes/db_connect.php';

// Fetch lists for dropdowns
$categories = $pdo->query("SELECT DISTINCT category FROM assets ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);
$locations = $pdo->query("SELECT location_id, location_name FROM locations ORDER BY location_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = 'Export & Reports';
$activeNav = 'export';
require_once 'includes/layout.php';
?>

<div class="card" style="margin-bottom: 24px; border: 1px solid var(--color-border); box-shadow: var(--shadow-sm);">
    <div class="card-header" style="background:var(--color-surface); padding:20px 24px; border-bottom: 1px solid var(--color-border);">
        <h3 style="margin:0; font-size:1.2rem; display:flex; align-items:center; gap:8px;">
            <i class="bi bi-file-earmark-spreadsheet" style="color:var(--color-accent);"></i> <?= htmlspecialchars(__('Data Export Portal')) ?>
        </h3>
        <p style="margin:4px 0 0; color:var(--color-text-muted); font-size:.9rem;"><?= htmlspecialchars(__('Generate and download CSV reports or print PDF Labels.')) ?></p>
    </div>
    
    <div class="card-body" style="padding: 24px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px;">
            
            <!-- 1. Full Inventory Export -->
            <div style="border:1px solid var(--color-border); border-radius:12px; padding:20px; background:#f8fafc; display:flex; flex-direction:column;">
                <h4 style="margin:0 0 10px 0; font-size:1.05rem; display:flex; align-items:center; gap:8px;">
                    <i class="bi bi-hdd-stack" style="color:#2563eb;"></i> <?= htmlspecialchars(__('All Devices (Full Inventory)')) ?>
                </h4>
                <p style="font-size:.85rem; color:#64748b; margin-bottom:16px; flex-grow:1;"><?= htmlspecialchars(__('Export a complete list of all device assets and accessories, sorted by type and status.')) ?></p>
                <form action="export_handler.php" method="GET" style="margin:0; display:flex; gap:10px;">
                    <input type="hidden" name="type" value="full_inventory">
                    <button type="submit" class="btn btn-primary" style="flex:1;"><i class="bi bi-filetype-csv"></i> <?= htmlspecialchars(__('Download CSV')) ?></button>
                    <button type="button" onclick="window.open('print_labels.php?type=all', '_blank')" class="btn btn-ghost" style="border:1px solid var(--color-border); background:#fff;"><i class="bi bi-printer"></i> <?= htmlspecialchars(__('Print All Labels')) ?></button>
                </form>
            </div>

            <!-- 2. By Category -->
            <div style="border:1px solid var(--color-border); border-radius:12px; padding:20px; background:#f8fafc; display:flex; flex-direction:column;">
                <h4 style="margin:0 0 10px 0; font-size:1.05rem; display:flex; align-items:center; gap:8px;">
                    <i class="bi bi-tags" style="color:#059669;"></i> <?= htmlspecialchars(__('Export By Category')) ?>
                </h4>
                <p style="font-size:.85rem; color:#64748b; margin-bottom:16px; flex-grow:1;"><?= htmlspecialchars(__('Select specific categories (e.g. Laptops, Monitors) to export their details.')) ?></p>
                <form action="export_handler.php" method="GET" style="margin:0; display:flex; flex-direction:column; gap:10px;">
                    <input type="hidden" name="type" value="by_category">
                    <select name="category" class="form-control form-select" required>
                        <option value="">— <?= htmlspecialchars(__('Select Category')) ?> —</option>
                        <option value="ALL_ASSETS">-- <?= htmlspecialchars(__('All Core Assets')) ?> --</option>
                        <option value="ALL_ACCESSORIES">-- <?= htmlspecialchars(__('All Accessories')) ?> --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div style="display:flex; gap:10px;">
                        <button type="submit" class="btn btn-primary" style="flex:1; background:#059669; border-color:#059669;"><i class="bi bi-filetype-csv"></i> <?= htmlspecialchars(__('Download CSV')) ?></button>
                        <button type="button" onclick="const cat = this.form.category.value; if(cat) window.open('print_labels.php?cat='+encodeURIComponent(cat), '_blank');" class="btn btn-ghost" style="border:1px solid var(--color-border); background:#fff;"><i class="bi bi-printer"></i> <?= htmlspecialchars(__('Labels')) ?></button>
                    </div>
                </form>
            </div>

            <!-- 3. Custom Export -->
            <div style="border:1px solid var(--color-border); border-radius:12px; padding:20px; background:#f8fafc; display:flex; flex-direction:column;">
                <h4 style="margin:0 0 10px 0; font-size:1.05rem; display:flex; align-items:center; gap:8px;">
                    <i class="bi bi-funnel" style="color:#d97706;"></i> <?= htmlspecialchars(__('Custom Filter Export')) ?>
                </h4>
                <p style="font-size:.85rem; color:#64748b; margin-bottom:16px; flex-grow:1;"><?= htmlspecialchars(__('Filter inventory by specific status, ownership type, or location.')) ?></p>
                <form action="export_handler.php" method="GET" style="margin:0; display:flex; flex-direction:column; gap:10px;">
                    <input type="hidden" name="type" value="custom">
                    
                    <div style="display:flex; gap:8px;">
                        <input type="text" name="search" class="form-control" placeholder="<?= htmlspecialchars(__('Search Hostname or Serial...')) ?>" style="flex:1;">
                    </div>
                    <select name="category" class="form-control form-select">
                        <option value="all"><?= htmlspecialchars(__('All Categories')) ?></option>
                        <option value="ALL_ASSETS">-- <?= htmlspecialchars(__('All Core Assets')) ?> --</option>
                        <option value="ALL_ACCESSORIES">-- <?= htmlspecialchars(__('All Accessories')) ?> --</option>
                        <?php foreach ($categories as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div style="display:flex; gap:8px;">
                        <select name="status" class="form-control form-select" style="flex:1;">
                            <option value="all"><?= htmlspecialchars(__('All Statuses')) ?></option>
                            <option value="in_stock"><?= htmlspecialchars(__('In Stock')) ?></option>
                            <option value="deployed"><?= htmlspecialchars(__('Deployed')) ?></option>
                            <option value="repair"><?= htmlspecialchars(__('Repair')) ?></option>
                            <option value="retired"><?= htmlspecialchars(__('Retired')) ?></option>
                            <option value="lost"><?= htmlspecialchars(__('Lost')) ?></option>
                        </select>
                        <select name="ownership" class="form-control form-select" style="flex:1;">
                            <option value="all"><?= htmlspecialchars(__('All Ownership')) ?></option>
                            <option value="Buy">Buy</option>
                            <option value="Rent">Rent</option>
                            <option value="Borrow">Borrow</option>
                        </select>
                    </div>
                    <select name="location_id" class="form-control form-select">
                        <option value="all"><?= htmlspecialchars(__('All Locations')) ?></option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= $loc['location_id'] ?>"><?= htmlspecialchars($loc['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <div style="display:flex; gap:10px;">
                        <button type="submit" class="btn btn-primary" style="flex:1; background:#d97706; border-color:#d97706;"><i class="bi bi-filetype-csv"></i> <?= htmlspecialchars(__('Download CSV')) ?></button>
                        <button type="button" onclick="const f=this.form; window.open(`print_labels.php?custom=1&search=${f.search.value}&cat=${f.category.value}&status=${f.status.value}&ownership=${f.ownership.value}&loc=${f.location_id.value}`, '_blank');" class="btn btn-ghost" style="border:1px solid var(--color-border); background:#fff;"><i class="bi bi-printer"></i> <?= htmlspecialchars(__('Labels')) ?></button>
                    </div>
                </form>
            </div>
            
        </div>
    </div>
</div>

<div class="card" style="border: 1px solid var(--color-border); box-shadow: var(--shadow-sm);">
    <div class="card-header" style="background:var(--color-surface); padding:20px 24px; border-bottom: 1px solid var(--color-border);">
        <h3 style="margin:0; font-size:1.2rem; display:flex; align-items:center; gap:8px;">
            <i class="bi bi-file-earmark-person" style="color:var(--color-accent);"></i> <?= htmlspecialchars(__('HR & People Reports')) ?>
        </h3>
    </div>
    
    <div class="card-body" style="padding: 24px;">
        <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px;">
            
            <!-- 4. Employee List -->
            <div style="border:1px solid var(--color-border); border-radius:12px; padding:20px; background:#f8fafc; display:flex; flex-direction:column;">
                <h4 style="margin:0 0 10px 0; font-size:1.05rem; display:flex; align-items:center; gap:8px;">
                    <i class="bi bi-people" style="color:#6366f1;"></i> <?= htmlspecialchars(__('Employee List')) ?>
                </h4>
                <p style="font-size:.85rem; color:#64748b; margin-bottom:16px; flex-grow:1;"><?= htmlspecialchars(__('Export all employee data along with the count of assets currently assigned to them.')) ?></p>
                <form action="export_handler.php" method="GET" style="margin:0; display:flex;">
                    <input type="hidden" name="type" value="employees">
                    <button type="submit" class="btn btn-primary" style="flex:1; background:#6366f1; border-color:#6366f1;"><i class="bi bi-filetype-csv"></i> <?= htmlspecialchars(__('Download Employee List')) ?></button>
                </form>
            </div>
            
            <!-- 5. Asset History / Logs -->
            <div style="border:1px solid var(--color-border); border-radius:12px; padding:20px; background:#f8fafc; display:flex; flex-direction:column;">
                <h4 style="margin:0 0 10px 0; font-size:1.05rem; display:flex; align-items:center; gap:8px;">
                    <i class="bi bi-clock-history" style="color:#ef4444;"></i> <?= htmlspecialchars(__('Transaction Logs')) ?>
                </h4>
                <p style="font-size:.85rem; color:#64748b; margin-bottom:16px; flex-grow:1;"><?= htmlspecialchars(__('Export the full system history logs including assignments, returns, and updates.')) ?></p>
                <form action="export_handler.php" method="GET" style="margin:0; display:flex;">
                    <input type="hidden" name="type" value="logs">
                    <button type="submit" class="btn btn-primary" style="flex:1; background:#ef4444; border-color:#ef4444;"><i class="bi bi-filetype-csv"></i> <?= htmlspecialchars(__('Download Full Logs')) ?></button>
                </form>
            </div>
            
        </div>
    </div>
</div>

<div class="alert alert-info" style="margin-top:24px;">
    <i class="bi bi-info-circle-fill"></i>
    <strong>CSV vs XLSX Formatting:</strong> CSV files are universally supported and completely formula-friendly in Excel. Ensure you open them directly or use Excel's Data Import tool for best formatting.
</div>

<?php require_once 'includes/layout_end.php'; ?>
