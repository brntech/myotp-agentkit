import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { promises as fs } from "node:fs";
import os from "node:os";
import path from "node:path";
import { MyOtpApiError } from "../../src/lib/api.js";
import { captureIo, ExitError, stderr, stdout } from "../helpers/io.js";

const verifyOtpMock = vi.fn();

vi.mock("../../src/lib/api.js", async (importOriginal) => {
  const actual = (await importOriginal()) as Record<string, unknown>;
  // Use a plain function so vitest's `restoreMocks: true` doesn't strip the
  // implementation between tests.
  return {
    ...actual,
    MyOtpClient: function MockMyOtpClient() {
      return { verifyOtp: verifyOtpMock };
    },
  };
});

let tmpHome: string;
let homedirSpy: ReturnType<typeof vi.spyOn>;

beforeEach(async () => {
  tmpHome = await fs.mkdtemp(path.join(os.tmpdir(), "myotp-verify-test-"));
  homedirSpy = vi.spyOn(os, "homedir").mockReturnValue(tmpHome);
  delete process.env.MYOTP_API_KEY;
  delete process.env.MYOTP_BASE_URL;
  verifyOtpMock.mockReset();
});

afterEach(async () => {
  homedirSpy.mockRestore();
  await fs.rm(tmpHome, { recursive: true, force: true });
});

describe("verify command", () => {
  it("prints OK and exits 0 on a successful match", async () => {
    verifyOtpMock.mockResolvedValueOnce({ status: "success", message: "OTP matched" });
    const { runVerify } = await import("../../src/commands/verify.js");
    const io = captureIo();
    try {
      await runVerify("+14155551234", "123456", { apiKey: "k" });
      expect(stdout(io)).toContain("OTP verified");
      expect(io.exits.length).toBe(0);
    } finally {
      io.restore();
    }
  });

  it("exits with code 2 on a failed match (soft failure)", async () => {
    verifyOtpMock.mockResolvedValueOnce({
      status: "failed",
      reason: "invalid",
      message: "Code does not match",
    });
    const { runVerify } = await import("../../src/commands/verify.js");
    const io = captureIo();
    try {
      await expect(runVerify("+14155551234", "123456", { apiKey: "k" })).rejects.toBeInstanceOf(
        ExitError
      );
      expect(io.exits[0]).toBe(2);
      expect(stdout(io)).toContain("OTP not verified");
      expect(stdout(io)).toContain("invalid");
    } finally {
      io.restore();
    }
  });

  it("rejects an OTP code that is not 3-8 digits", async () => {
    const { runVerify } = await import("../../src/commands/verify.js");
    const io = captureIo();
    try {
      await expect(runVerify("+14155551234", "12", { apiKey: "k" })).rejects.toBeInstanceOf(
        ExitError
      );
      expect(io.exits[0]).toBe(1);
      expect(stderr(io)).toContain("3 to 8 digits");
    } finally {
      io.restore();
    }
  });

  it("supports verifying by --message-id instead of phone", async () => {
    verifyOtpMock.mockResolvedValueOnce({ status: "success", message: "ok" });
    const { runVerify } = await import("../../src/commands/verify.js");
    const io = captureIo();
    try {
      await runVerify("+14155551234", "123456", { apiKey: "k", messageId: "msg_42" });
      expect(verifyOtpMock.mock.calls[0]?.[0]).toMatchObject({
        otp: "123456",
        message_id: "msg_42",
      });
      // Should NOT also include phone_number
      expect(verifyOtpMock.mock.calls[0]?.[0]?.phone_number).toBeUndefined();
    } finally {
      io.restore();
    }
  });

  it("emits JSON output with --json", async () => {
    verifyOtpMock.mockResolvedValueOnce({ status: "success", message: "ok" });
    const { runVerify } = await import("../../src/commands/verify.js");
    const io = captureIo();
    try {
      await runVerify("+14155551234", "123456", { apiKey: "k", json: true });
      const parsed = JSON.parse(stdout(io).trim());
      expect(parsed.ok).toBe(true);
      expect(parsed.data.verified).toBe(true);
    } finally {
      io.restore();
    }
  });

  it("does not echo the API key on a failed API call", async () => {
    verifyOtpMock.mockRejectedValueOnce(
      new MyOtpApiError("auth failed", { status: 401, body: null, endpoint: "/verify_otp" })
    );
    const { runVerify } = await import("../../src/commands/verify.js");
    const io = captureIo();
    const SECRET = "k_supersecret_dont_leak";
    try {
      await expect(runVerify("+14155551234", "123456", { apiKey: SECRET })).rejects.toBeInstanceOf(
        ExitError
      );
      expect(stderr(io)).not.toContain(SECRET);
      expect(stdout(io)).not.toContain(SECRET);
    } finally {
      io.restore();
    }
  });
});
