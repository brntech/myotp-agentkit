import { vi } from "vitest";
import type { MyOtpClient } from "../../src/client.js";
import { MyOtpApiError } from "../../src/types.js";

/**
 * Minimal stand-in for MyOtpClient for tool-handler tests. We replace `post`
 * and `get` with vi.fn so we can assert on calls and shape successes/errors
 * per test.
 */
export interface FakeClient {
  post: ReturnType<typeof vi.fn>;
  get: ReturnType<typeof vi.fn>;
}

export function makeFakeClient(): FakeClient {
  return {
    post: vi.fn(),
    get: vi.fn(),
  };
}

export function asMyOtpClient(c: FakeClient): MyOtpClient {
  return c as unknown as MyOtpClient;
}

export { MyOtpApiError };
