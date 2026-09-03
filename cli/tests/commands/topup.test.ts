import { EventEmitter } from "node:events";
import { promises as fs } from "node:fs";
import os from "node:os";
import path from "node:path";
import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { captureIo, ExitError, stderr, stdout } from "../helpers/io.js";

const { spawnMock } = vi.hoisted(() => ({ spawnMock: vi.fn() }));

vi.mock("node:child_process", () => ({ spawn: spawnMock }));

const fetchMock = vi.fn();
const quoteResponse = {
  credits: 100,
  amount_usd: "2.00",
  price_per_credit_usd: 0.02,
  min_credits: 25,
  max_credits: 50000,
  currency: "usd",
  methods: ["card via Stripe shared payment token", "usdc on tempo"],
};

function mockQuote(overrides: Partial<typeof quoteResponse> = {}): void {
  fetchMock.mockResolvedValueOnce(
    new Response(JSON.stringify({ ...quoteResponse, ...overrides }), {
      status: 200,
      headers: { "content-type": "application/json" },
    })
  );
}

function mockWalletExit(code: number | null): void {
  spawnMock.mockImplementationOnce(() => {
    const child = new EventEmitter();
    queueMicrotask(() => child.emit("close", code));
    return child;
  });
}

function mockWalletError(err: Error): void {
  spawnMock.mockImplementationOnce(() => {
    const child = new EventEmitter();
    queueMicrotask(() => child.emit("error", err));
    return child;
  });
}

let tmpHome: string;
let homedirSpy: ReturnType<typeof vi.spyOn>;

beforeEach(async () => {
  tmpHome = await fs.mkdtemp(path.join(os.tmpdir(), "myotp-topup-test-"));
  homedirSpy = vi.spyOn(os, "homedir").mockReturnValue(tmpHome);
  delete process.env.MYOTP_API_KEY;
  delete process.env.MYOTP_BASE_URL;
  fetchMock.mockReset();
  spawnMock.mockReset();
  vi.stubGlobal("fetch", fetchMock);
});

afterEach(async () => {
  homedirSpy.mockRestore();
  vi.unstubAllGlobals();
  await fs.rm(tmpHome, { recursive: true, force: true });
});

describe("topup command - quotes", () => {
  it("gets and prints the default 100-credit quote without requiring an API key", async () => {
    mockQuote();
    const { runTopup } = await import("../../src/commands/topup.js");
    const io = captureIo();
    try {
      await runTopup({ quote: true, baseUrl: "https://api.example.test/" });

      expect(fetchMock).toHaveBeenCalledOnce();
      expect(fetchMock).toHaveBeenCalledWith(
        "https://api.example.test/v1/topup/quote?credits=100",
        expect.objectContaining({ method: "GET", body: undefined })
      );
      const request = fetchMock.mock.calls[0]?.[1] as RequestInit;
      expect(request.headers).not.toHaveProperty("X-API-Key");
      expect(spawnMock).not.toHaveBeenCalled();

      const out = stdout(io);
      expect(out).toContain("Top-up quote");
      expect(out).toContain("$2.00 USD");
      expect(out).toContain("$0.02 per credit");
      expect(out).toContain("card via Stripe shared payment token");
      expect(out).toContain("usdc on tempo");
      expect(out).toContain("25 to 50,000 credits");
      expect(out).toContain("$100 per account per rolling 24 hours");
      expect(out).toMatch(/USDC cap\s+:.*uncapped/);
    } finally {
      io.restore();
    }
  });

  it("emits one machine-readable JSON document with --json", async () => {
    mockQuote({ credits: 250, amount_usd: "5.00" });
    const { runTopup } = await import("../../src/commands/topup.js");
    const io = captureIo();
    try {
      await runTopup({ credits: "250", quote: true, json: true, baseUrl: "https://api.example.test" });

      const parsed = JSON.parse(stdout(io).trim());
      expect(parsed).toMatchObject({
        ok: true,
        command: "topup",
        data: {
          credits: 250,
          amount_usd: "5.00",
          price_per_credit_usd: 0.02,
          min_credits: 25,
          max_credits: 50000,
          currency: "usd",
          methods: quoteResponse.methods,
          cap_rules: {
            card: "$100 per account per rolling 24 hours",
            usdc: "uncapped",
          },
        },
      });
      expect(spawnMock).not.toHaveBeenCalled();
    } finally {
      io.restore();
    }
  });

  it.each([
    [25, "0.50"],
    [50000, "1000.00"],
  ])("accepts the boundary value %s", async (credits, amount) => {
    mockQuote({ credits, amount_usd: amount });
    const { runTopup } = await import("../../src/commands/topup.js");
    const io = captureIo();
    try {
      await runTopup({ credits, quote: true, baseUrl: "https://api.example.test" });
      expect(fetchMock.mock.calls[0]?.[0]).toBe(
        `https://api.example.test/v1/topup/quote?credits=${credits}`
      );
      expect(io.exits).toHaveLength(0);
    } finally {
      io.restore();
    }
  });
});

describe("topup command - payment", () => {
  it("prints the quote and spawns the exact default USDC wallet command", async () => {
    mockQuote();
    mockWalletExit(0);
    const { runTopup } = await import("../../src/commands/topup.js");
    const io = captureIo();
    const apiKey = "k_test_topup";
    try {
      await runTopup({ apiKey, baseUrl: "https://api.example.test/" });

      expect(spawnMock).toHaveBeenCalledWith(
        "npx",
        [
          "-y",
          "mppx@0.9.2",
          "https://api.example.test/v1/topup",
          "-X",
          "POST",
          "-H",
          "x-api-key: k_test_topup",
          "-H",
          "content-type: application/json",
          "-d",
          '{"credits":100}',
        ],
        { stdio: "inherit" }
      );
      const out = stdout(io);
      expect(out.indexOf("Top-up quote")).toBeLessThan(out.indexOf("Paying $2.00 USD"));
      expect(out).toContain("mppx@0.9.2 (USDC wallet)");
      expect(out).not.toContain(apiKey);
      expect(stderr(io)).not.toContain(apiKey);
    } finally {
      io.restore();
    }
  });

  it("spawns the exact Stripe Link card command", async () => {
    mockQuote();
    mockWalletExit(0);
    const { runTopup } = await import("../../src/commands/topup.js");
    const io = captureIo();
    try {
      await runTopup({
        apiKey: "k_test_topup",
        baseUrl: "https://api.example.test",
        method: "card",
      });

      expect(spawnMock).toHaveBeenCalledWith(
        "npx",
        [
          "-y",
          "@stripe/link-cli",
          "mpp",
          "pay",
          "https://api.example.test/v1/topup",
          "-X",
          "POST",
          "-d",
          '{"credits":100}',
          "-H",
          "x-api-key: k_test_topup",
          "--context",
          "MyOTP credits",
        ],
        { stdio: "inherit" }
      );
      expect(stdout(io)).toContain("Paying $2.00 USD with @stripe/link-cli (card wallet)");
    } finally {
      io.restore();
    }
  });

  it.each([
    ["usdc", "npx mppx account create"],
    ["card", "npx @stripe/link-cli auth login"],
  ])("exits 1 with the %s wallet setup command after a non-zero exit", async (method, hint) => {
    mockQuote();
    mockWalletExit(1);
    const { runTopup } = await import("../../src/commands/topup.js");
    const io = captureIo();
    try {
      await expect(
        runTopup({ apiKey: "k", baseUrl: "https://api.example.test", method })
      ).rejects.toBeInstanceOf(ExitError);
      expect(io.exits[0]).toBe(1);
      expect(stderr(io)).toContain(hint);
    } finally {
      io.restore();
    }
  });

  it("handles a wallet spawn error with the setup hint", async () => {
    mockQuote();
    mockWalletError(new Error("spawn npx ENOENT"));
    const { runTopup } = await import("../../src/commands/topup.js");
    const io = captureIo();
    try {
      await expect(
        runTopup({ apiKey: "k", baseUrl: "https://api.example.test" })
      ).rejects.toBeInstanceOf(ExitError);
      expect(io.exits[0]).toBe(1);
      expect(stderr(io)).toContain("npx mppx account create");
    } finally {
      io.restore();
    }
  });

  it("requires an API key before fetching a payment quote", async () => {
    const { runTopup } = await import("../../src/commands/topup.js");
    const io = captureIo();
    try {
      await expect(
        runTopup({ baseUrl: "https://api.example.test" })
      ).rejects.toBeInstanceOf(ExitError);
      expect(io.exits[0]).toBe(1);
      expect(stderr(io)).toContain("No API key configured");
      expect(fetchMock).not.toHaveBeenCalled();
      expect(spawnMock).not.toHaveBeenCalled();
    } finally {
      io.restore();
    }
  });
});

describe("topup command - validation", () => {
  it.each([24, 50001, 25.5, "many", ""])("rejects invalid credits %j before network access", async (credits) => {
    const { runTopup } = await import("../../src/commands/topup.js");
    const io = captureIo();
    try {
      await expect(
        runTopup({ credits, quote: true, baseUrl: "https://api.example.test" })
      ).rejects.toBeInstanceOf(ExitError);
      expect(io.exits[0]).toBe(1);
      expect(stderr(io)).toContain("credits must be an integer between 25 and 50000");
      expect(fetchMock).not.toHaveBeenCalled();
      expect(spawnMock).not.toHaveBeenCalled();
    } finally {
      io.restore();
    }
  });

  it("uses the exact API validation message in JSON mode", async () => {
    const { runTopup } = await import("../../src/commands/topup.js");
    const io = captureIo();
    try {
      await expect(
        runTopup({ credits: "not-a-number", quote: true, json: true })
      ).rejects.toBeInstanceOf(ExitError);
      const parsed = JSON.parse(stdout(io).trim());
      expect(parsed.error.message).toBe("credits must be an integer between 25 and 50000");
    } finally {
      io.restore();
    }
  });

  it("rejects an unsupported payment method before network access", async () => {
    const { runTopup } = await import("../../src/commands/topup.js");
    const io = captureIo();
    try {
      await expect(
        runTopup({ method: "cash", apiKey: "k" })
      ).rejects.toBeInstanceOf(ExitError);
      expect(io.exits[0]).toBe(1);
      expect(stderr(io)).toContain("method");
      expect(fetchMock).not.toHaveBeenCalled();
      expect(spawnMock).not.toHaveBeenCalled();
    } finally {
      io.restore();
    }
  });
});

describe("topup platform handling", () => {
  it("rejects --json on the payment path without spawning a wallet", async () => {
    const { runTopup } = await import("../../src/commands/topup.js");
    const io = captureIo();
    try {
      await expect(runTopup({ json: true, apiKey: "k_test_topup", baseUrl: "https://api.example.test/" })).rejects.toBeInstanceOf(ExitError);
      expect(spawnMock).not.toHaveBeenCalled();
      expect(stdout(io) + stderr(io)).toContain("--quote");
    } finally {
      io.restore();
    }
  });

  it("runs npx-cli.js through the node binary on win32 and plain npx elsewhere", async () => {
    const { npxInvocation } = await import("../../src/commands/topup.js");
    const platform = Object.getOwnPropertyDescriptor(process, "platform")!;
    try {
      Object.defineProperty(process, "platform", { value: "linux" });
      expect(npxInvocation(["-y", "mppx@0.9.2"])).toEqual({ command: "npx", args: ["-y", "mppx@0.9.2"] });
      Object.defineProperty(process, "platform", { value: "win32" });
      const inv = npxInvocation(["-y", "mppx@0.9.2"]);
      expect(["npx.cmd", process.execPath]).toContain(inv.command);
      expect(inv.args.slice(-2)).toEqual(["-y", "mppx@0.9.2"]);
      expect(inv.command === "npx.cmd" || inv.args[0].endsWith("npx-cli.js")).toBe(true);
    } finally {
      Object.defineProperty(process, "platform", platform);
    }
  });
});
