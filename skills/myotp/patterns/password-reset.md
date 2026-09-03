# Pattern: password reset

Reset a forgotten password using the phone number on file. No email magic link, no security questions. The OTP proves possession of the phone, after which the user picks a new password.

## Sequence

```
User                Frontend            Backend                    MyOTP API
 | "Forgot password" -->                  |                              |
 |                    POST /pwreset/start>|                              |
 |                    (phone or email)    | look up user.phone           |
 |                                        | (always respond ok, even if  |
 |                                        |  no match — avoid enumeration|
 |                                        | POST /generate_otp --------->|
 |                                        |<-- 200 {message_id}          |
 |                    <-- 200 {reset_id,  |                              |
 |                            expires_at} |                              |
 | enter code        -->                  |                              |
 |                    POST /pwreset/check>|                              |
 |                                        | POST /verify_otp ----------->|
 |                                        |<-- 200 {status:success}      |
 |                    <-- 200 {token}     |                              |
 | new password      -->                  |                              |
 |                    POST /pwreset/finish>                              |
 |                    (token, password)   | UPDATE users.password_hash   |
 |                    <-- 200 ok          | invalidate sessions          |
```

## Backend pseudocode

```
POST /pwreset/start
  body: { phone }

  user = users.find_by_phone(phone)
  reset_id = uuid()       // always issue a reset_id, even if user is null
  if user:
    res = POST /generate_otp { phone_number: phone }
    cache.set("pwreset:" + reset_id,
              { phone, user_id: user.id, attempts: 0 },
              ttl = 600)
  return { reset_id, expires_at: now + 300 }   // generic response, no enumeration

POST /pwreset/check
  body: { reset_id, code }

  ctx = cache.get("pwreset:" + reset_id)
  if not ctx: 410 expired
  if ctx.attempts >= 5: 429 too many attempts
  ctx.attempts += 1; cache.set(...)

  result = POST /verify_otp { phone_number: ctx.phone, otp: code }
  if result.status != "success":
    return 400 { reason: result.reason }

  // Issue a short-lived reset token; do not let /pwreset/check itself set the password
  token = sign({ user_id: ctx.user_id, scope: "pwreset", exp: now + 300 })
  cache.delete("pwreset:" + reset_id)
  return { token }

POST /pwreset/finish
  body: { token, password }

  claims = verify_jwt(token)
  if claims.scope != "pwreset" or expired: 401
  validate new password strength
  UPDATE users SET password_hash = hash(password) WHERE id = claims.user_id
  delete all sessions for user_id   // force re-login everywhere
  return { ok: true }
```

## Security gotchas

- Do not leak whether the phone number exists. Always return a `reset_id` regardless. Otherwise an attacker can scrape your user list by trying numbers.
- Split the flow into three steps. `verify_otp` proves phone possession; the reset token proves the user just verified; the password change consumes the token. Combining steps lets a leaked OTP set a new password directly.
- After password change, invalidate every active session and refresh token for that user. Otherwise a still-logged-in attacker keeps access.
- Rate-limit `/pwreset/start` per phone number and per IP. Even with the silent-failure response, an attacker can DOS your OTP credits otherwise.
- Token scope matters. The reset token should only be accepted by `/pwreset/finish`, never as a general auth token.
- Log "password reset completed for user X" without the OTP, the new password, or the token. Useful for audit; not useful for attackers.
