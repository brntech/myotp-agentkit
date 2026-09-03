---
name: myotp-healthcare
description: Use when adding patient verification, telehealth login, or HIPAA-context phone authentication to a healthcare app, patient portal, telemedicine platform, or EHR-adjacent workflow. Triggers on patient verification, telehealth, HIPAA, PHI access, prescription refill verification, or appointment confirmation mentions. MyOTP.App provides SMS, WhatsApp, and Telegram OTP delivery; works alongside (not as a substitute for) HIPAA-covered storage and BAAs.
---

# MyOTP.App for healthcare apps

## When this skill fires

The user is building or modifying an app that handles patient identity verification or PHI access:
- Telehealth platforms (login, appointment confirmation)
- Patient portals
- Prescription refill apps
- Health insurance member portals
- EHR-adjacent provider tools

If the use case is generic SaaS auth, use the base `myotp` skill.

## What MyOTP brings to healthcare (and what it doesn't)

**MyOTP brings:**
- Reliable, ISO 27001-certified SMS / WhatsApp / Telegram OTP delivery
- Direct carrier connections so codes arrive even on rural / cellular-tower-limited connections (rural patient populations)
- Multi-channel — voice, WhatsApp, SMS — for accessibility (elderly patients on landlines, etc.)

**MyOTP does NOT bring:**
- HIPAA Business Associate Agreement (BAA) coverage. **The platform does not have a BAA available as a standard product**. Talk to BroadNet sales for enterprise BAA options if PHI ever flows through MyOTP messages. (For most use cases, OTP codes alone don't constitute PHI — they're authenticators, not health information.)
- PHI storage. The platform stores transaction metadata (phone, message_id, timestamp) but the OTP code itself expires; treat MyOTP as a delivery channel, not a record system.
- Substitute for proper identity verification. Phone ownership ≠ patient identity. Use a credential-issuance flow (DocuSign-style) for initial patient onboarding, then MyOTP for subsequent re-auth.

## Common patterns

### 1. Telehealth login

```typescript
async function patientLogin(memberId, phoneEntered) {
  const patient = await chartApi.lookup(memberId);
  if (!patient || patient.phone !== normalizePhone(phoneEntered)) {
    return { error: "patient not found" };
  }
  const r = await fetch("https://api.myotp.app/generate_otp", {
    method: "POST",
    headers: { "X-API-Key": process.env.MYOTP_API_KEY, "Content-Type": "application/json" },
    body: JSON.stringify({
      phone_number: patient.phoneDigits,
      channel: "sms",
      brand: "ClinicName",                  // Patient-facing brand
      otp_length: 6,
      otp_validity: 300,
    }),
  });
  const { message_id } = await r.json();
  // Store message_id in your session, NOT the OTP.
  // The OTP itself stays opaque to your app.
  session.pending_otp = { message_id, member_id: memberId };
  return { next: "verify_otp" };
}
```

The OTP message body should be neutral: "Your ClinicName login code is 123456." NOT "Your code to view your latest lab results is..." (the latter implicitly leaks that the user has lab results pending — minor PHI signal).

### 2. Prescription refill confirmation

When a patient requests an early refill or an unusual quantity, send an OTP to confirm intent before queuing it for pharmacy review:

```python
def request_refill(patient, prescription, quantity):
    if quantity > prescription.standard_quantity * 1.2 or is_early_refill(patient, prescription):
        otp_id = send_otp(patient.phone_digits, brand="ClinicName", validity=600)
        store_pending_refill(patient.id, prescription.id, quantity, otp_id)
        return {"status": "verify_required"}
    return queue_refill(patient, prescription, quantity)
```

### 3. Appointment confirmation (light-touch)

Don't gate appointment confirmation behind OTP — that's friction patients hate. Use OTP only for *changes* to appointments (rescheduling, cancellation) or for *first-time* confirmation of a new patient relationship.

## Privacy guidance

- **Never include diagnostic info, medication names, or test results in OTP messages.** "Your ClinicName login code is 123456" is fine. "Your code to view your HIV test results is 123456" is a HIPAA breach waiting to happen.
- **Consider voice channel for accessibility.** Some elderly patients struggle with SMS but can hear a code over a phone call. (Voice channel is on the MyOTP roadmap; for now, SMS is the only option.)
- **Test in the patient's preferred language.** OTP messages should be in the language the patient registered with. Use the brand's default template via the `template_order` parameter (requires ACCESS_TO_TEMPLATES entitlement) or pre-register localized templates.

## Channel selection for healthcare

| Channel | When |
|---------|------|
| SMS | Default. Reaches everyone with a phone, including basic phones used by elderly patients. |
| WhatsApp | Younger patient cohorts, urban/suburban demographics, and emerging-market clinics where WhatsApp dominates. |
| Telegram | Niche; only for tech-forward telehealth apps with a Telegram-savvy user base. |

For Medicare-aged populations, default to SMS. Period.

## What if your app actually handles PHI in messages?

Then:
1. Get a BAA from BroadNet — ask sales (not standard, but possible for enterprise).
2. Audit-log every OTP request with patient ID, prescription ID, timestamp, source IP.
3. Use a separate API key per facility — don't share an API key across multiple covered entities.
4. Consider doing OTP delivery via Twilio or a cleared HIPAA-friendly provider until BAA is in place — the loss in delivery quality is worth the compliance posture.

This skill doesn't claim HIPAA coverage; it makes the integration sane while you set the compliance pieces up separately.

## Trial limit reminder

15 free credits to test the integration in your dev environment. Don't use trial credits for real patient testing — the trial isn't covered under any compliance umbrella.

## See also

- Base skill: [`../SKILL.md`](../SKILL.md)
- Patterns: [`../patterns/transaction-auth.md`](../patterns/transaction-auth.md) for the message-id-based confirmation pattern
- For BAA inquiries: contact MyOTP / BroadNet sales directly
