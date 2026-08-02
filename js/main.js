/* VectorIT — site interactions */
(function () {
  "use strict";

  var reducedMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------- sticky header ---------- */
  var header = document.querySelector(".site-header");
  function onScroll() {
    if (window.scrollY > 24) header.classList.add("scrolled");
    else header.classList.remove("scrolled");
  }
  window.addEventListener("scroll", onScroll, { passive: true });
  onScroll();

  /* ---------- mobile nav ---------- */
  var toggle = document.querySelector(".nav-toggle");
  var links = document.querySelector(".nav-links");
  if (toggle && links) {
    toggle.addEventListener("click", function () {
      var open = links.classList.toggle("open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });
    links.addEventListener("click", function (e) {
      if (e.target.tagName === "A") {
        links.classList.remove("open");
        toggle.setAttribute("aria-expanded", "false");
      }
    });
  }

  /* ---------- scroll reveal ---------- */
  var revealEls = document.querySelectorAll(".reveal");
  if ("IntersectionObserver" in window && !reducedMotion) {
    var io = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("visible");
            io.unobserve(entry.target);
          }
        });
      },
      // Low threshold on purpose: a tall element (e.g. an image gallery) can never
      // reach a high visibility ratio on a short viewport, and would stay hidden.
      { threshold: 0.02, rootMargin: "0px 0px -40px 0px" }
    );
    revealEls.forEach(function (el) { io.observe(el); });
  } else {
    revealEls.forEach(function (el) { el.classList.add("visible"); });
  }

  /* ---------- animated counters ---------- */
  var counters = document.querySelectorAll("[data-count]");
  if (counters.length && "IntersectionObserver" in window) {
    var cio = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (!entry.isIntersecting) return;
          var el = entry.target;
          cio.unobserve(el);
          var target = parseFloat(el.getAttribute("data-count"));
          var suffix = el.getAttribute("data-suffix") || "";
          if (reducedMotion) { el.textContent = target + suffix; return; }
          var start = null;
          var dur = 1400;
          function tick(ts) {
            if (!start) start = ts;
            var p = Math.min((ts - start) / dur, 1);
            var eased = 1 - Math.pow(1 - p, 3);
            el.textContent = Math.round(target * eased) + suffix;
            if (p < 1) requestAnimationFrame(tick);
          }
          requestAnimationFrame(tick);
        });
      },
      { threshold: 0.6 }
    );
    counters.forEach(function (el) { cio.observe(el); });
  }

  /* ---------- footer year ---------- */
  var yearEl = document.getElementById("year");
  if (yearEl) yearEl.textContent = new Date().getFullYear();

  /* ---------- vector field hero ---------- */
  var canvas = document.getElementById("vector-field");
  if (canvas && !reducedMotion) {
    var ctx = canvas.getContext("2d");
    var dpr = Math.min(window.devicePixelRatio || 1, 2);
    var arrows = [];
    var SPACING = 56;
    var mouse = { x: -9999, y: -9999 };
    var idleT = 0;

    function resize() {
      var rect = canvas.parentElement.getBoundingClientRect();
      canvas.width = rect.width * dpr;
      canvas.height = rect.height * dpr;
      canvas.style.width = rect.width + "px";
      canvas.style.height = rect.height + "px";
      arrows = [];
      for (var y = SPACING / 2; y < rect.height; y += SPACING) {
        for (var x = SPACING / 2; x < rect.width; x += SPACING) {
          arrows.push({ x: x, y: y, a: Math.random() * Math.PI * 2, ta: 0 });
        }
      }
    }

    canvas.parentElement.addEventListener("mousemove", function (e) {
      var rect = canvas.getBoundingClientRect();
      mouse.x = e.clientX - rect.left;
      mouse.y = e.clientY - rect.top;
    });
    canvas.parentElement.addEventListener("mouseleave", function () {
      mouse.x = -9999; mouse.y = -9999;
    });

    function draw() {
      var w = canvas.width / dpr;
      var h = canvas.height / dpr;
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      ctx.clearRect(0, 0, w, h);
      idleT += 0.004;

      for (var i = 0; i < arrows.length; i++) {
        var ar = arrows[i];
        var dx = mouse.x - ar.x;
        var dy = mouse.y - ar.y;
        var dist = Math.sqrt(dx * dx + dy * dy);
        var near = mouse.x > -999 && dist < 340;

        if (near) {
          ar.ta = Math.atan2(dy, dx);
        } else {
          /* gentle ambient flow field when cursor is away */
          ar.ta = Math.sin(ar.x * 0.006 + idleT) + Math.cos(ar.y * 0.006 + idleT * 0.8);
        }

        /* shortest-path angular easing */
        var diff = ar.ta - ar.a;
        while (diff > Math.PI) diff -= Math.PI * 2;
        while (diff < -Math.PI) diff += Math.PI * 2;
        ar.a += diff * 0.08;

        var alpha = near ? Math.max(0.2, 0.7 - dist / 600) : 0.18;
        var len = near ? 11 : 8;

        ctx.save();
        ctx.translate(ar.x, ar.y);
        ctx.rotate(ar.a);
        ctx.strokeStyle = near
          ? "rgba(46, 91, 219, " + alpha + ")"
          : "rgba(46, 91, 219, " + alpha + ")";
        ctx.lineWidth = 1.4;
        ctx.lineCap = "round";
        ctx.beginPath();
        ctx.moveTo(-len / 2, 0);
        ctx.lineTo(len / 2, 0);
        ctx.moveTo(len / 2 - 3.5, -3);
        ctx.lineTo(len / 2, 0);
        ctx.lineTo(len / 2 - 3.5, 3);
        ctx.stroke();
        ctx.restore();
      }
      requestAnimationFrame(draw);
    }

    resize();
    window.addEventListener("resize", resize);
    requestAnimationFrame(draw);
  }

  /* ---------- contact form ---------- */
  var form = document.getElementById("contact-form");
  if (form && form.getAttribute("action")) {
    // Server-side submission: show the result banner after redirect back.
    var params = new URLSearchParams(window.location.search);
    var note = document.getElementById("sent-note");
    if (note && params.get("sent") === "1") {
      note.classList.add("show");
      note.scrollIntoView({ block: "center" });
    } else if (params.get("sent") === "0") {
      var status = document.getElementById("form-status");
      if (status) status.textContent = "Kuch reh gaya — please give your name and a WhatsApp number or email, then send again.";
    }
    form.addEventListener("submit", function () {
      var btn = form.querySelector('button[type="submit"]');
      if (btn) { btn.disabled = true; btn.style.opacity = "0.7"; }
    });
  } else if (form) {
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      var data = new FormData(form);
      var subject = "[vectorsolution.it] Project inquiry — " + (data.get("name") || "New lead");
      var body =
        "Name: " + data.get("name") + "\n" +
        "Email: " + data.get("email") + "\n" +
        "Company: " + (data.get("company") || "—") + "\n" +
        "Service: " + (data.get("service") || "—") + "\n" +
        "Budget: " + (data.get("budget") || "—") + "\n\n" +
        data.get("message");
      window.location.href =
        "mailto:shadab@vectorsolution.it?subject=" +
        encodeURIComponent(subject) +
        "&body=" +
        encodeURIComponent(body);
      var note = document.getElementById("form-status");
      if (note) note.textContent = "Your email app should open with the message ready to send. If it doesn't, email us directly at shadab@vectorsolution.it.";
    });
  }
})();
