import { promises as fs } from "node:fs";
import os from "node:os";
import path from "node:path";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { captureIo, ExitError, stderr, stdout } from "../helpers/io.js";

const fetchMock = vi.fn();
const registrationResponse = {
  account_id: "a1b2c3d4e5f60",
  api_key: "1234567890abcdef1234567890abcdef",
  api_key_note: "Shown once. Send it as the X-API-Key header.",
  email: "dev@example.com",
  email_verified: false,
  balance: 0,
  plan_id: 6452,
  status: "active",
  topup: {
    quote: "https://api.myotp.app/v1/topup/quote?credits=100",
    endpoint: "https://api.myotp.app/v1/topup",
    note: "Balance is zero until you top up. USDC works now; card top-ups unlock once the email is confirmed.",
  },
  docs: "https://myotp.app/developer-api/",
  verification_email_sent: true,
};

function mockRegistration(body: unknown = registrationResponse, status = 201): void {
  fetchMock.mockResolvedValueOnce(
    new Response(JSON.stringify(body), {
      status,
      headers: { "content-type": "application/json" },
    })
  );
}

let tmpHome: string;
let homedirSpy: ReturnType<typeof vi.spyOn>;
let originalExitCode: typeof process.exitCode;

beforeEach(async () => {
  tmpHome = await fs.mkdtemp(path.join(os.tmpdir(), "myotp-register-test-"));
  homedirSpy = vi.spyOn(os, "homedir").mockReturnValue(tmpHome);
  process.env.MYOTP_API_KEY = "configured-key-that-must-not-be-sent";
  delete process.env.MYOTP_BASE_URL;
  fetchMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
  originalExitCode = process.exitCode;
  process.exitCode = undefined;
});

afterEach(async () => {
  homedirSpy.mockRestore();
  vi.unstubAllGlobals();
  delete process.env.MYOTP_API_KEY;
  delete process.env.MYOTP_BASE_URL;
  process.exitCode = originalExitCode;
  await fs.rm(tmpHome, { recursive: true, force: true });
});

describe("register command", () => {
  it("posts the live request without authentication, saves the key, and prints next steps", async () => {
    mockRegistration();
    const { runRegister } = await import("../../src/commands/register.js");
    const { readConfig } = await import("../../src/lib/config.js");
    const io = captureIo();
    try {
      await runRegister({
        email: " dev@example.com ",
        name: " Acme ",
        baseUrl: "https://api.example.test/",
      });

      expect(fetchMock).toHaveBeenCalledOnce();
      expect(fetchMock).toHaveBeenCalledWith(
        "https://api.example.test/v1/agent/register",
        expect.objectContaining({
          method: "POST",
          body: JSON.stringify({ email: "dev@example.com", name: "Acme" }),
        })
      );
      const request = fetchMock.mock.calls[0]?.[1] as RequestInit;
      expect(request.headers).not.toHaveProperty("X-API-Key");

      await expect(readConfig()).resolves.toMatchObject({
        apiKey: registrationResponse.api_key,
        email: registrationResponse.email,
        accountId: registrationResponse.account_id,
        baseUrl: "https://api.example.test",
      });

      const out = stdout(io);
      expect(out).toContain(registrationResponse.account_id);
      expect(out).toContain("balance");
      expect(out).toContain("0 credits");
      expect(out).toContain("A confirmation email was sent");
      expect(out).toContain("myotp topup --quote");
      expect(out).toContain("Click the link in the confirmation email to unlock card top-ups");
      expect(out.split(registrationResponse.api_key)).toHaveLength(2);
      expect(stderr(io)).toBe("");
    } finally {
      io.restore();
    }
  });

  it("returns the full response as JSON and omits an absent name", async () => {
    mockRegistration();
    const { runRegister } = await import("../../src/commands/register.js");
    const { readConfig } = await import("../../src/lib/config.js");
    const io = captureIo();
    try {
      await runRegister({ email: "dev@example.com", json: true });

      const request = fetchMock.mock.calls[0]?.[1] as RequestInit;
      expect(JSON.parse(String(request.body))).toEqual({ email: "dev@example.com" });
      expect(request.headers).not.toHaveProperty("X-API-Key");
      expect(JSON.parse(stdout(io).trim())).toEqual({
        ok: true,
        command: "register",
        data: registrationResponse,
      });
      await expect(readConfig()).resolves.toMatchObject({
        apiKey: registrationResponse.api_key,
        email: registrationResponse.email,
        accountId: registrationResponse.account_id,
      });
    } finally {
      io.restore();
    }
  });

  it("shows the one-time key and exits nonzero when the config cannot be saved", async () => {
    mockRegistration();
    const { runRegister } = await import("../../src/commands/register.js");
    const writeSpy = vi.spyOn(fs, "writeFile").mockRejectedValueOnce(new Error("EACCES: denied"));
    const io = captureIo();
    try {
      await runRegister({ email: "dev@example.com" });

      expect(stdout(io).split(registrationResponse.api_key)).toHaveLength(2);
      expect(stdout(io)).toContain("not saved");
      expect(stderr(io)).toContain("Account created, but the API key could not be saved");
      expect(process.exitCode).toBe(1);
    } finally {
      io.restore();
      writeSpy.mockRestore();
    }
  });

  it("preserves the full response in JSON when the config cannot be saved", async () => {
    mockRegistration();
    const { runRegister } = await import("../../src/commands/register.js");
    const writeSpy = vi.spyOn(fs, "writeFile").mockRejectedValueOnce(new Error("EACCES: denied"));
    const io = captureIo();
    try {
      await runRegister({ email: "dev@example.com", json: true });

      expect(JSON.parse(stdout(io).trim())).toEqual({
        ok: false,
        command: "register",
        error: {
          code: "config_write_error",
          message: "account created, but its API key could not be saved; preserve registration_response.api_key now",
          details: {
            config_path: path.join(tmpHome, ".myotp", "config.json"),
            reason: "EACCES: denied",
            registration_response: registrationResponse,
          },
        },
      });
      expect(stdout(io).split(registrationResponse.api_key)).toHaveLength(2);
      expect(process.exitCode).toBe(1);
    } finally {
      io.restore();
      writeSpy.mockRestore();
    }
  });

  it.each([
    [
      400,
      { detail: { http_code: 400, message: "invalid email" } },
      "invalid email",
    ],
    [
      409,
      { detail: { http_code: 409, message: "an account with this email already exists" } },
      "an account with this email already exists; log in at myotp.app or use its API key",
    ],
    [429, { detail: "rate limited" }, "too many registration attempts from this address; try again after 24 hours"],
    [503, null, "account creation is temporarily unavailable; try again later"],
  ])("maps HTTP %s to a clear message", async (status, body, message) => {
    mockRegistration(body, status);
    const { runRegister } = await import("../../src/commands/register.js");
    const io = captureIo();
    try {
      await expect(
        runRegister({ email: "dev@example.com" })
      ).rejects.toBeInstanceOf(ExitError);
      expect(io.exits).toEqual([1]);
      expect(stderr(io)).toContain(message);
    } finally {
      io.restore();
    }
  });

  it.each([
    ["not-an-email", undefined, "Invalid email"],
    ["dev@example.com", "x".repeat(65), "String must contain at most 64 character(s)"],
  ])("rejects invalid registration input", async (email, name, message) => {
    const { runRegister } = await import("../../src/commands/register.js");
    const io = captureIo();
    try {
      await expect(runRegister({ email, name })).rejects.toBeInstanceOf(ExitError);
      expect(stderr(io)).toContain(message);
      expect(fetchMock).not.toHaveBeenCalled();
    } finally {
      io.restore();
    }
  });
});
