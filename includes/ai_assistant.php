<?php?>
<script>
(function () {
    var form = document.querySelector('form.form-stack');
    if (!form) return;
    var title = form.querySelector('[name=title]'), desc = form.querySelector('[name=description]'),
        price = form.querySelector('[name=price]'), cat = form.querySelector('[name=category]');
    if (!title || !price) return;

    var box = document.createElement('div');
    box.className = 'ai-box';
    box.hidden = true;
    box.setAttribute('aria-live', 'polite');
    price.insertAdjacentElement('afterend', box);

    var timer = null, seq = 0, lastAssessment = 'unknown';
    function esc(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

    function render(r) {
        lastAssessment = r.assessment || 'unknown';
        var h = '', p = r.price;
        if (p) {
            h += '<p class="ai-line"><span class="ai-tag">AI price hint</span> Similar listings sell for about <strong>' + p.mid +
                 ' kr</strong> (range ' + p.low + '&ndash;' + p.high + ' kr, ' + p.n + ' similar listings, ' + p.confidence + ' confidence). ' +
                 '<button type="button" class="ai-use" data-price="' + p.mid + '">Use ' + p.mid + ' kr</button></p>';
        }
        if (r.assessment === 'underpriced') {
            h += '<p class="ai-warn">This is less than half the usual price. Buyers may suspect a scam, and you may be selling too cheaply.</p>';
        } else if (r.assessment === 'overpriced') {
            h += '<p class="ai-note">This is well above similar listings, so it may take longer to sell.</p>';
        }
        if (r.category) {
            h += '<p class="ai-line"><span class="ai-tag">AI category</span> This looks like <strong>' + esc(r.category.name) +
                 '</strong>. <button type="button" class="ai-use" data-cat="' + esc(r.category.name) + '">Use it</button></p>';
        }
        (r.warnings || []).forEach(function (w) { h += '<p class="ai-warn">' + esc(w) + '</p>'; });
        box.innerHTML = h;
        box.hidden = !h;
    }

    function ask() {
        var my = ++seq, fd = new FormData();
        fd.append('title', title.value); fd.append('description', desc ? desc.value : '');
        fd.append('price', price.value); fd.append('category', cat ? cat.value : '');
        fetch('ai_assist.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (r) { if (r && my === seq) render(r); })
            .catch(function () {});
    }
    function later() { clearTimeout(timer); timer = setTimeout(ask, 600); }

    [title, desc, price, cat].forEach(function (el) { if (el) { el.addEventListener('input', later); el.addEventListener('change', later); } });
    form.addEventListener('submit', function (e) {
        if (lastAssessment === 'underpriced' && !confirm('This price is far below similar listings. Listings like this are often held for review as possible scams. Post it at this price anyway?')) {
            e.preventDefault();
        }
    });
    box.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        if (b.dataset.price) { price.value = b.dataset.price; }
        if (b.dataset.cat && cat) {
            var o = Array.prototype.find.call(cat.options || [], function (x) { return x.value.toLowerCase() === b.dataset.cat.toLowerCase(); });
            if (o) { cat.value = o.value; } else if (cat.tagName === 'INPUT') { cat.value = b.dataset.cat; }
        }
        later();
    });
})();
</script>