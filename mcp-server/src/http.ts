/**
 * Streamable HTTP transport for the hosted server.
 *
 * Each MCP session owns a transport and a server instance. Most clients never
 * send DELETE when they are done, so sessions are also closed after a period
 * with no requests, and a session whose request (usually an SSE stream) has
 * been open past busyMs is closed too. At the session cap a new initialize
 * closes the least recently used session, idle ones first, so open streams
 * cannot lock new clients out. A request for a session we no longer hold gets
 * 404, which the spec defines as the signal to initialize a new session.
 */

import { randomUUID } from "node:crypto";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import { isInitializeRequest } from "@modelcontextprotocol/sdk/types.js";
import express, { type Express, type Request, type Response } from "express";
import { createServer, resolveApiKeyFromHeaders, resolveApiKeyFromQuery } from "./server.js";

export const DEFAULT_SESSION_IDLE_MS = 30 * 60 * 1000;
export const DEFAULT_SESSION_BUSY_MS = 60 * 60 * 1000;
export const DEFAULT_MAX_SESSIONS = 1000;

export interface HttpAppOptions {
  path: string;
  /** Close a session after this long with no request in flight. */
  idleMs?: number;
  /** Close a session whose oldest open request started longer ago than this. */
  busyMs?: number;
  /** Most sessions held at once, counting initializes still in progress. */
  maxSessions?: number;
  now?: () => number;
}

interface Session {
  transport: StreamableHTTPServerTransport;
  lastSeen: number;
  inFlight: number;
}

export interface HttpApp {
  app: Express;
  sessionCount(): number;
  /** Close sessions idle past idleMs or busy past busyMs. Returns how many were closed. */
  sweep(): Promise<number>;
  closeAll(): Promise<void>;
}

function jsonRpcError(res: Response, status: number, message: string): void {
  res.status(status).json({ jsonrpc: "2.0", error: { code: -32000, message }, id: null });
}

export function createHttpApp(options: HttpAppOptions): HttpApp {
  const idleMs = options.idleMs ?? DEFAULT_SESSION_IDLE_MS;
  const busyMs = options.busyMs ?? DEFAULT_SESSION_BUSY_MS;
  const maxSessions = options.maxSessions ?? DEFAULT_MAX_SESSIONS;
  const now = options.now ?? Date.now;

  const app = express();
  app.use(express.json({ limit: "1mb" }));

  const sessions = new Map<string, Session>();
  // Initializes between transport creation and onsessioninitialized.
  let pending = 0;

  app.get("/healthz", (_req: Request, res: Response) => {
    res.json({ ok: true, server: "myotp-mcp" });
  });

  // Each request must carry its own key so one hosted server can serve many
  // tenants; we deliberately do NOT fall back to MYOTP_API_KEY in HTTP mode.
  // See resolveApiKeyFromHeaders for why Authorization: Bearer is also accepted.
  const resolveApiKey = (extra: {
    headers?: Record<string, string | string[] | undefined>;
    url?: string;
  }): string => resolveApiKeyFromHeaders(extra.headers) || resolveApiKeyFromQuery(extra.url);

  // Removal from the map is synchronous so callers can decide and remove
  // without yielding; the transport closes in the background.
  const closeSession = (sid: string): Promise<void> => {
    const session = sessions.get(sid);
    if (!session) return Promise.resolve();
    sessions.delete(sid);
    return session.transport.close().catch(() => undefined);
  };

  const isExpired = (s: Session, at: number): boolean =>
    s.inFlight === 0 ? s.lastSeen <= at - idleMs : s.lastSeen <= at - busyMs;

  // Make room for one more session without yielding between the check and
  // the caller's pending += 1, so concurrent initializes cannot overshoot.
  const evictForNewSession = (): void => {
    while (sessions.size > 0 && sessions.size + pending >= maxSessions) {
      let victim: [string, Session] | undefined;
      for (const entry of sessions) {
        const [, s] = entry;
        if (
          !victim ||
          (s.inFlight === 0 && victim[1].inFlight > 0) ||
          ((s.inFlight === 0) === (victim[1].inFlight === 0) && s.lastSeen < victim[1].lastSeen)
        ) {
          victim = entry;
        }
      }
      void closeSession(victim![0]);
    }
  };

  const track = (session: Session, res: Response): void => {
    session.inFlight += 1;
    session.lastSeen = now();
    res.on("close", () => {
      session.inFlight -= 1;
      session.lastSeen = now();
    });
  };

  const handleMcpRequest = async (req: Request, res: Response): Promise<void> => {
    const sessionId = req.header("mcp-session-id") ?? undefined;

    // Gateways that pass config in the URL: lift the key into the header the
    // tools read, unless a header already carries one.
    if (!resolveApiKeyFromHeaders(req.headers)) {
      const fromQuery = resolveApiKeyFromQuery(req.originalUrl ?? req.url);
      if (fromQuery) req.headers["x-api-key"] = fromQuery;
    }

    if (sessionId) {
      const session = sessions.get(sessionId);
      if (!session) {
        jsonRpcError(res, 404, "Session not found. Send a new initialize request.");
        return;
      }
      track(session, res);
      await session.transport.handleRequest(req, res, req.body);
      return;
    }

    // New session: only allowed if the body is an MCP `initialize` request.
    if (req.method !== "POST" || !isInitializeRequest(req.body)) {
      jsonRpcError(res, 400, "Bad Request: no Mcp-Session-Id and request is not an initialize call.");
      return;
    }
    if (pending >= maxSessions) {
      jsonRpcError(res, 503, "Server is at its session limit. Try again shortly.");
      return;
    }
    evictForNewSession();
    pending += 1;
    let counted = true;
    const uncount = (): void => {
      if (counted) pending -= 1;
      counted = false;
    };

    const session: Session = { transport: undefined as never, lastSeen: now(), inFlight: 0 };
    const transport = new StreamableHTTPServerTransport({
      sessionIdGenerator: () => randomUUID(),
      onsessioninitialized: (sid: string) => {
        uncount();
        sessions.set(sid, session);
      },
    });
    session.transport = transport;
    transport.onclose = () => {
      if (transport.sessionId && sessions.get(transport.sessionId) === session) {
        sessions.delete(transport.sessionId);
      }
    };

    // A transport that never initialized (bad Accept header, invalid body)
    // was never registered: close it so nothing outlives the request.
    res.on("close", () => {
      if (!transport.sessionId) void transport.close().catch(() => undefined);
    });

    try {
      const server = createServer({ resolveApiKey });
      await server.connect(transport);
      track(session, res);
      await transport.handleRequest(req, res, req.body);
    } finally {
      uncount();
    }
  };

  app.post(options.path, handleMcpRequest);
  app.get(options.path, handleMcpRequest);
  app.delete(options.path, handleMcpRequest);

  return {
    app,
    sessionCount: () => sessions.size,
    sweep: async () => {
      const at = now();
      const closing: Promise<void>[] = [];
      for (const [sid, s] of sessions) {
        if (isExpired(s, at)) closing.push(closeSession(sid));
      }
      await Promise.all(closing);
      return closing.length;
    },
    closeAll: async () => {
      await Promise.all([...sessions.keys()].map(closeSession));
    },
  };
}
