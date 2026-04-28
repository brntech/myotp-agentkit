// One small script glues all three pages: it dispatches based on which form
// is present in the DOM. This keeps the example flat — no client framework.

(function () {
  const phoneForm = document.getElementById("phone-form");
  const verifyForm = document.getElementById("verify-form");

  if (phoneForm) {
    phoneForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      const errorEl = document.getElementById("error");
      errorEl.hidden = true;

      const data = Object.fromEntries(new FormData(phoneForm).entries());

      try {
        const res = await fetch("/api/send-otp", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify(data),
        });
        const body = await res.json();
        if (!res.ok) throw new Error(body.error || "Failed to send code");

        sessionStorage.setItem("myotp_phone", body.phone);
        sessionStorage.setItem("myotp_channel", body.channel);
        location.href = "/verify.html";
      } catch (err) {
        errorEl.textContent = err.message;
        errorEl.hidden = false;
      }
    });
  }

  if (verifyForm) {
    const phone = sessionStorage.getItem("myotp_phone") || "";
    const channel = sessionStorage.getItem("myotp_channel") || "sms";
    if (!phone) {
      location.href = "/";
      return;
    }
    document.getElementById("hint").textContent =
      `We sent a code via ${channel} to +${phone}. Enter it below.`;

    verifyForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      const errorEl = document.getElementById("error");
      errorEl.hidden = true;

      const otp = new FormData(verifyForm).get("otp");

      try {
        const res = await fetch("/api/verify-otp", {
          method: "POST",
          headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ phone, otp }),
        });
        const body = await res.json();
        if (!res.ok) throw new Error(body.error || "Verification failed");

        sessionStorage.setItem("myotp_verified_phone", body.phone);
        location.href = "/success.html";
      } catch (err) {
        errorEl.textContent = err.message;
        errorEl.hidden = false;
      }
    });
  }

  const messageEl = document.getElementById("message");
  if (messageEl) {
    const phone = sessionStorage.getItem("myotp_verified_phone");
    if (phone) {
      messageEl.textContent = `Phone number +${phone} has been verified.`;
    }
  }
})();
