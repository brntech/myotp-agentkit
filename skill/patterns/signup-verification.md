# Pattern: signup verification

Verify a phone number during user registration. Account row only persists after the OTP is verified, so unverified phone numbers never pollute the user table.

## Sequence

```
User                   Frontend                 Backend                     MyOTP API
 |  enter phone, name -->                          |                              |
 |                       POST /signup/start ------>|                              |
 |                                                 | POST /generate_otp --------->|
 |                                                 |<-- 200 {message_id,         |
 |                                                 |        expires_at}          |
 |                       <-- 200 {pending_id,      |                              |
 |                                expires_at}      |                              |
 |  enter code         -->                          |                              |
 |                       POST /signup/complete --->|                              |
 |                       (pending_id, code, name)  | POST /verify_otp ----------->|
 |                                                 |<-- 200 {status:success}     |
 |                                                 | INSERT users ...            |
 |                       <-- 200 {user_id, jwt}    |                              |
 |  logged in          <--                          |                              |
```

## Backend pseudocode

```
POST /signup/start
  body: { phone, name, email, password }

  validate phone format (digits only, 7-15 chars, no leading 0)
  reject if user with this phone already exists

  res = POST https://api.myotp.app/generate_otp
    headers: X-API-Key: $MYOTP_API_KEY
    body:    { phone_number: phone }

  pending_id = uuid()
  cache.set(
    "signup:" + pending_id,
    { phone, name, email, password_hash: hash(password),
      message_id: res.message_id, attempts: 0 },
    ttl = 600        // 10 min, longer than OTP itself
  )
  return { pending_id, expires_at: res.expires_at }

POST /signup/complete
  body: { pending_id, code }

  pending = cache.get("signup:" + pending_id)
  if not pending: 410 expired
  if pending.attempts >= 5: 429 too many attempts; force restart
  pending.attempts += 1
  cache.set(...)

  result = POST https://api.myotp.app/verify_otp
    headers: X-API-Key: $MYOTP_API_KEY
    body:    { phone_number: pending.phone, otp: code }

  if result.status != "success":
    return 400 { reason: result.reason }    // "invalid" | "expired" | "not found"

  user = INSERT users { phone, email, password_hash, phone_verified_at: now }
  cache.delete("signup:" + pending_id)
  return { user_id: user.id, token: issue_jwt(user) }
```

## Security gotchas

- Do not create the user row before verification. A failed verification should leave no trace in your user table.
- Cap attempts per `pending_id` (5 is reasonable). MyOTP enforces single-use codes, but you also want to prevent rapid-fire guesses from burning out the OTP.
- Store password as a hash even in the temporary cache. The pending blob is short-lived but it still holds plaintext-equivalent data otherwise.
- 409 from `/generate_otp` means an OTP is already active for that number. Treat as "we already sent a code" in the UI, do not auto-retry with `force_send: "true"` unless the user explicitly clicks "resend."
- Do not log `code` or the cache value contents.
- If the user closes the browser, the pending entry expires on its own — no cleanup needed.
- For multi-channel apps, add `channel` to `/signup/start` and pass through to `/generate_otp`.
