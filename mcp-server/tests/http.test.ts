import type { AddressInfo } from "node:net";
import type { Server } from "node:http";
import { afterEach, describe, expect, it } from "vitest";
import { createHttpApp, type HttpApp } from "../src/http.js";

const INIT_BODY = {
  jsonrpc: "2.0",
  id: 1,
  method: "initialize",
  params: {
    protocolVersion: "2025-03-26",
    capabilities: {},
    clientInfo: { name: "test", version: "0" },
  },
};

const HEADERS = {
  "content-type": "application/json",
  accept: "application/json, text/event-stream",
};

let clock = 0;
let http: HttpApp | undefined;
let listener: Server | undefined;
let base = "";

async function start(opts: { idleMs?: number; busyMs?: number; maxSessions?: number } = {}): Promise<void> {
  clock = 1_000_000;
  http = createHttpApp({ path: "/mcp", now: () => clock, ...opts });
  listener = http.app.listen(0, "127.0.0.1");
  await new Promise<void>((resolve) => listener!.once("listening", () => resolve()));
  base = `http://127.0.0.1:${(listener.address() as AddressInfo).port}/mcp`;
}

async function initialize(): Promise<string> {
  const res = await fetch(base, { method: "POST", headers: HEADERS, body: JSON.stringify(INIT_BODY) });
  await res.text();
  expect(res.status).toBe(200);
  const sid = res.headers.get("mcp-session-id");
  expect(sid).toBeTruthy();
  return sid!;
}

async function ping(sid: string): Promise<number> {
  const res = await fetch(base, {
    method: "POST",
    headers: { ...HEADERS, "mcp-session-id": sid, "mcp-protocol-version": "2025-03-26" },
    body: JSON.stringify({ jsonrpc: "2.0", id: 2, method: "ping" }),
  });
  await res.text();
  return res.status;
}

// Open the standalone SSE stream for a session; abort() closes it.
async function openStream(sid: string): Promise<AbortController> {
  const ctrl = new AbortController();
  const res = await fetch(base, {
    method: "GET",
    headers: { accept: "text/event-stream", "mcp-session-id": sid, "mcp-protocol-version": "2025-03-26" },
    signal: ctrl.signal,
  });
  expect(res.status).toBe(200);
  return ctrl;
}

// Let the server see each response's "close" before the test moves the clock.
const settle = () => new Promise((resolve) => setTimeout(resolve, 20));

afterEach(async () => {
  await http?.closeAll();
  await new Promise<void>((resolve) => (listener ? listener.close(() => resolve()) : resolve()));
  http = undefined;
  listener = undefined;
});

describe("hosted HTTP sessions", () => {
  it("keeps a session across requests", async () => {
    await start();
    const sid = await initialize();
    expect(http!.sessionCount()).toBe(1);
    expect(await ping(sid)).toBe(200);
  });

  it("answers 404 for a session it does not hold, so the client re-initializes", async () => {
    await start();
    expect(await ping("00000000-0000-0000-0000-000000000000")).toBe(404);
    expect(http!.sessionCount()).toBe(0);
  });

  it("refuses a GET without a session and does not create one", async () => {
    await start();
    const res = await fetch(base, { method: "GET", headers: { accept: "text/event-stream" } });
    await res.text();
    expect(res.status).toBe(400);
    expect(http!.sessionCount()).toBe(0);
  });

  it("closes a session that sends DELETE", async () => {
    await start();
    const sid = await initialize();
    const res = await fetch(base, {
      method: "DELETE",
      headers: { "mcp-session-id": sid, "mcp-protocol-version": "2025-03-26" },
    });
    await res.text();
    expect(res.status).toBe(200);
    expect(http!.sessionCount()).toBe(0);
  });

  it("closes sessions left idle past the timeout and keeps recent ones", async () => {
    await start({ idleMs: 60_000 });
    const abandoned = await initialize();
    await settle();
    clock += 45_000;
    const recent = await initialize();
    await settle();
    clock += 30_000;

    expect(await http!.sweep()).toBe(1);
    expect(http!.sessionCount()).toBe(1);
    expect(await ping(abandoned)).toBe(404);
    expect(await ping(recent)).toBe(200);
  });

  it("counts a request as activity", async () => {
    await start({ idleMs: 60_000 });
    const sid = await initialize();
    await settle();
    clock += 50_000;
    expect(await ping(sid)).toBe(200);
    await settle();
    clock += 50_000;
    expect(await http!.sweep()).toBe(0);
    expect(await ping(sid)).toBe(200);
  });

  it("closes the least recently used session when the cap is reached", async () => {
    await start({ maxSessions: 2 });
    const first = await initialize();
    await settle();
    clock += 1_000;
    const second = await initialize();
    await settle();
    clock += 1_000;
    expect(await ping(first)).toBe(200);
    await settle();
    clock += 1_000;

    const third = await initialize();
    expect(http!.sessionCount()).toBe(2);
    expect(await ping(second)).toBe(404);
    expect(await ping(first)).toBe(200);
    expect(await ping(third)).toBe(200);
  });

  it("keeps a session with an open stream past the idle timeout, then closes it past the busy limit", async () => {
    await start({ idleMs: 60_000, busyMs: 600_000 });
    const sid = await initialize();
    await settle();
    const stream = await openStream(sid);
    clock += 120_000;
    expect(await http!.sweep()).toBe(0);
    expect(http!.sessionCount()).toBe(1);

    clock += 600_000;
    expect(await http!.sweep()).toBe(1);
    expect(http!.sessionCount()).toBe(0);
    stream.abort();
  });

  it("lets a new client in when every slot holds an open stream", async () => {
    await start({ maxSessions: 2 });
    const first = await initialize();
    await settle();
    const s1 = await openStream(first);
    clock += 1_000;
    const second = await initialize();
    await settle();
    const s2 = await openStream(second);
    clock += 1_000;

    const third = await initialize();
    expect(http!.sessionCount()).toBe(2);
    expect(await ping(first)).toBe(404);
    expect(await ping(third)).toBe(200);
    s1.abort();
    s2.abort();
  });

  it("prefers closing an idle session over one with an open stream", async () => {
    await start({ maxSessions: 2 });
    const streaming = await initialize();
    await settle();
    const s1 = await openStream(streaming);
    clock += 1_000;
    const idle = await initialize();
    await settle();
    clock += 1_000;

    await initialize();
    expect(await ping(idle)).toBe(404);
    expect(await ping(streaming)).toBe(200);
    s1.abort();
  });

  it("holds the cap under concurrent initializes", async () => {
    await start({ maxSessions: 3 });
    const results = await Promise.all(
      Array.from({ length: 12 }, async () => {
        const res = await fetch(base, { method: "POST", headers: HEADERS, body: JSON.stringify(INIT_BODY) });
        await res.text();
        return res.status;
      })
    );
    await settle();
    expect(results.every((status) => status === 200 || status === 503)).toBe(true);
    expect(http!.sessionCount()).toBeLessThanOrEqual(3);
  });

  it("does not keep a session for an initialize the transport rejects", async () => {
    await start();
    const res = await fetch(base, {
      method: "POST",
      headers: { "content-type": "application/json", accept: "application/json" },
      body: JSON.stringify(INIT_BODY),
    });
    await res.text();
    expect(res.status).toBe(406);
    await settle();
    expect(http!.sessionCount()).toBe(0);
  });
});
