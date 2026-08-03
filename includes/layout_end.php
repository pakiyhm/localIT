        </main><!-- /#pageContent -->
    </div><!-- /.main -->
</div><!-- /.app -->

<?php render_confirm_modal(); ?>

<script>
// ─── Custom Confirm Modal ──────────────────────────────────────
// Usage:
//   askConfirm({title, message, confirmText, danger}).then(ok => { if (ok) ... });
// Or via HTML attribute:
//   <form data-confirm="ลบจริงหรือ?"> ... </form>
//   <button data-confirm="ลบจริงหรือ?" data-confirm-title="ลบรายการ">Delete</button>
(function(){
    const modal = document.getElementById('confirmModal');
    if (!modal) return;
    const msgEl = modal.querySelector('.cm-msg');
    const titleEl = modal.querySelector('.cm-title');
    const confirmBtn = modal.querySelector('#cmConfirm');
    const cancelBtn = modal.querySelector('#cmCancel');
    let pending = null;

    function close(result) {
        modal.classList.remove('show', 'is-info');
        if (pending) { pending(result); pending = null; }
    }

    window.askConfirm = function(opts) {
        opts = opts || {};
        titleEl.textContent = opts.title || 'ยืนยันการดำเนินการ';
        msgEl.textContent   = opts.message || 'คุณแน่ใจหรือไม่?';
        confirmBtn.textContent = opts.confirmText || 'ยืนยัน';
        modal.classList.toggle('is-info', opts.danger === false);
        modal.classList.add('show');
        return new Promise(res => { pending = res; });
    };

    confirmBtn.addEventListener('click', () => close(true));
    cancelBtn.addEventListener('click', () => close(false));
    modal.addEventListener('click', e => { if (e.target === modal) close(false); });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && modal.classList.contains('show')) close(false);
    });

    // Wire data-confirm on forms
    document.addEventListener('submit', function(e) {
        const f = e.target;
        if (!(f instanceof HTMLFormElement)) return;
        const msg = f.dataset.confirm;
        if (!msg || f.dataset.confirmed === '1') return;
        e.preventDefault();
        askConfirm({
            title: f.dataset.confirmTitle || 'ยืนยันการดำเนินการ',
            message: msg,
            confirmText: f.dataset.confirmBtn || 'ยืนยัน',
            danger: f.dataset.confirmKind !== 'info'
        }).then(ok => {
            if (!ok) return;
            f.dataset.confirmed = '1';
            f.submit();
        });
    }, true);

    // Wire data-confirm on lone buttons/links (no form)
    document.addEventListener('click', function(e) {
        const t = e.target.closest('[data-confirm]');
        if (!t || t.tagName === 'FORM' || t.closest('form[data-confirm]')) return;
        if (t.dataset.confirmed === '1') return;
        e.preventDefault();
        askConfirm({
            title: t.dataset.confirmTitle || 'ยืนยันการดำเนินการ',
            message: t.dataset.confirm,
            confirmText: t.dataset.confirmBtn || 'ยืนยัน',
            danger: t.dataset.confirmKind !== 'info'
        }).then(ok => {
            if (!ok) return;
            t.dataset.confirmed = '1';
            if (t.tagName === 'A' && t.href) { window.location.href = t.href; }
            else { t.click(); }
        });
    }, true);
})();

// ─── Form Helpers: submit-disable + modal reset ────────────────
(function(){
    // Disable submit button on form submit to prevent double-submit
    document.addEventListener('submit', function(e) {
        const f = e.target;
        if (!(f instanceof HTMLFormElement)) return;
        if (f.dataset.noBlock === '1') return;
        // wait a tick so HTML5 validation can still block
        setTimeout(() => {
            f.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(btn => {
                if (btn.disabled) return;
                btn.disabled = true;
                btn.classList.add('is-loading');
                const orig = btn.innerHTML;
                btn.dataset._orig = orig;
                if (btn.tagName === 'BUTTON') {
                    btn.innerHTML = '<span class="btn-spinner"></span>กำลังบันทึก...';
                }
                // Re-enable if user navigates back
                setTimeout(() => {
                    btn.disabled = false; btn.classList.remove('is-loading');
                    if (btn.dataset._orig) btn.innerHTML = btn.dataset._orig;
                }, 8000);
            });
        }, 0);
    }, true);

    // Reset forms when their containing modal closes via close-button click
    // Convention: any modal element with [data-modal] or known inline modal pattern
    function resetForm(form) {
        if (!form) return;
        form.reset();
        delete form.dataset.confirmed;
        form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(btn => {
            btn.disabled = false; btn.classList.remove('is-loading');
            if (btn.dataset._orig) btn.innerHTML = btn.dataset._orig;
        });
    }
    window.resetModalForm = function(modalId) {
        const m = document.getElementById(modalId);
        if (!m) return;
        m.querySelectorAll('form').forEach(resetForm);
    };

    // Auto: when a div with inline display flips back to none via close-button, reset its form
    const inlineModals = document.querySelectorAll('div[id$="Modal"], div[id$="modal"]');
    inlineModals.forEach(m => {
        const obs = new MutationObserver(() => {
            if (m.style.display === 'none' || getComputedStyle(m).display === 'none') {
                m.querySelectorAll('form').forEach(resetForm);
            }
        });
        obs.observe(m, { attributes: true, attributeFilter: ['style'] });
    });
})();
</script>

<script>
// ─── Universal Scanner Handler ───────────────────────────────
function handleScan(e) {
    e.preventDefault();
    const q = document.getElementById('scannerInput').value.trim().toLowerCase();
    if (!q) return false;
    
    // Attempt local table filtering first (for pages like Assets, Accessories, Users)
    const table = document.querySelector('.tbl-wrap table, .table-wrap table, table');
    if (table) {
        const rows = table.querySelectorAll('tbody tr[data-row-id], tbody tr:not(.empty-state)');
        let foundAny = false;
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            if (text.includes(q)) {
                row.style.display = '';
                foundAny = true;
            } else {
                row.style.display = 'none';
            }
        });
        
        // Handle empty state row if applicable
        const emptyRow = table.querySelector('.empty-state')?.closest('tr');
        if (emptyRow) {
            emptyRow.style.display = foundAny ? 'none' : '';
        }
        return false;
    }

    // Fallback: If no table is found on the page (e.g. Dashboard), perform universal search
    window.location.href = 'index.php?q=' + encodeURIComponent(q);
    return false;
}

// ─── Expandable User Row Toggle ──────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.user-row').forEach(function(row) {
        row.addEventListener('click', function() {
            const uid = this.dataset.uid;
            const subRows = document.querySelectorAll('.asset-sub[data-uid="' + uid + '"]');
            const isOpen = this.classList.contains('open');

            this.classList.toggle('open');
            subRows.forEach(function(sr) {
                sr.classList.toggle('visible', !isOpen);
            });
        });
    });

    // Close dropdowns on outside click
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.dropdown')) {
            document.querySelectorAll('.dropdown-menu.show')
                    .forEach(function(m){ m.classList.remove('show'); });
        }
    });

    // ─── Mobile Sidebar Toggle ──────────────────────────────────
    const sidebar      = document.querySelector('.sidebar');
    const sidebarBtn   = document.getElementById('sidebarToggle');
    const sidebarOvl   = document.getElementById('sidebarOverlay');

    function openSidebar()  {
        if (!sidebar) return;
        sidebar.classList.add('open');
        if (sidebarOvl) { sidebarOvl.style.display = 'block'; requestAnimationFrame(() => sidebarOvl.classList.add('active')); }
        if (sidebarBtn) sidebarBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
    }
    function closeSidebar() {
        if (!sidebar) return;
        sidebar.classList.remove('open');
        if (sidebarOvl) { sidebarOvl.classList.remove('active'); setTimeout(() => sidebarOvl.style.display = 'none', 300); }
        if (sidebarBtn) sidebarBtn.innerHTML = '<i class="bi bi-list"></i>';
    }

    if (sidebarBtn) {
        sidebarBtn.addEventListener('click', function() {
            sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
        });
    }
    if (sidebarOvl) {
        sidebarOvl.addEventListener('click', closeSidebar);
    }

    // Close sidebar on nav click (mobile)
    document.querySelectorAll('.nav-item').forEach(function(item) {
        item.addEventListener('click', function() {
            if (window.innerWidth <= 768) closeSidebar();
        });
    });
});
</script>
</body>
</html>
