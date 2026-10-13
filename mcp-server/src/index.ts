#!/usr/bin/env node
/**
 * Entry point for the MyOTP MCP server.
 *
 * Picks transport based on CLI args / env vars:
 *   - default: stdio (for local agent installs — Claude Desktop, Claude Code, Cursor)
 *   - --http (or MYOTP_MCP_TRANSPORT=http): streamable HTTP (for hosting at mcp.myotp.app)
 */

import { StdioServerTransport } from "@modelcontextprotocol/sdk/server/stdio.js";
import {
  createHttpApp,
  DEFAULT_MAX_SESSIONS,
  DEFAULT_SESSION_BUSY_MS,
  DEFAULT_SESSION_IDLE_MS,
} from "./http.js";
import { createServer } from "./server.js";

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

function positiveEnv(name: string, fallback: number): number {
  const value = Number(process.env[name]);
  return Number.isFinite(value) && value > 0 ? value : fallback;
}

async function runHttp(config: CliConfig): Promise<void> {
  const idleMs = positiveEnv("MCP_SESSION_IDLE_MS", DEFAULT_SESSION_IDLE_MS);
  const busyMs = positiveEnv("MCP_SESSION_BUSY_MS", DEFAULT_SESSION_BUSY_MS);
  const maxSessions = positiveEnv("MCP_MAX_SESSIONS", DEFAULT_MAX_SESSIONS);
  const http = createHttpApp({ path: config.path, idleMs, busyMs, maxSessions });

  const sweepTimer = setInterval(() => {
    void http.sweep();
  }, Math.min(idleMs, 60_000));
  sweepTimer.unref();

  http.app.listen(config.port, config.host, () => {
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
