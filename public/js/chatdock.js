/* Chat pop-ups on every page, like the old Facebook chat. Plain JavaScript, no libraries.
   - chat_inbox.php tells which conversations have unread messages, a small pop-up announces a new one.
   - "Reply" opens a small chat box in the bottom right corner. It uses chat_api.php, the same as the full chat page.
   - Open boxes are remembered in sessionStorage, so they stay open when the person goes to another page. */
(function () {
    'use strict';
    if (window.__ttDock) { return; }
    window.__ttDock = true;

    var ME = (document.currentScript && document.currentScript.getAttribute('data-me')) || '0';
    var FRESH = !!(document.currentScript && document.currentScript.getAttribute('data-fresh'));   // first page after logging in
    var INBOX = 'chat_inbox.php', API = 'chat_api.php';
    var boxes = [];            // open chat boxes: {l, w, name, initials, title, min, lastId, seenId, sig, el, busy}
    var notified = {};         // conversation key -> last message id already announced
    var firstPoll = true;
    var unreadByKey = {};

    function key(l, w) { return l + '_' + w; }
    function store(name, value) { try { sessionStorage.setItem(name + '_' + ME, JSON.stringify(value)); } catch (e) { } }
    function load(name, fallback) { try { var v = sessionStorage.getItem(name + '_' + ME); return v ? JSON.parse(v) : fallback; } catch (e) { return fallback; } }
    function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text !== undefined) { e.textContent = text; } return e; }

    /* ---------- styles ---------- */
    var css = '' +
    '.cd-wrap{position:fixed;right:16px;bottom:0;z-index:55;display:flex;flex-direction:row-reverse;gap:12px;align-items:flex-end;pointer-events:none;}' +
    '.cd-box{pointer-events:auto;width:320px;max-width:calc(100vw - 32px);background:#fff;border:1px solid var(--line);border-bottom:0;border-radius:12px 12px 0 0;box-shadow:0 -2px 18px rgba(35,33,91,.22);display:flex;flex-direction:column;}' +
    '.cd-head{display:flex;align-items:center;gap:.5rem;padding:.55rem .7rem;background:var(--ink);color:#fff;border-radius:11px 11px 0 0;cursor:pointer;}' +
    '.cd-head .avatar{width:28px;height:28px;font-size:.7rem;flex:none;}' +
    '.cd-name{flex:1;min-width:0;line-height:1.15;}' +
    '.cd-name b{display:block;font-size:.9rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
    '.cd-name small{display:block;font-size:.72rem;opacity:.8;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
    '.cd-btn{background:none;border:0;color:#fff;font-size:1.1rem;line-height:1;cursor:pointer;padding:.15rem .3rem;border-radius:6px;text-decoration:none;}' +
    '.cd-btn:hover{background:rgba(255,255,255,.2);}' +
    '.cd-dot{background:var(--hl);color:var(--ink-deep);font-weight:700;font-size:.7rem;border-radius:999px;padding:0 .4rem;}' +
    '.cd-body{height:280px;overflow-y:auto;padding:.6rem;background:var(--panel);display:flex;flex-direction:column;gap:3px;}' +
    '.cd-box.min .cd-body,.cd-box.min .cd-foot{display:none;}' +
    '.cd-m{max-width:80%;padding:.4rem .7rem;border-radius:16px;font-size:.88rem;line-height:1.35;word-wrap:break-word;overflow-wrap:anywhere;}' +
    '.cd-m.them{align-self:flex-start;background:#e9e8f5;color:var(--text);}' +
    '.cd-m.me{align-self:flex-end;background:var(--ink);color:#fff;}' +
    '.cd-m.sys{align-self:center;background:none;color:var(--muted);font-size:.78rem;text-align:center;}' +
    '.cd-seen{align-self:flex-end;font-size:.7rem;color:var(--muted);}' +
    '.cd-foot{display:flex;gap:.4rem;padding:.5rem;border-top:1px solid var(--line);background:#fff;}' +
    '.cd-foot textarea{flex:1;resize:none;border:1px solid var(--line);border-radius:16px;padding:.4rem .7rem;font:inherit;font-size:.88rem;max-height:80px;}' +
    '.cd-foot button{border:0;background:var(--ink);color:#fff;border-radius:16px;padding:0 .9rem;cursor:pointer;font-weight:700;}' +
    '.cd-foot button:disabled{opacity:.5;}' +
    '.cd-err{color:#611a15;font-size:.78rem;padding:0 .6rem .3rem;background:#fff;display:none;}' +
    '.cd-toasts{position:fixed;right:16px;bottom:16px;z-index:56;display:flex;flex-direction:column;gap:.6rem;width:330px;max-width:calc(100vw - 32px);pointer-events:none;}' +
    '.cd-toast{pointer-events:auto;background:#fff;border:2px solid var(--ink);border-left:6px solid var(--hl);border-radius:14px;box-shadow:0 8px 26px rgba(35,33,91,.3);padding:.7rem .8rem;animation:cdIn .25s ease-out;}' +
    '.cd-toast.out{opacity:0;transform:translateX(20px);transition:opacity .35s,transform .35s;}' +
    '@keyframes cdIn{from{opacity:0;transform:translateX(24px);}to{opacity:1;transform:none;}}' +
    '.cd-toast .top{display:flex;gap:.6rem;align-items:center;}' +
    '.cd-toast .avatar{flex:none;}' +
    '.cd-toast .who{flex:1;min-width:0;}' +
    '.cd-toast .who b{display:block;font-size:.92rem;}' +
    '.cd-toast .who small{display:block;color:var(--muted);font-size:.75rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}' +
    '.cd-toast p{margin:.35rem 0 .5rem;font-size:.9rem;}' +
    '.cd-toast .x{background:none;border:0;font-size:1.2rem;cursor:pointer;color:var(--muted);align-self:flex-start;}' +
    '.cd-toast .row{display:flex;gap:.5rem;}';
    var style = document.createElement('style'); style.textContent = css; document.head.appendChild(style);

    var wrap = el('div', 'cd-wrap');
    var toasts = el('div', 'cd-toasts');
    document.body.appendChild(wrap);
    document.body.appendChild(toasts);

    function layoutToasts() {
        var h = 0;
        boxes.forEach(function (b) { h = Math.max(h, b.el.offsetHeight); });
        toasts.style.bottom = (h ? h + 14 : 16) + 'px';
    }

    /* ---------- chat boxes ---------- */
    function findBox(l, w) { for (var i = 0; i < boxes.length; i++) { if (boxes[i].l === l && boxes[i].w === w) { return boxes[i]; } } return null; }
    function saveBoxes() { store('tt_dock', boxes.map(function (b) { return { l: b.l, w: b.w, name: b.name, initials: b.initials, title: b.title, min: b.min }; })); }

    function openBox(info, minimized) {
        var b = findBox(info.l, info.w);
        if (b) { if (b.min && !minimized) { setMin(b, false); } return b; }
        if (boxes.length >= 3) { closeBox(boxes[boxes.length - 1]); }
        b = { l: info.l, w: info.w, name: info.name, initials: info.initials, title: info.title, min: !!minimized, lastId: 0, seenId: 0, sig: '', busy: false, timer: null };
        var box = el('div', 'cd-box' + (b.min ? ' min' : ''));
        var head = el('div', 'cd-head');
        head.appendChild(el('span', 'avatar', b.initials));
        var nm = el('div', 'cd-name'); nm.appendChild(el('b', '', b.name)); nm.appendChild(el('small', '', b.title));
        head.appendChild(nm);
        b.dot = el('span', 'cd-dot'); b.dot.style.display = 'none'; head.appendChild(b.dot);
        var full = el('a', 'cd-btn', '\u2197'); full.href = 'conversation.php?listing=' + b.l + '&with=' + b.w; full.title = 'Open the full chat'; full.addEventListener('click', function (e) { e.stopPropagation(); });
        var mn = el('button', 'cd-btn', '\u2013'); mn.type = 'button'; mn.title = 'Minimise';
        var cl = el('button', 'cd-btn', '\u00d7'); cl.type = 'button'; cl.title = 'Close';
        head.appendChild(full); head.appendChild(mn); head.appendChild(cl);
        b.body = el('div', 'cd-body');
        var err = el('div', 'cd-err'); b.err = err;
        var foot = el('form', 'cd-foot');
        b.input = el('textarea'); b.input.rows = 1; b.input.placeholder = 'Write a message...'; b.input.maxLength = 2000;
        b.send = el('button', '', 'Send'); b.send.type = 'submit';
        foot.appendChild(b.input); foot.appendChild(b.send);
        box.appendChild(head); box.appendChild(b.body); box.appendChild(err); box.appendChild(foot);
        b.el = box;
        wrap.insertBefore(box, wrap.firstChild);
        boxes.unshift(b);

        head.addEventListener('click', function () { setMin(b, !b.min); });
        mn.addEventListener('click', function (e) { e.stopPropagation(); setMin(b, !b.min); });
        cl.addEventListener('click', function (e) { e.stopPropagation(); closeBox(b); });
        foot.addEventListener('submit', function (e) { e.preventDefault(); sendMsg(b); });
        b.input.addEventListener('keydown', function (e) { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); sendMsg(b); } });
        saveBoxes();
        if (!b.min) { poll(b); }
        refreshDots();
        layoutToasts();
        return b;
    }

    function setMin(b, min) {
        b.min = min;
        b.el.classList.toggle('min', min);
        saveBoxes();
        clearTimeout(b.timer);
        if (!min) { poll(b); b.input.focus(); }
        refreshDots();
        layoutToasts();
    }

    function closeBox(b) {
        clearTimeout(b.timer);
        if (b.el.parentNode) { b.el.parentNode.removeChild(b.el); }
        boxes = boxes.filter(function (x) { return x !== b; });
        saveBoxes();
        layoutToasts();
    }

    function refreshDots() {
        boxes.forEach(function (b) {
            var n = (b.min && unreadByKey[key(b.l, b.w)]) || 0;
            b.dot.textContent = n; b.dot.style.display = n ? '' : 'none';
        });
    }

    function render(b, msgs, stick) {
        msgs.forEach(function (m) {
            if (b.body.querySelector('[data-id="' + m.id + '"]')) { return; }
            var d = el('div', 'cd-m ' + (m.system ? 'sys' : (m.mine ? 'me' : 'them')));
            d.setAttribute('data-id', m.id);
            d.title = m.ts.slice(11, 16);
            String(m.body).split('\n').forEach(function (line, i) { if (i) { d.appendChild(document.createElement('br')); } d.appendChild(document.createTextNode(line)); });
            b.body.appendChild(d);
            if (m.id > b.lastId) { b.lastId = m.id; }
        });
        var old = b.body.querySelector('.cd-seen'); if (old) { old.parentNode.removeChild(old); }
        var mine = b.body.querySelectorAll('.cd-m.me');
        if (mine.length) {
            var last = mine[mine.length - 1];
            var s = el('div', 'cd-seen', parseInt(last.getAttribute('data-id'), 10) <= b.seenId ? 'Seen' : 'Sent');
            b.body.appendChild(s);
        }
        if (stick) { b.body.scrollTop = b.body.scrollHeight; }
    }

    function handle(b, data) {
        if (!data) { return; }
        var stick = b.body.scrollHeight - b.body.scrollTop - b.body.clientHeight < 80 || b.lastId === 0;
        if (typeof data.seen === 'number') { b.seenId = data.seen; }
        if (data.sig) { b.sig = data.sig; }
        render(b, data.messages || [], stick);
        delete unreadByKey[key(b.l, b.w)];
    }

    function poll(b) {
        clearTimeout(b.timer);
        if (b.min || !b.el.parentNode) { return; }
        if (b.busy) { b.timer = setTimeout(function () { poll(b); }, 1000); return; }
        b.busy = true;
        fetch(API + '?listing=' + b.l + '&with=' + b.w + '&after=' + b.lastId + '&sig=' + encodeURIComponent(b.sig), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (d) { b.err.style.display = 'none'; handle(b, d); })
            .catch(function (e) { if (e === 404 || e === 401) { b.err.textContent = e === 401 ? 'Please log in again.' : 'This chat is not available.'; b.err.style.display = 'block'; } })
            .then(function () { b.busy = false; b.timer = setTimeout(function () { poll(b); }, document.hidden ? 10000 : 2500); });
    }

    function sendMsg(b) {
        var text = b.input.value.trim();
        if (!text || b.send.disabled) { return; }
        b.send.disabled = true; b.busy = true;
        var p = new URLSearchParams();
        p.append('listing', b.l); p.append('with', b.w); p.append('body', text); p.append('after', b.lastId); p.append('sig', b.sig);
        fetch(API, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body: p })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
            .then(function (res) {
                if (!res.ok || res.j.error) { b.err.textContent = res.j.error || 'The message could not be sent.'; b.err.style.display = 'block'; return; }
                b.err.style.display = 'none'; b.input.value = '';
                handle(b, res.j); b.body.scrollTop = b.body.scrollHeight;
            })
            .catch(function () { b.err.textContent = 'No connection. The message was not sent.'; b.err.style.display = 'block'; })
            .then(function () { b.send.disabled = false; b.busy = false; b.input.focus(); poll(b); });
    }

    /* ---------- pop-up for a new message ---------- */
    function toast(item) {
        var t = el('div', 'cd-toast');
        var top = el('div', 'top');
        top.appendChild(el('span', 'avatar', item.initials));
        var who = el('div', 'who'); who.appendChild(el('b', '', item.name + (item.count > 1 ? ' (' + item.count + ' new)' : ''))); who.appendChild(el('small', '', item.title));
        top.appendChild(who);
        var x = el('button', 'x', '\u00d7'); x.type = 'button'; x.setAttribute('aria-label', 'Dismiss'); top.appendChild(x);
        t.appendChild(top);
        t.appendChild(el('p', '', item.snippet));
        var row = el('div', 'row');
        var reply = el('button', 'btn-small', 'Reply'); reply.type = 'button';
        var open = el('a', 'btn-small btn-ghost', 'Open chat'); open.href = 'conversation.php?listing=' + item.listing + '&with=' + item.with;
        row.appendChild(reply); row.appendChild(open); t.appendChild(row);
        toasts.appendChild(t);
        layoutToasts();
        var timer = setTimeout(gone, 12000);
        function gone() { clearTimeout(timer); t.classList.add('out'); setTimeout(function () { if (t.parentNode) { t.parentNode.removeChild(t); } }, 400); }
        x.addEventListener('click', gone);
        t.addEventListener('mouseenter', function () { clearTimeout(timer); });
        reply.addEventListener('click', function () {
            gone();
            var b = openBox({ l: item.listing, w: item.with, name: item.name, initials: item.initials, title: item.title }, false);
            if (b) { b.input.focus(); }
        });
    }

    /* ---------- ask the server what is unread ---------- */
    function checkInbox() {
        fetch(INBOX, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (d) {
                unreadByKey = {};
                (d.items || []).forEach(function (it) {
                    var k = key(it.listing, it.with);
                    unreadByKey[k] = it.count;
                    var open = findBox(it.listing, it.with);
                    if (!firstPoll && !(notified[k] >= it.last_id) && !(open && !open.min)) {
                        toast(it);
                        if (open && open.min) { /* the dot on the box shows it */ }
                    }
                    if (!(notified[k] >= it.last_id)) { notified[k] = it.last_id; }
                });
                store('tt_notified', notified);
                firstPoll = false;
                refreshDots();
            })
            .catch(function () { })
            .then(function () { setTimeout(checkInbox, document.hidden ? 20000 : 6000); });
    }

    /* ---------- start ---------- */
    if (FRESH) { try { sessionStorage.removeItem('tt_notified_' + ME); sessionStorage.removeItem('tt_dock_' + ME); } catch (e) { } }
    notified = load('tt_notified', {});
    firstPoll = !sessionStorage.getItem('tt_notified_' + ME);   // the first page of a session only sets the baseline
    load('tt_dock', []).forEach(function (s) { openBox({ l: s.l, w: s.w, name: s.name, initials: s.initials, title: s.title }, s.min); });
    checkInbox();
})();