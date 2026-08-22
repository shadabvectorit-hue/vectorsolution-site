/* VectorIT — the 3D robotic arm that works the page.
 *
 * A silver industrial arm (Three.js) lives on a fixed, transparent WebGL
 * canvas over the right side of the homepage. It is scrubbed by scroll:
 * per-section ScrollTriggers write a "beat" float, and a rAF loop lerps the
 * pose toward it, solving planar two-bone IK so the hand genuinely reaches
 * toward live DOM elements — headings, the laptop, the invoice, the CTA.
 * Scroll back up and it retraces its path; nothing loops on its own.
 *
 * If assets/arm.glb exists it is loaded via GLTFLoader (DRACO wiring ready)
 * and its joints are mapped by name; otherwise the arm is built procedurally
 * from primitives — which guarantees individually controllable shoulder,
 * elbow, wrist and gripper regardless of how a downloaded model was rigged.
 *
 * Fail-safe: any error tears the canvas down and the page is exactly what it
 * was before this file existed. Reduced-motion users never get a canvas.
 */
import * as THREE from "three";
import { GLTFLoader } from "./vendor/GLTFLoader.js";
import { RoomEnvironment } from "./vendor/RoomEnvironment.js";

(function () {
  "use strict";

  var reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (reduced) { return; }
  if (typeof window.gsap === "undefined" || typeof window.ScrollTrigger === "undefined") { return; }

  var desktop = window.matchMedia("(min-width: 1024px)").matches;
  var CFG = desktop
    ? { widthVW: 52, dpr: Math.min(window.devicePixelRatio || 1, 2), damp: 1 }
    : { widthVW: 46, dpr: 1, damp: 0.55 };

  /* ---------- canvas ---------- */
  var canvas = document.createElement("canvas");
  canvas.id = "arm3d";
  canvas.setAttribute("aria-hidden", "true");
  canvas.style.cssText =
    "position:fixed;top:0;right:0;height:100vh;width:" + CFG.widthVW + "vw;" +
    "pointer-events:none;z-index:55;";
  document.body.appendChild(canvas);

  var renderer;
  try {
    renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true, powerPreference: "high-performance" });
  } catch (e) {
    canvas.remove();
    return;
  }
  renderer.setPixelRatio(CFG.dpr);
  renderer.setClearColor(0x000000, 0); /* transparent: the blueprint shows through */
  renderer.outputColorSpace = THREE.SRGBColorSpace;
  renderer.toneMapping = THREE.ACESFilmicToneMapping;
  renderer.toneMappingExposure = 1.05;

  var scene = new THREE.Scene();
  var camera = new THREE.PerspectiveCamera(38, 1, 0.1, 60);
  /* the reference is shot slightly from below — camera sits low, looks up */
  camera.position.set(0, -1.0, 7.4);
  camera.lookAt(0, 0.5, 0);

  /* ---------- lights: three, no shadows — premium metal comes from the env ---------- */
  scene.add(new THREE.HemisphereLight(0xdfe8ff, 0x0a0f1e, 0.85));
  var key = new THREE.DirectionalLight(0xffffff, 1.6);
  key.position.set(-3, 4, 5);
  scene.add(key);
  var rim = new THREE.DirectionalLight(0x5b8cff, 0.9);
  rim.position.set(4, -2, -3);
  scene.add(rim);
  if (desktop) {
    try {
      var pmrem = new THREE.PMREMGenerator(renderer);
      scene.environment = pmrem.fromScene(new RoomEnvironment(), 0.04).texture;
    } catch (e) { /* metal still reads under the three lights */ }
  }

  /* ---------- materials ---------- */
  var SILVER = new THREE.MeshStandardMaterial({ color: 0xe8ecf3, metalness: 0.95, roughness: 0.28 });
  var GRAPHITE = new THREE.MeshStandardMaterial({ color: 0x2a3040, metalness: 0.7, roughness: 0.45 });
  var GLOWBLUE = new THREE.MeshStandardMaterial({ color: 0x16223f, emissive: 0x2e5bdb, emissiveIntensity: 1.6, metalness: 0.4, roughness: 0.4 });

  function capsule(len, r, mat) {
    var m = new THREE.Mesh(new THREE.CapsuleGeometry(r, len, 6, 20), mat);
    m.rotation.z = -Math.PI / 2;      /* capsule runs along +X */
    m.position.x = len / 2;
    return m;
  }
  function joint(r) {
    var g = new THREE.Group();
    g.add(new THREE.Mesh(new THREE.SphereGeometry(r, 28, 20), SILVER));
    var ring = new THREE.Mesh(new THREE.TorusGeometry(r * 0.78, r * 0.14, 12, 36), GRAPHITE);
    g.add(ring);
    var core = new THREE.Mesh(new THREE.TorusGeometry(r * 0.5, r * 0.07, 10, 28), GLOWBLUE);
    core.rotation.y = Math.PI / 2;
    g.add(core);
    return g;
  }

  /* ---------- the arm (procedural default) ---------- */
  var L1 = 2.35, L2 = 1.95, HAND = 0.6;
  var root = new THREE.Group();       /* rises from the bottom right */
  var shoulder = new THREE.Group();   /* rotation.z */
  var elbow = new THREE.Group();      /* rotation.z */
  var wrist = new THREE.Group();      /* rotation.z aim + rotation.x roll */
  var hand = new THREE.Group();
  var fingers = [];

  function buildProcedural() {
    /* pedestal stub below the shoulder, so the machine appears mounted off-screen */
    var mount = new THREE.Mesh(new THREE.CylinderGeometry(0.5, 0.66, 1.6, 24), GRAPHITE);
    mount.position.y = -1.15;
    root.add(mount);
    var collar = new THREE.Mesh(new THREE.CylinderGeometry(0.56, 0.56, 0.18, 24), SILVER);
    collar.position.y = -0.36;
    root.add(collar);

    shoulder.add(joint(0.52));
    shoulder.add(capsule(L1, 0.3, SILVER));
    var ribs = new THREE.Mesh(new THREE.CylinderGeometry(0.34, 0.34, 0.1, 20), GRAPHITE);
    ribs.rotation.z = Math.PI / 2;
    ribs.position.x = L1 * 0.55;
    shoulder.add(ribs);

    elbow.position.x = L1;
    elbow.add(joint(0.4));
    elbow.add(capsule(L2, 0.23, SILVER));
    var ribs2 = ribs.clone();
    ribs2.scale.setScalar(0.8);
    ribs2.position.x = L2 * 0.5;
    elbow.add(ribs2);

    wrist.position.x = L2;
    wrist.add(joint(0.3));

    var palm = new THREE.Mesh(new THREE.BoxGeometry(0.5, 0.42, 0.34), SILVER);
    palm.position.x = 0.42;
    hand.add(palm);
    /* three fingers: two above, one thumb below — each a 2-segment claw */
    [[0.13, 0.11], [0.13, -0.11], [-0.16, 0]].forEach(function (fp, i) {
      var f = new THREE.Group();
      f.position.set(0.62, fp[0], fp[1]);
      var seg1 = new THREE.Mesh(new THREE.BoxGeometry(0.34, 0.09, 0.1), SILVER);
      seg1.position.x = 0.17;
      f.add(seg1);
      var knuckle = new THREE.Group();
      knuckle.position.x = 0.34;
      var seg2 = new THREE.Mesh(new THREE.BoxGeometry(0.26, 0.075, 0.09), GRAPHITE);
      seg2.position.x = 0.13;
      knuckle.add(seg2);
      f.add(knuckle);
      f.userData.knuckle = knuckle;
      f.userData.dir = i === 2 ? -1 : 1;
      fingers.push(f);
      hand.add(f);
    });
    var tip = new THREE.Mesh(new THREE.SphereGeometry(0.05, 12, 10), GLOWBLUE);
    tip.position.x = 0.66;
    hand.add(tip);

    wrist.add(hand);
    elbow.add(wrist);
    shoulder.add(elbow);
    root.add(shoulder);
    scene.add(root);
  }

  /* Optional GLB: drop a rigged model at assets/arm.glb and it takes over.
     Joints are matched by node name (shoulder / elbow / wrist / grip*). For a
     DRACO-compressed file, host the decoder at js/vendor/draco/ and uncomment
     the two DRACOLoader lines. */
  function tryGLB(done) {
    fetch("assets/arm.glb", { method: "HEAD" }).then(function (r) {
      if (!r.ok) { done(false); return; }
      var loader = new GLTFLoader();
      // var draco = new DRACOLoader(); draco.setDecoderPath("js/vendor/draco/");
      // loader.setDRACOLoader(draco);
      loader.load("assets/arm.glb", function (gltf) {
        var found = { shoulder: null, elbow: null, wrist: null };
        gltf.scene.traverse(function (n) {
          var nm = (n.name || "").toLowerCase();
          if (!found.shoulder && /shoulder|upper/.test(nm)) { found.shoulder = n; }
          if (!found.elbow && /elbow|fore/.test(nm)) { found.elbow = n; }
          if (!found.wrist && /wrist|hand/.test(nm)) { found.wrist = n; }
        });
        if (found.shoulder && found.elbow && found.wrist) {
          root.add(gltf.scene);
          scene.add(root);
          shoulder = found.shoulder; elbow = found.elbow; wrist = found.wrist;
          done(true);
        } else { done(false); }
      }, undefined, function () { done(false); });
    }).catch(function () { done(false); });
  }

  /* ---------- viewport→world on the arm's plane (z = 0) ---------- */
  function viewportToWorld(px, py) {
    var ndcX = ((px - (window.innerWidth - canvas.clientWidth)) / canvas.clientWidth) * 2 - 1;
    var ndcY = -(py / canvas.clientHeight) * 2 + 1;
    var v = new THREE.Vector3(ndcX, ndcY, 0.5).unproject(camera);
    var dir = v.sub(camera.position).normalize();
    var t = -camera.position.z / dir.z;
    return camera.position.clone().add(dir.multiplyScalar(t));
  }

  /* ---------- beats: which element the hand is reaching for ---------- */
  var BEATS = [
    { sel: ".p-hero",      el: null,                      grip: 0.5, roll: 0.0 },
    { sel: ".oldway",      el: ".oldway-line",            grip: 0.35, roll: 0.1, dx: 60, dy: 20 },
    { sel: ".rlab",        el: ".rlap-screen",            grip: 0.75, roll: 0.25, dx: 40, dy: -20 },
    { sel: "#modules",     el: "#modules .section-head h2", grip: 0.3, roll: -0.9, dx: 80, dy: 10 },
    { sel: ".fbr-cinema",  el: ".fbr-doc",                grip: 0.3, roll: 0.45, dx: 30, dy: -10 },
    { sel: ".cta-band",    el: ".cta-band .btn-primary",  grip: 0.02, roll: 0.0, dx: 10, dy: -6 }
  ];
  var beat = 0;                       /* float 0..5, written by ScrollTriggers */
  BEATS.forEach(function (b, i) {
    var trig = document.querySelector(b.sel);
    if (!trig) { return; }
    ScrollTrigger.create({
      trigger: trig,
      start: "top 80%",
      end: "bottom 45%",
      /* while section i is on screen, the arm travels from beat i-1 to beat i —
         scrolling back up runs the same path in reverse */
      onUpdate: function (self) { beat = i === 0 ? self.progress : (i - 1) + self.progress; }
    });
  });

  /* ---------- pose state, lerped every frame ---------- */
  var cur = { sh: 1.7, el: -2.3, wrAim: 0.4, roll: 0, grip: 0.5, rise: 0, rx: 0, ry: 0 };
  var smooth = function (a, b, k) { return a + (b - a) * k; };

  function anchor() {
    /* the shoulder sits near the bottom-right of the canvas */
    var w = viewportToWorld(window.innerWidth * (desktop ? 0.86 : 0.88), window.innerHeight * 0.96);
    return w;
  }

  function solve(dt) {
    var i0 = Math.max(0, Math.min(BEATS.length - 1, Math.floor(beat)));
    var i1 = Math.max(0, Math.min(BEATS.length - 1, i0 + 1));
    var f = Math.max(0, Math.min(1, beat - i0));
    f = f * f * (3 - 2 * f); /* smoothstep between beats */

    var A = BEATS[i0], B = BEATS[i1];
    var rise = Math.min(1, beat / 0.9);

    /* where is the hand going? blend the two beats' live element positions */
    function targetOf(bt) {
      if (!bt.el) { return null; }
      var el = document.querySelector(bt.el);
      if (!el) { return null; }
      var r = el.getBoundingClientRect();
      if (r.bottom < -80 || r.top > window.innerHeight + 80) {
        /* off-screen: reach toward its edge of the viewport instead */
        var y = r.top > window.innerHeight ? window.innerHeight * 0.94 : window.innerHeight * 0.06;
        return { x: Math.min(r.right, window.innerWidth * 0.7), y: y };
      }
      return { x: Math.min(r.right + (bt.dx || 0), window.innerWidth * 0.92), y: r.top + r.height / 2 + (bt.dy || 0) };
    }
    var tA = targetOf(A), tB = targetOf(B);
    var tgt = tB && tA ? { x: smooth(tA.x, tB.x, f), y: smooth(tA.y, tB.y, f) } : (tB || tA);
    if (qaTgt) { tgt = { x: qaTgt.x * window.innerWidth, y: qaTgt.y * window.innerHeight }; }

    var base = anchor();
    var tSh = 1.7, tEl = -2.3, tAim = 0.4;
    if (tgt) {
      var w = viewportToWorld(tgt.x, tgt.y);
      var dx = (w.x - base.x) * CFG.damp, dy = (w.y - base.y + 0.4) * CFG.damp;
      var d = Math.sqrt(dx * dx + dy * dy);
      d = Math.max(0.9, Math.min(L1 + L2 - 0.12, d));
      var baseAng = Math.atan2(dy, dx);
      var cosSh = (L1 * L1 + d * d - L2 * L2) / (2 * L1 * d);
      var cosEl = (L1 * L1 + L2 * L2 - d * d) / (2 * L1 * L2);
      cosSh = Math.max(-1, Math.min(1, cosSh));
      cosEl = Math.max(-1, Math.min(1, cosEl));
      tSh = baseAng + Math.acos(cosSh);            /* elbow-up configuration */
      tEl = -(Math.PI - Math.acos(cosEl));
      tAim = baseAng - (tSh + tEl);                /* hand points along the reach */
    }
    var tGrip = smooth(A.grip, B.grip, f);
    var tRoll = smooth(A.roll, B.roll, f);

    var k = Math.min(1, dt * 5.5);
    cur.sh = smooth(cur.sh, tSh, k);
    cur.el = smooth(cur.el, tEl, k);
    cur.wrAim = smooth(cur.wrAim, tAim, k);
    cur.roll = smooth(cur.roll, tRoll, k);
    cur.grip = smooth(cur.grip, tGrip, k);
    cur.rise = smooth(cur.rise, rise, Math.min(1, dt * 3));
    cur.rx = base.x;
    cur.ry = base.y;
  }

  var t0 = performance.now(), frozen = null;
  function frame(now) {
    var dt = Math.min(0.05, (now - t0) / 1000);
    t0 = now;
    if (frozen !== null) { beat = frozen; }
    solve(dt);

    var breathe = Math.sin(now * 0.0012) * 0.018;
    root.position.set(cur.rx, cur.ry + (1 - cur.rise) * -9, 0);
    root.rotation.z = (1 - cur.rise) * -0.25;
    shoulder.rotation.z = cur.sh + breathe;
    elbow.rotation.z = cur.el - breathe * 0.6;
    wrist.rotation.z = cur.wrAim;
    wrist.rotation.x = cur.roll;
    fingers.forEach(function (fg) {
      var open = 0.15 + cur.grip * 0.55;
      fg.rotation.z = fg.userData.dir * open;
      fg.userData.knuckle.rotation.z = -fg.userData.dir * open * 1.2;
    });

    renderer.render(scene, camera);
    if (!document.hidden) { requestAnimationFrame(frame); }
  }
  document.addEventListener("visibilitychange", function () {
    if (!document.hidden) { t0 = performance.now(); requestAnimationFrame(frame); }
  });

  function size() {
    var w = canvas.clientWidth, h = canvas.clientHeight;
    renderer.setSize(w, h, false);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
  }
  window.addEventListener("resize", size);

  /* ---------- QA hook: ?arm=2.5 freezes the arm at that beat ---------- */
  var qp = new URLSearchParams(location.search);
  var q = qp.get("arm");
  var qaTgt = null;
  if (q !== null) {
    frozen = Math.max(0, Math.min(5, parseFloat(q) || 0));
    cur.rise = 1;
    if (qp.get("tx") !== null) { qaTgt = { x: parseFloat(qp.get("tx")), y: parseFloat(qp.get("ty") || "0.5") }; }
  }

  tryGLB(function () {
    if (!scene.children.includes(root)) { buildProcedural(); }
    document.body.classList.add("has-arm3d");
    size();
    requestAnimationFrame(frame);
  });
})();
