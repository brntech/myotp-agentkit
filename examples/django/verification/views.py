"""Views for the MyOTP.App phone-verification demo.

Two API calls drive everything:
- POST /send/          -> calls MyOTP /generate_otp, stores message_id in session
- POST /verify/submit/ -> calls MyOTP /verify_otp using the stored message_id

Function-based views were chosen over CBVs because they keep the request flow
visible end-to-end -- easy to read alongside the Express/Flask examples.
"""

from django.http import HttpRequest, HttpResponse
from django.shortcuts import redirect, render
from django.urls import reverse
from django.views.decorators.http import require_GET, require_POST

from . import myotp_client
from .myotp_client import MyOtpError
from .phone import sanitise_phone

VALID_CHANNELS = {"sms", "whatsapp", "telegram"}


@require_GET
def phone_form(request: HttpRequest) -> HttpResponse:
    return render(request, "verification/index.html")


@require_POST
def send_otp(request: HttpRequest) -> HttpResponse:
    phone = sanitise_phone(request.POST.get("phone", ""))
    channel = request.POST.get("channel", "sms")
    if channel not in VALID_CHANNELS:
        channel = "sms"

    if not phone:
        return render(
            request,
            "verification/index.html",
            {"error": "Phone number is required."},
            status=400,
        )

    try:
        result = myotp_client.generate_otp(phone_number=phone, channel=channel)
    except MyOtpError as err:
        status = err.status if err.status >= 400 else 500
        return render(
            request,
            "verification/index.html",
            {"error": err.message},
            status=status,
        )

    # message_id is opaque to the browser (a UUID), but Django's signed
    # session cookie keeps it tamper-proof. For multi-server deployments use
    # a server-side session backend (DB, Redis, cache) instead of the default
    # DB-backed sessions.
    request.session["message_id"] = result["message_id"]
    request.session["phone"] = phone
    request.session["channel"] = channel
    return redirect(reverse("verify_form"))


@require_GET
def verify_form(request: HttpRequest) -> HttpResponse:
    if "message_id" not in request.session:
        return redirect(reverse("phone_form"))
    return render(
        request,
        "verification/verify.html",
        {
            "phone": request.session["phone"],
            "channel": request.session["channel"],
        },
    )


@require_POST
def verify_otp(request: HttpRequest) -> HttpResponse:
    if "message_id" not in request.session:
        return redirect(reverse("phone_form"))

    otp = (request.POST.get("otp") or "").strip()
    phone = request.session["phone"]
    channel = request.session["channel"]
    message_id = request.session["message_id"]

    if not otp:
        return render(
            request,
            "verification/verify.html",
            {
                "phone": phone,
                "channel": channel,
                "error": "Enter the code you received.",
            },
            status=400,
        )

    try:
        result = myotp_client.verify_otp(otp=otp, message_id=message_id)
    except MyOtpError as err:
        status = err.status if err.status >= 400 else 500
        return render(
            request,
            "verification/verify.html",
            {"phone": phone, "channel": channel, "error": err.message},
            status=status,
        )

    if result.get("status") != "success":
        return render(
            request,
            "verification/verify.html",
            {
                "phone": phone,
                "channel": channel,
                "error": result.get("message", "Verification failed."),
            },
            status=400,
        )

    # Hand off the verified phone in the session, then clear the OTP state.
    del request.session["message_id"]
    del request.session["channel"]
    del request.session["phone"]
    request.session["verified_phone"] = phone
    return redirect(reverse("success"))


@require_GET
def success(request: HttpRequest) -> HttpResponse:
    phone = request.session.pop("verified_phone", "")
    if not phone:
        return redirect(reverse("phone_form"))
    return render(request, "verification/success.html", {"phone": phone})
