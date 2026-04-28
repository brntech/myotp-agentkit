# curl — MyOTP.App

Pre-flight: `export MYOTP_API_KEY=your-32-char-key`.

## Send an OTP (SMS — default channel)

```bash
curl -sS -X POST https://api.myotp.app/generate_otp \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $MYOTP_API_KEY" \
  -d '{"phone_number": "14155551234"}'
```

Response shape:
```json
{
  "message_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
  "status": "accepted",
  "message": "OTP sent",
  "date_sent": "2026-04-28T10:30:00.000000",
  "expires_at": "2026-04-28T10:35:00.000000",
  "cost": 0.035
}
```

## Send via WhatsApp

```bash
curl -sS -X POST https://api.myotp.app/generate_otp \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $MYOTP_API_KEY" \
  -d '{"phone_number": "14155551234", "channel": "whatsapp"}'
```

## Send via Telegram

```bash
# Telegram requires a 4-8 digit OTP; default 6 is fine.
curl -sS -X POST https://api.myotp.app/generate_otp \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $MYOTP_API_KEY" \
  -d '{"phone_number": "14155551234", "channel": "telegram"}'
```

## Verify the OTP the user typed

```bash
curl -sS -X POST https://api.myotp.app/verify_otp \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $MYOTP_API_KEY" \
  -d '{"phone_number": "14155551234", "otp": "123456"}'
```

Success: `{"status": "success", "message": "OTP verified"}`
Failure (200 with failed status): `{"status": "failed", "reason": "invalid"|"expired"|"not found", ...}`

## Check delivery status (Business+ for DLR fields)

```bash
curl -sS -X POST https://api.myotp.app/check_otp_status \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $MYOTP_API_KEY" \
  -d '{"message_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890"}'
```
