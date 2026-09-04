import { describe, expect, it } from 'vitest';

import { buildRequest, describeApiError, ValidationError } from '../nodes/MyOtp/transport';

// Pinned literally on purpose: a wrong host in transport.ts must fail here.
const BASE_URL = 'https://api.myotp.app';

describe('buildRequest', () => {
	it('sendOtp: phone number and channel only, optional fields omitted', () => {
		const req = buildRequest('sendOtp', {
			phone_number: '19876543210',
			channel: 'sms',
			additionalFields: {},
		});
		expect(req).toEqual({
			method: 'POST',
			url: `${BASE_URL}/generate_otp`,
			body: { phone_number: '19876543210', channel: 'sms' },
		});
	});

	it('sendOtp: passes every optional field, force_send as a string, return_otp as a boolean', () => {
		const req = buildRequest('sendOtp', {
			phone_number: '19876543210',
			channel: 'whatsapp',
			additionalFields: {
				otp_length: 4,
				otp_validity: 600,
				template_order: 2,
				brand: 'Acme',
				force_send: true,
				return_otp: false,
			},
		});
		expect(req.body).toEqual({
			phone_number: '19876543210',
			channel: 'whatsapp',
			otp_length: 4,
			otp_validity: 600,
			template_order: 2,
			brand: 'Acme',
			force_send: 'true',
			return_otp: false,
		});
	});

	it('sendOtp: return_otp true stays a boolean', () => {
		const req = buildRequest('sendOtp', {
			phone_number: '19876543210',
			additionalFields: { return_otp: true },
		});
		expect(req.body?.return_otp).toBe(true);
	});

	it('sendOtp: drops empty strings so the API applies its defaults', () => {
		const req = buildRequest('sendOtp', {
			phone_number: '19876543210',
			channel: 'telegram',
			additionalFields: { brand: '', otp_length: 8 },
		});
		expect(req.body).toEqual({ phone_number: '19876543210', channel: 'telegram', otp_length: 8 });
	});

	it('sendOtp: rejects a missing phone number with a ValidationError', () => {
		expect(() => buildRequest('sendOtp', { phone_number: '' })).toThrow(ValidationError);
		expect(() => buildRequest('sendOtp', { phone_number: '' })).toThrow('Phone Number is required');
	});

	it('verifyOtp: otp plus optional phone_number and message_id', () => {
		expect(buildRequest('verifyOtp', { otp: '123456', additionalFields: {} })).toEqual({
			method: 'POST',
			url: `${BASE_URL}/verify_otp`,
			body: { otp: '123456' },
		});
		expect(
			buildRequest('verifyOtp', {
				otp: '123456',
				additionalFields: { phone_number: '19876543210', message_id: 'abc' },
			}).body,
		).toEqual({ otp: '123456', phone_number: '19876543210', message_id: 'abc' });
	});

	it('verifyOtp: rejects a missing otp', () => {
		expect(() => buildRequest('verifyOtp', { otp: '' })).toThrow('OTP is required');
	});

	it('extendOtp: message_id and duration are both required', () => {
		expect(buildRequest('extendOtp', { message_id: 'abc', duration: 600 })).toEqual({
			method: 'POST',
			url: `${BASE_URL}/extend_otp`,
			body: { message_id: 'abc', duration: 600 },
		});
		expect(() => buildRequest('extendOtp', { message_id: '', duration: 600 })).toThrow(
			'Message ID is required',
		);
		expect(() => buildRequest('extendOtp', { message_id: 'abc' })).toThrow('Duration is required');
	});

	it('checkOtpStatus: posts the message_id', () => {
		expect(buildRequest('checkOtpStatus', { message_id: 'abc' })).toEqual({
			method: 'POST',
			url: `${BASE_URL}/check_otp_status`,
			body: { message_id: 'abc' },
		});
		expect(() => buildRequest('checkOtpStatus', {})).toThrow('Message ID is required');
	});

	it('account: GET /me with no body', () => {
		expect(buildRequest('account', {})).toEqual({ method: 'GET', url: `${BASE_URL}/me` });
	});

	it('report: POST /report with only the fields that were set', () => {
		expect(buildRequest('report', { additionalFields: {} })).toEqual({
			method: 'POST',
			url: `${BASE_URL}/report`,
			body: {},
		});
		expect(
			buildRequest('report', {
				additionalFields: { start_date: '2026-08-01', end_date: '', page: 2, per_page: 50 },
			}).body,
		).toEqual({ start_date: '2026-08-01', page: 2, per_page: 50 });
	});
});

describe('describeApiError', () => {
	const envelope = { error: { http_code: 402, message: 'Insufficient balance' } };

	it('reads the envelope from response.body', () => {
		expect(describeApiError({ response: { body: envelope } })).toEqual({
			httpCode: '402',
			message: 'Insufficient balance',
		});
	});

	it('reads the envelope from cause.response.data (axios shape)', () => {
		expect(describeApiError({ message: 'Request failed', cause: { response: { data: envelope } } }))
			.toEqual({ httpCode: '402', message: 'Insufficient balance' });
	});

	it('reads a JSON string body', () => {
		expect(describeApiError({ response: { body: JSON.stringify(envelope) } })).toEqual({
			httpCode: '402',
			message: 'Insufficient balance',
		});
	});

	it('reads the "detail" envelope used by /v1/agent routes', () => {
		expect(
			describeApiError({ response: { body: { detail: { http_code: 409, message: 'exists' } } } }),
		).toEqual({ httpCode: '409', message: 'exists' });
	});

	it('falls back to the status code and error message', () => {
		expect(describeApiError({ message: 'socket hang up', response: { statusCode: 502 } })).toEqual({
			httpCode: '502',
			message: 'MyOTP API returned 502: socket hang up',
		});
	});

	it('falls back to the plain message with no status', () => {
		expect(describeApiError(new Error('ECONNRESET'))).toEqual({
			httpCode: undefined,
			message: 'ECONNRESET',
		});
		expect(describeApiError(undefined)).toEqual({
			httpCode: undefined,
			message: 'Request to MyOTP failed',
		});
	});
});
