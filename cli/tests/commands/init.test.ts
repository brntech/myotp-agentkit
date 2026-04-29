import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { promises as fs } from "node:fs";
import os from "node:os";
import path from "node:path";
import { MyOtpApiError } from "../../src/lib/api.js";
import { captureIo, ExitError, stdout } from "../helpers/io.js";

// Init now just validates a pasted/passed API key against /me. No prompting in
// JSON mode. Interactive paste flow (TTY-based) is intentionally out of scope.

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
  tmpHome = await fs.mkdtemp(path.join(os.tmpdir(), "myotp-init-test-"));
  homedirSpy = vi.spyOn(os, "homedir").mockReturnValue(tmpHome);
  delete process.env.MYOTP_API_KEY;
  delete process.env.MYOTP_BASE_URL;
  meMock.mockReset();
});

afterEach(async () => {
  homedirSpy.mockRestore();
  await fs.rm(tmpHome, { recursive: true, force: true });
});

describe("init command — JSON mode with --key", () => {
  it("validates the key against /me and saves it on success", async () => {
    meMock.mockResolvedValueOnce({ email: "user@example.com" });
    const { runInit } = await import("../../src/commands/init.js");
    const { readConfig } = await import("../../src/lib/config.js");
    const io = captureIo();
    try {
      await runInit({ key: "k_valid_key_12345", json: true });
      const parsed = JSON.parse(stdout(io).trim());
      expect(parsed.ok).toBe(true);
      expect(parsed.data.status).toBe("active");
      expect(parsed.data.email).toBe("user@example.com");
      expect(parsed.data.api_key_set).toBe(true);

      const cfg = await readConfig();
      expect(cfg.apiKey).toBe("k_valid_key_12345");
      expect(cfg.email).toBe("user@example.com");
    } finally {
      io.restore();
    }
  });

  it("requires --key in JSON mode", async () => {
    const { runInit } = await import("../../src/commands/init.js");
    const io = captureIo();
    try {
      await expect(runInit({ json: true })).rejects.toBeInstanceOf(ExitError);
      expect(io.exits[0]).toBe(1);
    } finally {
      io.restore();
    }
  });

  it("surfaces a clear error when the key is rejected (401)", async () => {
    meMock.mockRejectedValueOnce(
      new MyOtpApiError("Unauthorized", { status: 401, body: null, endpoint: "/me" })
    );
    const { runInit } = await import("../../src/commands/init.js");
    const io = captureIo();
    try {
      await expect(runInit({ key: "k_bogus", json: true })).rejects.toBeInstanceOf(ExitError);
      expect(io.exits[0]).toBe(1);
    } finally {
      io.restore();
    }
  });

  it("surfaces a different error when IP is not whitelisted (403)", async () => {
    meMock.mockRejectedValueOnce(
      new MyOtpApiError("Forbidden", { status: 403, body: null, endpoint: "/me" })
    );
    const { runInit } = await import("../../src/commands/init.js");
    const io = captureIo();
    try {
      await expect(runInit({ key: "k_valid_but_ip_blocked", json: true })).rejects.toBeInstanceOf(ExitError);
      expect(io.exits[0]).toBe(1);
    } finally {
      io.restore();
    }
  });
});

describe("init command — existing config", () => {
  it("refuses to overwrite without --force in JSON mode", async () => {
    // Seed a config first
    const { writeConfig } = await import("../../src/lib/config.js");
    await writeConfig({ apiKey: "k_existing", email: "old@example.com" });

    const { runInit } = await import("../../src/commands/init.js");
    const io = captureIo();
    try {
      await expect(
        runInit({ key: "k_new", json: true })
      ).rejects.toBeInstanceOf(ExitError);
      expect(io.exits[0]).toBe(1);
    } finally {
      io.restore();
    }
  });

  it("overwrites with --force in JSON mode", async () => {
    const { writeConfig, readConfig } = await import("../../src/lib/config.js");
    await writeConfig({ apiKey: "k_existing", email: "old@example.com" });

    meMock.mockResolvedValueOnce({ email: "new@example.com" });
    const { runInit } = await import("../../src/commands/init.js");
    const io = captureIo();
    try {
      await runInit({ key: "k_new_key", force: true, json: true });
      const parsed = JSON.parse(stdout(io).trim());
      expect(parsed.ok).toBe(true);

      const cfg = await readConfig();
      expect(cfg.apiKey).toBe("k_new_key");
      expect(cfg.email).toBe("new@example.com");
    } finally {
      io.restore();
    }
  });
});
