<?php
$slides = [
    's1.jpg' => 'Sell the laptop you no longer need',
    's2.jpg' => 'Find textbooks from students on your course',
    's3.jpg' => 'Trade study gear with people you can trust',
    's4.jpg' => 'Every seller is a verified student',
];
?>
<div class="split-left has-slides">
    <div class="slides" id="authSlides">
        <?php $i = 0; foreach ($slides as $file => $caption): ?>
            <div class="slide<?= $i === 0 ? ' active' : '' ?>"
                 style="background-image:url('../public/images/students/<?= htmlspecialchars($file) ?>')"
                 data-caption="<?= htmlspecialchars($caption) ?>"></div>
        <?php $i++; endforeach; ?>
        <div class="slides-shade"></div>
    </div>

    <div class="brand-row">
        <span class="brand-pill">
            <img src="../public/images/logo.svg" alt="TrustTrade" style="height:40px;">
        </span>
    </div>

    <div class="slides-text">
        <p class="slide-caption" id="slideCaption"><?= htmlspecialchars(reset($slides)) ?></p>
        <p class="split-tagline"><?= htmlspecialchars($panelTagline) ?></p>
        <div class="slide-dots" id="slideDots">
            <?php for ($d = 0; $d < count($slides); $d++): ?>
                <button type="button" class="dot<?= $d === 0 ? ' active' : '' ?>"
                        aria-label="Show photo <?= $d + 1 ?>" data-i="<?= $d ?>"></button>
            <?php endfor; ?>
        </div>
    </div>
</div>

<script>
(function () {
    var slides  = document.querySelectorAll('#authSlides .slide');
    var dots    = document.querySelectorAll('#slideDots .dot');
    var caption = document.getElementById('slideCaption');
    if (slides.length < 2) { return; }

    var current = 0, timer = null;

    function show(n) {
        slides[current].classList.remove('active');
        dots[current].classList.remove('active');
        current = (n + slides.length) % slides.length;
        slides[current].classList.add('active');
        dots[current].classList.add('active');
        caption.textContent = slides[current].getAttribute('data-caption');
    }

    function start() {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { return; }
        timer = setInterval(function () { show(current + 1); }, 4500);
    }

    dots.forEach(function (dot) {
        dot.addEventListener('click', function () {
            clearInterval(timer);
            show(parseInt(dot.getAttribute('data-i'), 10));
            start();
        });
    });

    start();
})();
</script>