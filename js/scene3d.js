/* VectorIT — the 3D story. One Three.js stage, fixed behind the page, that
 * carries the hero and the pinned chapter after it:
 *
 *   hero     closed laptop, V emblem glowing on the lid, floating on paper
 *   dim      the room darkens to ink, the laptop turns and opens
 *   screens  nine real VectorERP screens play on the display as you scroll
 *   fbr      a phone slides in with an FBR-verified invoice
 *   layers   devices drop away; Interface / Logic / Data plates explode
 *   close    everything returns, the lid closes, the lights come back up
 *
 * Everything is generated in code except the screens, which are the real demo
 * screenshots in assets/laptop. Degrades to the photo hero when WebGL is
 * missing, and to a direct cut (no easing) under prefers-reduced-motion.
 */
(function () {
  "use strict";
  var hero = document.querySelector(".fx-hero"), story = document.getElementById("story");
  var heroAnchor = document.getElementById("heroAnchor"), storyAnchor = document.getElementById("storyAnchor");
  var canvas = document.getElementById("vit3d");
  if (!hero || !story || !heroAnchor || !storyAnchor || !canvas || !window.THREE) { return bail(); }

  var reduce = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  var renderer;
  try {
    renderer = new THREE.WebGLRenderer({ canvas: canvas, antialias: true, alpha: true, powerPreference: "high-performance" });
  } catch (e) { return bail(); }
  document.body.classList.add("has3d");

  /* ---------- palette (the site's tokens) ---------- */
  var C = { paper: 0xF6F8FD, ink: 0x0F1B33, blue: 0x2E5BDB, soft: 0x5B8CFF, signal: 0xFF5A2D, alu: 0xD3D9E6, dark: 0x1A2540 };
  var HEX = { blue: "#2E5BDB", soft: "#5B8CFF", signal: "#FF5A2D", ink: "#0F1B33", muted: "#55617D", paper: "#F6F8FD", green: "#178A50" };
  var FD = '"Archivo", system-ui, sans-serif', FM = '"IBM Plex Mono", ui-monospace, monospace';

  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, 1.6));
  renderer.outputEncoding = THREE.sRGBEncoding;
  renderer.setClearColor(C.paper, 1);
  var scene = new THREE.Scene();
  scene.fog = new THREE.Fog(C.paper, 11, 22);
  var camera = new THREE.PerspectiveCamera(35, 1, 0.1, 100);
  var DIST = 9.4;
  camera.position.set(0, 0, DIST);

  /* ---------- lights: a bright room that can dim to a studio ---------- */
  var hemi = new THREE.HemisphereLight(0xffffff, 0xdfe6f5, 1.05); scene.add(hemi);
  var key = new THREE.DirectionalLight(0xffffff, 0.9); key.position.set(4, 6, 6); scene.add(key);
  var rim = new THREE.DirectionalLight(C.signal, 0); rim.position.set(-5, 2, -4); scene.add(rim);
  var fill = new THREE.PointLight(C.soft, 0.25, 24); fill.position.set(-4, 1, 5); scene.add(fill);
  var under = new THREE.PointLight(C.blue, 0, 10); under.position.set(0, -1.2, 1.5); scene.add(under);

  /* ---------- helpers ---------- */
  function rbox(w, h, d, r) {
    var s = new THREE.Shape(), x = -w / 2, y = -h / 2;
    s.moveTo(x + r, y); s.lineTo(x + w - r, y); s.quadraticCurveTo(x + w, y, x + w, y + r);
    s.lineTo(x + w, y + h - r); s.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    s.lineTo(x + r, y + h); s.quadraticCurveTo(x, y + h, x, y + h - r);
    s.lineTo(x, y + r); s.quadraticCurveTo(x, y, x + r, y);
    var b = Math.min(0.02, d / 4);
    var g = new THREE.ExtrudeGeometry(s, { depth: d - 2 * b, bevelEnabled: true, bevelThickness: b, bevelSize: b, bevelSegments: 3, curveSegments: 10 });
    g.center(); return g;
  }
  function rr(g, x, y, w, h, r) { g.beginPath(); g.moveTo(x + r, y); g.arcTo(x + w, y, x + w, y + h, r); g.arcTo(x + w, y + h, x, y + h, r); g.arcTo(x, y + h, x, y, r); g.arcTo(x, y, x + w, y, r); g.closePath(); }
  function tex(w, h, draw) {
    var c = document.createElement("canvas"); c.width = w; c.height = h; var g = c.getContext("2d");
    draw(g, w, h); var t = new THREE.CanvasTexture(c); t.encoding = THREE.sRGBEncoding;
    t.anisotropy = renderer.capabilities.getMaxAnisotropy(); t.userData = { draw: draw, g: g, w: w, h: h }; return t;
  }
  function redraw(t) { var u = t.userData; u.g.clearRect(0, 0, u.w, u.h); u.draw(u.g, u.w, u.h); t.needsUpdate = true; }
  var clamp = function (v, a, b) { return Math.min(b, Math.max(a, v)); };
  var seg = function (p, a, b) { return clamp((p - a) / (b - a), 0, 1); };
  var ease = function (t) { return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; };
  var lerp = function (a, b, t) { return a + (b - a) * t; };
  var col = new THREE.Color(), cA = new THREE.Color(), cB = new THREE.Color();
  function mixCol(a, b, t) { cA.set(a); cB.set(b); return col.copy(cA).lerp(cB, t); }

  /* ---------- textures drawn in code ---------- */
  var waitTex = tex(1280, 800, function (g, w, h) {
    g.fillStyle = HEX.ink; g.fillRect(0, 0, w, h);
    g.fillStyle = "#fff"; g.font = "800 72px " + FD; g.textAlign = "center"; g.fillText("Vector", w / 2 - 70, h / 2 + 10);
    g.fillStyle = HEX.soft; g.fillText("ERP", w / 2 + 140, h / 2 + 10);
    g.fillStyle = HEX.muted; g.font = "500 26px " + FM; g.fillText("LOADING THE LIVE DEMO", w / 2, h / 2 + 70); g.textAlign = "left";
  });
  var keysTex = tex(1024, 680, function (g, w, h) {
    var cols = 14, kw = 62, gap = 8, x0 = (w - (cols * kw + (cols - 1) * gap)) / 2;
    for (var r = 0; r < 5; r++) for (var c = 0; c < cols; c++) { g.fillStyle = "#C8CFDB"; rr(g, x0 + c * (kw + gap), 40 + r * (kw - 6 + gap), kw, kw - 6, 7); g.fill(); }
    g.fillStyle = "rgba(190,198,212,.55)"; rr(g, w / 2 - 170, 400, 340, 220, 16); g.fill();
  });
  var phoneTex = tex(420, 860, function (g, w, h) {
    g.fillStyle = "#F6F8FD"; g.fillRect(0, 0, w, h);
    g.fillStyle = HEX.ink; g.fillRect(0, 0, w, 150);
    g.fillStyle = "#fff"; g.font = "600 15px " + FM; g.fillText("9:41", 28, 36);
    g.fillStyle = "#fff"; g.font = "800 24px " + FD; g.fillText("Vector", 28, 92); g.fillStyle = HEX.soft; g.fillText("ERP", 112, 92);
    g.fillStyle = "#B9C6E4"; g.font = "500 13px " + FM; g.fillText("SALES TAX INVOICE  ·  #1042", 28, 124);
    g.fillStyle = "#fff"; rr(g, 20, 172, 380, 330, 16); g.fill();
    g.fillStyle = HEX.ink; g.font = "700 18px " + FD; g.fillText("Al-Karam Traders (Pvt) Ltd", 40, 206);
    g.fillStyle = HEX.muted; g.font = "500 12px " + FM; g.fillText("STRN 17-00-2251-889-64  ·  18 OCT 2026", 40, 228);
    var items = [["Cooking oil 5L (carton)", "80", "5,12,000"], ["Flour 10kg (bag)", "150", "1,68,000"], ["Sugar 50kg", "20", "2,86,000"]];
    items.forEach(function (it, i) {
      var y = 268 + i * 44; g.fillStyle = HEX.ink; g.font = "500 15px " + FD; g.fillText(it[0], 40, y);
      g.fillStyle = HEX.muted; g.font = "13px " + FM; g.fillText("x" + it[1], 290, y);
      g.fillStyle = HEX.ink; g.font = "600 14px " + FM; g.textAlign = "right"; g.fillText(it[2], 386, y); g.textAlign = "left";
      g.fillStyle = "#EEF2FA"; g.fillRect(40, y + 12, 346, 1);
    });
    g.fillStyle = HEX.muted; g.font = "13px " + FM; g.fillText("Sales tax 18%", 40, 426); g.textAlign = "right"; g.fillText("1,73,880", 386, 426); g.textAlign = "left";
    g.fillStyle = HEX.ink; g.font = "800 20px " + FD; g.fillText("Total", 40, 470); g.textAlign = "right"; g.fillText("Rs 11,39,880", 386, 470); g.textAlign = "left";
    // FBR block with a QR
    g.fillStyle = "#fff"; rr(g, 20, 520, 380, 230, 16); g.fill();
    g.strokeStyle = HEX.ink; g.lineWidth = 3; rr(g, 40, 548, 128, 128, 4); g.stroke();
    var seed = 7; function rnd() { seed = (seed * 9301 + 49297) % 233280; return seed / 233280; }
    g.fillStyle = HEX.ink;
    for (var qy = 0; qy < 14; qy++) for (var qx = 0; qx < 14; qx++) { if (rnd() > 0.55) g.fillRect(46 + qx * 8.3, 554 + qy * 8.3, 7, 7); }
    [[46, 554], [46 + 8.3 * 10, 554], [46, 554 + 8.3 * 10]].forEach(function (p) { g.fillStyle = "#fff"; g.fillRect(p[0], p[1], 33, 33); g.fillStyle = HEX.ink; g.fillRect(p[0], p[1], 33, 33); g.fillStyle = "#fff"; g.fillRect(p[0] + 5, p[1] + 5, 23, 23); g.fillStyle = HEX.ink; g.fillRect(p[0] + 10, p[1] + 10, 13, 13); });
    g.fillStyle = "#E7F5EC"; rr(g, 190, 548, 196, 34, 17); g.fill();
    g.fillStyle = HEX.green; g.font = "700 14px " + FD; g.fillText("✓  FBR verified", 212, 571);
    g.fillStyle = HEX.muted; g.font = "11px " + FM; g.fillText("FBR INVOICE NO.", 190, 612);
    g.fillStyle = HEX.ink; g.font = "600 12px " + FM; g.fillText("7000007DI1754014", 190, 632);
    g.fillStyle = HEX.muted; g.font = "11px " + FM; g.fillText("REPORTED", 190, 662); g.fillStyle = HEX.ink; g.font = "600 12px " + FM; g.fillText("18 Oct 2026 14:32", 190, 682);
    g.fillStyle = HEX.blue; rr(g, 20, 772, 380, 54, 27); g.fill();
    g.fillStyle = "#fff"; g.font = "700 17px " + FD; g.textAlign = "center"; g.fillText("Send to customer on WhatsApp", w / 2, 806); g.textAlign = "left";
  });
  function layerTex(num, title, sub, colr) {
    return tex(1024, 640, function (g, w, h) {
      g.clearRect(0, 0, w, h); g.strokeStyle = colr; g.lineWidth = 5; rr(g, 10, 10, w - 20, h - 20, 34); g.stroke();
      g.fillStyle = colr; g.font = "500 40px " + FM; g.fillText(num, 70, 130);
      g.fillStyle = "#fff"; g.font = "800 78px " + FD; g.fillText(title, 70, 250);
      g.fillStyle = "#B9C6E4"; g.font = "500 38px " + FD; g.fillText(sub, 70, 320);
      for (var i = 0; i < 4; i++) { g.fillStyle = "rgba(255,255,255,.08)"; rr(g, 70 + i * 225, 400, 200, 150, 16); g.fill(); }
    });
  }
  var shadowTex = tex(512, 512, function (g, w, h) { var r = g.createRadialGradient(w / 2, h / 2, 0, w / 2, h / 2, w / 2); r.addColorStop(0, "rgba(15,27,51,.32)"); r.addColorStop(0.5, "rgba(15,27,51,.08)"); r.addColorStop(1, "rgba(15,27,51,0)"); g.fillStyle = r; g.fillRect(0, 0, w, h); });
  var glowTex = tex(512, 512, function (g, w, h) { var r = g.createRadialGradient(w / 2, h / 2, 0, w / 2, h / 2, w / 2); r.addColorStop(0, "rgba(91,140,255,.42)"); r.addColorStop(0.45, "rgba(91,140,255,.1)"); r.addColorStop(1, "rgba(91,140,255,0)"); g.fillStyle = r; g.fillRect(0, 0, w, h); });

  /* the nine real screens */
  var SCREENS = ["dashboard", "accounts", "invoices", "inventory", "crm", "payroll", "pos", "resto", "pharma"];
  var loader = new THREE.TextureLoader(), screens = SCREENS.map(function () { return waitTex; });
  SCREENS.forEach(function (n, i) {
    loader.load("assets/laptop/" + n + ".jpg", function (t) { t.encoding = THREE.sRGBEncoding; t.anisotropy = renderer.capabilities.getMaxAnisotropy(); screens[i] = t; });
  });

  /* ---------- materials ---------- */
  var alu = new THREE.MeshStandardMaterial({ color: C.alu, metalness: 0.65, roughness: 0.32 });
  var graphite = new THREE.MeshStandardMaterial({ color: 0x1D2535, metalness: 0.6, roughness: 0.35 });
  var black = new THREE.MeshBasicMaterial({ color: 0x05070a });
  var emblemMat = new THREE.MeshStandardMaterial({ color: C.blue, emissive: C.blue, emissiveIntensity: 0.35, metalness: 0.3, roughness: 0.35 });

  /* ---------- world ---------- */
  var world = new THREE.Group(); scene.add(world);
  var rig = new THREE.Group(); world.add(rig);          // tilts the whole desk a little toward the camera

  // laptop
  var laptop = new THREE.Group(); rig.add(laptop);
  var base = new THREE.Mesh(rbox(3.2, 2.2, 0.12, 0.14), alu); base.rotation.x = -Math.PI / 2; laptop.add(base);
  var keys = new THREE.Mesh(new THREE.PlaneGeometry(3.0, 2.0), new THREE.MeshBasicMaterial({ map: keysTex, transparent: true }));
  keys.rotation.x = -Math.PI / 2; keys.position.set(0, 0.062, 0); laptop.add(keys);
  var pivot = new THREE.Group(); pivot.position.set(0, 0.07, -1.1); laptop.add(pivot);
  var lid = new THREE.Mesh(rbox(3.2, 2.1, 0.07, 0.14), alu); lid.rotation.x = -Math.PI / 2; lid.position.set(0, 0.035, 1.05); pivot.add(lid);
  var bezel = new THREE.Mesh(new THREE.PlaneGeometry(3.08, 1.98), black); bezel.rotation.x = Math.PI / 2; bezel.position.set(0, -0.001, 1.05); pivot.add(bezel);
  var screenA = new THREE.Mesh(new THREE.PlaneGeometry(2.92, 1.82), new THREE.MeshBasicMaterial({ map: waitTex, toneMapped: false }));
  screenA.rotation.x = Math.PI / 2; screenA.position.set(0, -0.003, 1.07); pivot.add(screenA);
  var screenB = new THREE.Mesh(new THREE.PlaneGeometry(2.92, 1.82), new THREE.MeshBasicMaterial({ map: waitTex, toneMapped: false, transparent: true, opacity: 0 }));
  screenB.rotation.x = Math.PI / 2; screenB.position.set(0, -0.005, 1.07); pivot.add(screenB);

  // the V emblem on the lid: dot, two strokes, arrowhead — the logo, extruded
  var emblem = new THREE.Group();
  (function () {
    var k = 0.62 / 120, pts = { dot: [30, 34], v: [60, 92], tip: [81.5, 50.5] };
    function P(p) { return new THREE.Vector2((p[0] - 60) * k, -(p[1] - 62) * k); }
    var d = P(pts.dot), v = P(pts.v), t = P(pts.tip), thick = 11 * k, depth = 0.03;
    emblem.add(new THREE.Mesh(new THREE.CylinderGeometry(8.2 * k, 8.2 * k, depth, 24).rotateX(Math.PI / 2), emblemMat).translateX(d.x).translateY(d.y));
    function stroke(a, b) {
      var len = a.distanceTo(b), m = new THREE.Mesh(new THREE.BoxGeometry(len, thick, depth), emblemMat);
      m.position.set((a.x + b.x) / 2, (a.y + b.y) / 2, 0); m.rotation.z = Math.atan2(b.y - a.y, b.x - a.x); return m;
    }
    emblem.add(stroke(d, v)); emblem.add(stroke(v, t));
    [d, v, t].forEach(function (p) { emblem.add(new THREE.Mesh(new THREE.CylinderGeometry(thick / 2, thick / 2, depth, 16).rotateX(Math.PI / 2), emblemMat).translateX(p.x).translateY(p.y)); });
    var tri = new THREE.Shape(); var h1 = P([91, 32]), h2 = P([91.6, 50.3]), h3 = P([75.6, 42.1]);
    tri.moveTo(h1.x, h1.y); tri.lineTo(h2.x, h2.y); tri.lineTo(h3.x, h3.y); tri.closePath();
    var head = new THREE.Mesh(new THREE.ExtrudeGeometry(tri, { depth: depth, bevelEnabled: false }), emblemMat); head.position.z = -depth / 2; emblem.add(head);
  })();
  emblem.rotation.x = -Math.PI / 2; emblem.position.set(0, 0.075, 1.05); pivot.add(emblem);
  var emblemGlow = new THREE.Mesh(new THREE.PlaneGeometry(1.6, 1.6), new THREE.MeshBasicMaterial({ map: glowTex, transparent: true, depthWrite: false, opacity: 0.55 }));
  emblemGlow.rotation.x = -Math.PI / 2; emblemGlow.position.set(0, 0.074, 1.05); pivot.add(emblemGlow);

  var shadow = new THREE.Mesh(new THREE.PlaneGeometry(7.5, 7.5), new THREE.MeshBasicMaterial({ map: shadowTex, transparent: true, depthWrite: false }));
  shadow.rotation.x = -Math.PI / 2; shadow.position.y = -0.075; laptop.add(shadow);
  var glow = new THREE.Mesh(new THREE.PlaneGeometry(8, 8), new THREE.MeshBasicMaterial({ map: glowTex, transparent: true, depthWrite: false, opacity: 0 }));
  glow.rotation.x = -Math.PI / 2; glow.position.y = -0.07; laptop.add(glow);

  // phone
  var phone = new THREE.Group(); rig.add(phone);
  phone.add(new THREE.Mesh(rbox(0.92, 1.9, 0.09, 0.14), graphite));
  var pscreen = new THREE.Mesh(new THREE.PlaneGeometry(0.84, 1.8), new THREE.MeshBasicMaterial({ map: phoneTex, toneMapped: false }));
  pscreen.position.z = 0.047; phone.add(pscreen);
  // the FBR badge that floats beside the phone
  var badgeTex = tex(512, 256, function (g, w, h) { g.clearRect(0, 0, w, h); g.fillStyle = "rgba(15,27,51,.92)"; rr(g, 10, 10, w - 20, h - 20, 60); g.fill(); g.strokeStyle = HEX.green; g.lineWidth = 6; rr(g, 10, 10, w - 20, h - 20, 60); g.stroke(); g.fillStyle = HEX.green; g.font = "800 92px " + FD; g.fillText("✓", 54, 160); g.fillStyle = "#fff"; g.font = "800 60px " + FD; g.fillText("FBR", 150, 118); g.fillStyle = "#B9C6E4"; g.font = "500 30px " + FM; g.fillText("REPORTED · QR", 150, 176); });
  var badge = new THREE.Mesh(new THREE.PlaneGeometry(1.5, 0.75), new THREE.MeshBasicMaterial({ map: badgeTex, transparent: true, depthWrite: false })); rig.add(badge);

  // layer stack
  var stack = new THREE.Group(); rig.add(stack);
  var layerDefs = [["03", "Data", "MariaDB / SQL Server · backups · audit", "#DCE2EE"], ["02", "Logic", "Rules · approvals · FBR e-invoicing", HEX.signal], ["01", "Interface", "Web · Android · point of sale", HEX.soft]];
  var plates = layerDefs.map(function (d) {
    var grp = new THREE.Group();
    var m = new THREE.Mesh(rbox(2.8, 1.75, 0.05, 0.12), new THREE.MeshStandardMaterial({ color: 0x14213D, metalness: 0.3, roughness: 0.45, transparent: true, opacity: 0.94 }));
    m.rotation.x = -Math.PI / 2; grp.add(m);
    var t = layerTex(d[0], d[1], d[2], d[3]);
    var lab = new THREE.Mesh(new THREE.PlaneGeometry(2.72, 1.7), new THREE.MeshBasicMaterial({ map: t, transparent: true, depthWrite: false }));
    lab.rotation.x = -Math.PI / 2; lab.position.y = 0.03; grp.add(lab);
    grp.userData.tex = t; stack.add(grp); return grp;
  });

  // dust: slow particles that only show once the room is dark
  var N = 700, pos = new Float32Array(N * 3);
  for (var i = 0; i < N; i++) { pos[i * 3] = (Math.random() - 0.5) * 18; pos[i * 3 + 1] = (Math.random() - 0.5) * 10; pos[i * 3 + 2] = (Math.random() - 0.5) * 10 - 2; }
  var dustGeo = new THREE.BufferGeometry(); dustGeo.setAttribute("position", new THREE.BufferAttribute(pos, 3));
  var dust = new THREE.Points(dustGeo, new THREE.PointsMaterial({ color: C.soft, size: 0.035, transparent: true, opacity: 0, depthWrite: false })); scene.add(dust);

  if (document.fonts && document.fonts.ready) { document.fonts.ready.then(function () { [waitTex, phoneTex, badgeTex].forEach(redraw); plates.forEach(function (p) { redraw(p.userData.tex); }); }); }

  /* ---------- page hooks ---------- */
  var cards = Array.prototype.slice.call(story.querySelectorAll(".st-card"));
  var names = Array.prototype.slice.call(story.querySelectorAll(".st-track li"));
  var dots = Array.prototype.slice.call(story.querySelectorAll(".st-rail a"));
  var fallback = document.querySelector(".fx-fallback");
  if (fallback) fallback.style.display = "none";

  var W = 1, H = 1, aspect = 1, halfH = 1, halfW = 1, mobile = false;
  function onResize() {
    W = window.innerWidth; H = window.innerHeight; aspect = W / H;
    renderer.setSize(W, H, false); camera.aspect = aspect; camera.updateProjectionMatrix();
    halfH = DIST * Math.tan(camera.fov * Math.PI / 360); halfW = halfH * aspect; mobile = W <= 900;
  }
  var mx = 0, my = 0, tmx = 0, tmy = 0;
  window.addEventListener("mousemove", function (e) { tmx = (e.clientX / W - 0.5) * 2; tmy = (e.clientY / H - 0.5) * 2; }, { passive: true });
  window.addEventListener("resize", onResize); onResize();

  // where on the page the laptop should sit, in world units at z = 0
  var anchor = { x: 0, y: 0, s: 1 };
  function rectToWorld(r) {
    var cx = r.left + r.width / 2, cy = r.top + r.height / 2;
    var nx = (cx / W) * 2 - 1, ny = -((cy / H) * 2 - 1);
    var wW = r.width / W * 2 * halfW;
    return { x: nx * halfW, y: ny * halfH, s: clamp(wW / 4.6, 0.42, 1.15) };
  }

  var target = 0, cur = 0, step = -1, shown = -1;
  function progress() {
    var top = story.getBoundingClientRect().top + window.scrollY, len = story.offsetHeight - H;
    target = len > 0 ? (window.scrollY - top) / len : 0;   // < 0 while in the hero, 0..1 through the story
  }
  window.addEventListener("scroll", progress, { passive: true }); progress();

  function setStep(i) {
    if (i === shown) return; shown = i;
    cards.forEach(function (c, k) { c.classList.toggle("on", k === i); });
    dots.forEach(function (d, k) { d.classList.toggle("on", k === i); });
  }

  var clock = new THREE.Clock(), running = true;
  function frame() {
    requestAnimationFrame(frame);
    var t = clock.getElapsedTime();
    cur = reduce ? target : cur + (target - cur) * 0.09;
    var s = clamp(cur, -1, 1);
    // past the story: hide the stage entirely and stop drawing
    var past = cur > 1.02 || (story.getBoundingClientRect().top > H + 10 && hero.getBoundingClientRect().bottom < -10);
    canvas.style.opacity = past ? 0 : 1;
    if (past) { if (running) { running = false; } return; }
    running = true;

    mx += (tmx - mx) * 0.05; my += (tmy - my) * 0.05;
    var idle = reduce ? 0 : Math.sin(t * 0.7) * 0.05;

    /* timing */
    var toStory = ease(seg(s, 0.0, 0.13));          // hero -> stage
    var dim = ease(seg(s, 0.03, 0.17));
    var open = ease(seg(s, 0.11, 0.25));
    var play = seg(s, 0.25, 0.50);                   // nine screens
    var ph = ease(seg(s, 0.50, 0.60));
    var exIn = ease(seg(s, 0.63, 0.74)), exOut = ease(seg(s, 0.80, 0.88));
    var ex = exIn * (1 - exOut);
    var spread = ease(seg(s, 0.68, 0.78)) * (1 - exOut);
    var back = ease(seg(s, 0.84, 0.93));             // laptop returns, closes
    var relight = ease(seg(s, 0.90, 0.98));
    var dark = dim * (1 - relight);
    document.body.classList.toggle('stage-dark', dark > 0.45);

    /* anchor: hero column -> story column */
    var ra = rectToWorld(heroAnchor.getBoundingClientRect()), rb = rectToWorld(storyAnchor.getBoundingClientRect());
    anchor.x = lerp(ra.x, rb.x, toStory); anchor.y = lerp(ra.y, rb.y, toStory); anchor.s = lerp(ra.s, rb.s, toStory);
    world.position.set(anchor.x, anchor.y - 0.35 * anchor.s, 0);
    world.scale.setScalar(anchor.s);
    rig.rotation.x = 0.42 - 0.12 * open;             // tilt the desk toward the viewer
    rig.rotation.y = mx * 0.08;

    /* room */
    var bg = mixCol(C.paper, C.ink, dark);
    renderer.setClearColor(bg, 1); scene.fog.color.copy(bg);
    hemi.color.copy(mixCol(0xffffff, 0xcfd8ff, dark)); hemi.groundColor.copy(mixCol(0xdfe6f5, 0x101318, dark)); hemi.intensity = lerp(1.05, 0.55, dark);
    key.intensity = lerp(0.9, 1.15, dark); rim.intensity = 0.95 * dark; fill.intensity = lerp(0.25, 0.65, dark); under.intensity = 0.9 * dark * open;
    shadow.material.opacity = 1 - dark; glow.material.opacity = dark; dust.material.opacity = 0.75 * dark;
    dust.rotation.y = reduce ? 0 : t * 0.012; dust.position.y = reduce ? 0 : Math.sin(t * 0.3) * 0.2;
    emblemMat.emissiveIntensity = lerp(0.35, 1.1, dark) * (1 - open) + 0.2; emblemGlow.material.opacity = lerp(0.55, 0.9, dark) * (1 - open);

    /* laptop: closed and angled, then opens and faces you; returns and closes at the end */
    var lidOpen = open * (1 - back);
    pivot.rotation.x = -1.86 * lidOpen;
    laptop.rotation.y = lerp(-0.62, 0.16, ease(seg(s, 0.0, 0.26))) * (1 - back) + idle * (1 - open) + back * (-0.3 + mx * 0.05);
    laptop.position.set(lerp(0, -0.7, ph) * (1 - back), lerp(0, -6.0, ex) + (reduce ? 0 : Math.sin(t * 0.8) * 0.02 * (1 - open)), lerp(0, -0.3, ph));
    laptop.scale.setScalar(lerp(1, 0.8, ex) * lerp(1, 1.04, back));

    /* screens */
    var idx = clamp(Math.floor(play * SCREENS.length), 0, SCREENS.length - 1), f = play * SCREENS.length - idx;
    screenA.material.map = screens[idx]; screenB.material.map = screens[Math.min(idx + 1, SCREENS.length - 1)];
    screenB.material.opacity = play >= 1 ? 0 : ease(seg(f, 0.84, 1)); screenA.material.needsUpdate = screenB.material.needsUpdate = true;
    names.forEach(function (li, k) { li.classList.toggle("on", k === idx && play > 0); li.classList.toggle("seen", k < idx); });

    /* phone + badge */
    phone.visible = ph > 0.001;
    phone.position.set(lerp(5.5, 1.75, ph) + ex * 2.2 + back * 5.0, 0.95 - ex * 6.0 + (reduce ? 0 : Math.sin(t * 1.1) * 0.03 * ph), 0.7);
    phone.rotation.set(-0.06, lerp(-1.2, -0.42, ph), lerp(0.25, 0.04, ph));
    var bd = ease(seg(s, 0.555, 0.62)) * (1 - exIn);
    badge.visible = bd > 0.001; badge.material.opacity = bd;
    badge.position.set(1.55, 2.35 + (reduce ? 0 : Math.sin(t * 1.4) * 0.05), 1.0); badge.scale.setScalar(lerp(0.6, 1, bd));

    /* layers */
    stack.visible = ex > 0.001;
    stack.position.set(0.2, lerp(5.5, 0.15, exIn) + exOut * 6, 0);
    stack.rotation.set(0.02, -0.55 + (reduce ? 0 : t * 0.1) * ex, 0);
    var gap = lerp(0.12, 0.85, spread);
    plates.forEach(function (pl, i) { pl.position.y = (i - 1) * gap; pl.position.x = (i - 1) * spread * 0.25; });

    /* camera */
    camera.position.set(mx * 0.35, 0.25 + my * -0.2 - open * 0.25 + ex * 0.4, DIST + ex * 0.6 + back * 0.4);
    camera.lookAt(0, 0.1, 0);
    renderer.render(scene, camera);

    /* cards */
    var st = s < 0.22 ? 0 : s < 0.50 ? 1 : s < 0.63 ? 2 : s < 0.84 ? 3 : 4;
    setStep(st);
  }
  frame();

  function bail() {
    document.body.classList.add("no3d");
    if (canvas) canvas.style.display = "none";
  }
})();
