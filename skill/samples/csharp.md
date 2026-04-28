# C# / .NET — MyOTP.App

.NET 6+ with `System.Net.Http` and `System.Text.Json`. No NuGet packages needed.
Set `MYOTP_API_KEY` in your environment.

```csharp
using System;
using System.Net.Http;
using System.Net.Http.Json;
using System.Text.Json.Serialization;
using System.Threading.Tasks;

public record GenerateRequest(
    [property: JsonPropertyName("phone_number")] string PhoneNumber,
    [property: JsonPropertyName("channel")]      string Channel
);

public record VerifyRequest(
    [property: JsonPropertyName("phone_number")] string PhoneNumber,
    [property: JsonPropertyName("otp")]          string Otp
);

public record GenerateResponse(
    [property: JsonPropertyName("message_id")] string MessageId,
    [property: JsonPropertyName("status")]     string Status,
    [property: JsonPropertyName("expires_at")] string ExpiresAt,
    [property: JsonPropertyName("cost")]       decimal Cost
);

public record VerifyResponse(
    [property: JsonPropertyName("status")]  string Status,
    [property: JsonPropertyName("reason")]  string? Reason,
    [property: JsonPropertyName("message")] string Message
);

public class MyOtpClient
{
    private readonly HttpClient _http;
    public MyOtpClient(HttpClient http)
    {
        _http = http;
        _http.BaseAddress = new Uri("https://api.myotp.app");
        _http.DefaultRequestHeaders.Add("X-API-Key",
            Environment.GetEnvironmentVariable("MYOTP_API_KEY"));
    }

    // channel: "sms" (default), "whatsapp", or "telegram"
    public async Task<GenerateResponse> GenerateAsync(string phoneNumber, string channel = "sms")
    {
        var res = await _http.PostAsJsonAsync("/generate_otp",
            new GenerateRequest(phoneNumber, channel));
        res.EnsureSuccessStatusCode();
        return (await res.Content.ReadFromJsonAsync<GenerateResponse>())!;
    }

    // verify_otp returns 200 even when status is "failed" — check Status, not HTTP code.
    public async Task<VerifyResponse> VerifyAsync(string phoneNumber, string otp)
    {
        var res = await _http.PostAsJsonAsync("/verify_otp",
            new VerifyRequest(phoneNumber, otp));
        res.EnsureSuccessStatusCode();
        return (await res.Content.ReadFromJsonAsync<VerifyResponse>())!;
    }
}

// Demo
var client = new MyOtpClient(new HttpClient());
var sent = await client.GenerateAsync("14155551234");
Console.WriteLine($"message_id: {sent.MessageId}");
var result = await client.VerifyAsync("14155551234", "123456");
Console.WriteLine(result.Status == "success" ? "verified" : $"failed: {result.Reason}");
```
