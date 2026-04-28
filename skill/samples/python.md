# Python — MyOTP.App

`pip install requests flask`. Set `MYOTP_API_KEY` in your environment.

## Plain script with requests

```python
import os
import requests

BASE = "https://api.myotp.app"
HEADERS = {
    "Content-Type": "application/json",
    "X-API-Key": os.environ["MYOTP_API_KEY"],
}

def generate_otp(phone_number: str, channel: str = "sms") -> dict:
    # channel: "sms" (default), "whatsapp", or "telegram"
    r = requests.post(
        f"{BASE}/generate_otp",
        headers=HEADERS,
        json={"phone_number": phone_number, "channel": channel},
        timeout=10,
    )
    r.raise_for_status()
    return r.json()  # {message_id, status, expires_at, cost, ...}

def verify_otp(phone_number: str, otp: str) -> dict:
    # Returns 200 with status=success or status=failed (reason: invalid/expired/not found)
    r = requests.post(
        f"{BASE}/verify_otp",
        headers=HEADERS,
        json={"phone_number": phone_number, "otp": otp},
        timeout=10,
    )
    r.raise_for_status()
    return r.json()

if __name__ == "__main__":
    sent = generate_otp("14155551234")
    print("message_id:", sent["message_id"])
    # ... wait for user to enter the code ...
    result = verify_otp("14155551234", "123456")
    print("verified" if result["status"] == "success" else f"failed: {result.get('reason')}")
```

## Flask route

```python
import os
from flask import Flask, request, jsonify, session
import requests

app = Flask(__name__)
app.secret_key = os.environ["FLASK_SECRET"]

API = "https://api.myotp.app"
HEADERS = {"Content-Type": "application/json", "X-API-Key": os.environ["MYOTP_API_KEY"]}

@app.post("/auth/send-code")
def send_code():
    body = request.get_json()
    r = requests.post(
        f"{API}/generate_otp",
        headers=HEADERS,
        json={"phone_number": body["phone"], "channel": body.get("channel", "sms")},
        timeout=10,
    )
    data = r.json()
    session["otp_phone"] = body["phone"]
    return jsonify(ok=r.ok, expires_at=data.get("expires_at"))

@app.post("/auth/verify-code")
def verify_code():
    body = request.get_json()
    r = requests.post(
        f"{API}/verify_otp",
        headers=HEADERS,
        json={"phone_number": session["otp_phone"], "otp": body["code"]},
        timeout=10,
    )
    data = r.json()
    return jsonify(verified=data.get("status") == "success", reason=data.get("reason"))
```
