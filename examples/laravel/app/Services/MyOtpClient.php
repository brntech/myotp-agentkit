<?php

namespace App\Services;

use App\Exceptions\MyOtpException;
use Illuminate\Support\Facades\Http;

/**
 * Server-side client for the MyOTP.App REST API.
 *
 * Bound as a singleton in AppServiceProvider; injected into controllers.
 * The API key is read from config('services.myotp.key'), which is populated
 * from MYOTP_API_KEY at config-load time.
 *
 * API reference: https://api.myotp.app
 */
class MyOtpClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * @throws MyOtpException
     */
    public function generateOtp(string $phoneNumber, string $channel = 'sms'): array
    {
        return $this->post('/generate_otp', [
            'phone_number' => $phoneNumber,
            'channel' => $channel,
        ]);
    }

    /**
     * @throws MyOtpException
     */
    public function verifyOtp(string $otp, ?string $messageId = null, ?string $phoneNumber = null): array
    {
        $payload = ['otp' => $otp];
        if ($messageId !== null) {
            $payload['message_id'] = $messageId;
        }
        if ($phoneNumber !== null) {
            $payload['phone_number'] = $phoneNumber;
        }
        return $this->post('/verify_otp', $payload);
    }

    /**
     * @throws MyOtpException
     */
    private function post(string $path, array $payload): array
    {
        if ($this->apiKey === '') {
            throw new MyOtpException(500, 'MYOTP_API_KEY is not configured');
        }

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-API-Key' => $this->apiKey,
        ])
            ->timeout(15)
            ->post(rtrim($this->baseUrl, '/') . $path, $payload);

        $data = $response->json() ?? [];

        if (! $response->successful()) {
            $message = $data['message'] ?? $data['error'] ?? 'MyOTP error ' . $response->status();
            throw new MyOtpException($response->status(), (string) $message);
        }

        return $data;
    }
}
