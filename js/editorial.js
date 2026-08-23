/* VectorIT — editorial homepage interactions. Vanilla, no libraries:
 * the page scrolls natively; motion is load-time line reveals and the
 * index preview that trails the cursor. Everything degrades to a plain
 * readable document with JS off or reduced-motion on.
 */
(function () {
  "use strict";

  var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------- hero: lines rise out of their masks ---------- */
  var lines = document.querySelectorAll(".ed-line > span");
  if (lines.length && !reduced) {
    lines.forEach(function (s, i) {
      s.style.transform = "translateY(110%)";
      s.style.transition = "transform .9s cubic-bezier(.22,1,.36,1) " + (0.09 * i) + "s";
    });
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        lines.forEach(function (s) { s.style.transform = "translateY(0)"; });
      });
    });
  }

  /* ---------- index: the preview trails the cursor ---------- */
  var fine = window.matchMedia("(min-width: 901px) and (hover: hover)").matches
    || window.matchMedia("(min-width: 901px)").matches; /* hover MQ lies in some headless/pane envs */
  var preview = document.querySelector(".ed-preview");
  var indexEl = document.querySelector(".ed-index");
  if (preview && indexEl && fine && !reduced) {
    var imgs = preview.querySelectorAll("img");
    var tx = 0, ty = 0, cx = 0, cy = 0, visible = false, raf = null;

    function tick() {
      cx += (tx - cx) * 0.14;
      cy += (ty - cy) * 0.14;
      preview.style.transform = "translate(" + (cx + 24) + "px," + (cy - preview.offsetHeight / 2) + "px)";
      if (Math.abs(tx - cx) > 0.3 || Math.abs(ty - cy) > 0.3 || visible) {
        raf = requestAnimationFrame(tick);
      } else { raf = null; }
    }

    indexEl.addEventListener("mousemove", function (e) {
      tx = Math.min(e.clientX, window.innerWidth - preview.offsetWidth - 60);
      ty = e.clientY;
      if (!raf) { raf = requestAnimationFrame(tick); }
    });

    indexEl.querySelectorAll(".ed-row").forEach(function (row) {
      row.addEventListener("mouseenter", function () {
        var want = row.getAttribute("data-preview");
        imgs.forEach(function (im) { im.classList.toggle("on", im.getAttribute("data-for") === want); });
        visible = true;
        preview.style.opacity = "1";
      });
      row.addEventListener("mouseleave", function () {
        visible = false;
        preview.style.opacity = "0";
      });
    });
  }
})();
