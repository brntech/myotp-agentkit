# Flutter / Dart — MyOTP.App

`flutter pub add http`.

Important: never embed an API key in a mobile app binary — it can be extracted. Run MyOTP calls from your backend and have the Flutter client call your backend. The snippet below shows the HTTP shape; treat it as the server-side reference or as code that runs against your own proxy endpoint (where you swap the URL and remove the API key).

```dart
import 'dart:convert';
import 'dart:io' show Platform;
import 'package:http/http.dart' as http;

class MyOtp {
  static const String _base = 'https://api.myotp.app';

  // BACKEND ONLY. Read from a secure server-side env, never bundled config.
  String get _apiKey => Platform.environment['MYOTP_API_KEY'] ?? '';

  // channel: "sms" (default), "whatsapp", or "telegram"
  Future<Map<String, dynamic>> generateOtp(
    String phoneNumber, {
    String channel = 'sms',
  }) async {
    final res = await http.post(
      Uri.parse('$_base/generate_otp'),
      headers: {
        'Content-Type': 'application/json',
        'X-API-Key': _apiKey,
      },
      body: jsonEncode({
        'phone_number': phoneNumber,
        'channel': channel,
      }),
    );
    if (res.statusCode >= 400) {
      throw Exception('generate_otp ${res.statusCode}: ${res.body}');
    }
    return jsonDecode(res.body) as Map<String, dynamic>;
  }

  // verify_otp returns 200 even on logical failure — check `status` field.
  Future<Map<String, dynamic>> verifyOtp(
    String phoneNumber,
    String otp,
  ) async {
    final res = await http.post(
      Uri.parse('$_base/verify_otp'),
      headers: {
        'Content-Type': 'application/json',
        'X-API-Key': _apiKey,
      },
      body: jsonEncode({
        'phone_number': phoneNumber,
        'otp': otp,
      }),
    );
    return jsonDecode(res.body) as Map<String, dynamic>;
  }
}

// Demo (server-side or via your own proxy)
void main() async {
  final myotp = MyOtp();
  final sent = await myotp.generateOtp('14155551234');
  print('message_id: ${sent['message_id']}');

  final result = await myotp.verifyOtp('14155551234', '123456');
  print(result['status'] == 'success' ? 'verified' : 'failed: ${result['reason']}');
}
```
