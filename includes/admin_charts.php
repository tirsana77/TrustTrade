<?php
/**
 * Charts for the admin overview, drawn as plain SVG and CSS (no chart library).
 * Hovering a slice or bar shows its number, and the doughnut slices link to the matching admin tab.
 */

/**
 * The details behind a slice, bar or column of the charts (shown in the window that opens on a click).
 * Called by admin.php when it receives ?detail=1. Returns null when there is nothing for that type and key.
 */
function adminDetail(PDO $db, string $type, string $key): ?array
{
    $date = fn($t) => $t ? date('j M Y', strtotime($t)) : '';
    $out  = null;

    if ($type === 'students') {
        $where = [
            'verified' => 'student_verified = 1',
            'pending'  => "student_verified = 0 AND student_id_photo IS NOT NULL AND student_id_photo != ''",
            'none'     => "student_verified = 0 AND (student_id_photo IS NULL OR student_id_photo = '')",
        ];
        $names = ['verified' => 'Verified students', 'pending' => 'Students waiting for review', 'none' => 'Students not verified yet'];
        if (isset($where[$key])) {
            $rows = $db->query('SELECT full_name, email, created_at FROM users WHERE is_admin = 0 AND ' . $where[$key] . ' ORDER BY created_at DESC LIMIT 200')->fetchAll();
            $out = [
                'title'   => $names[$key],
                'columns' => ['Name', 'Email', 'Joined'],
                'rows'    => array_map(fn($r) => [$r['full_name'], $r['email'], $date($r['created_at'])], $rows),
                'link'    => ['admin.php?tab=' . ($key === 'pending' ? 'verifications' : 'users'), $key === 'pending' ? 'Open Verifications' : 'Open Users'],
            ];
        }
    } elseif ($type === 'orders' || $type === 'orders_day') {
        $sql = 'SELECT l.title, b.full_name AS buyer, s.full_name AS seller, o.type, o.status, o.payment_status,
                       COALESCE(o.payment_amount, l.price) AS amount, o.created_at
                FROM orders o
                JOIN listings l ON l.id = o.listing_id
                JOIN users b ON b.id = o.buyer_id
                JOIN users s ON s.id = o.seller_id WHERE ';
        $params = [];
        $title  = '';
        if ($type === 'orders' && in_array($key, ['pending', 'accepted', 'completed', 'declined', 'cancelled'], true)) {
            $sql .= 'o.status = :k';
            $params = ['k' => $key];
            $title = 'Requests: ' . $key;
        } elseif ($type === 'orders_day' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
            $sql .= 'DATE(o.created_at) = :k';
            $params = ['k' => $key];
            $title = 'Requests on ' . date('j M Y', strtotime($key));
        }
        if ($title !== '') {
            $stmt = $db->prepare($sql . ' ORDER BY o.created_at DESC LIMIT 200');
            $stmt->execute($params);
            $out = [
                'title'   => $title,
                'columns' => ['Item', 'Buyer', 'Seller', 'Type', 'Status', 'Amount', 'Date'],
                'rows'    => array_map(fn($r) => [$r['title'], $r['buyer'], $r['seller'], $r['type'],
                                                  $r['status'] . ($r['type'] === 'buy' && $r['payment_status'] !== 'unpaid' ? ' / ' . $r['payment_status'] : ''),
                                                  number_format((float) $r['amount'], 2) . ' DKK', $date($r['created_at'])], $stmt->fetchAll()),
                'link'    => null,
            ];
        }
    } elseif ($type === 'listings' && $key !== '') {
        $stmt = $db->prepare(
            "SELECT l.title, u.full_name AS seller, l.price, l.status, l.created_at
             FROM listings l JOIN users u ON u.id = l.user_id
             WHERE COALESCE(NULLIF(l.category, ''), 'Other') = :k ORDER BY l.created_at DESC LIMIT 200"
        );
        $stmt->execute(['k' => $key]);
        $out = [
            'title'   => 'Listings in ' . $key,
            'columns' => ['Item', 'Seller', 'Price', 'Status', 'Listed'],
            'rows'    => array_map(fn($r) => [$r['title'], $r['seller'], number_format((float) $r['price'], 2) . ' DKK', $r['status'], $date($r['created_at'])], $stmt->fetchAll()),
            'link'    => ['admin.php?tab=listings', 'Open Listings'],
        ];
    }

    return $out;
}

function chartH(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/** Doughnut chart. $items: label => count. $details: label => [type, key] (the details window that opens on a click). */
function chartDoughnut(string $id, array $items, array $colors, array $details = []): string
{
    $total = array_sum($items);
    $h  = '<div class="ch-donut" id="' . $id . '">';
    $h .= '<svg viewBox="0 0 42 42" class="ch-donut-svg" role="img" aria-label="Chart">';
    $h .= '<circle cx="21" cy="21" r="15.9155" fill="none" stroke="#ecebfb" stroke-width="6"></circle>';
    $offset = 25;   // start at the top
    $i = 0;
    foreach ($items as $label => $n) {
        $pct = $total > 0 ? ($n / $total) * 100 : 0;
        if ($pct > 0) {
            $d = isset($details[$label]) ? ' data-type="' . chartH($details[$label][0]) . '" data-key="' . chartH($details[$label][1]) . '" tabindex="0"' : '';
            $h .= '<g class="ch-seg' . ($d !== '' ? ' ch-click' : '') . '"' . $d . ' data-label="' . chartH($label) . '" data-n="' . (int) $n . '" data-pct="' . round($pct) . '">'
                . '<circle cx="21" cy="21" r="15.9155" fill="none" stroke="' . $colors[$i % count($colors)] . '" stroke-width="6" '
                . 'stroke-dasharray="' . round($pct, 3) . ' ' . round(100 - $pct, 3) . '" stroke-dashoffset="' . round($offset, 3) . '"><title>' . chartH($label) . ': ' . (int) $n . ' (click for details)</title></circle></g>';
        }
        $offset -= $pct;
        $i++;
    }
    $h .= '<text x="21" y="21" class="ch-c-num" text-anchor="middle" dominant-baseline="central">' . (int) $total . '</text>';
    $h .= '<text x="21" y="26.5" class="ch-c-lab" text-anchor="middle">students</text>';
    $h .= '</svg><ul class="ch-legend">';
    $i = 0;
    foreach ($items as $label => $n) {
        $li = '<span class="ch-dot" style="background:' . $colors[$i % count($colors)] . '"></span>' . chartH($label) . ' <strong>' . (int) $n . '</strong>';
        $d  = isset($details[$label]) ? ' class="ch-click" data-type="' . chartH($details[$label][0]) . '" data-key="' . chartH($details[$label][1]) . '" tabindex="0"' : '';
        $h .= '<li' . $d . '>' . $li . '</li>';
        $i++;
    }
    return $h . '</ul></div>';
}

/** Horizontal bars. $items: label => count. A click opens the details for $type and the key (the label, or $keys[label]). */
function chartHBars(array $items, string $color, string $type = '', array $keys = []): string
{
    $max = max(1, max($items ?: [0]));
    $h = '<div class="ch-hbars">';
    foreach ($items as $label => $n) {
        $w = round(($n / $max) * 100);
        $row = '<span class="ch-hl">' . chartH($label) . '</span>'
             . '<span class="ch-track"><span class="ch-fill" style="width:' . $w . '%;background:' . $color . '" title="' . chartH($label) . ': ' . (int) $n . '"></span></span>'
             . '<span class="ch-hn">' . (int) $n . '</span>';
        $d = $type !== '' ? ' data-type="' . chartH($type) . '" data-key="' . chartH($keys[$label] ?? $label) . '" tabindex="0"' : '';
        $h .= '<div class="ch-row' . ($d !== '' ? ' ch-click' : '') . '"' . $d . '>' . $row . '</div>';
    }
    return $h . '</div>';
}

/** Vertical bars for a series by day. $items: 'Y-m-d' => count. */
function chartColumns(array $items, string $color, string $type = ''): string
{
    $max = max(1, max($items ?: [0]));
    $h = '<div class="ch-cols">';
    foreach ($items as $day => $n) {
        $hgt = $n > 0 ? max(6, round(($n / $max) * 100)) : 2;
        $d = $type !== '' ? ' data-type="' . chartH($type) . '" data-key="' . chartH($day) . '" tabindex="0"' : '';
        $h .= '<div class="ch-col' . ($d !== '' ? ' ch-click' : '') . '"' . $d . ' title="' . chartH(date('j M', strtotime($day))) . ': ' . (int) $n . ' request' . ($n === 1 ? '' : 's') . ' (click for details)">'
            . '<span class="ch-colnum">' . ($n > 0 ? (int) $n : '') . '</span>'
            . '<span class="ch-bar" style="height:' . $hgt . '%;background:' . $color . '"></span>'
            . '<span class="ch-day">' . chartH(date('j', strtotime($day))) . '</span></div>';
    }
    return $h . '</div>';
}

function chartStyles(): string
{
    return <<<'CSS'
<style>
.ch-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1rem; margin-bottom: 1.5rem; }
.ch-card { background: #fff; border-radius: 12px; box-shadow: var(--shadow); padding: 1.1rem 1.25rem; }
.ch-card h3 { margin: 0 0 0.2rem; font-size: 1.05rem; }
.ch-card .ch-sub { margin: 0 0 0.9rem; color: var(--muted); font-size: 0.82rem; }
.ch-donut { display: flex; align-items: center; gap: 1.2rem; flex-wrap: wrap; }
.ch-donut-svg { width: 190px; height: 190px; flex: none; }
.ch-seg circle { transition: stroke-width .15s, opacity .15s; cursor: pointer; transform-origin: 21px 21px; animation: chDraw .8s ease-out; }
.ch-donut-svg:hover .ch-seg circle { opacity: .55; }
.ch-donut-svg .ch-seg:hover circle { opacity: 1; stroke-width: 7.5; }
@keyframes chDraw { from { opacity: 0; transform: rotate(-30deg); } to { opacity: 1; transform: none; } }
.ch-c-num, .ch-c-lab { pointer-events: none; }
.ch-c-num { font-size: 7px; font-weight: 800; fill: var(--ink); font-family: inherit; }
.ch-c-lab { font-size: 2.1px; fill: var(--muted); font-family: inherit; }
.ch-legend { list-style: none; margin: 0; padding: 0; flex: 1; min-width: 150px; }
.ch-legend li { padding: 0.3rem 0; font-size: 0.92rem; }
.ch-legend a { color: inherit; text-decoration: none; display: block; }
.ch-legend a:hover { color: var(--ink); text-decoration: underline; }
.ch-dot { display: inline-block; width: 11px; height: 11px; border-radius: 50%; margin-right: 0.5rem; vertical-align: -1px; }
.ch-hbars { display: flex; flex-direction: column; gap: 0.55rem; }
.ch-row { display: grid; grid-template-columns: 110px 1fr 34px; align-items: center; gap: 0.6rem; font-size: 0.88rem; color: inherit; text-decoration: none; }
a.ch-row:hover .ch-fill { filter: brightness(1.15); }
.ch-track { background: #ecebfb; border-radius: 6px; height: 16px; overflow: hidden; }
.ch-fill { display: block; height: 100%; border-radius: 6px; transform-origin: left; animation: chGrow .7s ease-out; min-width: 3px; }
@keyframes chGrow { from { transform: scaleX(0); } to { transform: none; } }
.ch-hn { font-weight: 700; text-align: right; }
.ch-cols { display: flex; align-items: flex-end; gap: 5px; height: 170px; }
.ch-col { flex: 1; height: 100%; display: flex; flex-direction: column; justify-content: flex-end; align-items: center; gap: 3px; }
.ch-bar { display: block; width: 100%; border-radius: 5px 5px 0 0; transform-origin: bottom; animation: chRise .7s ease-out; }
.ch-col:hover .ch-bar { filter: brightness(1.2); }
@keyframes chRise { from { transform: scaleY(0); } to { transform: none; } }
.ch-colnum { font-size: 0.72rem; font-weight: 700; color: var(--ink); min-height: 1em; }
.ch-day { font-size: 0.68rem; color: var(--muted); }
.ch-click { cursor: pointer; }
.ch-legend li.ch-click:hover { color: var(--ink); text-decoration: underline; }
.ch-row.ch-click:hover .ch-fill { filter: brightness(1.15); }
.ch-row.ch-click:hover .ch-hl { text-decoration: underline; }
dialog.ch-dialog { border: 0; border-radius: 16px; padding: 0; width: min(900px, calc(100vw - 2rem)); max-height: 85vh; box-shadow: 0 20px 50px rgba(21,19,58,.4); }
dialog.ch-dialog::backdrop { background: rgba(21,19,58,.45); backdrop-filter: blur(5px); -webkit-backdrop-filter: blur(5px); }
.chd-head { display: flex; justify-content: space-between; align-items: center; gap: 1rem; padding: 1rem 1.3rem; border-bottom: 1px solid var(--line); position: sticky; top: 0; background: #fff; }
.chd-head h3 { margin: 0; }
.chd-head small { color: var(--muted); display: block; font-weight: 400; }
.chd-close { border: 0; background: none; font-size: 1.7rem; cursor: pointer; color: var(--muted); line-height: 1; }
.chd-body { padding: 0.4rem 1.3rem 1.2rem; overflow: auto; max-height: calc(85vh - 80px); }
.chd-body table { width: 100%; border-collapse: collapse; font-size: 0.9rem; }
.chd-body th { text-align: left; color: var(--muted); font-size: 0.78rem; text-transform: uppercase; letter-spacing: .03em; padding: 0.6rem 0.5rem; border-bottom: 2px solid var(--line); }
.chd-body td { padding: 0.55rem 0.5rem; border-bottom: 1px solid var(--line-soft); }
.chd-body .chd-empty { color: var(--muted); padding: 1.2rem 0; }
.chd-link { display: inline-block; margin-top: 0.9rem; }
@media (prefers-reduced-motion: reduce) { .ch-seg circle, .ch-fill, .ch-bar { animation: none; } }
</style>
CSS;
}

function chartScript(): string
{
    return <<<'JS'
<dialog class="ch-dialog" id="chDialog">
    <div class="chd-head"><div><h3 id="chdTitle"></h3><small id="chdCount"></small></div><button type="button" class="chd-close" id="chdClose" aria-label="Close">&times;</button></div>
    <div class="chd-body" id="chdBody"></div>
</dialog>
<script>
(function () {
    // Hovering a doughnut slice shows its number in the middle
    document.querySelectorAll('.ch-donut').forEach(function (d) {
        var num = d.querySelector('.ch-c-num'), lab = d.querySelector('.ch-c-lab');
        if (!num || !lab) { return; }
        var n0 = num.textContent, l0 = lab.textContent;
        d.querySelectorAll('.ch-seg').forEach(function (s) {
            s.addEventListener('mouseenter', function () { num.textContent = s.getAttribute('data-n'); lab.textContent = s.getAttribute('data-label'); });
            s.addEventListener('mouseleave', function () { num.textContent = n0; lab.textContent = l0; });
        });
    });

    // Clicking a slice, bar or column opens the details in a window that stays until it is closed
    var dlg = document.getElementById('chDialog'), body = document.getElementById('chdBody');
    var title = document.getElementById('chdTitle'), count = document.getElementById('chdCount');
    document.getElementById('chdClose').addEventListener('click', function () { dlg.close(); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } });

    function show(t, c, node) {
        title.textContent = t; count.textContent = c;
        body.innerHTML = ''; body.appendChild(node);
        if (!dlg.open) { dlg.showModal(); }
    }
    function msg(text) { var p = document.createElement('p'); p.className = 'chd-empty'; p.textContent = text; return p; }

    function open(type, key) {
        show('Loading...', '', msg('Loading...'));
        fetch('admin.php?detail=1&type=' + encodeURIComponent(type) + '&key=' + encodeURIComponent(key), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.text().then(function (t) { var j = null; try { j = JSON.parse(t); } catch (e) { } return { ok: r.ok && j !== null, status: r.status, j: j || {}, raw: t }; }); })
            .then(function (res) {
                if (!res.ok) {
                    var why = res.j.error || ('The server answered with HTTP ' + res.status + (res.status === 404 ? ' (page not found).' : '. ' + res.raw.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 300)));
                    show('Details', '', msg(why));
                    return;
                }
                var d = res.j, wrap = document.createElement('div');
                if (!d.rows.length) {
                    wrap.appendChild(msg('Nothing to show.'));
                } else {
                    var table = document.createElement('table'), tr = document.createElement('tr');
                    d.columns.forEach(function (c) { var th = document.createElement('th'); th.textContent = c; tr.appendChild(th); });
                    table.appendChild(tr);
                    d.rows.forEach(function (row) {
                        var r = document.createElement('tr');
                        row.forEach(function (cell) { var td = document.createElement('td'); td.textContent = cell; r.appendChild(td); });
                        table.appendChild(r);
                    });
                    wrap.appendChild(table);
                }
                if (d.link) { var a = document.createElement('a'); a.href = d.link[0]; a.className = 'btn-small chd-link'; a.textContent = d.link[1]; wrap.appendChild(a); }
                show(d.title, d.rows.length + (d.rows.length === 1 ? ' entry' : ' entries'), wrap);
            })
            .catch(function () { show('Details', '', msg('No connection. Please try again.')); });
    }

    document.querySelectorAll('.ch-click').forEach(function (el) {
        function go() { open(el.getAttribute('data-type'), el.getAttribute('data-key')); }
        el.addEventListener('click', go);
        el.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
    });
})();
</script>
JS;
}