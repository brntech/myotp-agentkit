import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { promises as fs } from "node:fs";
import os from "node:os";
import path from "node:path";
import { MyOtpApiError } from "../../src/lib/api.js";
import { captureIo, ExitError, stderr, stdout } from "../helpers/io.js";

// Stand-in fake replaces the real MyOtpClient. The mock factory below is
// called once at module load; each test pushes per-call behavior into
// `generateOtpMock`. The constructor mock returns a fresh object that
// delegates to that stable mock function.
const generateOtpMock = vi.fn();

vi.mock("../../src/lib/api.js", async (importOriginal) => {
  const actual = (await importOriginal()) as Record<string, unknown>;
  // Note: we use a regular function rather than vi.fn().mockImplementation()
  // because mockReset() on the constructor mock would erase the
  // implementation between tests.
  return {
    ...actual,
    MyOtpClient: function MockMyOtpClient() {
      return { generateOtp: generateOtpMock };
    },
  };
});

let tmpHome: string;
let homedirSpy: ReturnType<typeof vi.spyOn>;

beforeEach(async () => {
  tmpHome = await fs.mkdtemp(path.join(os.tmpdir(), "myotp-test-test-"));
  homedirSpy = vi.spyOn(os, "homedir").mockReturnValue(tmpHome);
  delete process.env.MYOTP_API_KEY;
  delete process.env.MYOTP_BASE_URL;
  generateOtpMock.mockReset();
});

afterEach(async () => {
  homedirSpy.mockRestore();
  await fs.rm(tmpHome, { recursive: true, force: true });
});

describe("test command — happy path", () => {
  it("normalizes the phone, sends the OTP, and prints a success message", async () => {
    generateOtpMock.mockResolvedValueOnce({
      message_id: "msg_1",
      status: "queued",
      message: "ok",
      date_sent: "2026-04-28T10:00:00Z",
      expires_at: "2026-04-28T10:05:00Z",
      cost: 0.01,
    });

    const { runTest } = await import("../../src/commands/test.js");
    const io = captureIo();
    try {
      await runTest("+1 (415) 555-1234", { apiKey: "k_test_123" });
      expect(generateOtpMock).toHaveBeenCalledWith({
        phone_number: "14155551234",
        channel: "sms",
      });
      expect(stdout(io)).toContain("OTP queued for delivery");
      expect(stdout(io)).toContain("msg_1");
      expect(stdout(io)).toContain("0.01");
    } finally {
      io.restore();
    }
  });

  it("emits parseable JSON when --json is set", async () => {
    generateOtpMock.mockResolvedValueOnce({
      message_id: "msg_2",
      status: "queued",
      message: "ok",
      date_sent: "2026-04-28T10:00:00Z",
      expires_at: "2026-04-28T10:05:00Z",
      cost: 0.02,
    });

    const { runTest } = await import("../../src/commands/test.js");
    const io = captureIo();
    try {
      await runTest("+14155551234", { apiKey: "k", json: true });
      const out = stdout(io).trim();
      const parsed = JSON.parse(out);
      expect(parsed.ok).toBe(true);
      expect(parsed.command).toBe("test");
      expect(parsed.data.message_id).toBe("msg_2");
      expect(parsed.data.phone).toBe("14155551234");
    } finally {
      io.restore();
    }
  });

  it("prints extra detail with --verbose", async () => {
    generateOtpMock.mockResolvedValueOnce({
      message_id: "id",
      status: "queued",
      message: "ok",
      date_sent: "x",
      expires_at: "y",
      cost: 0,
    });
    const { runTest } = await import("../../src/commands/test.js");
    const io = captureIo();
    try {
      await runTest("+14155551234", { apiKey: "k", verbose: true });
      expect(stdout(io)).toContain("Sending sms OTP to 14155551234");
    } finally {
      io.restore();
    }
  });

  it("forwards the channel option to the API client", async () => {
    generateOtpMock.mockResolvedValueOnce({
      message_id: "id",
      status: "queued",
      message: "ok",
      date_sent: "x",
      expires_at: "y",
      cost: 0,
    });
    const { runTest } = await import("../../src/commands/test.js");
    const io = captureIo();
    try {
      await runTest("+14155551234", { apiKey: "k", channel: "whatsapp" });
      expect(generateOtpMock.mock.calls[0]?.[0]).toMatchObject({ channel: "whatsapp" });
    } finally {
      io.restore();
    }
  });
});

describe("test command — failure modes", () => {
  it("exits with code 1 and a clear error when no API key is configured", async () => {
    const { runTest } = await import("../../src/commands/test.js");
    const io = captureIo();
    try {
      await expect(runTest("+14155551234", {})).rejects.toBeInstanceOf(ExitError);
      expect(io.exits[0]).toBe(1);
      expect(stderr(io)).toContain("No API key configured");
    } finally {
      io.restore();
    }
  });

  it("exits with code 1 on an invalid phone number", async () => {
    const { runTest } = await import("../../src/commands/test.js");
    const io = captureIo();
    try {
      await expect(runTest("not a phone", { apiKey: "k" })).rejects.toBeInstanceOf(ExitError);
      expect(io.exits[0]).toBe(1);
      expect(stderr(io)).toMatch(/digit|short/i);
    } finally {
      io.restore();
    }
  });

  it("does not echo the API key in error output", async () => {
    generateOtpMock.mockRejectedValueOnce(
      new MyOtpApiError("Insufficient balance", { status: 402, body: null, endpoint: "/generate_otp" })
    );
    const { runTest } = await import("../../src/commands/test.js");
    const io = captureIo();
    const SECRET = "k_super_secret_value_xyz";
    try {
      await expect(runTest("+14155551234", { apiKey: SECRET })).rejects.toBeInstanceOf(ExitError);
      expect(stderr(io)).toContain("Insufficient balance");
      expect(stderr(io)).not.toContain(SECRET);
      expect(stdout(io)).not.toContain(SECRET);
    } finally {
      io.restore();
    }
  });

  it("emits a JSON error when --json + invalid phone", async () => {
    const { runTest } = await import("../../src/commands/test.js");
    const io = captureIo();
    try {
      await expect(runTest("not a phone", { apiKey: "k", json: true })).rejects.toBeInstanceOf(ExitError);
      const parsed = JSON.parse(stdout(io).trim());
      expect(parsed.ok).toBe(false);
      expect(parsed.error.code).toBe("invalid_phone");
    } finally {
      io.restore();
    }
  });
});
