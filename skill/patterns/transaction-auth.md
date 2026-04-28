# Pattern: transaction step-up auth

Require an OTP before a high-value or sensitive operation: large transfers, account email change, withdrawing crypto, deleting an account, changing 2FA settings, releasing payouts. The user is already logged in — the OTP is an additional fence around the specific action.

## Sequence

```
User              Frontend          Backend                         MyOTP API
 | initiate transfer --->|                                              |
 |                    POST /tx/initiate (amount, dest)                  |
 |                       |  validate, hold funds                        |
 |                       |  if amount > threshold:                      |
 |                       |    POST /generate_otp ---------------------->|
 |                       |<-- 200 {message_id}                          |
 |                    <-- 200 {tx_id, requires_otp:true, expires_at}    |
 | enter OTP        --->|                                                |
 |                    POST /tx/confirm (tx_id, otp)                      |
 |                       | POST /verify_otp -------------------------->|
 |                       |<-- 200 {status:success}                      |
 |                       | settle transfer; record audit row            |
 |                    <-- 200 {confirmed:true, receipt_id}              |
```

## Backend pseudocode

```
POST /tx/initiate
  auth: logged-in session
  body: { amount, destination }

  validate amount, balance, destination
  tx = transactions.insert {
    user_id, amount, destination, state: "pending_otp",
    expires_at: now + 300
  }
  hold_funds(user_id, amount)

  if amount >= STEP_UP_THRESHOLD or destination is new:
    user = users.get(user_id)
    res = POST /generate_otp { phone_number: user.phone }
    tx.message_id = res.message_id
    transactions.update(tx)
    return { tx_id: tx.id, requires_otp: true, expires_at: res.expires_at }

  // small amount, trusted destination — settle immediately
  settle(tx)
  return { tx_id: tx.id, requires_otp: false }

POST /tx/confirm
  auth: logged-in session
  body: { tx_id, otp }

  tx = transactions.get(tx_id)
  if tx.user_id != session.user_id: 403
  if tx.state != "pending_otp": 409 already_processed
  if tx.attempts >= 5:
    cancel(tx)            // release funds, mark failed
    return 429
  tx.attempts += 1; transactions.update(tx)

  // Verify by message_id, not phone — defends against a user changing their phone mid-flow
  result = POST /verify_otp { message_id: tx.message_id, otp: otp }

  if result.status != "success":
    return 400 { reason: result.reason }

  settle(tx)
  audit.write({
    user_id: tx.user_id, action: "step_up_auth.tx_confirmed",
    tx_id: tx.id, ts: now
  })
  return { confirmed: true, receipt_id: tx.receipt_id }
```

## Security gotchas

- Verify by `message_id`, not by phone number. A user with a compromised account who changes their phone right before the OTP step would otherwise succeed against the new phone. `message_id` binds the verification to the specific generated OTP.
- Hold funds (or lock the resource) at `/tx/initiate`. Do not commit the transaction state on the read of `/tx/confirm`; commit only after `/verify_otp` returns success.
- Apply the OTP only above a sensible threshold and on first-seen destinations. Friction every transaction trains users to autofill codes without reading them.
- Cap attempts per `tx_id`. After max attempts, cancel the transaction outright and force the user to start over. Releasing the OTP for retry indefinitely is a brute-force vector.
- Log the audit row server-side, never trust the client to report success.
- `requires_otp:false` is a legitimate response for small or trusted-destination transfers. Make sure your client handles both branches; otherwise the agent will mistakenly send `verify_otp` for transactions that did not require it.
- For especially sensitive ops (account deletion, 2FA disable), force OTP regardless of threshold.
