<?php

namespace App\Http\Controllers;

use App\Exceptions\MyOtpException;
use App\Http\Requests\SendOtpRequest;
use App\Http\Requests\VerifyOtpRequest;
use App\Services\MyOtpClient;
use App\Support\PhoneSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class VerificationController extends Controller
{
    public function __construct(private readonly MyOtpClient $myotp)
    {
    }

    public function phoneForm(): View
    {
        return view('verification.index');
    }

    public function sendOtp(SendOtpRequest $request): RedirectResponse|Response|View
    {
        $phone = PhoneSanitizer::sanitize($request->input('phone'));
        $channel = $request->input('channel', 'sms');

        if ($phone === '') {
            return response()->view('verification.index', [
                'error' => 'Phone number is required.',
            ], 400);
        }

        try {
            $result = $this->myotp->generateOtp(phoneNumber: $phone, channel: $channel);
        } catch (MyOtpException $e) {
            $status = $e->status >= 400 ? $e->status : 500;
            return response()->view('verification.index', [
                'error' => $e->getMessage(),
            ], $status);
        }

        // message_id is opaque to the browser (a UUID). Laravel encrypts the
        // session cookie when SESSION_ENCRYPT=true (default in this demo) so
        // the value cannot be read or tampered with client-side.
        $request->session()->put('message_id', $result['message_id']);
        $request->session()->put('phone', $phone);
        $request->session()->put('channel', $channel);

        return redirect()->route('verify.form');
    }

    public function verifyForm(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('message_id')) {
            return redirect()->route('phone.form');
        }

        return view('verification.verify', [
            'phone' => $request->session()->get('phone'),
            'channel' => $request->session()->get('channel'),
        ]);
    }

    public function verifyOtp(VerifyOtpRequest $request): RedirectResponse|Response|View
    {
        if (! $request->session()->has('message_id')) {
            return redirect()->route('phone.form');
        }

        $phone = $request->session()->get('phone');
        $channel = $request->session()->get('channel');
        $messageId = $request->session()->get('message_id');
        $otp = trim((string) $request->input('otp'));

        try {
            $result = $this->myotp->verifyOtp(otp: $otp, messageId: $messageId);
        } catch (MyOtpException $e) {
            $status = $e->status >= 400 ? $e->status : 500;
            return response()->view('verification.verify', [
                'phone' => $phone,
                'channel' => $channel,
                'error' => $e->getMessage(),
            ], $status);
        }

        if (($result['status'] ?? null) !== 'success') {
            return response()->view('verification.verify', [
                'phone' => $phone,
                'channel' => $channel,
                'error' => $result['message'] ?? 'Verification failed.',
            ], 400);
        }

        $request->session()->forget(['message_id', 'channel']);
        $request->session()->put('verified_phone', $phone);
        $request->session()->forget('phone');

        return redirect()->route('verify.success');
    }

    public function success(Request $request): View|RedirectResponse
    {
        $phone = $request->session()->pull('verified_phone');
        if (! $phone) {
            return redirect()->route('phone.form');
        }

        return view('verification.success', ['phone' => $phone]);
    }
}
