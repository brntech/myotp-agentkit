/**
 * Standalone MyOTP API client.
 *
 * This is intentionally not shared with @myotp/mcp-server. Each package can
 * evolve independently and ship its own dependency surface.
 *
 * API spec: https://api.myotp.app (see MyOTP.Website/new-pages/llms-full.txt)
 */

export type Channel = 'sms' | 'whatsapp' | 'telegram';

export interface GenerateOtpRequest {
  phone_number: string;
  channel?: Channel;
  otp_length?: number;
  otp_code?: string;
  otp_validity?: number;
  force_send?: 'true' | 'false';
  return_otp?: 'true' | 'false';
  brand?: string;
  template_order?: number;
}

export interface GenerateOtpResponse {
  message_id: string;
  status: string;
  message: string;
  date_sent: string;
  expires_at: string;
  cost: number;
  otp?: string;
}

export interface VerifyOtpRequest {
  otp: string;
  phone_number?: string;
  message_id?: string;
}

export interface VerifyOtpResponse {
  status: 'success' | 'failed';
  message: string;
  reason?: string;
}

export interface MeResponse {
  email: string;
}

export interface RegisterRequest {
  email: string;
  phone: string;
  company_name: string;
  source: string;
}

export interface RegisterResponse {
  account_id: string;
  status: string;
  api_key?: string;
  next?: string;
}

export interface ApiError extends Error {
  status: number;
  body: unknown;
  endpoint: string;
}

export class MyOtpApiError extends Error implements ApiError {
  status: number;
  body: unknown;
  endpoint: string;

  constructor(message: string, opts: { status: number; body: unknown; endpoint: string }) {
    super(message);
    this.name = 'MyOtpApiError';
    this.status = opts.status;
    this.body = opts.body;
    this.endpoint = opts.endpoint;
  }
}

export class MyOtpNetworkError extends Error {
  cause?: unknown;
  endpoint: string;

  constructor(message: string, endpoint: string, cause?: unknown) {
    super(message);
    this.name = 'MyOtpNetworkError';
    this.endpoint = endpoint;
    this.cause = cause;
  }
}

const USER_AGENT = '@myotp/cli';

export interface ClientOptions {
  baseUrl: string;
  apiKey?: string;
  /** Optional fetch implementation. Defaults to globalThis.fetch (Node 20+). */
  fetchImpl?: typeof fetch;
  /** Request timeout in ms. Default 30s. */
  timeoutMs?: number;
  /** Optional version label appended to the User-Agent. */
  userAgentSuffix?: string;
}

export class MyOtpClient {
  private readonly baseUrl: string;
  private readonly apiKey?: string;
  private readonly fetchImpl: typeof fetch;
  private readonly timeoutMs: number;
  private readonly userAgent: string;

  constructor(opts: ClientOptions) {
    this.baseUrl = opts.baseUrl.replace(/\/+$/, '');
    this.apiKey = opts.apiKey;
    this.fetchImpl = opts.fetchImpl ?? globalThis.fetch.bind(globalThis);
    this.timeoutMs = opts.timeoutMs ?? 30_000;
    this.userAgent = opts.userAgentSuffix ? `${USER_AGENT}/${opts.userAgentSuffix}` : USER_AGENT;
  }

  private async request<T>(
    method: 'GET' | 'POST',
    path: string,
    body?: unknown,
    extraHeaders?: Record<string, string>
  ): Promise<T> {
    const url = `${this.baseUrl}${path}`;
    const headers: Record<string, string> = {
      Accept: 'application/json',
      'User-Agent': this.userAgent,
      ...extraHeaders,
    };
    if (body !== undefined) {
      headers['Content-Type'] = 'application/json';
    }
    if (this.apiKey) {
      headers['X-API-Key'] = this.apiKey;
    }

    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), this.timeoutMs);

    let res: Response;
    try {
      res = await this.fetchImpl(url, {
        method,
        headers,
        body: body !== undefined ? JSON.stringify(body) : undefined,
        signal: controller.signal,
      });
    } catch (err) {
      throw new MyOtpNetworkError(
        `Network error calling ${method} ${path}: ${(err as Error).message ?? 'unknown error'}`,
        path,
        err
      );
    } finally {
      clearTimeout(timeout);
    }

    const text = await res.text();
    let parsed: unknown = null;
    if (text.length > 0) {
      try {
        parsed = JSON.parse(text);
      } catch {
        parsed = text;
      }
    }

    if (!res.ok) {
      const messageFromBody =
        parsed && typeof parsed === 'object' && 'message' in (parsed as Record<string, unknown>)
          ? String((parsed as Record<string, unknown>)['message'])
          : typeof parsed === 'string'
            ? parsed
            : `HTTP ${res.status}`;
      throw new MyOtpApiError(messageFromBody, {
        status: res.status,
        body: parsed,
        endpoint: path,
      });
    }

    return parsed as T;
  }

  generateOtp(req: GenerateOtpRequest): Promise<GenerateOtpResponse> {
    return this.request<GenerateOtpResponse>('POST', '/generate_otp', req);
  }

  verifyOtp(req: VerifyOtpRequest): Promise<VerifyOtpResponse> {
    return this.request<VerifyOtpResponse>('POST', '/verify_otp', req);
  }

  me(): Promise<MeResponse> {
    return this.request<MeResponse>('GET', '/me');
  }

  /**
   * Onboarding API - not yet shipped at the time of writing. Callers should
   * handle 404 gracefully.
   */
  register(req: RegisterRequest): Promise<RegisterResponse> {
    return this.request<RegisterResponse>('POST', '/v1/agent/register', req);
  }

  verifyEmail(req: { email: string; code: string }): Promise<{ account_id?: string; api_key?: string; status?: string }> {
    return this.request('POST', '/v1/verify-email', req);
  }

  verifyPhone(req: { phone: string; code: string }): Promise<{ status?: string }> {
    return this.request('POST', '/v1/verify-phone', req);
  }
}
