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

  /* No smooth-scroll library. The reference site this design chases runs on
     native scroll — the rubbery feel of a lerped scroller was the one thing
     the owner kept flagging. Scrub values on each timeline provide all the
     easing the animations need; the page itself answers the wheel directly. */
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

  /* Module cards enter via the horizontal scrub below; their .reveal class
     still fades each one in as it arrives from the right. */

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

  /* ---------- the terminal is an object: mouse-tilt in 3D ---------- */
  if (mock) {
    var heroEl = document.querySelector(".p-hero");
    var qx = gsap.quickTo(mock, "rotationY", { duration: 0.7, ease: "power3.out" });
    var qy = gsap.quickTo(mock, "rotationX", { duration: 0.7, ease: "power3.out" });
    heroEl.addEventListener("mousemove", function (e) {
      var r = heroEl.getBoundingClientRect();
      qx(((e.clientX - r.left) / r.width - 0.5) * 10);
      qy(((e.clientY - r.top) / r.height - 0.5) * -8);
    });
    heroEl.addEventListener("mouseleave", function () { qx(0); qy(0); });
  }

  /* Ghost type drifts slower than the page — depth between layers. */
  var ghost = document.querySelector(".ghost-type");
  if (ghost) {
    gsap.to(ghost, {
      y: 120,
      ease: "none",
      scrollTrigger: { trigger: ".p-hero", start: "top top", end: "bottom top", scrub: 0.4 }
    });
  }

  /* ---------- cursor follower: dot tracks, ring lags ---------- */
  var dot = document.createElement("div");
  var ring = document.createElement("div");
  dot.className = "cursor-dot";
  ring.className = "cursor-ring";
  document.body.appendChild(dot);
  document.body.appendChild(ring);
  document.body.classList.add("has-cursor");
  var dx = gsap.quickTo(dot, "x", { duration: 0.08, ease: "power2.out" });
  var dy = gsap.quickTo(dot, "y", { duration: 0.08, ease: "power2.out" });
  var rx = gsap.quickTo(ring, "x", { duration: 0.38, ease: "power3.out" });
  var ry = gsap.quickTo(ring, "y", { duration: 0.38, ease: "power3.out" });
  window.addEventListener("mousemove", function (e) {
    dx(e.clientX); dy(e.clientY); rx(e.clientX); ry(e.clientY);
  });
  document.addEventListener("mouseover", function (e) {
    ring.classList.toggle("is-link", !!e.target.closest("a, button, summary"));
  });

  /* ---------- magnetic CTAs ---------- */
  document.querySelectorAll(".hero-ctas .btn, .cta-band .btn, .fbr-cinema .btn").forEach(function (b) {
    var bx = gsap.quickTo(b, "x", { duration: 0.4, ease: "power3.out" });
    var by = gsap.quickTo(b, "y", { duration: 0.4, ease: "power3.out" });
    b.addEventListener("mousemove", function (e) {
      var r = b.getBoundingClientRect();
      bx((e.clientX - r.left - r.width / 2) * 0.28);
      by((e.clientY - r.top - r.height / 2) * 0.34);
    });
    b.addEventListener("mouseleave", function () {
      gsap.to(b, { x: 0, y: 0, duration: 0.55, ease: "elastic.out(1, 0.45)" });
    });
  });

  /* ---------- headings rise word by word ---------- */
  document.querySelectorAll("main h2").forEach(function (h) {
    if (h.closest(".scene") || h.classList.contains("sr-only")) { return; }
    if (h.children.length > 0) {
      /* headings with markup inside (drafted underlines etc.) rise whole */
      gsap.set(h, { opacity: 0, y: 30 });
      gsap.to(h, {
        opacity: 1, y: 0, duration: 0.7, ease: "power2.out",
        scrollTrigger: { trigger: h, start: "top 84%" }
      });
      return;
    }
    var words = h.textContent.split(/\s+/).filter(Boolean);
    h.innerHTML = words.map(function (w) {
      return '<span class="h2w"><i>' + w.replace(/&/g, "&amp;").replace(/</g, "&lt;") + "</i></span>";
    }).join(" ");
    var parts = h.querySelectorAll(".h2w > i");
    gsap.set(parts, { yPercent: 110 });
    gsap.to(parts, {
      yPercent: 0, duration: 0.65, stagger: 0.055, ease: "power3.out",
      scrollTrigger: { trigger: h, start: "top 84%" }
    });
  });

  /* ---------- modules: vertical scroll drives a horizontal row ---------- */
  var modSec = document.getElementById("modules");
  if (modSec) {
    modSec.classList.add("hscrub");
    var track = modSec.querySelector(".modules");
    gsap.to(track, {
      x: function () { return -(track.scrollWidth - modSec.clientWidth + 60); },
      /* scrub distance is 0.55x the travel: the row moves faster than the page,
         so the pin never reads as "scrolling stopped" */
      ease: "none",
      scrollTrigger: {
        trigger: modSec,
        start: "top top",
        end: function () { return "+=" + Math.round((track.scrollWidth - modSec.clientWidth) * 0.55 + 200); },
        pin: true,
        scrub: 0.5,
        invalidateOnRefresh: true
      }
    });
  }

  /* ---------- THE ROBOT LAB: arm deploys the terminal, then the services ---------- */
  var rlab = document.querySelector(".rlab");
  if (rlab) {
    rlab.classList.add("rlab-armed");
    var rWords = rlab.querySelectorAll(".rw");
    rWords.forEach(function (w) { w.setAttribute("data-t", w.textContent); });

    var rArm = rlab.querySelector(".rarm");
    var rSh = rlab.querySelector("#ra-shoulder");
    var rFo = rlab.querySelector("#ra-fore");
    var rHa = rlab.querySelector("#ra-hand");
    var rLap = rlab.querySelector(".rlap");
    var rLid = rlab.querySelector(".rlap-lid");
    var rScr = rlab.querySelector(".rlap-screen");
    var rBoot = rlab.querySelector(".rscr-boot");
    var rBar = rlab.querySelector(".rb-bar u");
    var rKpis = rlab.querySelectorAll(".ra-kpis > div");
    var rRows = rlab.querySelectorAll(".ra-rows > div");
    var rCaps = rlab.querySelectorAll(".rcap");
    var rTail = rlab.querySelector(".rlab-tail");

    /* joints rotate around their pins */
    gsap.set(rSh, { svgOrigin: "836 450", rotation: 34 });
    gsap.set(rFo, { svgOrigin: "500 262", rotation: -46 });
    gsap.set(rHa, { svgOrigin: "268 470", rotation: 24 });
    gsap.set(rArm, { xPercent: 42 });
    gsap.set(rLap, { yPercent: 135, rotation: -3 });
    gsap.set(rLid, { rotationX: -88 });
    gsap.set([rKpis, rRows], { opacity: 0, y: 12 });

    var rtl = gsap.timeline({
      scrollTrigger: {
        trigger: rlab,
        start: "top top",
        end: "+=420%",
        pin: true,
        scrub: 0.5,
        invalidateOnRefresh: true
      },
      defaults: { ease: "none" }
    });

    /* 0–14%: the arm reaches in */
    rtl.to(rArm, { xPercent: 0, duration: 8 }, 0)
       .to(rSh, { rotation: -6, svgOrigin: "836 450", duration: 10 }, 0)
       .to(rFo, { rotation: 14, svgOrigin: "500 262", duration: 10 }, 1)
       .to(rHa, { rotation: -10, svgOrigin: "268 470", duration: 10 }, 2)
       .to(rCaps[0], { opacity: 1, duration: 3 }, 1)

       /* 10–18%: the terminal is placed */
       .to(rLap, { yPercent: 0, rotation: 0, duration: 7, ease: "power1.out" }, 9)

       /* 18–26%: the lid opens, the screen blooms */
       .to(rLid, { rotationX: 0, duration: 8, ease: "power1.inOut" }, 17)
       .to(rScr, { boxShadow: "0 0 90px rgba(91,140,255,0.4)", duration: 6 }, 21)
       .to(rCaps[0], { opacity: 0, duration: 2 }, 20)

       /* 24–34%: boot sequence */
       .to(rBoot, { opacity: 1, duration: 2 }, 24)
       .to(rCaps[1], { opacity: 1, duration: 3 }, 25)
       .fromTo(rBar, { scaleX: 0 }, { scaleX: 1, duration: 6 }, 26)
       .to(rBoot, { opacity: 0, duration: 3 }, 33)

       /* 34–44%: the ERP is live */
       .to(rKpis, { opacity: 1, y: 0, duration: 4, stagger: 1.2 }, 35)
       .to(rRows, { opacity: 1, y: 0, duration: 4, stagger: 1.2 }, 38)
       .to(rCaps[1], { opacity: 0, duration: 2 }, 40)
       .to(rCaps[2], { opacity: 1, duration: 3 }, 42.5)

       /* 44–52%: the arm presents; the terminal steps aside */
       .to(rSh, { rotation: -22, svgOrigin: "836 450", duration: 8 }, 44)
       .to(rFo, { rotation: 36, svgOrigin: "500 262", duration: 8 }, 44)
       .to(rHa, { rotation: 4, svgOrigin: "268 470", duration: 8 }, 44)
       .to(rLap, { x: "-4%", scale: 0.94, duration: 8 }, 44)
       .to(rCaps[2], { opacity: 0, duration: 2 }, 48)
       .to(rCaps[3], { opacity: 1, duration: 3 }, 50);

    /* 52–96%: the services light up, one by one */
    var wStart = 52;
    var wSlot = (96 - wStart) / rWords.length;
    rWords.forEach(function (w, i) {
      var at = wStart + i * wSlot;
      rtl.to(w, { "--on": 1, duration: wSlot * 0.45 }, at);
      if (i < rWords.length - 1) {
        rtl.to(w, { "--on": 0, duration: wSlot * 0.4 }, at + wSlot * 0.62);
      }
    });

    /* 94–100%: the invitation */
    rtl.to(rTail, { opacity: 1, duration: 4 }, 95);

    /* QA hook: ?lab=0.4 freezes the choreography at that progress. */
    var labDbg = new URLSearchParams(location.search).get("lab");
    if (labDbg !== null) {
      rtl.scrollTrigger.kill(true);
      rtl.progress(Math.min(Math.max(parseFloat(labDbg) || 0, 0), 1));
      rlab.style.position = "fixed";
      rlab.style.inset = "0";
      rlab.style.zIndex = "90";
      rlab.style.background = "var(--paper)";
      document.body.style.overflow = "hidden";
    }
  }

  /* The filed invoice levitates once its entrance has played. */
  var fdoc = document.querySelector(".fbr-doc");
  if (fdoc) {
    gsap.to(fdoc, {
      y: "-=10",
      duration: 3.2,
      ease: "sine.inOut",
      yoyo: true,
      repeat: -1,
      scrollTrigger: { trigger: ".fbr-cinema", start: "center center" }
    });
  }
})();
