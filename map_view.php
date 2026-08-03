<?php
// map_view.php — 2D Asset Visualizer
require_once 'includes/db_connect.php';
require_once 'includes/csrf.php';

// Fetch all maps for dropdown
$maps = $pdo->query("SELECT * FROM maps ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

$activeMapId = (int)($_GET['map_id'] ?? ($maps[0]['map_id'] ?? 0));
$activeMap = null;
$zones = [];
$assetsData = [];

if ($activeMapId > 0) {
    $s = $pdo->prepare("SELECT * FROM maps WHERE map_id = :id");
    $s->execute(['id' => $activeMapId]);
    $activeMap = $s->fetch(PDO::FETCH_ASSOC);
    
    if ($activeMap) {
        $zs = $pdo->prepare("
            SELECT mz.*, l.location_name 
            FROM map_zones mz 
            JOIN locations l ON mz.location_id = l.location_id 
            WHERE mz.map_id = :id
        ");
        $zs->execute(['id' => $activeMapId]);
        $zones = $zs->fetchAll(PDO::FETCH_ASSOC);

        // Load saved pin positions for this map
        $pp = $pdo->prepare("SELECT group_key, pin_x, pin_y FROM map_pin_positions WHERE map_id = :id");
        $pp->execute(['id' => $activeMapId]);
        $savedPinsAll = $pp->fetchAll(PDO::FETCH_ASSOC);
        $pinPositions = [];
        foreach ($savedPinsAll as $p) {
            $pinPositions[$p['group_key']] = ['x' => $p['pin_x'], 'y' => $p['pin_y']];
        }

        // Fetch All unique categories for filtering
        $allCats = $pdo->query("SELECT category FROM assets UNION SELECT category FROM accessories ORDER BY category ASC")->fetchAll(PDO::FETCH_COLUMN);

        // Extract location IDs to query assets
        $locIds = array_column($zones, 'location_id');
        if (!empty($locIds)) {
            $in = str_repeat('?,', count($locIds) - 1) . '?';
            
            // Fetch Assets
            $as = $pdo->prepare("
                SELECT 'asset' as type, a.asset_id as id, a.category, a.hostname as label, a.model, a.serial_number, a.status, a.location_id, u.full_name as assigned_to, u.department
                FROM assets a
                LEFT JOIN users u ON a.user_id = u.user_id
                WHERE a.location_id IN ($in)
            ");
            $as->execute($locIds);
            $allAssets = $as->fetchAll(PDO::FETCH_ASSOC);

            // Fetch Accessories
            $acc = $pdo->prepare("
                SELECT 'accessory' as type, ac.accessory_id as id, ac.category, ac.name as label, ac.model, ac.serial_number, ac.status, ac.location_id, u.full_name as assigned_to, u.department
                FROM accessories ac
                LEFT JOIN users u ON ac.user_id = u.user_id
                WHERE ac.location_id IN ($in)
            ");
            $acc->execute($locIds);
            $allAcc = $acc->fetchAll(PDO::FETCH_ASSOC);

            // Merge and group by location
            $merged = array_merge($allAssets, $allAcc);
            foreach ($merged as $item) {
                $assetsData[$item['location_id']][] = $item;
            }
        }
    }
}

$pageTitle = '2D Map Visualizer';
$activeNav = 'map_view';
require_once 'includes/layout.php';
?>

<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
    <div>
        <h2 style="margin:0; font-size:1.4rem;"><i class="bi bi-pin-map-fill" style="color:var(--color-accent); margin-right:8px;"></i> <?= htmlspecialchars(__('2D Floor Plan Visualizer')) ?></h2>
        <p style="margin:4px 0 0; color:var(--color-text-muted); font-size:.9rem;"><?= htmlspecialchars(__('View asset distribution across physical locations.')) ?></p>
    </div>
    <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <button type="button" onclick="fitToScreen()" class="btn btn-ghost btn-sm" style="border:1px solid var(--color-border); border-radius:6px;" title="Fit Map to Screen">
            <i class="bi bi-aspect-ratio"></i> <?= htmlspecialchars(__('Fit Screen')) ?>
        </button>
        <button type="button" onclick="actualSize()" class="btn btn-ghost btn-sm" style="border:1px solid var(--color-border); border-radius:6px;" title="Actual Size 100%">
            <i class="bi bi-zoom-in"></i> 100%
        </button>
        <button id="dragModeBtn" onclick="toggleDragMode()" class="btn btn-ghost btn-sm" style="border:1px solid var(--color-border); border-radius:6px;">
            <i class="bi bi-arrows-move"></i> <?= htmlspecialchars(__('Edit Positions')) ?>
        </button>
        <form method="GET" style="margin:0;">
            <select name="map_id" class="form-control form-select" style="width:220px;" onchange="this.form.submit()">
                <?php if (empty($maps)): ?>
                    <option value=""><?= htmlspecialchars(__('No maps available')) ?></option>
                <?php else: ?>
                    <?php foreach ($maps as $m): ?>
                        <option value="<?= $m['map_id'] ?>" <?= $m['map_id'] === $activeMapId ? 'selected' : '' ?>><?= htmlspecialchars($m['name']) ?></option>
                    <?php endforeach; ?>
                <?php endif; ?>
            </select>
        </form>
    </div>
</div>

<?php if ($activeMap): ?>
    <!-- Category Filter Bar -->
    <div id="categoryFilters" style="display:flex; gap:8px; margin-bottom:16px; flex-wrap:wrap; background:var(--color-surface); padding:10px 16px; border-radius:10px; border:1px solid var(--color-border); align-items:center;">
        <span style="font-size:.8rem; font-weight:600; color:var(--color-text-muted); margin-right:8px; text-transform:uppercase; letter-spacing:0.02em;"><?= htmlspecialchars(__('Filter By')) ?>:</span>
        <button class="cat-btn active" data-cat="all"><?= htmlspecialchars(__('All Types')) ?></button>
        <?php foreach ($allCats as $cat): ?>
            <button class="cat-btn" data-cat="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></button>
        <?php endforeach; ?>
    </div>

    <style>
        .cat-btn {
            background: #f1f5f9;
            color: #475569;
            border: 1px solid #e2e8f0;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: .8rem;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .cat-btn:hover { background: #e2e8f0; }
        .cat-btn.active {
            background: var(--color-accent);
            color: #fff;
            border-color: var(--color-accent);
            box-shadow: 0 2px 4px rgba(37, 99, 235, 0.2);
        }
        .pin-badge {
            position: absolute;
            top: -4px;
            right: -4px;
            background: #ef4444;
            color: white;
            font-size: 9px;
            font-weight: 700;
            width: 14px;
            height: 14px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            pointer-events: none;
        }
        #pinPopover {
            width: 260px !important;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .pop-item {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        .pop-item:last-child { border-bottom: none; }
    </style>
    <div class="card" style="margin:0; overflow:hidden;">
        <div class="card-body" style="padding:0; position:relative; background:#e2e8f0; width:100%; height:calc(100vh - 220px); min-height:450px; max-height:750px; overflow:hidden;">
            <div id="mapWrapper" style="position:relative; display:inline-block; transform-origin: top left;">
                <img id="mapImage" src="<?= htmlspecialchars($activeMap['image_path']) ?>" style="display:block; max-width:none;" alt="Floor Plan">
                
                <!-- SVG layer for Polygons -->
                <svg id="mapSvg" style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none;"></svg>

                <!-- Divs for Pins -->
                <div id="pinsContainer" style="position:absolute; top:0; left:0; width:100%; height:100%; pointer-events:none;"></div>
            </div>
        </div>
    </div>

    <!-- Tooltip Popover -->
    <div id="pinPopover" style="display:none; position:absolute; background:#fff; border:1px solid var(--color-border); border-radius:12px; box-shadow:0 12px 30px rgba(0,0,0,.15); padding:16px; z-index:1000; width:260px; pointer-events:auto;">
        <div id="popHeader" style="display:flex; align-items:center; gap:8px; margin-bottom:12px; padding-bottom:10px; border-bottom:1px solid #f1f5f9;">
            <div id="popIcon" style="width:32px; height:32px; border-radius:8px; background:var(--color-accent-lite); display:flex; align-items:center; justify-content:center; color:var(--color-accent-dark);"></div>
            <div style="overflow:hidden;">
                <div id="popTitle" style="font-size:0.95rem; font-weight:700; color:var(--color-accent-dark); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"></div>
                <div id="popSub" style="font-size:0.75rem; color:var(--color-text-muted); font-weight:500;"></div>
            </div>
        </div>
        <div id="popList" style="font-size:.82rem; display:flex; flex-direction:column; max-height:200px; overflow-y:auto;"></div>
    </div>

    <script>
        const zones        = <?= json_encode($zones) ?>;
        const assetsData   = <?= json_encode($assetsData) ?>;
        const pinPositions = <?= json_encode($pinPositions ?? []) ?>;
        const mapId        = <?= $activeMapId ?>;
        const csrfToken    = <?= json_encode(csrf_token()) ?>;
        let dragMode = false;

        function toggleDragMode() {
            dragMode = !dragMode;
            const btn = document.getElementById('dragModeBtn');
            btn.style.background = dragMode ? 'var(--color-accent)' : '';
            btn.style.color      = dragMode ? '#fff' : '';
            btn.innerHTML = dragMode
                ? '<i class="bi bi-lock-fill"></i> <?= htmlspecialchars(__('Saving Positions...')) ?>'
                : '<i class="bi bi-arrows-move"></i> <?= htmlspecialchars(__('Edit Positions')) ?>';
            // Re-render with/without drag handles
            renderMap();
        }

        const svg = document.getElementById('mapSvg');
        const pinsCont = document.getElementById('pinsContainer');
        const popover = document.getElementById('pinPopover');
        const img = document.getElementById('mapImage');

        let activeCategory = 'all';
        let popoverHideTimeout = null;

        popover.addEventListener('mouseenter', () => {
            if (popoverHideTimeout) {
                clearTimeout(popoverHideTimeout);
                popoverHideTimeout = null;
            }
        });
        popover.addEventListener('mouseleave', () => {
            popover.style.display = 'none';
        });

        // ── Map Pan & Zoom ──
        let currentScale = 1;
        let mapTranslateX = 0, mapTranslateY = 0;
        let mapIsPanning = false;
        let mapStartX = 0, mapStartY = 0;

        const mapContainer = document.querySelector('.card-body');
        const mapWrapperEl = document.getElementById('mapWrapper');

        mapContainer.style.overflow = 'hidden';
        mapContainer.style.cursor = 'grab';
        // Prevent default image drag
        img.addEventListener('dragstart', e => e.preventDefault());

        mapContainer.addEventListener('mousedown', (e) => {
            if (e.target.closest('.map-pin')) return;
            mapIsPanning = true;
            mapContainer.style.cursor = 'grabbing';
            mapStartX = e.clientX - mapTranslateX;
            mapStartY = e.clientY - mapTranslateY;
        });

        document.addEventListener('mousemove', (e) => {
            if (!mapIsPanning) return;
            mapTranslateX = e.clientX - mapStartX;
            mapTranslateY = e.clientY - mapStartY;
            updateMapTransform();
        });

        document.addEventListener('mouseup', () => {
            mapIsPanning = false;
            mapContainer.style.cursor = 'grab';
        });

        mapContainer.addEventListener('wheel', (e) => {
            e.preventDefault();
            const rect = mapContainer.getBoundingClientRect();
            const mouseX = e.clientX - rect.left;
            const mouseY = e.clientY - rect.top;

            const delta = e.deltaY > 0 ? -0.1 : 0.1;
            const oldScale = currentScale;
            currentScale += delta;
            
            if (currentScale < 0.05) currentScale = 0.05;
            if (currentScale > 5) currentScale = 5;

            mapTranslateX = mouseX - (mouseX - mapTranslateX) * (currentScale / oldScale);
            mapTranslateY = mouseY - (mouseY - mapTranslateY) * (currentScale / oldScale);
            
            updateMapTransform();
        }, { passive: false });

        function updateMapTransform() {
            mapWrapperEl.style.transform = `translate(${mapTranslateX}px, ${mapTranslateY}px) scale(${currentScale})`;
        }

        function fitToScreen() {
            if (!img.naturalWidth || !img.naturalHeight) return;
            const containerW = mapContainer.clientWidth;
            const containerH = mapContainer.clientHeight;

            const scaleX = containerW / img.naturalWidth;
            const scaleY = containerH / img.naturalHeight;
            currentScale = Math.min(scaleX, scaleY) * 0.96;
            if (currentScale < 0.05) currentScale = 0.05;

            mapTranslateX = (containerW - img.naturalWidth * currentScale) / 2;
            mapTranslateY = (containerH - img.naturalHeight * currentScale) / 2;

            updateMapTransform();
        }

        function actualSize() {
            currentScale = 1;
            mapTranslateX = 0;
            mapTranslateY = 0;
            updateMapTransform();
        }

        img.addEventListener('load', () => {
            fitToScreen();
            renderMap();
        });
        if (img.complete) {
            setTimeout(() => { fitToScreen(); renderMap(); }, 50);
        }

        function getSavedPos(groupKey, defaultX, defaultY) {
            if (pinPositions[groupKey]) {
                return { px: pinPositions[groupKey].x, py: pinPositions[groupKey].y };
            }
            return { px: defaultX, py: defaultY };
        }

        function getCategoryIcon(category) {
            const cat = (category || '').toLowerCase();
            if (cat.includes('laptop')) return '<i class="bi bi-laptop" style="color:white; font-size:13px;"></i>';
            if (cat.includes('desktop') || cat.includes('pc')) return '<i class="bi bi-pc-display" style="color:white; font-size:13px;"></i>';
            if (cat.includes('monitor') || cat.includes('display')) return '<i class="bi bi-display" style="color:white; font-size:13px;"></i>';
            if (cat.includes('printer')) return '<i class="bi bi-printer" style="color:white; font-size:13px;"></i>';
            if (cat.includes('keyboard')) return '<i class="bi bi-keyboard" style="color:white; font-size:13px;"></i>';
            if (cat.includes('mouse')) return '<i class="bi bi-mouse" style="color:white; font-size:13px;"></i>';
            if (cat.includes('headset') || cat.includes('headphone')) return '<i class="bi bi-headset" style="color:white; font-size:13px;"></i>';
            if (cat.includes('phone') || cat.includes('mobile')) return '<i class="bi bi-phone" style="color:white; font-size:13px;"></i>';
            if (cat.includes('tablet')) return '<i class="bi bi-tablet" style="color:white; font-size:13px;"></i>';
            if (cat.includes('scanner') || cat.includes('barcode')) return '<i class="bi bi-upc-scan" style="color:white; font-size:13px;"></i>';
            return '<i class="bi bi-box-seam" style="color:white; font-size:13px;"></i>';
        }

        function getDeptColor(dept) {
            if (!dept) return '#2563eb';
            const colors = ['#2563eb', '#db2777', '#7c3aed', '#0d9488', '#ea580c', '#65a30d', '#4f46e5', '#ca8a04', '#0891b2', '#c026d3'];
            let hash = 0;
            for (let i = 0; i < dept.length; i++) {
                hash = dept.charCodeAt(i) + ((hash << 5) - hash);
            }
            return colors[Math.abs(hash) % colors.length];
        }

        function savePinPosition(groupKey, locationId, x, y) {
            const fd = new FormData();
            fd.append('action', 'save_pin_position');
            fd.append('_csrf_token', csrfToken);
            fd.append('map_id', mapId);
            fd.append('group_key', groupKey);
            fd.append('location_id', locationId);
            fd.append('pin_x', x);
            fd.append('pin_y', y);
            fetch('actions.php', { method:'POST', body:fd })
                .then(r => r.json())
                .then(d => {
                    if (d.ok) pinPositions[groupKey] = { x, y };
                });
        }

        function renderMap() {
            svg.setAttribute('width', img.naturalWidth);
            svg.setAttribute('height', img.naturalHeight);
            
            svg.innerHTML = '';
            pinsCont.innerHTML = '';

            zones.forEach(z => {

                const pts = JSON.parse(z.polygon_json);
                if (!pts || pts.length < 3) return;

                // Create SVG Polygon
                const pointsStr = pts.map(p => `${p.x},${p.y}`).join(' ');
                const polygon = document.createElementNS("http://www.w3.org/2000/svg", "polygon");
                polygon.setAttribute('points', pointsStr);
                polygon.setAttribute('fill', 'rgba(37, 99, 235, 0.05)');
                polygon.setAttribute('stroke', 'rgba(37, 99, 235, 0.3)');
                polygon.setAttribute('stroke-width', '1.5');
                polygon.style.pointerEvents = 'auto';
                polygon.style.cursor = 'pointer';
                polygon.style.transition = 'all 0.2s ease';

                // Add Assets summary on Zone Hover
                polygon.addEventListener('mouseenter', (e) => {
                    if (popoverHideTimeout) { clearTimeout(popoverHideTimeout); popoverHideTimeout = null; }
                    polygon.setAttribute('fill', 'rgba(37, 99, 235, 0.15)');
                    showZonePopover(e, z.location_name, assetsData[z.location_id] || []);
                });
                polygon.addEventListener('mouseleave', () => {
                    polygon.setAttribute('fill', 'rgba(37, 99, 235, 0.05)');
                    popoverHideTimeout = setTimeout(() => {
                        popover.style.display = 'none';
                    }, 150);
                });

                svg.appendChild(polygon);

                // Add Zone Label
                let cx=0, cy=0;
                pts.forEach(p => { cx+=p.x; cy+=p.y; });
                cx /= pts.length;
                cy /= pts.length;

                const text = document.createElementNS("http://www.w3.org/2000/svg", "text");
                text.setAttribute('x', cx);
                text.setAttribute('y', cy);
                text.setAttribute('fill', 'rgba(30, 58, 138, 0.4)');
                text.setAttribute('font-family', 'Inter, sans-serif');
                text.setAttribute('font-size', '13px');
                text.setAttribute('font-weight', '600');
                text.setAttribute('text-anchor', 'middle');
                text.style.pointerEvents = 'none';
                text.textContent = z.location_name;
                svg.appendChild(text);

                // Group Assets & Accessories in this zone
                const locAssets = assetsData[z.location_id] || [];
                
                // 1. Filter by Category
                const filtered = locAssets.filter(a => activeCategory === 'all' || a.category === activeCategory);
                if (filtered.length === 0) return;

                // 2. Group by User (if assigned) or category/status (if in inventory-like location)
                const groups = {};
                const userDepts = {};
                const isInventoryLoc = z.location_name.toLowerCase().includes('server') || z.location_name.toLowerCase().includes('inventory') || z.location_name.toLowerCase().includes('stock');

                filtered.forEach(a => {
                    let groupKey;
                    if (a.assigned_to) {
                        groupKey = a.assigned_to;
                    } else if (isInventoryLoc) {
                        // In inventory locations, group unassigned items by category and status
                        groupKey = 'STOCK_' + a.category + '_' + a.status;
                    } else {
                        // Regular locations: unassigned items get individual pins (or group by type if preferred)
                        groupKey = 'STOCK_' + a.type + '_' + a.id + '_' + a.status;
                    }
                    
                    if (!groups[groupKey]) groups[groupKey] = [];
                    groups[groupKey].push(a);
                    if (a.assigned_to && a.department) userDepts[groupKey] = a.department;
                });

                // 3. Render one pin per group
                Object.entries(groups).forEach(([key, items], idx) => {
                    const isUserGroup = !key.startsWith('STOCK_');

                    // Default spiral position
                    const angle = idx * 2.39996;
                    const radius = Math.sqrt(idx) * (isInventoryLoc ? 35 : 20); // Wider spacing for inventory groups if many
                    const defaultPx = cx + radius * Math.cos(angle);
                    const defaultPy = cy + radius * Math.sin(angle) + (isInventoryLoc ? 0 : 20);

                    // Use saved position if available, else default
                    const { px, py } = getSavedPos(key, defaultPx, defaultPy);

                    const pin = document.createElement('div');
                    pin.className = 'map-pin';
                    pin.dataset.groupKey   = key;
                    pin.dataset.locationId = z.location_id;
                    pin.style.position = 'absolute';
                    pin.style.left = (px - 7) + 'px';
                    pin.style.top  = (py - 20) + 'px';
                    pin.style.width = '14px';
                    pin.style.height = '20px';
                    pin.style.pointerEvents = 'auto';
                    pin.style.cursor = dragMode ? 'grab' : 'pointer';
                    pin.style.zIndex = '1';
                    pin.style.transition = dragMode ? 'none' : 'all 0.2s cubic-bezier(0.175, 0.885, 0.32, 1.275)';
                    if (dragMode) pin.style.opacity = '0.9';

                    // Pin Background Color
                    let bgColor = isUserGroup ? getDeptColor(userDepts[key]) : '#64748b';
                    if (!isUserGroup) {
                        if (key.includes('repair'))   bgColor = '#f59e0b';
                        else if (key.includes('lost'))     bgColor = '#ef4444';
                        else if (key.includes('in_stock')) bgColor = '#10b981';
                    }
                    
                    let shortLabel = isUserGroup ? key : (items[0].label || items[0].category) + (items.length > 1 ? ' (' + items.length + ')' : '');
                    pin.title = shortLabel;

                    let innerIcon = '';
                    if (activeCategory !== 'all') {
                        innerIcon = getCategoryIcon(activeCategory);
                    } else {
                        if (isUserGroup) {
                            innerIcon = '<i class="bi bi-person-fill" style="color:white; font-size:14px;"></i>';
                        } else {
                            innerIcon = getCategoryIcon(items[0].category);
                        }
                    }

                    pin.innerHTML = `
                        <svg width="14" height="20" viewBox="0 0 24 34" xmlns="http://www.w3.org/2000/svg" style="position:absolute; top:0; left:0; filter:drop-shadow(0 2px 2px rgba(0,0,0,0.3));">
                          <path d="M12 0C5.373 0 0 5.373 0 12c0 8.5 12 22 12 22s12-13.5 12-22c0-6.627-5.373-12-12-12z" fill="${bgColor}" stroke="#ffffff" stroke-width="2"/>
                        </svg>
                        <div style="position:absolute; top:1px; left:0; width:14px; height:14px; display:flex; align-items:center; justify-content:center;">
                            <div style="transform: scale(0.65); display:flex;">
                                ${innerIcon}
                            </div>
                        </div>
                    `;

                    if (items.length > 1) {
                        const badge = document.createElement('div');
                        badge.className = 'pin-badge';
                        badge.innerText = items.length;
                        badge.style.top = '-4px';
                        badge.style.right = '-6px';
                        badge.style.transform = 'scale(0.75)';
                        badge.style.transformOrigin = 'top right';
                        pin.appendChild(badge);
                    }

                    // ── Drag to reposition ──
                    if (dragMode) {
                        let dragging = false, startX, startY, origLeft, origTop;
                        pin.addEventListener('mousedown', e => {
                            dragging = true;
                            popover.style.display = 'none';
                            startX = e.clientX;
                            startY = e.clientY;
                            origLeft = parseFloat(pin.style.left);
                            origTop  = parseFloat(pin.style.top);
                            pin.style.cursor = 'grabbing';
                            pin.style.zIndex = 100;
                            e.stopPropagation();
                        });
                        document.addEventListener('mousemove', e => {
                            if (!dragging) return;
                            const scale = currentScale || 1;
                            pin.style.left = (origLeft + (e.clientX - startX) / scale) + 'px';
                            pin.style.top  = (origTop  + (e.clientY - startY) / scale) + 'px';
                        });
                        document.addEventListener('mouseup', e => {
                            if (!dragging) return;
                            dragging = false;
                            pin.style.cursor = 'grab';
                            pin.style.zIndex = '';
                            const newX = parseFloat(pin.style.left) + 7;
                            const newY = parseFloat(pin.style.top)  + 20;
                            savePinPosition(key, z.location_id, newX, newY);
                        });
                    } else {
                        pin.addEventListener('click', () => {
                            if (isUserGroup && items[0].user_id) {
                                window.location = 'users.php#user-' + items[0].user_id;
                            }
                        });
                    }

                    // Hover events for BOTH modes
                    pin.addEventListener('mouseenter', e => {
                        if (popoverHideTimeout) { clearTimeout(popoverHideTimeout); popoverHideTimeout = null; }
                        if (!dragMode) pin.style.transform = 'scale(1.2) translateY(-5px)';
                        pin.style.zIndex = '10';
                        showPopover(e, key, items, isUserGroup, userDepts[key], z.location_name);
                    });
                    pin.addEventListener('mouseleave', () => {
                        if (!dragMode) pin.style.transform = 'scale(1)';
                        pin.style.zIndex = dragMode ? '' : '1';
                        popoverHideTimeout = setTimeout(() => {
                            popover.style.display = 'none';
                        }, 150);
                    });

                    pinsCont.appendChild(pin);
                });
            });
        }

        function showPopover(e, titleKey, items, isUserGroup, deptName, locName) {
            popover.style.display = 'block';
            popover.style.width = '260px';
            
            const popTitle = document.getElementById('popTitle');
            const popSub = document.getElementById('popSub');
            const popIcon = document.getElementById('popIcon');
            const popList = document.getElementById('popList');

            if (isUserGroup) {
                popTitle.innerText = titleKey;
                popSub.innerText = deptName ? `Dept: ${deptName}` : 'Assigned Personnel';
                popIcon.innerHTML = '<i class="bi bi-person-fill" style="font-size:1.2rem;"></i>';
                popIcon.style.background = '#eff6ff';
                popIcon.style.color = '#2563eb';
            } else {
                const item = items[0];
                const status = item.status.toUpperCase();
                popTitle.innerText = items.length > 1 ? `${item.category} (${items.length})` : (item.label || item.category);
                popSub.innerText = locName ? locName + ' • ' + status : status;
                popIcon.innerHTML = '<i class="bi bi-box-seam-fill" style="font-size:1.1rem;"></i>';
                popIcon.style.background = '#f1f5f9';
                popIcon.style.color = '#475569';
                if (item.status === 'repair') { popIcon.style.background = '#fffbeb'; popIcon.style.color = '#d97706'; }
                if (item.status === 'in_stock') { popIcon.style.background = '#f0fdf4'; popIcon.style.color = '#16a34a'; }
            }

            // Populate List
            popList.innerHTML = '';
            items.forEach(item => {
                const div = document.createElement('div');
                div.className = 'pop-item';
                div.innerHTML = `
                    <div style="display:flex; flex-direction:column; gap:1px;">
                        <span style="font-weight:600; color:#1e293b;">${item.label || item.model || 'Unnamed Device'}</span>
                        <span style="font-size:0.7rem; color:#64748b;">${item.category} • SN: ${item.serial_number || 'N/A'}</span>
                    </div>
                `;
                popList.appendChild(div);
            });

            // Position popover
            const rect = e.target.getBoundingClientRect();
            const popWidth = 260;
            const popHeight = popover.offsetHeight;
            
            let left = rect.left + window.scrollX - (popWidth/2) + (rect.width/2);
            let top = rect.top + window.scrollY - popHeight - 12;

            popover.style.left = left + 'px';
            popover.style.top = top + 'px';
        }

        function showZonePopover(e, locName, items) {
            popover.style.display = 'block';
            popover.style.width = '320px';
            
            const popTitle = document.getElementById('popTitle');
            const popSub = document.getElementById('popSub');
            const popIcon = document.getElementById('popIcon');
            const popList = document.getElementById('popList');

            popTitle.innerText = locName;
            popSub.innerText = 'Location Inventory List';
            popIcon.innerHTML = '<i class="bi bi-geo-alt-fill" style="font-size:1.1rem;"></i>';
            popIcon.style.background = '#fef2f2';
            popIcon.style.color = '#ef4444';

            // Filter items by current active category
            const filtered = items.filter(a => activeCategory === 'all' || a.category === activeCategory);

            popList.innerHTML = '';
            if (filtered.length === 0) {
                popList.innerHTML = '<div style="color:var(--color-text-muted); font-size:0.75rem; padding:10px 0;">No items in this location matching current filter.</div>';
            } else {
                // Group by category for compact summary
                const catCounts = {};
                filtered.forEach(item => {
                    catCounts[item.category] = (catCounts[item.category] || 0) + 1;
                });

                // Render as a clean compact summary
                const listContainer = document.createElement('div');
                listContainer.style.display = 'flex';
                listContainer.style.flexDirection = 'column';
                listContainer.style.gap = '6px';
                
                Object.entries(catCounts).sort((a, b) => b[1] - a[1]).forEach(([cat, count]) => {
                    const itemDiv = document.createElement('div');
                    itemDiv.style.display = 'flex';
                    itemDiv.style.justifyContent = 'space-between';
                    itemDiv.style.alignItems = 'center';
                    itemDiv.style.padding = '6px 12px';
                    itemDiv.style.background = '#f8fafc';
                    itemDiv.style.borderRadius = '6px';
                    itemDiv.style.fontSize = '0.8rem';
                    
                    itemDiv.innerHTML = `
                        <div style="font-weight:600; color:#334155;">${cat}</div>
                        <div style="font-weight:700; color:var(--color-accent); font-family:monospace; font-size:0.9rem;">${count}</div>
                    `;
                    listContainer.appendChild(itemDiv);
                });
                
                popList.appendChild(listContainer);
                
                const totalDiv = document.createElement('div');
                totalDiv.style.marginTop = '12px';
                totalDiv.style.paddingTop = '8px';
                totalDiv.style.borderTop = '1px dashed #cbd5e1';
                totalDiv.style.fontSize = '0.75rem';
                totalDiv.style.fontWeight = '700';
                totalDiv.style.textAlign = 'right';
                totalDiv.style.color = 'var(--color-text-muted)';
                totalDiv.innerText = `<?= htmlspecialchars(__('TOTAL')) ?>: ${filtered.length} <?= htmlspecialchars(__('items')) ?>`;
                popList.appendChild(totalDiv);
            }

            // Position popover
            let left = e.clientX + window.scrollX + 20;
            let top = e.clientY + window.scrollY - 50;

            // Prevent going off screen
            if (left + 320 > window.innerWidth + window.scrollX) left = e.clientX + window.scrollX - 340;

            popover.style.left = left + 'px';
            popover.style.top = top + 'px';
        }

        // Handle Category Clicks
        document.getElementById('categoryFilters').addEventListener('click', (e) => {
            if (e.target.classList.contains('cat-btn')) {
                document.querySelectorAll('.cat-btn').forEach(b => b.classList.remove('active'));
                e.target.classList.add('active');
                activeCategory = e.target.getAttribute('data-cat');
                renderMap();
            }
        });

        if (img.complete) {
            renderMap();
        } else {
            img.onload = renderMap;
        }

    </script>
<?php else: ?>
    <div style="display:flex; align-items:center; justify-content:center; border:2px dashed var(--color-border); border-radius:12px; color:var(--color-text-muted); height:400px;">
        <div style="text-align:center;">
            <i class="bi bi-map-fill" style="font-size:2rem; color:var(--color-border-soft); margin-bottom:16px; display:block;"></i>
            <p><?= htmlspecialchars(__('No floor plans found. Please upload one via the Map Manager.')) ?></p>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'includes/layout_end.php'; ?>
