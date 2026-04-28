#!/usr/bin/env node
/**
 * Entry point for the MyOTP MCP server.
 *
 * Picks transport based on CLI args / env vars:
 *   - default: stdio (for local agent installs — Claude Desktop, Claude Code, Cursor)
 *   - --http (or MYOTP_MCP_TRANSPORT=http): streamable HTTP (for hosting at mcp.myotp.app)
 */

import { randomUUID } from "node:crypto";
import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import { StreamableHTTPServerTransport } from "@modelcontextprotocol/sdk/server/streamableHttp.js";
import { isInitializeRequest } from "@modelcontextprotocol/sdk/types.js";
import express, { type Request, type Response } from "express";
import { createServer, getHeader } from "./server.js";

interface CliConfig {
  transport: "stdio" | "http";
  port: number;
  host: string;
  path: string;
}

function parseArgs(argv: string[]): CliConfig {
  const args = argv.slice(2);
  const envTransport = process.env.MYOTP_MCP_TRANSPORT?.toLowerCase();
  let transport: "stdio" | "http" =
    args.includes("--http") || envTransport === "http" ? "http" : "stdio";
  if (args.includes("--stdio") || envTransport === "stdio") transport = "stdio";

  const portArg = readArgValue(args, "--port") ?? process.env.PORT;
  const hostArg = readArgValue(args, "--host") ?? process.env.HOST;
  const pathArg = readArgValue(args, "--path") ?? process.env.MCP_PATH;

  return {
    transport,
    port: portArg ? Number(portArg) : 3000,
    host: hostArg ?? "0.0.0.0",
    path: pathArg ?? "/mcp",
  };
}

function readArgValue(args: string[], flag: string): string | undefined {
  const idx = args.indexOf(flag);
  if (idx >= 0 && idx + 1 < args.length) return args[idx + 1];
  for (const a of args) {
    if (a.startsWith(`${flag}=`)) return a.slice(flag.length + 1);
  }
  return undefined;
}

async function runStdio(): Promise<void> {
  const server = createServer({
    resolveApiKey: () => process.env.MYOTP_API_KEY ?? "",
  });
  const transport = new StdioServerTransport();
  await server.connect(transport);
  // stdio servers must not write to stdout (it's the protocol channel).
  // stderr is fine.
  process.stderr.write(`[myotp-mcp] running on stdio (base url: ${process.env.MYOTP_BASE_URL ?? "https://api.myotp.app"})\n`);
}

async function runHttp(config: CliConfig): Promise<void> {
  const app = express();
  app.use(express.json({ limit: "1mb" }));

  // Per the MCP spec, each session has its own transport instance. We key them
  // by Mcp-Session-Id (issued on initialize) so subsequent requests reuse the
  // same transport and preserve protocol state.
  const transports = new Map<string, StreamableHTTPServerTransport>();

  app.get("/healthz", (_req: Request, res: Response) => {
    res.json({ ok: true, server: "myotp-mcp" });
  });

  // Resolve API key from the X-API-Key header sent by the agent. We deliberately
  // do NOT read MYOTP_API_KEY in HTTP mode — each request must carry its own key
  // so a single hosted server can serve many tenants.
  const resolveApiKey = (extra: {
    headers?: Record<string, string | string[] | undefined>;
  }): string => {
    return getHeader(extra.headers, "x-api-key") ?? "";
  };

  const handleMcpRequest = async (req: Request, res: Response): Promise<void> => {
    const sessionId = req.header("mcp-session-id") ?? undefined;

    let transport: StreamableHTTPServerTransport | undefined = sessionId
      ? transports.get(sessionId)
      : undefined;

    if (!transport) {
      // New session — only allowed if the body is an MCP `initialize` request.
      if (req.method === "POST" && !isInitializeRequest(req.body)) {
        res.status(400).json({
          jsonrpc: "2.0",
          error: {
            code: -32000,
            message: "Bad Request: no Mcp-Session-Id and request is not an initialize call.",
          },
          id: null,
        });
        return;
      }

      transport = new StreamableHTTPServerTransport({
        sessionIdGenerator: () => randomUUID(),
        onsessioninitialized: (sid: string) => {
          if (transport) transports.set(sid, transport);
        },
      });
      transport.onclose = () => {
        if (transport?.sessionId) transports.delete(transport.sessionId);
      };

      const server = createServer({ resolveApiKey });
      await server.connect(transport);
    }

    await transport.handleRequest(req, res, req.body);
  };

  app.post(config.path, handleMcpRequest);
  app.get(config.path, handleMcpRequest);
  app.delete(config.path, handleMcpRequest);

  app.listen(config.port, config.host, () => {
    process.stderr.write(
      `[myotp-mcp] HTTP listening on http://${config.host}:${config.port}${config.path}\n`
    );
  });
}

async function main(): Promise<void> {
  const config = parseArgs(process.argv);
  if (config.transport === "http") {
    await runHttp(config);
  } else {
    await runStdio();
  }
}

main().catch((err) => {
  process.stderr.write(`[myotp-mcp] fatal: ${err instanceof Error ? err.stack ?? err.message : String(err)}\n`);
  process.exit(1);
});
