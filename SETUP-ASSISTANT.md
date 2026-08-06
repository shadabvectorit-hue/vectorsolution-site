# VectorIT AI assistant — what it is and how to switch it on

One assistant, two places it answers:

- **WhatsApp** — a customer messages +92 336 3138686, the assistant replies, captures the lead, and pings you when a human is needed.
- **The website chat** — the same brain behind the widget already on the site.

Both write leads into the same file the contact form uses, so everything still lands in one place: `vectorsolution.it/inquiries.php`.

---

## First, the money — because this is why we built it this way

You mentioned $50/month for "WhatsApp AI". That price is a **reseller's platform fee** (Wati, Twilio, Respond.io and similar). It is not what WhatsApp costs.

| | Reseller route | What we built |
|---|---|---|
| Platform fee | ~$50/mo | **$0** |
| WhatsApp API | included | **$0** — Meta's Cloud API is free to use |
| Replies to customer messages | included | **$0** — see note below |
| AI | often extra | **~$2–5/mo** on Claude Haiku |
| Server for n8n | $5–24/mo | **$0** — runs on your existing hosting |
| **Total** | **~$50–75/mo** | **~$2–5/mo** |

**The note that matters:** Meta charges per *conversation*, and conversations a **customer starts** are free. Everything this system sends is a reply to an incoming message, so it stays inside that free window by design. You would only start paying if you send *marketing* messages first — which this does not do.

⚠️ **Verify current pricing before you rely on these numbers.** Meta has changed WhatsApp pricing more than once, and my information has a cutoff. Check Meta's own pricing page when you set up the account. The architecture is right either way; only the per-message figure could have moved.

### Why not n8n

n8n is not a plugin. It is a **Node.js server** that has to run somewhere permanently — it cannot run on your LiteSpeed shared hosting. Using it would mean either n8n Cloud (~€24/mo) or a VPS (~$5/mo plus you maintaining it), to do a job your existing PHP hosting already does for nothing.

n8n becomes worth it later, when you want WhatsApp **plus** email **plus** a CRM **plus** accounting all syncing to each other. For "answer WhatsApp intelligently", it is a monthly bill and a second server to keep patched, for no gain.

---

## What you need to do

### Step 1 — Anthropic key (5 minutes, needed for both channels)

1. Go to <https://console.anthropic.com>, sign up.
2. Add credit — **$5 is plenty to start** and will last months at your volume.
3. **API keys → Create key**. Copy it (starts `sk-ant-`). It is shown once.

> ⚠️ **Never write the key into this file, or into any file in this folder.**
> Everything here is tracked by git and gets pushed to GitHub. The key belongs
> in `/home/vectorit/_private/config.php` on the server and nowhere else.
> A key that has been in a repo file, a chat, or an email should be deleted in
> the console and reissued — it takes thirty seconds and removes all doubt.

### Step 2 — Create the config file

The file lives **outside the website folder** so it can never be served, and it is not in git so a key can never be pushed to GitHub.

In cPanel → **File Manager**, go to `/home/vectorit/` (one level *above* `public_html`), create a folder `_private` if it does not exist, and inside it create **`config.php`**:

```php
<?php
return [
    'anthropic_key'   => 'sk-ant-PASTE-YOURS-HERE',

    // WhatsApp — leave blank until Step 3 is done
    'wa_token'        => '',
    'wa_phone_id'     => '',
    'wa_verify_token' => 'pick-any-random-text-vectorit-2026',
    'wa_app_secret'   => '',
    'wa_owner'        => '923363138686',

    // Spend ceilings. These are hard stops, not warnings.
    'daily_reply_cap' => 400,
    'per_user_cap'    => 40,
    'kill_switch'     => false,
];
```

Set its permissions to **600**.

**The website chat now works.** Check it at `vectorsolution.it/wa/health.php` (same password as your inbox).

### Step 3 — WhatsApp (about an hour, mostly Meta's verification)

You need a phone number that is **not currently on the WhatsApp app**. A second SIM, or your landline. If you want to use 0336 3138686, you must first delete its WhatsApp account — do not do this until you have decided.

1. <https://developers.facebook.com> → **My Apps → Create App → Business**.
2. Add the **WhatsApp** product. Meta gives you a free test number to try immediately.
3. From **API Setup**, copy the **Phone number ID** → `wa_phone_id`.
4. **Business Settings → Users → System Users** → add one, give it the WhatsApp app, generate a **permanent token** → `wa_token`. (The token on the API Setup page expires in 24 hours — do not use that one.)
5. **App Settings → Basic → App Secret** → `wa_app_secret`.
6. **WhatsApp → Configuration → Webhook → Edit**:
   - Callback URL: `https://vectorsolution.it/wa/hook.php`
   - Verify token: whatever you put in `wa_verify_token`
   - Click **Verify and save**, then **Subscribe** to the `messages` field.
7. Business verification — Meta will ask for documents. Until it completes you can send to a few test numbers, which is enough to prove it works.

Re-check `vectorsolution.it/wa/health.php`. All green means send yourself a WhatsApp message and watch it reply.

---

## Living with it

**The health page** — `vectorsolution.it/wa/health.php` tells you whether the server can reach the APIs, whether every credential is present, and how many tokens you have spent this month. It is the first place to look if anything seems off.

**The kill switch** — set `'kill_switch' => true` in `config.php` and every AI reply stops instantly. The website falls back to the old scripted chat; WhatsApp messages still reach you, just without an automatic answer. No deploy needed, it takes effect on the next message.

**The spend caps** — `daily_reply_cap` stops everyone together burning the budget, `per_user_cap` stops one person or one loop doing it. Both are hard stops.

**Handover** — when a customer asks for a discount, wants a person, or is ready to buy, you get a WhatsApp with what they said and a link straight to the chat.

## What it will not do, on purpose

- **It will not invent a price.** It only knows the three plans published on your site.
- **It will not claim you are an FBR licensed integrator.** You are not; PRAL is. This is written into its instructions because getting it wrong is a claim you cannot support.
- **It will not promise discounts, dates, or custom features.** Those are yours to give.
- **It will not read images, voice notes or PDFs.** It says so and calls you instead of guessing.

When it does not know something, it says it will have you confirm — which is the correct answer, and the one that does not cost you a customer three weeks later.
