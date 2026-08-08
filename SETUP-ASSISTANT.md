# VectorIT AI assistant — what it is and how to switch it on

One assistant, two places it answers:

- **WhatsApp** — a customer messages +92 302 2219093, the assistant replies, captures the lead, and pings +92 336 3138686 when a human is needed.
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

**The two numbers, and why they must stay two.**

| Number | Role | Must it have the WhatsApp app? |
|---|---|---|
| **0302 2219093** | The API number. Customers message this; the assistant answers it. | **No — it must NOT.** Registering it to the Cloud API takes the number over. |
| **0336 3138686** | Yours. Receives the handover alert when a customer needs a person. | Yes, keep it as normal WhatsApp. |

If both were the same number the assistant would be messaging the account it runs on: alerts would loop or silently vanish while everything looked healthy. Keeping them separate also means **you never have to delete WhatsApp from your own number** — that was the risk in the earlier plan, and it is gone.

**Check first whether 0302 2219093 has WhatsApp on it at all.** If it never has, there is nothing to delete and the rest of this box does not apply.

If it does:

- **Consumer WhatsApp (green app)** — the account must be **deleted**, from Settings → Account → Delete my account on the primary device. *Uninstalling is not deleting*: the registration lives on Meta's servers, not the handset. Deletion is irreversible — chats, media, backups and group memberships all go.
- **WhatsApp Business app (blue app)** — deletion is **not** the only option. Meta's Coexistence lets one number run the Business app and the API together with history preserved, but it requires onboarding through a Solution Partner / Tech Provider and costs you: 20 messages/sec instead of ~80, no group chats, no disappearing or view-once messages, no live location, no calls or channels on the API side, broadcast lists read-only, and you cannot deregister via API. For one assistant on a dedicated number it buys nothing. Use a clean number instead.

Deleting a WhatsApp account does **not** lift a Meta account restriction — they are unrelated. Do not delete anything until the portfolio is confirmed working.

### The actual steps (verified against Meta's docs, Aug 2026)

1. **Business portfolio** — business.facebook.com → Create a business portfolio. Name must avoid internal capitals: "Vector It", not "VectorIT". **A WhatsApp Business Account cannot be moved between portfolios later**, so get this right once.
2. **Create the app** — developers.facebook.com/apps → Create app → **Use cases → "Connect with customers through WhatsApp"**. App *types* no longer exist; any guide that says "choose Business type" is out of date. Attach the portfolio during creation — do not pick "I don't want to connect a business portfolio yet". Use cases cannot be removed afterwards.
3. **Test WABA and test number** are provisioned automatically. WhatsApp Business Terms are accepted inline here; there is no separate terms page.
4. **System user + permanent token** — business.facebook.com/settings/system-users → Add → Admin. **Assign the assets before generating the token**: Apps → your app (Full control), then WhatsApp accounts → your WABA (Full control). Then Generate new token → your app → Expiration **Never** → tick `whatsapp_business_messaging`, `whatsapp_business_management`, `business_management`. Shown once → `wa_token`. The blue "Generate access token" button on the App Dashboard is a short-lived *user* token — never ship that one.
5. **App Secret** — App settings → Basic → Show → `wa_app_secret`. It is used only to validate the `X-Hub-Signature-256` header on webhooks, and is not an API credential.
6. **Webhooks** — WhatsApp → Configuration → Callback URL `https://vectorsolution.it/wa/hook.php`, verify token = `wa_verify_token`, then subscribe the **`messages`** field.
7. **Phone Number ID** — WhatsApp → API Setup → `wa_phone_id`. It is an ID, not the phone number.
8. **Add the real number** — WhatsApp Manager → Account tools → Phone numbers → Add phone number. Display name goes to review; verify by SMS or voice.
9. **Register it — a separate API call.** Adding the number in the UI does *not* register it: `POST /<PHONE_NUMBER_ID>/register` with `messaging_product: "whatsapp"` and your 6-digit two-step PIN. **Limit 10 attempts per number per 72 hours** — exceeding it returns error 133016 and locks the number for 72 hours. Do not burn attempts guessing the PIN.
10. **Attach a payment method** in Meta Business Suite. Production numbers cannot send without one — the symptoms are errors 131042 and 2388103. The free test number does not need this.

**Two things widely reported as required that are not.** App Review / Advanced Access is *not* needed for a business messaging its own customers from its own WABA. Business verification is *not* needed to start — it is a scaling lever: 250 unique recipients per rolling 24h unverified, 2,000 once verified, and the phone-number cap goes 2 → 20. Since 7 Oct 2025 these limits are **per portfolio**, not per number.

Re-check `vectorsolution.it/wa/health.php`. All green means send a WhatsApp message to the test number and watch it reply.

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
