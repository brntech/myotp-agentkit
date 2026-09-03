# Language samples

One file per language, each ~30-60 lines, copy-pasteable. All examples read the API key from an environment variable named `MYOTP_API_KEY`.

| File | Stack | Notes |
|------|-------|-------|
| [curl.md](curl.md) | bash + curl | Quick smoke tests and shell scripts. |
| [nodejs.md](nodejs.md) | Node.js 18+ | Native `fetch`, plus an Express POST handler. |
| [python.md](python.md) | Python 3 + `requests` | Plain script and a Flask route. |
| [php.md](php.md) | PHP 7.4+ | cURL extension, no external deps. |
| [csharp.md](csharp.md) | .NET 6+ | `HttpClient` with `System.Text.Json`. |
| [java.md](java.md) | Java 11+ | Built-in `java.net.http.HttpClient`. |
| [go.md](go.md) | Go 1.18+ | `net/http` standard library. |
| [ruby.md](ruby.md) | Ruby 3.x | `net/http` standard library. |
| [flutter.md](flutter.md) | Flutter / Dart | `http` package — server-only usage; never embed the API key in the app. |

Every sample shows both `generate_otp` and `verify_otp`. Channel selection (`sms` / `whatsapp` / `telegram`) is the same JSON field across every language.
