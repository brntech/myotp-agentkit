import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { promises as fs } from "node:fs";
import os from "node:os";
import path from "node:path";
import { MyOtpApiError } from "../../src/lib/api.js";
import { captureIo, ExitError, stderr, stdout } from "../helpers/io.js";

const meMock = vi.fn();

vi.mock("../../src/lib/api.js", async (importOriginal) => {
  const actual = (await importOriginal()) as Record<string, unknown>;
  return {
    ...actual,
    MyOtpClient: function MockMyOtpClient() {
      return { me: meMock };
    },
  };
});

let tmpHome: string;
let homedirSpy: ReturnType<typeof vi.spyOn>;

beforeEach(async () => {
  tmpHome = await fs.mkdtemp(path.join(os.tmpdir(), "myotp-status-test-"));
  homedirSpy = vi.spyOn(os, "homedir").mockReturnValue(tmpHome);
  delete process.env.MYOTP_API_KEY;
  delete process.env.MYOTP_BASE_URL;
  meMock.mockReset();
});

afterEach(async () => {
  homedirSpy.mockRestore();
  await fs.rm(tmpHome, { recursive: true, force: true });
});

describe("status command", () => {
  it("prints email and masked API key on happy path", async () => {
    meMock.mockResolvedValueOnce({ email: "user@example.com" });
    const { runStatus } = await import("../../src/commands/status.js");
    const io = captureIo();
    try {
      await runStatus({ apiKey: "k_test_abcdef1234567890" });
      const out = stdout(io);
      expect(out).toContain("user@example.com");
      // The full key must not appear; only a masked version.
      expect(out).not.toContain("k_test_abcdef1234567890");
      expect(out).toContain("MyOTP account");
    } finally {
      io.restore();
    }
  });

  it("returns JSON with --json", async () => {
    meMock.mockResolvedValueOnce({ email: "u@example.com" });
    const { runStatus } = await import("../../src/commands/status.js");
    const io = captureIo();
    try {
      await runStatus({ apiKey: "k_abcd1234efgh", json: true });
      const parsed = JSON.parse(stdout(io).trim());
      expect(parsed.ok).toBe(true);
      expect(parsed.data.email).toBe("u@example.com");
      expect(parsed.data.api_key_source).toBe("flag");
      expect(parsed.data.api_key_masked).toContain("*");
      // Notes should be present so agents can detect the partially-implemented endpoint.
      expect(parsed.data.notes).toContain("not exposed by the public API");
    } finally {
      io.restore();
    }
  });

  it("exits 1 with no API key", async () => {
    const { runStatus } = await import("../../src/commands/status.js");
    const io = captureIo();
    try {
      await expect(runStatus({})).rejects.toBeInstanceOf(ExitError);
      expect(io.exits[0]).toBe(1);
      expect(stderr(io)).toContain("No API key configured");
    } finally {
      io.restore();
    }
  });

  it("does not echo the API key on a 401", async () => {
    meMock.mockRejectedValueOnce(
      new MyOtpApiError("Unauthorized", { status: 401, body: null, endpoint: "/me" })
    );
    const { runStatus } = await import("../../src/commands/status.js");
    const io = captureIo();
    const SECRET = "k_dont_leak_me";
    try {
      await expect(runStatus({ apiKey: SECRET })).rejects.toBeInstanceOf(ExitError);
      expect(stderr(io)).not.toContain(SECRET);
    } finally {
      io.restore();
    }
  });
});
