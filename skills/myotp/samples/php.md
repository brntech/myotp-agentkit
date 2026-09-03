# PHP — MyOTP.App

PHP 7.4+ with the cURL extension. No external dependencies.
Set `MYOTP_API_KEY` in your environment.

```php
<?php
const MYOTP_BASE = "https://api.myotp.app";

function myotp_request(string $path, array $body): array {
    $ch = curl_init(MYOTP_BASE . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body),
        CURLOPT_HTTPHEADER     => [
            "Content-Type: application/json",
            "X-API-Key: " . getenv("MYOTP_API_KEY"),
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $status   = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($response === false) {
        throw new RuntimeException("MyOTP request failed");
    }
    return ["status" => $status, "body" => json_decode($response, true)];
}

// Send OTP. channel: "sms" (default), "whatsapp", or "telegram".
function generate_otp(string $phone, string $channel = "sms"): array {
    return myotp_request("/generate_otp", [
        "phone_number" => $phone,
        "channel"      => $channel,
    ]);
}

// Verify the code the user typed. Inspect body.status for "success" or "failed".
function verify_otp(string $phone, string $otp): array {
    return myotp_request("/verify_otp", [
        "phone_number" => $phone,
        "otp"          => $otp,
    ]);
}

// Demo
$sent = generate_otp("14155551234");
// Store the message_id from $sent in your session.
print "message_id: " . $sent["body"]["message_id"] . PHP_EOL;

$result = verify_otp("14155551234", "123456");
if (($result["body"]["status"] ?? "") === "success") {
    print "verified" . PHP_EOL;
} else {
    print "failed: " . ($result["body"]["reason"] ?? "unknown") . PHP_EOL;
}
```
