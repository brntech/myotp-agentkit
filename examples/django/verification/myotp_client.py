"""Server-side client for the MyOTP.App REST API.

The API key is read from Django settings at call time so it stays on the
server. Settings are populated from environment variables in settings.py.

API reference: https://api.myotp.app
"""

from __future__ import annotations

from typing import Optional

import requests
from django.conf import settings


class MyOtpError(Exception):
    def __init__(self, status: int, message: str):
        super().__init__(message)
        self.status = status
        self.message = message


def _post(path: str, payload: dict) -> dict:
    api_key = getattr(settings, "MYOTP_API_KEY", "") or ""
    if not api_key:
        raise MyOtpError(500, "MYOTP_API_KEY is not configured")

    base_url = getattr(settings, "MYOTP_BASE_URL", "https://api.myotp.app").rstrip("/")
    res = requests.post(
        f"{base_url}{path}",
        headers={
            "Content-Type": "application/json",
            "X-API-Key": api_key,
        },
        json=payload,
        timeout=15,
    )
    try:
        data = res.json()
    except ValueError:
        data = {}
    if not res.ok:
        msg = data.get("message") or data.get("error") or f"MyOTP error {res.status_code}"
        raise MyOtpError(res.status_code, msg)
    return data


def generate_otp(phone_number: str, channel: str = "sms") -> dict:
    return _post("/generate_otp", {"phone_number": phone_number, "channel": channel})


def verify_otp(otp: str, message_id: Optional[str] = None, phone_number: Optional[str] = None) -> dict:
    payload: dict = {"otp": otp}
    if message_id:
        payload["message_id"] = message_id
    if phone_number:
        payload["phone_number"] = phone_number
    return _post("/verify_otp", payload)
