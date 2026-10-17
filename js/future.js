/* VectorIT — 2040 skin interactions. Vanilla, degrades to a plain page.
 * 1. Hero flow field: a few hundred faint arrows drift along a vector field.
 * 2. Hero frame tilts with the cursor.
 * 3. Solution cards carry a cursor spotlight.
 * 4. Pamphlet rail: arrows scroll it, click opens a lightbox.
 * 5. Numbers in the band count up when they come into view.
 */
(function () {
  "use strict";
  var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var fine = window.matchMedia("(min-width: 901px)").matches;

  /* ---------- 1. flow field ---------- */
  var canvas = document.querySelector(".fx-field canvas");
  if (canvas && !reduced) {
    var ctx = canvas.getContext("2d"), W = 0, H = 0, pts = [], t = 0, raf = null;
    function size() {
      var r = canvas.parentElement.getBoundingClientRect();
      W = canvas.width = Math.floor(r.width); H = canvas.height = Math.floor(r.height);
      var n = Math.min(260, Math.floor(W * H / 9000));
      pts = [];
      for (var i = 0; i < n; i++) pts.push({ x: Math.random() * W, y: Math.random() * H, a: 0 });
    }
    function angle(x, y, t) {
      return Math.sin(x * 0.0022 + t * 0.00035) * 1.3 + Math.cos(y * 0.0026 - t * 0.00028) * 1.1 - 0.6;
    }
    function draw(now) {
      t = now;
      ctx.clearRect(0, 0, W, H);
      ctx.strokeStyle = "rgba(46,91,219,0.16)"; ctx.fillStyle = "rgba(46,91,219,0.22)"; ctx.lineWidth = 1.2;
      for (var i = 0; i < pts.length; i++) {
        var p = pts[i], a = angle(p.x, p.y, t);
        p.x += Math.cos(a) * 0.35; p.y += Math.sin(a) * 0.35;
        if (p.x < -10 || p.x > W + 10 || p.y < -10 || p.y > H + 10) { p.x = Math.random() * W; p.y = Math.random() * H; }
        var L = 9, ex = p.x + Math.cos(a) * L, ey = p.y + Math.sin(a) * L;
        ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(ex, ey); ctx.stroke();
        ctx.beginPath(); ctx.moveTo(ex, ey);
        ctx.lineTo(ex - Math.cos(a - 0.5) * 3.2, ey - Math.sin(a - 0.5) * 3.2);
        ctx.lineTo(ex - Math.cos(a + 0.5) * 3.2, ey - Math.sin(a + 0.5) * 3.2);
        ctx.closePath(); ctx.fill();
      }
      raf = requestAnimationFrame(draw);
    }
    size(); window.addEventListener("resize", size);
    var hero = canvas.closest(".fx-hero");
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) { if (e.isIntersecting) { if (!raf) raf = requestAnimationFrame(draw); } else if (raf) { cancelAnimationFrame(raf); raf = null; } });
    });
    io.observe(hero);
  }

  /* ---------- 2. hero frame tilt ---------- */
  var scene = document.querySelector(".fx-scene"), frame = document.querySelector(".fx-frame");
  if (scene && frame && fine && !reduced) {
    scene.addEventListener("mousemove", function (e) {
      var r = scene.getBoundingClientRect(), x = (e.clientX - r.left) / r.width - 0.5, y = (e.clientY - r.top) / r.height - 0.5;
      frame.style.transform = "rotateY(" + (x * 8) + "deg) rotateX(" + (-y * 8) + "deg) translateZ(0)";
    });
    scene.addEventListener("mouseleave", function () { frame.style.transform = ""; });
  }

  /* ---------- 3. card spotlight ---------- */
  document.querySelectorAll(".fx-card").forEach(function (card) {
    card.addEventListener("mousemove", function (e) {
      var r = card.getBoundingClientRect();
      card.style.setProperty("--mx", (e.clientX - r.left) + "px");
      card.style.setProperty("--my", (e.clientY - r.top) + "px");
    });
  });

  /* ---------- 4. pamphlet rail + lightbox ---------- */
  var rail = document.querySelector(".fx-rail");
  if (rail) {
    document.querySelectorAll(".fx-rail-nav button").forEach(function (b) {
      b.addEventListener("click", function () {
        var leaf = rail.querySelector(".fx-leaf"), step = leaf ? leaf.getBoundingClientRect().width + 22 : 320;
        rail.scrollBy({ left: b.dataset.dir === "next" ? step : -step, behavior: "smooth" });
      });
    });
    var lb = document.querySelector(".fx-lb");
    if (lb) {
      var img = lb.querySelector("img"), dl = lb.querySelector(".fx-lb-dl");
      rail.querySelectorAll(".fx-leaf").forEach(function (leaf) {
        leaf.addEventListener("click", function () {
          img.src = leaf.dataset.full; img.alt = leaf.querySelector("img").alt;
          dl.href = leaf.dataset.full; lb.classList.add("on"); document.body.style.overflow = "hidden";
        });
      });
      function close() { lb.classList.remove("on"); document.body.style.overflow = ""; }
      lb.addEventListener("click", function (e) { if (e.target === lb || e.target.classList.contains("fx-lb-x")) close(); });
      document.addEventListener("keydown", function (e) { if (e.key === "Escape") close(); });
    }
  }

  /* ---------- 5. count-up ---------- */
  var nums = document.querySelectorAll("[data-count]");
  if (nums.length) {
    var seen = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return; seen.unobserve(e.target);
        var el = e.target, end = parseFloat(el.dataset.count), suffix = el.dataset.suffix || "", t0 = null;
        if (reduced) { el.firstChild.nodeValue = end + suffix; return; }
        function step(now) {
          if (!t0) t0 = now; var k = Math.min(1, (now - t0) / 1100); k = 1 - Math.pow(1 - k, 3);
          el.firstChild.nodeValue = Math.round(end * k) + suffix;
          if (k < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
      });
    }, { threshold: 0.5 });
    nums.forEach(function (n) { seen.observe(n); });
  }
})();
