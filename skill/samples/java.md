# Java — MyOTP.App

Java 11+. Uses the built-in `java.net.http.HttpClient`. No external libraries required for HTTP; the snippet uses minimal manual JSON to stay dependency-free. For production, swap in Jackson or Gson.
Set `MYOTP_API_KEY` in your environment.

```java
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;
import java.time.Duration;

public class MyOtp {
    private static final String BASE = "https://api.myotp.app";
    private static final String KEY  = System.getenv("MYOTP_API_KEY");
    private static final HttpClient HTTP = HttpClient.newBuilder()
            .connectTimeout(Duration.ofSeconds(10))
            .build();

    // channel: "sms" (default), "whatsapp", or "telegram"
    public static String generateOtp(String phoneNumber, String channel) throws Exception {
        String body = String.format(
            "{\"phone_number\":\"%s\",\"channel\":\"%s\"}", phoneNumber, channel);
        HttpRequest req = HttpRequest.newBuilder()
                .uri(URI.create(BASE + "/generate_otp"))
                .header("Content-Type", "application/json")
                .header("X-API-Key", KEY)
                .POST(HttpRequest.BodyPublishers.ofString(body))
                .build();
        HttpResponse<String> res = HTTP.send(req, HttpResponse.BodyHandlers.ofString());
        if (res.statusCode() >= 400) {
            throw new RuntimeException("generate_otp " + res.statusCode() + ": " + res.body());
        }
        return res.body(); // includes message_id, status, expires_at, cost
    }

    // verify_otp returns 200 even on logical failure — parse status from body.
    public static String verifyOtp(String phoneNumber, String otp) throws Exception {
        String body = String.format(
            "{\"phone_number\":\"%s\",\"otp\":\"%s\"}", phoneNumber, otp);
        HttpRequest req = HttpRequest.newBuilder()
                .uri(URI.create(BASE + "/verify_otp"))
                .header("Content-Type", "application/json")
                .header("X-API-Key", KEY)
                .POST(HttpRequest.BodyPublishers.ofString(body))
                .build();
        return HTTP.send(req, HttpResponse.BodyHandlers.ofString()).body();
    }

    public static void main(String[] args) throws Exception {
        String sent = generateOtp("14155551234", "sms");
        System.out.println("Sent: " + sent);
        // ... user enters code ...
        String verified = verifyOtp("14155551234", "123456");
        System.out.println("Verify: " + verified);
    }
}
```
