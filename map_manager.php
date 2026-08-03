<?php
// map_manager.php — Upload Floor Plans and Draw Zones
require_once 'includes/db_connect.php';

// Fetch all maps
$maps = $pdo->query("SELECT * FROM maps ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all locations for the zone assignment dropdown
$locations = $pdo->query("SELECT * FROM locations ORDER BY location_name ASC")->fetchAll(PDO::FETCH_ASSOC);

$activeMapId = (int)($_GET['map_id'] ?? ($maps[0]['map_id'] ?? 0));
$activeMap = null;
$zones = [];

if ($activeMapId > 0) {
    $s = $pdo->prepare("SELECT * FROM maps WHERE map_id = :id");
    $s->execute(['id' => $activeMapId]);
    $activeMap = $s->fetch(PDO::FETCH_ASSOC);
    
    if ($activeMap) {
        // Fetch zones for this map
        $zs = $pdo->prepare("
            SELECT mz.*, l.location_name 
            FROM map_zones mz 
            JOIN locations l ON mz.location_id = l.location_id 
            WHERE mz.map_id = :id
        ");
        $zs->execute(['id' => $activeMapId]);
        $zones = $zs->fetchAll(PDO::FETCH_ASSOC);
    }
}

$msg   = $_GET['msg']   ?? '';
$error = $_GET['error'] ?? '';

$pageTitle = 'Map Manager';
$activeNav = 'map_mgr';
require_once 'includes/layout.php';
?>

<?php if ($msg === 'success'): ?>
    <div class="alert alert-success"><i class="bi bi-check-circle-fill"></i> Action completed successfully.</div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><i class="bi bi-exclamation-circle-fill"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div style="display:grid; grid-template-columns:300px 1fr; gap:24px; align-items:start;">

    <!-- ── LEFT COLUMN: MAPS LIST & UPLOAD ── -->
    <div style="display:flex; flex-direction:column; gap:24px;">
        
        <div class="card" style="margin:0;">
            <div class="card-header" style="padding:20px 24px;">
                <h3><i class="bi bi-upload"></i> <?= htmlspecialchars(__('Upload Floor Plan')) ?></h3>
            </div>
            <div class="card-body" style="padding:20px;">
                <form action="actions.php" method="POST" enctype="multipart/form-data" class="edit-only">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="upload_map">
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('Floor Plan Name')) ?></label>
                        <input type="text" name="map_name" class="form-control" placeholder="e.g. Building A - Floor 1" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label"><?= htmlspecialchars(__('Image File (JPG, PNG)')) ?></label>
                        <input type="file" name="map_image" class="form-control" accept="image/*" required>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width:100%; justify-content:center;">
                        <i class="bi bi-cloud-arrow-up"></i> <?= htmlspecialchars(__('Upload Map')) ?>
                    </button>
                </form>
            </div>
        </div>

        <div class="card" style="margin:0;">
            <div class="card-header" style="padding:20px 24px;">
                <h3><i class="bi bi-map"></i> <?= htmlspecialchars(__('Floor Plans')) ?></h3>
            </div>
            <div class="card-body tbl-wrap">
                <table>
                    <tbody>
                    <?php if (empty($maps)): ?>
                        <tr><td style="color:var(--color-text-muted); text-align:center; padding:20px;"><?= htmlspecialchars(__('No maps uploaded yet.')) ?></td></tr>
                    <?php else: ?>
                        <?php foreach ($maps as $m): ?>
                        <tr style="cursor:pointer; <?= $m['map_id'] === $activeMapId ? 'background:var(--color-accent-lite);' : '' ?>" onclick="window.location='map_manager.php?map_id=<?= $m['map_id'] ?>'">
                            <td class="fw-600" style="color: <?= $m['map_id'] === $activeMapId ? 'var(--color-accent-dark)' : 'var(--color-text)' ?>;">
                                <?= htmlspecialchars($m['name']) ?>
                            </td>
                            <td style="text-align:right;">
                                <form action="actions.php" method="POST" class="edit-only"
                                      data-confirm="ลบ map นี้ทั้งหมด? (ทุก zone จะถูกลบไปด้วย)" data-confirm-title="Delete Map"
                                      style="margin:0;" onclick="event.stopPropagation();">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_map">
                                    <input type="hidden" name="map_id" value="<?= $m['map_id'] ?>">
                                    <button type="submit" class="btn btn-ghost btn-sm" style="color:var(--color-danger); padding:4px;">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ── RIGHT COLUMN: MAP EDITOR ── -->
    <?php if ($activeMap): ?>
    <div class="card" style="margin:0;">
        <div class="card-header" style="display:flex; justify-content:space-between; align-items:center; padding:20px 24px;">
            <h3><i class="bi bi-bounding-box"></i> <?= htmlspecialchars(__('Zone Editor')) ?>: <?= htmlspecialchars($activeMap['name']) ?></h3>
            <div style="font-size:.8rem; color:var(--color-text-muted);">
                <?= htmlspecialchars(__('Click to add points • Double-click to close polygon')) ?>
            </div>
        </div>
        <div class="card-body" style="padding:0; position:relative; overflow:auto; max-height:700px; background:#e2e8f0; border-bottom:1px solid var(--color-border-soft);">
            <div id="mapContainer" style="position:relative; display:inline-block; transform-origin: top left;">
                <img id="mapImage" src="<?= htmlspecialchars($activeMap['image_path']) ?>" style="display:block; max-width:none;" alt="Floor Plan">
                <canvas id="mapCanvas" style="position:absolute; top:0; left:0; cursor:crosshair;"></canvas>
            </div>
        </div>
        <div class="card-body tbl-wrap">
            <h4 style="font-size:.9rem; font-weight:600; padding:16px 20px 8px; margin:0; border-bottom:1px solid var(--color-border-soft);"><?= htmlspecialchars(__('Defined Zones')) ?></h4>
            <table>
                <thead>
                    <tr>
                        <th><?= htmlspecialchars(__('Location Link')) ?></th>
                        <th><?= htmlspecialchars(__('Vertices')) ?></th>
                        <th style="text-align:right;"><?= htmlspecialchars(__('Action')) ?></th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($zones)): ?>
                    <tr><td colspan="3" style="text-align:center; color:var(--color-text-muted); padding:20px;"><?= htmlspecialchars(__('No zones defined for this map. Start drawing above!')) ?></td></tr>
                <?php else: ?>
                    <?php foreach ($zones as $z): ?>
                        <?php $pts = json_decode($z['polygon_json'], true); ?>
                        <tr>
                            <td class="fw-600"><?= htmlspecialchars($z['location_name']) ?></td>
                            <td class="text-muted text-mono" style="font-size:.8rem;"><?= is_array($pts) ? count($pts) : 0 ?> <?= htmlspecialchars(__('points')) ?></td>
                            <td style="text-align:right;">
                                <form action="actions.php" method="POST" class="edit-only"
                                      data-confirm="ลบ zone นี้?" data-confirm-title="Delete Zone" style="margin:0;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_zone">
                                    <input type="hidden" name="zone_id" value="<?= $z['zone_id'] ?>">
                                    <button type="submit" class="btn btn-danger-ghost btn-sm">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Save Zone Modal -->
    <div id="saveZoneModal" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:999;align-items:center;justify-content:center;backdrop-filter:blur(2px);">
        <div style="background:#fff;border-radius:12px;width:400px;box-shadow:0 20px 60px rgba(0,0,0,.15);overflow:hidden;">
            <div style="padding:20px 24px;border-bottom:1px solid #e8eaed;">
                <h3 style="font-size:1rem;font-weight:600;margin:0;"><?= htmlspecialchars(__('Save New Zone')) ?></h3>
            </div>
            <form action="actions.php" method="POST" style="padding:20px 24px;">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_zone">
                <input type="hidden" name="map_id" value="<?= $activeMapId ?>">
                <input type="hidden" name="polygon_json" id="polygonJsonInput">
                
                <div class="form-group">
                    <label class="form-label"><?= htmlspecialchars(__('Link to Location')) ?></label>
                    <select name="location_id" class="form-control form-select" required>
                        <option value=""><?= htmlspecialchars(__('-- Select Location --')) ?></option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= $loc['location_id'] ?>"><?= htmlspecialchars($loc['location_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:20px;">
                    <button type="button" onclick="cancelZone()" class="btn btn-ghost"><?= htmlspecialchars(__('Cancel')) ?></button>
                    <button type="submit" class="btn btn-primary"><?= htmlspecialchars(__('Save Zone')) ?></button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const img = document.getElementById('mapImage');
        const canvas = document.getElementById('mapCanvas');
        const ctx = canvas.getContext('2d');
        const existingZones = <?= json_encode($zones) ?>;
        
        let currentPoints = [];
        let mouseX = 0;
        let mouseY = 0;

        img.onload = function() {
            canvas.width = img.naturalWidth;
            canvas.height = img.naturalHeight;
            draw();
        }
        if (img.complete) img.onload();

        canvas.addEventListener('mousemove', function(e) {
            const rect = canvas.getBoundingClientRect();
            // Scale correctly if CSS is altering size
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            mouseX = (e.clientX - rect.left) * scaleX;
            mouseY = (e.clientY - rect.top) * scaleY;
            draw();
        });

        canvas.addEventListener('mousedown', function(e) {
            const rect = canvas.getBoundingClientRect();
            const scaleX = canvas.width / rect.width;
            const scaleY = canvas.height / rect.height;
            mouseX = (e.clientX - rect.left) * scaleX;
            mouseY = (e.clientY - rect.top) * scaleY;

            // Check if it's a double click
            if (e.detail === 2) {
                if (currentPoints.length >= 3) {
                    // Close polygon
                    document.getElementById('polygonJsonInput').value = JSON.stringify(currentPoints);
                    document.getElementById('saveZoneModal').style.display = 'flex';
                }
            } else {
                currentPoints.push({x: mouseX, y: mouseY});
                draw();
            }
        });

        function cancelZone() {
            document.getElementById('saveZoneModal').style.display = 'none';
            currentPoints = [];
            draw();
        }

        function draw() {
            ctx.clearRect(0, 0, canvas.width, canvas.height);

            // Draw existing zones
            existingZones.forEach(z => {
                let pts;
                try { pts = JSON.parse(z.polygon_json); } catch(e) { return; }
                if (!pts || pts.length < 3) return;
                
                ctx.beginPath();
                ctx.moveTo(pts[0].x, pts[0].y);
                for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i].x, pts[i].y);
                ctx.closePath();
                
                ctx.fillStyle = 'rgba(37, 99, 235, 0.2)';
                ctx.fill();
                ctx.lineWidth = 2;
                ctx.strokeStyle = 'rgba(37, 99, 235, 0.8)';
                ctx.stroke();

                // Draw label at center
                let cx=0, cy=0;
                pts.forEach(p => { cx+=p.x; cy+=p.y; });
                cx /= pts.length;
                cy /= pts.length;
                
                ctx.font = 'bold 14px Inter, sans-serif';
                ctx.fillStyle = '#1e3a8a';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(z.location_name, cx, cy);
            });

            // Draw currently drawing zone
            if (currentPoints.length > 0) {
                ctx.beginPath();
                ctx.moveTo(currentPoints[0].x, currentPoints[0].y);
                for (let i = 1; i < currentPoints.length; i++) {
                    ctx.lineTo(currentPoints[i].x, currentPoints[i].y);
                }
                ctx.lineTo(mouseX, mouseY); // preview line
                
                ctx.lineWidth = 2;
                ctx.strokeStyle = '#dc2626';
                ctx.setLineDash([5, 5]);
                ctx.stroke();
                ctx.setLineDash([]); // reset

                // Draw vertices
                ctx.fillStyle = '#dc2626';
                currentPoints.forEach(p => {
                    ctx.beginPath();
                    ctx.arc(p.x, p.y, 4, 0, Math.PI*2);
                    ctx.fill();
                });
            }
        }
    </script>
    <?php else: ?>
        <div style="display:flex; align-items:center; justify-content:center; border:2px dashed var(--color-border); border-radius:12px; color:var(--color-text-muted);">
            <div style="text-align:center; padding:40px;">
                <i class="bi bi-map" style="font-size:2rem; color:var(--color-border-soft); margin-bottom:16px; display:block;"></i>
                <p>Select or upload a floor plan to start drawing zones.</p>
            </div>
        </div>
    <?php endif; ?>

</div>

<?php require_once 'includes/layout_end.php'; ?>
