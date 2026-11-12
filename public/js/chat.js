
(function () {
    'use strict';
    var root = document.getElementById('chat');
    if (!root) { return; }

    var api = root.getAttribute('data-api');
    var listingId = root.getAttribute('data-listing');
    var withId = root.getAttribute('data-with');
    var initials = root.getAttribute('data-initials') || '?';
    var lastId = parseInt(root.getAttribute('data-last'), 10) || 0;
    var seenId = parseInt(root.getAttribute('data-seen'), 10) || 0;
    var sig = root.getAttribute('data-sig') || '';

    var list = document.getElementById('chatList');
    var scroller = document.getElementById('chatScroll');
    var form = document.getElementById('chatForm');
    var input = document.getElementById('chatInput');
    var sendBtn = document.getElementById('chatSend');
    var errBox = document.getElementById('chatError');
    var orderBox = document.getElementById('chatOrder');
    var chips = document.getElementById('chatChips');
    var draftKey = 'tt_draft_' + listingId + '_' + withId;
    var busy = false;

    function pad(n) { return n < 10 ? '0' + n : '' + n; }
    function todayKey() { var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    function yesterdayKey() { var d = new Date(Date.now() - 86400000); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
    var MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    function dayLabel(ts) {
        var key = ts.slice(0, 10);
        if (key === todayKey()) { return 'Today'; }
        if (key === yesterdayKey()) { return 'Yesterday'; }
        return parseInt(ts.slice(8, 10), 10) + ' ' + MONTHS[parseInt(ts.slice(5, 7), 10) - 1];
    }
    function ms(ts) { return new Date(ts.replace(' ', 'T')).getTime(); }

    function showError(text) {
        if (!errBox) { return; }
        errBox.textContent = text || '';
        errBox.style.display = text ? 'block' : 'none';
    }

    function nearBottom() { return scroller.scrollHeight - scroller.scrollTop - scroller.clientHeight < 90; }
    function toBottom() { scroller.scrollTop = scroller.scrollHeight; }

    function makeMessage(m) {
        var div = document.createElement('div');
        div.className = 'msg ' + (m.system ? 'msg-system' : (m.mine ? 'msg-me' : 'msg-them'));
        div.setAttribute('data-id', m.id);
        div.setAttribute('data-ts', m.ts);
        var bubble = document.createElement('div');
        bubble.className = 'msg-bubble';
        String(m.body).split('\n').forEach(function (line, i) {
            if (i > 0) { bubble.appendChild(document.createElement('br')); }
            bubble.appendChild(document.createTextNode(line));
        });
        div.appendChild(bubble);
        return div;
    }

    /* Day separators, grouping of messages from the same person, time under each group, avatar, and "Seen". */
    function regroup() {
        Array.prototype.forEach.call(list.querySelectorAll('.msg-day, .msg-time, .msg-seen, .msg-avatar'), function (el) { el.parentNode.removeChild(el); });
        var empty = document.getElementById('chatEmpty');
        var msgs = list.querySelectorAll('.msg');
        if (empty && msgs.length) { empty.parentNode.removeChild(empty); }
        var prev = null, prevDay = '';
        var lastMine = null;
        Array.prototype.forEach.call(msgs, function (el, i) {
            var ts = el.getAttribute('data-ts');
            var day = ts.slice(0, 10);
            var system = el.classList.contains('msg-system');
            if (day !== prevDay) {
                var sep = document.createElement('div');
                sep.className = 'msg-day';
                sep.textContent = dayLabel(ts);
                list.insertBefore(sep, el);
                prevDay = day;
                prev = null;
            }
            var same = prev && !system && !prev.classList.contains('msg-system') &&
                prev.className.split(' ')[1] === el.className.split(' ')[1] &&
                (ms(ts) - ms(prev.getAttribute('data-ts'))) < 5 * 60000;
            el.classList.toggle('cont', !!same);
            el.classList.remove('group-end');
            prev = el;
            if (!system && el.classList.contains('msg-me')) { lastMine = el; }
        });
        /* mark the last message of every group, and add time (and avatar for the other person) */
        var all = list.querySelectorAll('.msg');
        Array.prototype.forEach.call(all, function (el, i) {
            var next = all[i + 1];
            var end = !next || !next.classList.contains('cont') || next.previousElementSibling.classList.contains('msg-day');
            if (next && next.classList.contains('cont')) { end = false; }
            if (end) {
                el.classList.add('group-end');
                if (!el.classList.contains('msg-system')) {
                    var t = document.createElement('div');
                    t.className = 'msg-time';
                    t.textContent = el.getAttribute('data-ts').slice(11, 16);
                    el.appendChild(t);
                    if (el.classList.contains('msg-them')) {
                        var a = document.createElement('span');
                        a.className = 'avatar msg-avatar';
                        a.textContent = initials;
                        el.insertBefore(a, el.firstChild);
                    }
                }
            }
        });
        if (lastMine) {
            var id = parseInt(lastMine.getAttribute('data-id'), 10);
            var s = document.createElement('div');
            s.className = 'msg-seen';
            s.textContent = id <= seenId ? 'Seen' : 'Sent';
            lastMine.appendChild(s);
        }
    }

    function handle(data) {
        if (!data) { return; }
        var stick = nearBottom();
        var added = false, mineAdded = false;
        (data.messages || []).forEach(function (m) {
            if (list.querySelector('[data-id="' + m.id + '"]')) { return; }
            list.appendChild(makeMessage(m));
            if (m.id > lastId) { lastId = m.id; }
            added = true;
            if (m.mine) { mineAdded = true; }
        });
        if (typeof data.seen === 'number') { seenId = data.seen; }
        if (data.order_html !== undefined && orderBox) { orderBox.innerHTML = data.order_html; }
        if (data.sig) { sig = data.sig; }
        regroup();
        if (added && (stick || mineAdded)) { toBottom(); }
    }

    function query(extra) {
        var p = 'listing=' + encodeURIComponent(listingId) + '&with=' + encodeURIComponent(withId) +
            '&after=' + lastId + '&sig=' + encodeURIComponent(sig);
        return extra ? p + '&' + extra : p;
    }

    function poll() {
        if (busy) { return schedule(); }
        busy = true;
        fetch(api + '?' + query(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.ok ? r.json() : Promise.reject(r.status); })
            .then(function (data) { showError(''); handle(data); })
            .catch(function (e) { if (e === 401) { showError('Please log in again.'); } })
            .then(function () { busy = false; schedule(); });
    }
    var timer = null;
    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(poll, document.hidden ? 10000 : 2500);
    }
    document.addEventListener('visibilitychange', function () { if (!document.hidden) { clearTimeout(timer); poll(); } });

    function grow() {
        if (!input) { return; }
        input.style.height = 'auto';
        input.style.height = Math.min(input.scrollHeight, 120) + 'px';
    }

    function sendMessage() {
        var text = input.value.trim();
        if (text === '' || sendBtn.disabled) { return; }
        sendBtn.disabled = true;
        busy = true;
        var body = new URLSearchParams();
        body.append('listing', listingId);
        body.append('with', withId);
        body.append('body', text);
        body.append('after', lastId);
        body.append('sig', sig);
        fetch(api, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' }, body: body })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); })
            .then(function (res) {
                if (!res.ok || res.json.error) {
                    showError(res.json.error || 'The message could not be sent.');
                    return;
                }
                showError('');
                input.value = '';
                try { sessionStorage.removeItem(draftKey); } catch (e) { }
                grow();
                handle(res.json);
            })
            .catch(function () { showError('No connection. The message was not sent.'); })
            .then(function () { sendBtn.disabled = false; busy = false; input.focus(); schedule(); });
    }

    if (form && input) {
        form.addEventListener('submit', function (e) { e.preventDefault(); sendMessage(); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); sendMessage(); }
        });
        input.addEventListener('input', function () {
            grow();
            try { sessionStorage.setItem(draftKey, input.value); } catch (e) { }
        });
        try { var d = sessionStorage.getItem(draftKey); if (d) { input.value = d; grow(); } } catch (e) { }
    }
    if (chips && input) {
        chips.addEventListener('click', function (e) {
            var b = e.target.closest('button');
            if (!b) { return; }
            input.value = (input.value ? input.value + ' ' : '') + b.getAttribute('data-text');
            grow();
            input.focus();
        });
    }

    regroup();
    toBottom();
    schedule();
})();