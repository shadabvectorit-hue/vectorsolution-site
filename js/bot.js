/* VectorIT chat bot — picks a language, qualifies the visitor, saves the lead,
   then hands over to the real WhatsApp number so the conversation lands on the
   owner's phone. Languages: English · Roman Urdu · اردو (RTL). */
(function () {
  "use strict";

  var WA_NUMBER = "923363138686";
  var launcher = document.querySelector(".wa-float");
  if (!launcher) return;

  /* ---------- language packs ---------- */
  var L = {
    en: {
      dir: "ltr", label: "English",
      greet: "Hello! 👋 I'm the VectorIT assistant.",
      askName: "What's your name?",
      askPhone: function (n) { return "Good to meet you, " + n + ". What's your WhatsApp number, so we can reach you?"; },
      askNeed: "What do you need help with?",
      needs: ["VectorERP / accounting software", "FBR e-invoicing", "Website or mobile app", "Hospital / booking system", "Something else"],
      askBudget: "Roughly what budget do you have in mind? (Just a ballpark — nothing is fixed)",
      budgets: ["Under Rs 50,000", "Rs 50,000 – 5 lakh", "Over Rs 5 lakh", "Not sure yet"],
      askMore: "Anything else you'd like to add? (or just type 'no')",
      no: /^(no|nope|nothing)$/i,
      thanks: function (n) { return "Thank you, " + n + "! 🙏 I've got your details."; },
      handover: "Now I'll connect you directly with Shadab. Tap below and the conversation continues on WhatsApp — he'll get it on his phone:",
      waBtn: "Continue on WhatsApp",
      after: "Your details are saved — tap the WhatsApp button above, or we'll reach out to you. 👍",
      waMsg: function (d) {
        return "Hello Shadab, I'm coming from the VectorIT website.\n" +
          "Name: " + d.name + "\n" + (d.whatsapp ? "WhatsApp: " + d.whatsapp + "\n" : "") +
          "Need: " + d.service + "\nBudget: " + d.budget + (d.message ? "\nDetails: " + d.message : "");
      }
    },
    ur_rm: {
      dir: "ltr", label: "Roman Urdu",
      greet: "Assalam o Alaikum! 👋 Main VectorIT ka assistant hoon.",
      askName: "Aap ka naam kya hai?",
      askPhone: function (n) { return "Khush amdeed, " + n + "! Aap ka WhatsApp number kya hai? (taake rabta kar saken)"; },
      askNeed: "Aap ko kis cheez mein madad chahiye?",
      needs: ["VectorERP / accounting software", "FBR e-invoicing", "Website ya mobile app", "Hospital / booking system", "Kuch aur"],
      askBudget: "Andaazan budget kya soch rahe hain? (Sirf idea ke liye — koi pabandi nahi)",
      budgets: ["Rs 50 hazar se kam", "Rs 50 hazar – 5 lakh", "Rs 5 lakh se zyada", "Abhi andaza nahi"],
      askMore: "Koi aur baat jo batana chahein? (ya seedha 'nahi' likh dein)",
      no: /^(nahi|nahin|no)$/i,
      thanks: function (n) { return "Shukriya " + n + "! 🙏 Aap ki maloomat mil gayi."; },
      handover: "Ab main aap ko seedha Shadab sb se connect kar raha hoon. Neeche button dabayen — baat WhatsApp par jari rahegi, unke mobile par pohnch jayegi:",
      waBtn: "WhatsApp par baat karein",
      after: "Aap ki maloomat mehfooz hai — upar WhatsApp button dabayen, ya hum khud rabta karenge. 👍",
      waMsg: function (d) {
        return "Assalam o Alaikum Shadab sb, website se aa raha/rahi hoon.\n" +
          "Naam: " + d.name + "\n" + (d.whatsapp ? "WhatsApp: " + d.whatsapp + "\n" : "") +
          "Zaroorat: " + d.service + "\nBudget: " + d.budget + (d.message ? "\nTafseel: " + d.message : "");
      }
    },
    ur: {
      dir: "rtl", label: "اردو",
      greet: "السلام علیکم! 👋 میں ویکٹر آئی ٹی کا اسسٹنٹ ہوں۔",
      askName: "آپ کا نام کیا ہے؟",
      askPhone: function (n) { return "خوش آمدید " + n + "! آپ کا واٹس ایپ نمبر کیا ہے؟ (تاکہ ہم رابطہ کر سکیں)"; },
      askNeed: "آپ کو کس چیز میں مدد چاہیے؟",
      needs: ["ویکٹر ای آر پی / اکاؤنٹنگ سافٹ ویئر", "ایف بی آر ای-انوائسنگ", "ویب سائٹ یا موبائل ایپ", "ہسپتال / بکنگ سسٹم", "کچھ اور"],
      askBudget: "تقریباً کتنا بجٹ سوچ رہے ہیں؟ (صرف اندازے کے لیے — کوئی پابندی نہیں)",
      budgets: ["پچاس ہزار سے کم", "پچاس ہزار سے پانچ لاکھ", "پانچ لاکھ سے زیادہ", "ابھی اندازہ نہیں"],
      askMore: "کوئی اور بات جو آپ بتانا چاہیں؟ (یا صرف 'نہیں' لکھ دیں)",
      no: /^(نہیں|نہی|no|nahi)$/i,
      thanks: function (n) { return "شکریہ " + n + "! 🙏 آپ کی معلومات موصول ہو گئی ہیں۔"; },
      handover: "اب میں آپ کو براہِ راست شاداب صاحب سے ملا رہا ہوں۔ نیچے بٹن دبائیں — بات واٹس ایپ پر جاری رہے گی اور اُن کے موبائل پر پہنچ جائے گی:",
      waBtn: "واٹس ایپ پر بات جاری رکھیں",
      after: "آپ کی معلومات محفوظ ہو گئی ہیں — اوپر واٹس ایپ بٹن دبائیں، یا ہم خود رابطہ کریں گے۔ 👍",
      waMsg: function (d) {
        return "السلام علیکم شاداب صاحب، ویب سائٹ سے رابطہ کر رہا/رہی ہوں۔\n" +
          "نام: " + d.name + "\n" + (d.whatsapp ? "واٹس ایپ: " + d.whatsapp + "\n" : "") +
          "ضرورت: " + d.service + "\nبجٹ: " + d.budget + (d.message ? "\nتفصیل: " + d.message : "");
      }
    }
  };

  /* ---------- panel ---------- */
  var panel = document.createElement("div");
  panel.className = "bot-panel";
  panel.setAttribute("role", "dialog");
  panel.setAttribute("aria-label", "Chat with VectorIT");
  panel.innerHTML =
    '<div class="bot-head">' +
      '<span class="b-avatar">V</span>' +
      '<div><b>VectorIT</b><span id="bot-sub">English · Roman Urdu · اردو</span></div>' +
      '<button class="bot-close" aria-label="Close chat">&times;</button>' +
    "</div>" +
    '<div class="bot-msgs" id="bot-msgs"></div>' +
    '<form class="bot-input" id="bot-form" hidden>' +
      '<input id="bot-text" type="text" autocomplete="off" aria-label="Your reply">' +
      '<button type="submit" aria-label="Send"><svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 8h11M9 3.5 13.5 8 9 12.5"/></svg></button>' +
    "</form>";
  document.body.appendChild(panel);

  var msgs = panel.querySelector("#bot-msgs");
  var form = panel.querySelector("#bot-form");
  var input = panel.querySelector("#bot-text");
  var sub = panel.querySelector("#bot-sub");

  var t = null;                 // active language pack
  var lead = { source: "bot", lang: "", name: "", whatsapp: "", service: "", budget: "", message: "" };
  var step = 0;
  var started = false;

  function say(text, delay) {
    setTimeout(function () {
      var m = document.createElement("div");
      m.className = "bot-msg bot";
      if (t && t.dir === "rtl") { m.dir = "rtl"; m.classList.add("rtl"); }
      m.textContent = text;
      msgs.appendChild(m);
      msgs.scrollTop = msgs.scrollHeight;
    }, delay || 0);
  }
  function userSay(text) {
    var m = document.createElement("div");
    m.className = "bot-msg user";
    if (t && t.dir === "rtl") { m.dir = "rtl"; m.classList.add("rtl"); }
    m.textContent = text;
    msgs.appendChild(m);
    msgs.scrollTop = msgs.scrollHeight;
  }
  function quick(options, handler, delay) {
    setTimeout(function () {
      var wrap = document.createElement("div");
      wrap.className = "bot-quick";
      if (t && t.dir === "rtl") wrap.dir = "rtl";
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
    }, delay || 350);
  }

  /* ---------- step 0: language ---------- */
  function start() {
    if (started) return;
    started = true;
    say("Assalam o Alaikum! 👋  Hello!");
    say("Aap kis zabaan mein baat karna chahenge? / Which language would you prefer?", 450);
    quick([L.en.label, L.ur_rm.label, L.ur.label], function (choice) {
      t = choice === L.en.label ? L.en : (choice === L.ur_rm.label ? L.ur_rm : L.ur);
      lead.lang = t.label;
      sub.textContent = t.label;
      panel.classList.toggle("rtl-mode", t.dir === "rtl");
      input.dir = t.dir;
      form.hidden = false;
      say(t.greet, 200);
      say(t.askName, 700);
      step = 1;
      input.focus();
    }, 800);
  }

  function waLink() {
    return "https://wa.me/" + WA_NUMBER + "?text=" + encodeURIComponent(t.waMsg(lead));
  }

  function saveLead() {
    try {
      var data = new FormData();
      Object.keys(lead).forEach(function (k) { data.append(k, lead[k]); });
      data.append("message", "[" + lead.lang + "] " + (lead.message || "(bot lead)"));
      fetch("contact-submit.php", { method: "POST", body: data, headers: { Accept: "application/json" } });
    } catch (e) { /* the WhatsApp handover still carries everything */ }
  }

  function finish() {
    say(t.thanks(lead.name));
    say(t.handover, 600);
    setTimeout(function () {
      var wrap = document.createElement("div");
      wrap.className = "bot-msg bot";
      if (t.dir === "rtl") { wrap.dir = "rtl"; wrap.classList.add("rtl"); }
      var a = document.createElement("a");
      a.className = "bot-wa-link";
      a.href = waLink();
      a.target = "_blank";
      a.rel = "noopener";
      a.innerHTML = '<svg viewBox="0 0 32 32" aria-hidden="true"><path d="M16 3C9.4 3 4 8.4 4 15c0 2.1.6 4.2 1.6 6L4 29l8.2-1.5c1.2.6 2.5.9 3.8.9 6.6 0 12-5.4 12-12S22.6 3 16 3z"/></svg><span>' + t.waBtn + "</span>";
      wrap.appendChild(a);
      msgs.appendChild(wrap);
      msgs.scrollTop = msgs.scrollHeight;
      form.hidden = true;
    }, 1300);
    saveLead();
    step = 99;
  }

  function handleText(text) {
    if (step === 1) {
      lead.name = text;
      say(t.askPhone(text));
      step = 2;
    } else if (step === 2) {
      lead.whatsapp = text;
      say(t.askNeed);
      quick(t.needs, function (need) {
        lead.service = need;
        say(t.askBudget);
        quick(t.budgets, function (b) {
          lead.budget = b;
          say(t.askMore);
          step = 4;
          input.focus();
        });
      });
      step = 3;
    } else if (step === 4) {
      lead.message = t.no.test(text.trim()) ? "" : text;
      finish();
    } else if (step === 99) {
      say(t.after);
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
    if (open) start();
  });
  panel.querySelector(".bot-close").addEventListener("click", function () {
    panel.classList.remove("open");
  });
})();
