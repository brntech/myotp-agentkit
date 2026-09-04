import type { IDataObject, IHttpRequestMethods } from 'n8n-workflow';

export const BASE_URL = 'https://api.myotp.app';

export type Operation =
	| 'sendOtp'
	| 'verifyOtp'
	| 'extendOtp'
	| 'checkOtpStatus'
	| 'account'
	| 'report';

export interface BuiltRequest {
	method: IHttpRequestMethods;
	url: string;
	body?: IDataObject;
}

/** Values the node collects for one item, keyed by API field name. */
export interface OperationParams {
	phone_number?: string;
	channel?: string;
	otp?: string;
	message_id?: string;
	duration?: number;
	additionalFields?: IDataObject;
}

function isSet(value: unknown): boolean {
	return value !== undefined && value !== null && value !== '';
}

/** Copy only the fields the user filled in, so the API applies its own defaults. */
function pickSet(source: IDataObject | undefined, keys: string[]): IDataObject {
	const out: IDataObject = {};
	if (!source) return out;
	for (const key of keys) {
		if (isSet(source[key])) out[key] = source[key];
	}
	return out;
}

/** The API expects force_send and return_otp as the strings "true" / "false". */
function boolString(value: unknown): 'true' | 'false' {
	return value === true || value === 'true' ? 'true' : 'false';
}

/**
 * Build the HTTP request for one operation. Field names, required flags and
 * enum values follow openapi-reference.yaml (MyOTPApp API 1.4.0).
 */
export function buildRequest(operation: Operation, params: OperationParams): BuiltRequest {
	const extra = params.additionalFields;

	switch (operation) {
		case 'sendOtp': {
			if (!isSet(params.phone_number)) {
				throw new Error('Phone Number is required');
			}
			const body: IDataObject = {
				phone_number: String(params.phone_number),
				...pickSet(extra, ['otp_length', 'otp_validity', 'template_order', 'brand']),
			};
			if (isSet(params.channel)) body.channel = params.channel;
			if (extra && isSet(extra.force_send)) body.force_send = boolString(extra.force_send);
			if (extra && isSet(extra.return_otp)) body.return_otp = boolString(extra.return_otp);
			return { method: 'POST', url: `${BASE_URL}/generate_otp`, body };
		}

		case 'verifyOtp': {
			if (!isSet(params.otp)) {
				throw new Error('OTP is required');
			}
			const body: IDataObject = {
				otp: String(params.otp),
				...pickSet(extra, ['phone_number', 'message_id']),
			};
			return { method: 'POST', url: `${BASE_URL}/verify_otp`, body };
		}

		case 'extendOtp': {
			if (!isSet(params.message_id)) {
				throw new Error('Message ID is required');
			}
			if (!isSet(params.duration)) {
				throw new Error('Duration is required');
			}
			return {
				method: 'POST',
				url: `${BASE_URL}/extend_otp`,
				body: { message_id: String(params.message_id), duration: Number(params.duration) },
			};
		}

		case 'checkOtpStatus': {
			if (!isSet(params.message_id)) {
				throw new Error('Message ID is required');
			}
			return {
				method: 'POST',
				url: `${BASE_URL}/check_otp_status`,
				body: { message_id: String(params.message_id) },
			};
		}

		case 'account':
			return { method: 'GET', url: `${BASE_URL}/me` };

		case 'report':
			return {
				method: 'POST',
				url: `${BASE_URL}/report`,
				body: pickSet(extra, ['start_date', 'end_date', 'page', 'per_page']),
			};

		default:
			throw new Error(`Unknown operation "${String(operation)}"`);
	}
}

export interface ApiErrorInfo {
	httpCode?: string;
	message: string;
}

function asRecord(value: unknown): Record<string, unknown> | undefined {
	if (value && typeof value === 'object') return value as Record<string, unknown>;
	if (typeof value === 'string') {
		try {
			const parsed: unknown = JSON.parse(value);
			if (parsed && typeof parsed === 'object') return parsed as Record<string, unknown>;
		} catch {
			return undefined;
		}
	}
	return undefined;
}

/** Read the {"error":{"http_code","message"}} envelope out of a body, if present. */
function readEnvelope(body: unknown): ApiErrorInfo | undefined {
	const record = asRecord(body);
	if (!record) return undefined;
	const envelope = asRecord(record.error) ?? asRecord(record.detail);
	if (!envelope || typeof envelope.message !== 'string') return undefined;
	const code = envelope.http_code;
	return {
		message: envelope.message,
		httpCode: code !== undefined && code !== null ? String(code) : undefined,
	};
}

/**
 * Turn whatever the n8n request helper threw into a readable message.
 * The MyOTP API answers every rejected request with
 * {"error":{"http_code":N,"message":"..."}}; find that body wherever the
 * helper put it and fall back to the status code or the raw error text.
 */
export function describeApiError(error: unknown): ApiErrorInfo {
	const err = asRecord(error) ?? {};
	const response = asRecord(err.response);
	const cause = asRecord(err.cause);
	const causeResponse = cause ? asRecord(cause.response) : undefined;

	const candidates: unknown[] = [
		response?.body,
		response?.data,
		causeResponse?.data,
		causeResponse?.body,
		err.body,
		err.error,
		err.description,
		err.message,
	];
	for (const candidate of candidates) {
		const info = readEnvelope(candidate);
		if (info) return info;
	}

	const statusRaw =
		err.httpCode ??
		err.statusCode ??
		response?.status ??
		response?.statusCode ??
		causeResponse?.status;
	const httpCode = statusRaw !== undefined && statusRaw !== null ? String(statusRaw) : undefined;

	let text = 'Request to MyOTP failed';
	if (typeof err.message === 'string' && err.message.length > 0) {
		text = err.message;
	} else if (typeof error === 'string') {
		text = error;
	}

	return { httpCode, message: httpCode ? `MyOTP API returned ${httpCode}: ${text}` : text };
}
