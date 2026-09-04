import { describe, expect, it, vi } from 'vitest';

import { MyOtp } from '../nodes/MyOtp/MyOtp.node';
import { MyOtpApi } from '../credentials/MyOtpApi.credentials';

type Params = Record<string, unknown>;

/** A minimal stand-in for IExecuteFunctions covering what execute() touches. */
function makeContext(params: Params, opts: { items?: number; continueOnFail?: boolean } = {}) {
	const request = vi.fn();
	const ctx = {
		getInputData: () => Array.from({ length: opts.items ?? 1 }, () => ({ json: {} })),
		getNodeParameter: (name: string, _i: number, fallback?: unknown) =>
			name in params ? params[name] : fallback,
		continueOnFail: () => opts.continueOnFail ?? false,
		getNode: () => ({ name: 'MyOTP', type: 'n8n-nodes-myotp.myOtp', typeVersion: 1 }),
		helpers: { httpRequestWithAuthentication: request },
	};
	return { ctx, request };
}

async function run(ctx: unknown) {
	const node = new MyOtp();
	return node.execute.call(ctx as never);
}

describe('MyOtp node', () => {
	it('declares one credential and lists every operation', () => {
		const node = new MyOtp();
		expect(node.description.name).toBe('myOtp');
		expect(node.description.credentials).toEqual([{ name: 'myOtpApi', required: true }]);
		const opParam = node.description.properties.find((p) => p.name === 'operation');
		const values = (opParam?.options ?? []).map((o) => (o as { value: string }).value).sort();
		expect(values).toEqual(
			['account', 'checkOtpStatus', 'extendOtp', 'report', 'sendOtp', 'verifyOtp'].sort(),
		);
	});

	it('sendOtp calls the API through the myOtpApi credential', async () => {
		const { ctx, request } = makeContext({
			operation: 'sendOtp',
			phoneNumber: '19876543210',
			channel: 'sms',
			additionalFields: { force_send: true, otp_length: 6 },
		});
		request.mockResolvedValue({ message_id: 'm1', status: 'accepted' });

		const out = await run(ctx);

		expect(request).toHaveBeenCalledTimes(1);
		const [credName, options] = request.mock.calls[0] as [string, Record<string, unknown>];
		expect(credName).toBe('myOtpApi');
		expect(options.method).toBe('POST');
		expect(options.url).toBe('https://api.myotp.app/generate_otp');
		expect(options.json).toBe(true);
		expect(options.body).toEqual({
			phone_number: '19876543210',
			channel: 'sms',
			otp_length: 6,
			force_send: 'true',
		});
		expect(out).toEqual([[{ json: { message_id: 'm1', status: 'accepted' }, pairedItem: { item: 0 } }]]);
	});

	it('account sends GET /me without a body', async () => {
		const { ctx, request } = makeContext({ operation: 'account' });
		request.mockResolvedValue({ email: 'dev@example.com' });

		await run(ctx);

		const [, options] = request.mock.calls[0] as [string, Record<string, unknown>];
		expect(options.method).toBe('GET');
		expect(options.url).toBe('https://api.myotp.app/me');
		expect(options).not.toHaveProperty('body');
	});

	it('runs once per input item', async () => {
		const { ctx, request } = makeContext({ operation: 'checkOtpStatus', messageId: 'm1' }, { items: 3 });
		request.mockResolvedValue({ DLR: 'DELIVRD', is_active: true });

		const out = await run(ctx);

		expect(request).toHaveBeenCalledTimes(3);
		expect(out[0]).toHaveLength(3);
		expect(out[0]?.[2]?.pairedItem).toEqual({ item: 2 });
	});

	it('surfaces the API error envelope as a readable NodeApiError', async () => {
		const { ctx, request } = makeContext({ operation: 'verifyOtp', otp: '000000' });
		request.mockRejectedValue({
			message: 'Request failed with status code 400',
			response: { body: { error: { http_code: 400, message: 'Invalid OTP' } } },
		});

		await expect(run(ctx)).rejects.toMatchObject({
			name: 'NodeApiError',
			message: 'Invalid OTP',
			httpCode: '400',
		});
	});

	it('with continueOnFail the error becomes an output item', async () => {
		const { ctx, request } = makeContext(
			{ operation: 'extendOtp', messageId: 'm1', duration: 600 },
			{ continueOnFail: true },
		);
		request.mockRejectedValue({
			response: { body: { error: { http_code: 404, message: 'message_id not found' } } },
		});

		const out = await run(ctx);

		expect(out).toEqual([
			[{ json: { error: 'message_id not found', http_code: '404' }, pairedItem: { item: 0 } }],
		]);
	});

	it('a missing required field is reported before any request is made', async () => {
		const { ctx, request } = makeContext({ operation: 'sendOtp', phoneNumber: '' });

		await expect(run(ctx)).rejects.toMatchObject({ message: 'Phone Number is required' });
		expect(request).not.toHaveBeenCalled();
	});
});

describe('MyOtpApi credential', () => {
	it('sends the key as X-API-Key and tests against GET /me', () => {
		const cred = new MyOtpApi();
		expect(cred.name).toBe('myOtpApi');
		expect(cred.properties.map((p) => p.name)).toEqual(['apiKey']);
		expect(cred.properties[0]?.typeOptions).toEqual({ password: true });
		expect(cred.authenticate.properties.headers).toEqual({ 'X-API-Key': '={{$credentials.apiKey}}' });
		expect(cred.test.request).toEqual({
			baseURL: 'https://api.myotp.app',
			url: '/me',
			method: 'GET',
		});
	});
});
