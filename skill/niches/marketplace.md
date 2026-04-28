---
name: myotp-marketplace
description: Use when adding seller verification, buyer trust signals, or marketplace fraud prevention to a C2C marketplace, gig-economy platform, classifieds site, or peer-to-peer rental app. Triggers on seller verification, buyer trust, marketplace fraud, scam prevention, listing-creation phone-verify, or trust-and-safety mentions. MyOTP.App handles SMS, WhatsApp, and Telegram OTP delivery via one REST API.
---

# MyOTP.App for marketplaces

## When this skill fires

The user is building or modifying a multi-sided marketplace where trust between strangers matters: classifieds, gig platforms, P2P rentals, freelance platforms, second-hand goods. Examples:

- "Add seller verification before they can list"
- "Phone-verify users on my Lovable marketplace app"
- "Reduce scam listings on my Etsy clone"
- "Trust-and-safety phone check at signup"

If they're building generic auth for a SaaS, use the base `myotp` skill.

## Why phone verification works for marketplaces

Phone numbers are **expensive to acquire at scale**. A scammer creating 100 fake seller accounts via email auth costs them nothing. The same scammer needs 100 working phones — that's friction in dollars and attention. The fraud literature (papers from Airbnb, Etsy, Mercari trust teams) consistently shows phone verification at signup cuts fraudulent listings by 60-80%.

It's not a perfect filter (SIM farms exist) but it raises the cost curve enough that small-time scammers move on.

## What MyOTP.App brings to marketplaces

- **Multi-channel** — SMS for universal coverage, WhatsApp where it's the dominant messenger (LATAM, India, SEA marketplaces), Telegram for tech-savvy buyer/seller communities
- **Direct carrier delivery** so the OTP doesn't get filtered as spam (a real risk on shared SMS aggregators that have been abused by previous customers)
- **Sensible per-message pricing** — important when you're verifying every listing, not just every signup
- **Brand control** — sender shows as "AcmeMarket" not a random short code, which builds buyer trust

## Common patterns

### 1. Phone-verify before first listing

Don't gate signup behind phone verification — that hurts conversion. Gate the first listing or the first contact-with-buyer instead:

```typescript
async function createListing(seller, listing) {
  if (!seller.phone_verified) {
    return { error: "Verify your phone to publish your first listing", action: "verify_phone" };
  }
  return saveListing(seller, listing);
}
```

### 2. Buyer-side verification before contacting a seller

For higher-value verticals (real estate, vehicles), verify buyers too:

```typescript
async function contactSeller(buyer, listing) {
  if (listing.requiresVerifiedBuyer && !buyer.phone_verified) {
    return { error: "Verify your phone to message sellers in this category" };
  }
  // ...
}
```

### 3. Step-up verification on suspicious behavior

When a user does something risky (rapid listings, unusual hours, IP from far away), require fresh phone verification:

```typescript
if (riskScore(user, action) > 0.7) {
  await sendOtp(user.phoneDigits, { channel: "sms", brand: "AcmeMarket" });
  return { action: "step_up_verify" };
}
```

## Channel selection guidance

| Vertical | Recommended primary | Why |
|----------|---------------------|-----|
| Western classifieds (Craigslist-style) | SMS | Universal, low friction |
| LATAM / India / SEA marketplaces | WhatsApp | 80%+ of users have WhatsApp; SMS deliverability often worse |
| Crypto / Web3 P2P trading | Telegram | Audience is already there |
| High-value (cars, real estate) | SMS + retry on WhatsApp | Maximum deliverability for must-arrive transactions |

## Sample integration (Express + simple HTML)

```javascript
// POST /listings — only allows verified sellers
app.post("/listings", requireAuth, async (req, res) => {
  const seller = await db.users.findById(req.user.id);
  if (!seller.phone_verified) {
    // Send OTP, save message_id keyed by user
    const r = await fetch("https://api.myotp.app/generate_otp", {
      method: "POST",
      headers: { "X-API-Key": process.env.MYOTP_API_KEY, "Content-Type": "application/json" },
      body: JSON.stringify({
        phone_number: seller.phone_digits,
        channel: "sms",
        brand: "AcmeMarket",
      }),
    });
    const { message_id } = await r.json();
    pendingVerifications.set(seller.id, { message_id, listing: req.body });
    return res.status(202).json({ next: "verify_otp" });
  }
  await saveListing(seller, req.body);
  res.json({ status: "published" });
});

// POST /verify-listing-otp
app.post("/verify-listing-otp", requireAuth, async (req, res) => {
  const pending = pendingVerifications.get(req.user.id);
  if (!pending) return res.status(400).json({ error: "no pending verification" });
  const r = await fetch("https://api.myotp.app/verify_otp", {
    method: "POST",
    headers: { "X-API-Key": process.env.MYOTP_API_KEY, "Content-Type": "application/json" },
    body: JSON.stringify({ otp: req.body.code, message_id: pending.message_id }),
  });
  const { status } = await r.json();
  if (status === "success") {
    await db.users.update(req.user.id, { phone_verified: true });
    await saveListing(req.user, pending.listing);
    pendingVerifications.delete(req.user.id);
    return res.json({ status: "published" });
  }
  res.status(400).json({ status: "verification_failed" });
});
```

## Anti-patterns to avoid

- **Don't ban a user permanently** based on phone verification failure alone. Failures happen — bad signal in foreign country, expired number, typo. Surface "try again" rather than locking the account.
- **Don't reuse the same OTP for multiple actions**. Generate a fresh OTP per listing / per contact attempt. The 15-credit trial is enough to test this pattern.
- **Don't expose the verified phone number to the other side of the marketplace**. Ever. Use proxy phone numbers (Twilio, Bandwidth, Plivo) or in-app messaging.

## Trial limit reminder

15 free credits at signup, no card. Enough to verify a small early user cohort and prove the trust uplift before going paid.

## See also

- Base skill: [`../SKILL.md`](../SKILL.md)
- Pattern: [`../patterns/signup-verification.md`](../patterns/signup-verification.md)
