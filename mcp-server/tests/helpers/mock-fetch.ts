import { vi, type Mock } from "vitest";

export interface MockResponseInit {
  status?: number;
  statusText?: string;
  body?: unknown;
  /** When true, return a non-JSON text body (e.g. an HTML error page). */
  textBody?: string;
  /** When set, return an empty body. Wins over body/textBody. */
  empty?: boolean;
  headers?: Record<string, string>;
}

/**
 * Build a Response-like object that behaves enough like a real Response for
 * our client (which only calls .ok, .status, .statusText, .text()).
 */
export function makeResponse(init: MockResponseInit = {}): Response {
  const status = init.status ?? 200;
  const statusText = init.statusText ?? "OK";
  let bodyText: string;
  if (init.empty) {
    bodyText = "";
  } else if (init.textBody !== undefined) {
    bodyText = init.textBody;
  } else if (init.body !== undefined) {
    bodyText = JSON.stringify(init.body);
  } else {
    bodyText = "";
  }
  return {
    ok: status >= 200 && status < 300,
    status,
    statusText,
    headers: new Headers(init.headers ?? {}),
    text: async () => bodyText,
    json: async () => JSON.parse(bodyText),
  } as unknown as Response;
}

export interface FetchCall {
  url: string;
  method: string;
  headers: Record<string, string>;
  body: string | undefined;
}

/**
 * Install a mock fetch that returns the given response. Returns a helper to
 * inspect the calls made.
 */
export function installMockFetch(responses: MockResponseInit | MockResponseInit[] | (() => Promise<Response>)): {
  fetchMock: Mock;
  calls: () => FetchCall[];
} {
  const list = Array.isArray(responses) ? responses : [responses];
  let i = 0;
  const fetchMock = vi.fn(async (url: string, init?: RequestInit): Promise<Response> => {
    if (typeof responses === "function") {
      return responses();
    }
    const r = list[Math.min(i, list.length - 1)] ?? {};
    i += 1;
    // Simulate aborted fetch: respect the AbortSignal so timeout tests work.
    const signal = init?.signal as AbortSignal | undefined;
    if (signal?.aborted) {
      const err = new Error("aborted");
      err.name = "AbortError";
      throw err;
    }
    return makeResponse(r);
  });
  vi.stubGlobal("fetch", fetchMock);
  return {
    fetchMock,
    calls: () =>
      fetchMock.mock.calls.map(([url, init]) => {
        const init2 = (init ?? {}) as RequestInit;
        const headersIn = init2.headers as Record<string, string> | Headers | undefined;
        const headers: Record<string, string> = {};
        if (headersIn instanceof Headers) {
          headersIn.forEach((v, k) => {
            headers[k] = v;
          });
        } else if (headersIn) {
          for (const [k, v] of Object.entries(headersIn)) {
            headers[k] = String(v);
          }
        }
        return {
          url: String(url),
          method: String(init2.method ?? "GET"),
          headers,
          body: typeof init2.body === "string" ? init2.body : undefined,
        };
      }),
  };
}

/**
 * Install a fetch that never resolves but respects AbortSignal. Useful for
 * exercising the timeout path.
 */
export function installStalledFetch(): { fetchMock: Mock } {
  const fetchMock = vi.fn(
    (_url: string, init?: RequestInit): Promise<Response> =>
      new Promise<Response>((_resolve, reject) => {
        const signal = init?.signal as AbortSignal | undefined;
        if (signal) {
          signal.addEventListener("abort", () => {
            const err = new Error("The operation was aborted");
            err.name = "AbortError";
            reject(err);
          });
        }
      })
  );
  vi.stubGlobal("fetch", fetchMock);
  return { fetchMock };
}
