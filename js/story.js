/* VectorIT — homepage scroll story orchestration.
 *
 * Progressive enhancement, strictly: this file only ever ADDS motion. With
 * JavaScript off, GSAP missing, or prefers-reduced-motion set, the page is a
 * complete, fully visible document — every hidden state is set from here at
 * runtime, never in CSS.
 *
 * Tiers:
 *   reduced  — nothing animates. Counters show final values (already in HTML).
 *   mobile   — fades (main.js .reveal), counters, sticky CTA. No pin, no scrub,
 *              no smooth scroll: ad traffic on a 3-year-old phone stays fast.
 *   desktop  — Lenis smooth scroll + GSAP ScrollTrigger scrubs and staggers.
 */
(function () {
  "use strict";

  var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var desktop = window.matchMedia("(min-width: 1024px) and (hover: hover)").matches;
  var hasGsap = typeof window.gsap !== "undefined" && typeof window.ScrollTrigger !== "undefined";

  /* ---------- sticky CTA bar: every tier, IntersectionObserver only ---------- */
  var bar = document.querySelector(".sticky-cta");
  var hero = document.querySelector(".p-hero");
  var stops = document.querySelectorAll(".cta-band, .site-footer");
  if (bar && hero && "IntersectionObserver" in window) {
    var heroGone = false, stopNear = false;
    var apply = function () {
      var on = heroGone && !stopNear;
      bar.classList.toggle("on", on);
      document.body.classList.toggle("has-sticky", on);
    };
    new IntersectionObserver(function (es) {
      heroGone = !es[0].isIntersecting; apply();
    }, { threshold: 0.05 }).observe(hero);
    var stopIO = new IntersectionObserver(function (es) {
      stopNear = es.some(function (e) { return e.isIntersecting; }); apply();
    }, { rootMargin: "0px 0px -20% 0px" });
    stops.forEach(function (s) { stopIO.observe(s); });
  }

  if (reduced) { return; }

  /* ---------- counters: mobile + desktop ---------- */
  var counters = document.querySelectorAll(".counter b[data-tick]");
  if (counters.length && "IntersectionObserver" in window) {
    var fmt = function (n) { return n.toLocaleString("en-PK"); };
    var run = function (el) {
      var target = parseInt(el.getAttribute("data-tick"), 10);
      var sub = el.querySelector("sub");
      var suffix = sub ? sub.outerHTML : "";
      var t0 = null, dur = 1400;
      var step = function (ts) {
        if (!t0) { t0 = ts; }
        var p = Math.min((ts - t0) / dur, 1);
        var eased = 1 - Math.pow(1 - p, 3);
        el.innerHTML = fmt(Math.round(target * eased)) + suffix;
        if (p < 1) { requestAnimationFrame(step); }
      };
      requestAnimationFrame(step);
    };
    var cio = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (e.isIntersecting) { run(e.target); cio.unobserve(e.target); }
      });
    }, { threshold: 0.6 });
    counters.forEach(function (c) { cio.observe(c); });
  }

  /* ---------- hero kinetic type: mobile + desktop (cheap, load-once) ---------- */
  var words = document.querySelectorAll(".kin .w");
  if (words.length) {
    words.forEach(function (w, i) {
      w.style.opacity = "0";
      w.style.transform = "translateY(0.85em)";
      w.style.fontVariationSettings = '"wdth" 62';
      w.style.transition =
        "transform .7s cubic-bezier(.22,1,.36,1) " + (0.08 * i) + "s, " +
        "opacity .5s ease-out " + (0.08 * i) + "s, " +
        "font-variation-settings .9s cubic-bezier(.22,1,.36,1) " + (0.12 + 0.08 * i) + "s";
    });
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        words.forEach(function (w) {
          w.style.opacity = "1";
          w.style.transform = "translateY(0)";
          w.style.fontVariationSettings = '"wdth" 105';
        });
      });
    });
  }

  /* ---------- plotter mark draws itself on load ---------- */
  var plot = document.querySelectorAll(".plotter [data-draw]");
  plot.forEach(function (p, i) {
    var len = p.getTotalLength ? p.getTotalLength() : 0;
    if (!len) { return; }
    p.style.strokeDasharray = String(len);
    p.style.strokeDashoffset = String(len);
    p.style.transition = "stroke-dashoffset 1.4s cubic-bezier(.5,0,.2,1) " + (0.4 + 0.5 * i) + "s";
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { p.style.strokeDashoffset = "0"; });
    });
  });

  /* ---------- everything below needs GSAP + a desktop pointer ---------- */
  if (!hasGsap || !desktop) {
    /* The scrub can't run here, so the old way is simply already struck out —
       the message survives without the choreography. */
    document.querySelectorAll(".oldway .strike i").forEach(function (s) {
      s.style.transform = "scaleX(1)";
    });
    return;
  }

  gsap.registerPlugin(ScrollTrigger);

  /* Lenis smooth scroll, wired to ScrollTrigger the documented way.
     anchors:true makes same-page #links scroll through Lenis instead of
     fighting it — without it, clicking "Pricing" in the nav snaps back. */
  if (typeof window.Lenis !== "undefined") {
    var lenis = new Lenis({ lerp: 0.25, anchors: true });
    lenis.on("scroll", ScrollTrigger.update);
    gsap.ticker.add(function (t) { lenis.raf(t * 1000); });
    gsap.ticker.lagSmoothing(0);
    window.__lenis = lenis;
    /* Arriving from another page at /#pricing etc.: land on the target. */
    if (location.hash) {
      var target = document.getElementById(location.hash.slice(1));
      if (target) {
        requestAnimationFrame(function () {
          lenis.scrollTo(target, { immediate: true, force: true });
        });
      }
    }
  }

  /* Drafted underlines: plot in once when the heading arrives. */
  document.querySelectorAll(".drafted svg path").forEach(function (p) {
    var len = p.getTotalLength();
    gsap.set(p, { strokeDasharray: len, strokeDashoffset: len });
    gsap.to(p, {
      strokeDashoffset: 0,
      duration: 0.9,
      ease: "power2.out",
      scrollTrigger: { trigger: p.closest("h2, h1"), start: "top 78%" }
    });
  });

  /* The old way, struck out line by line as the reader scrolls through. */
  var oldway = document.querySelector(".oldway");
  if (oldway) {
    var strikes = oldway.querySelectorAll(".strike i");
    if (strikes.length) {
      var tl = gsap.timeline({
        scrollTrigger: { trigger: oldway, start: "top 70%", end: "bottom 55%", scrub: 0.4 }
      });
      strikes.forEach(function (s) { tl.to(s, { scaleX: 1, duration: 1, ease: "none" }); });
      tl.from(oldway.querySelector(".keep"), { opacity: 0.25, duration: 1.4, ease: "none" }, "<0.3");
    }
  }

  /* Module cards: drafted in as a stagger instead of one blunt fade. */
  var modules = document.querySelectorAll(".modules .module");
  if (modules.length) {
    gsap.set(modules, { opacity: 0, y: 26 });
    ScrollTrigger.batch(modules, {
      start: "top 86%",
      onEnter: function (batch) {
        gsap.to(batch, { opacity: 1, y: 0, duration: 0.6, stagger: 0.06, ease: "power2.out", overwrite: true });
      }
    });
    modules.forEach(function (m) { m.classList.remove("reveal"); m.classList.add("visible"); });
  }

  /* ---------- FBR chapter: the day flattens, the invoice files ---------- */
  var cinema = document.querySelector(".fbr-cinema");
  if (cinema) {
    var jag = cinema.querySelector(".fbr-wave .jag");
    var flat = cinema.querySelector(".fbr-wave .flat");
    var doc = cinema.querySelector(".fbr-doc");
    var stamp = cinema.querySelector(".fbr-stamp");
    var facts = cinema.querySelectorAll(".fbr-fact");

    if (jag && flat && doc && stamp) {
      var jl = jag.getTotalLength(), fl = flat.getTotalLength();
      gsap.set(jag, { strokeDasharray: jl, strokeDashoffset: 0 });
      gsap.set(flat, { strokeDasharray: fl, strokeDashoffset: fl });
      gsap.set(doc, { y: 90, opacity: 0, rotate: -2 });
      gsap.set(stamp, { scale: 2.6, opacity: 0, rotate: 18 });

      var ctl = gsap.timeline({
        scrollTrigger: { trigger: cinema, start: "top 62%", end: "center 42%", scrub: 0.5 }
      });
      ctl.to(jag, { strokeDashoffset: -jl, ease: "none", duration: 1.2 })
         .to(flat, { strokeDashoffset: 0, ease: "none", duration: 1.2 }, "<0.25")
         .to(doc, { y: 0, opacity: 1, rotate: 0, ease: "power2.out", duration: 1 }, "<0.2")
         .to(stamp, { scale: 1, opacity: 1, rotate: 8, ease: "power3.in", duration: 0.5 }, ">-0.15");

      if (facts.length) {
        gsap.set(facts, { opacity: 0, y: 24 });
        ScrollTrigger.batch(facts, {
          start: "top 88%",
          onEnter: function (b) { gsap.to(b, { opacity: 1, y: 0, duration: 0.55, stagger: 0.1, ease: "power2.out" }); }
        });
      }
    }
  }

  /* Hero mock drifts gently against the copy — parallax depth, nothing more. */
  var mock = document.querySelector(".p-hero .mock-wrap");
  if (mock) {
    gsap.to(mock, {
      y: -46,
      ease: "none",
      scrollTrigger: { trigger: ".p-hero", start: "top top", end: "bottom top", scrub: 0.6 }
    });
  }
})();
