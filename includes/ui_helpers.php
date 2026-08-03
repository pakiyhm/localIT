<?php
// includes/ui_helpers.php — shared UI partials & helpers
// Loaded once via layout.php

function render_confirm_modal() {
    ?>
    <div id="confirmModal" class="cm-backdrop" role="dialog" aria-modal="true" aria-hidden="true">
      <div class="cm-dialog">
        <div class="cm-icon"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <h3 class="cm-title">ยืนยันการดำเนินการ</h3>
        <p class="cm-msg">คุณแน่ใจหรือไม่?</p>
        <div class="cm-actions">
          <button type="button" class="btn btn-ghost" id="cmCancel">ยกเลิก</button>
          <button type="button" class="btn cm-confirm-btn" id="cmConfirm">ยืนยัน</button>
        </div>
      </div>
    </div>
    <?php
}

/**
 * Shared badge() helper — renders a status badge span.
 * Pages that define their own badge() before requiring layout.php
 * take precedence over this fallback (function_exists guard).
 *
 * @param string      $status             Asset/accessory status string
 * @param string|null $vendor_return_date If set and status=retired, shows "Returned"
 */
if (!function_exists('badge')) {
    function badge(string $status, ?string $vendor_return_date = null): string {
        if ($status === 'retired' && $vendor_return_date) {
            return "<span class='badge' style='background:#f1f5f9;color:#0f172a;border:1px solid #cbd5e1;'>Returned</span>";
        }
        $safe  = htmlspecialchars($status);
        $label = ucfirst(str_replace('_', ' ', $status));
        return "<span class='badge badge-{$safe}'>{$label}</span>";
    }
}

/**
 * Shared ownBadge() helper — renders an ownership type badge.
 * @param string|null $own  'Buy', 'Rent', or 'Borrow'
 */
if (!function_exists('ownBadge')) {
    function ownBadge(?string $own): string {
        $map = [
            'Buy'    => ['#dbeafe','#1d4ed8'],
            'Rent'   => ['#fef3c7','#b45309'],
            'Borrow' => ['#ede9fe','#6d28d9'],
        ];
        [$bg,$fg] = $map[$own] ?? ['#f3f4f6','#6b7280'];
        $safe = htmlspecialchars($own ?? '—');
        return "<span class='badge' style='background:{$bg};color:{$fg};'>{$safe}</span>";
    }
}
