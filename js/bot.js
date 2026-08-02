/* VectorIT chat bot — qualifies the visitor, saves the lead, hands over to WhatsApp. */
(function () {
  "use strict";

  var WA_NUMBER = "923363138686";
  var launcher = document.querySelector(".wa-float");
  if (!launcher) return;

  /* ---------- panel skeleton ---------- */
  var panel = document.createElement("div");
  panel.className = "bot-panel";
  panel.setAttribute("role", "dialog");
  panel.setAttribute("aria-label", "Chat with VectorIT");
  panel.innerHTML =
    '<div class="bot-head">' +
      '<span class="b-avatar">V</span>' +
      '<div><b>VectorIT Assistant</b><span>Jawab foran — Urdu ya English</span></div>' +
      '<button class="bot-close" aria-label="Close chat">&times;</button>' +
    "</div>" +
    '<div class="bot-msgs" id="bot-msgs"></div>' +
    '<form class="bot-input" id="bot-form">' +
      '<input id="bot-text" type="text" autocomplete="off" placeholder="Yahan likhein…" aria-label="Your reply">' +
      '<button type="submit" aria-label="Send"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 8h11M9 3.5 13.5 8 9 12.5"/></svg></button>' +
    "</form>";
  document.body.appendChild(panel);

  var msgs = panel.querySelector("#bot-msgs");
  var form = panel.querySelector("#bot-form");
  var input = panel.querySelector("#bot-text");
  var lead = { source: "bot", name: "", whatsapp: "", service: "", budget: "", message: "" };
  var step = 0;
  var started = false;

  function say(text, delay) {
    setTimeout(function () {
      var m = document.createElement("div");
      m.className = "bot-msg bot";
      m.textContent = text;
      msgs.appendChild(m);
      msgs.scrollTop = msgs.scrollHeight;
    }, delay || 0);
  }
  function userSay(text) {
    var m = document.createElement("div");
    m.className = "bot-msg user";
    m.textContent = text;
    msgs.appendChild(m);
    msgs.scrollTop = msgs.scrollHeight;
  }
  function quick(options, handler) {
    setTimeout(function () {
      var wrap = document.createElement("div");
      wrap.className = "bot-quick";
      options.forEach(function (opt) {
        var b = document.createElement("button");
        b.type = "button";
        b.textContent = opt;
        b.addEventListener("click", function () {
          wrap.remove();
          userSay(opt);
          handler(opt);
        });
        wrap.appendChild(b);
      });
      msgs.appendChild(wrap);
      msgs.scrollTop = msgs.scrollHeight;
    }, 350);
  }

  function start() {
    if (started) return;
    started = true;
    say("Assalam o Alaikum! 👋 Main VectorIT ka assistant hoon.");
    say("Aap ka naam kya hai?", 500);
    step = 1;
  }

  function waLink() {
    var summary =
      "Assalam o Alaikum Shadab sb, website bot se aa raha/rahi hoon.\n" +
      "Naam: " + lead.name + "\n" +
      (lead.whatsapp ? "WhatsApp: " + lead.whatsapp + "\n" : "") +
      "Zaroorat: " + lead.service + "\n" +
      "Budget: " + lead.budget +
      (lead.message ? "\nTafseel: " + lead.message : "");
    return "https://wa.me/" + WA_NUMBER + "?text=" + encodeURIComponent(summary);
  }

  function saveLead() {
    try {
      var data = new FormData();
      Object.keys(lead).forEach(function (k) { data.append(k, lead[k]); });
      data.append("message", "Bot lead" + (lead.message ? ": " + lead.message : ""));
      fetch("contact-submit.php", { method: "POST", body: data, headers: { "Accept": "application/json" } });
    } catch (e) { /* lead still reaches WhatsApp */ }
  }

  function finish() {
    say("Shukriya " + lead.name + "! 🙏 Aap ki maloomat mil gayi.");
    say("Ab main aap ko seedha Shadab sb se connect kar raha hoon — neeche button dabayen, WhatsApp par baat jari rakhein:", 600);
    setTimeout(function () {
      var wrap = document.createElement("div");
      wrap.className = "bot-msg bot";
      var a = document.createElement("a");
      a.className = "bot-wa-link";
      a.href = waLink();
      a.target = "_blank";
      a.rel = "noopener";
      a.innerHTML = '<svg viewBox="0 0 32 32"><path d="M16 3C9.4 3 4 8.4 4 15c0 2.1.6 4.2 1.6 6L4 29l8.2-1.5c1.2.6 2.5.9 3.8.9 6.6 0 12-5.4 12-12S22.6 3 16 3z"/></svg> WhatsApp par continue karein';
      wrap.appendChild(a);
      msgs.appendChild(wrap);
      msgs.scrollTop = msgs.scrollHeight;
    }, 1200);
    saveLead();
    step = 99;
  }

  function handleText(text) {
    if (step === 1) {
      lead.name = text;
      say("Khush amdeed, " + text + "! Aap ka WhatsApp number kya hai? (taake Shadab sb khud rabta kar saken)");
      step = 2;
    } else if (step === 2) {
      lead.whatsapp = text;
      say("Zabardast. Aap ko kis cheez mein madad chahiye?");
      quick(["VectorERP / accounting software", "FBR e-invoicing", "Website ya app", "Hospital / booking system", "Kuch aur"], function (opt) {
        lead.service = opt;
        say("Andaazan budget kya soch rahe hain? (Sirf idea ke liye — koi pabandi nahi)");
        quick(["Rs 50 hazar se kam", "Rs 50 hazar – 5 lakh", "Rs 5 lakh se zyada", "Abhi andaza nahi"], function (b) {
          lead.budget = b;
          say("Koi aur baat jo batana chahein? (ya seedha 'nahi' likh dein)");
          step = 4;
        });
      });
      step = 3;
    } else if (step === 4) {
      lead.message = /^nahi$/i.test(text.trim()) ? "" : text;
      finish();
    } else if (step === 99) {
      say("Aap ki baat note ho gayi hai — WhatsApp button upar hai, ya hum khud rabta karenge. 👍");
    }
  }

  form.addEventListener("submit", function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text) return;
    input.value = "";
    userSay(text);
    handleText(text);
  });

  launcher.addEventListener("click", function (e) {
    e.preventDefault();
    var open = panel.classList.toggle("open");
    if (open) { start(); input.focus(); }
  });
  panel.querySelector(".bot-close").addEventListener("click", function () {
    panel.classList.remove("open");
  });
})();
