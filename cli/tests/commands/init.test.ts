import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { promises as fs } from "node:fs";
import os from "node:os";
import path from "node:path";
import { MyOtpApiError } from "../../src/lib/api.js";
import { captureIo, ExitError, stdout } from "../helpers/io.js";

// We only test the --json path of init because that path bypasses interactive
// prompts entirely (--email/--phone/--company come in as flags). The
// interactive prompt flow (TTY-based) is intentionally out of scope.

const registerMock = vi.fn();

vi.mock("../../src/lib/api.js", async (importOriginal) => {
  const actual = (await importOriginal()) as Record<string, unknown>;
  return {
    ...actual,
    MyOtpClient: function MockMyOtpClient() {
      return { register: registerMock };
    },
  };
});

let tmpHome: string;
let homedirSpy: ReturnType<typeof vi.spyOn>;

beforeEach(async () => {
  tmpHome = await fs.mkdtemp(path.join(os.tmpdir(), "myotp-init-test-"));
  homedirSpy = vi.spyOn(os, "homedir").mockReturnValue(tmpHome);
  delete process.env.MYOTP_API_KEY;
  delete process.env.MYOTP_BASE_URL;
  registerMock.mockReset();
});

afterEach(async () => {
  homedirSpy.mockRestore();
  await fs.rm(tmpHome, { recursive: true, force: true });
});

describe("init command — onboarding endpoint not shipped (404)", () => {
  it("emits a JSON 'fallback' result when the register endpoint returns 404", async () => {
    registerMock.mockRejectedValueOnce(
      new MyOtpApiError("Not Found", { status: 404, body: null, endpoint: "/v1/agent/register" })
    );
    const { runInit } = await import("../../src/commands/init.js");
    const io = captureIo();
    try {
      await runInit({
        email: "user@example.com",
        phone: "+14155551234",
        company: "Acme",
        json: true,
      });
      const parsed = JSON.parse(stdout(io).trim());
      expect(parsed.ok).toBe(true);
      expect(parsed.data.status).toBe("fallback");
      expect(parsed.data.signup_url).toContain("myotp.app");
      expect(parsed.data.set_key_command).toContain("--set-key");
    } finally {
      io.restore();
    }
  });

  it("does NOT throw on 404 — just surfaces the fallback", async () => {
    registerMock.mockRejectedValueOnce(
      new MyOtpApiError("Not Found", { status: 404, body: null, endpoint: "/v1/agent/register" })
    );
    const { runInit } = await import("../../src/commands/init.js");
    const io = captureIo();
    try {
      await expect(
        runInit({ email: "u@example.com", phone: "+14155551234", company: "Acme", json: true })
      ).resolves.toBeUndefined();
      // No process.exit fired.
      expect(io.exits.length).toBe(0);
    } finally {
      io.restore();
    }
  });
});

describe("init command — JSON mode validation", () => {
  it("requires --email, --phone, --company in JSON mode", async () => {
    const { runInit } = await import("../../src/commands/init.js");
    const io = captureIo();
    try {
      await expect(runInit({ json: true })).rejects.toBeInstanceOf(ExitError);
      expect(io.exits[0]).toBe(1);
    } finally {
      io.restore();
    }
  });
});

describe("init command — register returns api_key", () => {
  it("writes the returned API key to the config and reports active status", async () => {
    registerMock.mockResolvedValueOnce({
      account_id: "acct_42",
      status: "active",
      api_key: "k_brand_new_key",
    });
    const { runInit } = await import("../../src/commands/init.js");
    const { readConfig } = await import("../../src/lib/config.js");
    const io = captureIo();
    try {
      await runInit({
        email: "u@example.com",
        phone: "+14155551234",
        company: "Acme",
        json: true,
      });
      const parsed = JSON.parse(stdout(io).trim());
      expect(parsed.data.status).toBe("active");
      expect(parsed.data.account_id).toBe("acct_42");

      const cfg = await readConfig();
      expect(cfg.apiKey).toBe("k_brand_new_key");
      expect(cfg.email).toBe("u@example.com");
      expect(cfg.accountId).toBe("acct_42");
    } finally {
      io.restore();
    }
  });
});
