"""Flask phone-verification demo for MyOTP.App.

Two API calls drive everything:
- POST /send   -> calls MyOTP /generate_otp, stores message_id in session
- POST /verify -> calls MyOTP /verify_otp using the stored message_id
"""

import os

from dotenv import load_dotenv
from flask import Flask, redirect, render_template, request, session, url_for

from myotp import generate_otp, verify_otp, MyOtpError
from phone import sanitise_phone

load_dotenv()

app = Flask(__name__)
app.secret_key = os.environ.get("FLASK_SECRET_KEY", "dev-only-do-not-use-in-prod")

VALID_CHANNELS = {"sms", "whatsapp", "telegram"}


@app.get("/")
def index():
    return render_template("index.html")


@app.post("/send")
def send():
    phone = sanitise_phone(request.form.get("phone", ""))
    channel = request.form.get("channel", "sms")
    if channel not in VALID_CHANNELS:
        channel = "sms"

    if not phone:
        return render_template("index.html", error="Phone number is required."), 400

    try:
        result = generate_otp(phone_number=phone, channel=channel)
    except MyOtpError as err:
        return render_template("index.html", error=err.message), err.status if err.status >= 400 else 500

    # message_id is opaque to the browser (a UUID), but Flask's signed session
    # cookie keeps it tamper-proof. For multi-server deployments use
    # flask-session or a server-side store instead.
    session["message_id"] = result["message_id"]
    session["phone"] = phone
    session["channel"] = channel
    return redirect(url_for("verify"))


@app.get("/verify")
def verify():
    if "message_id" not in session:
        return redirect(url_for("index"))
    return render_template("verify.html", phone=session["phone"], channel=session["channel"])


@app.post("/verify")
def verify_post():
    if "message_id" not in session:
        return redirect(url_for("index"))

    otp = (request.form.get("otp") or "").strip()
    if not otp:
        return render_template(
            "verify.html",
            phone=session["phone"],
            channel=session["channel"],
            error="Enter the code you received.",
        ), 400

    try:
        result = verify_otp(otp=otp, message_id=session["message_id"])
    except MyOtpError as err:
        return render_template(
            "verify.html",
            phone=session["phone"],
            channel=session["channel"],
            error=err.message,
        ), err.status if err.status >= 400 else 500

    if result.get("status") != "success":
        return render_template(
            "verify.html",
            phone=session["phone"],
            channel=session["channel"],
            error=result.get("message", "Verification failed."),
        ), 400

    verified_phone = session.pop("phone", "")
    session.pop("message_id", None)
    session.pop("channel", None)
    session["verified_phone"] = verified_phone
    return redirect(url_for("success"))


@app.get("/success")
def success():
    phone = session.pop("verified_phone", "")
    if not phone:
        return redirect(url_for("index"))
    return render_template("success.html", phone=phone)


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=5000, debug=True)
