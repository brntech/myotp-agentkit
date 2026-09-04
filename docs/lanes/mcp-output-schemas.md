# Lane: mcp-output-schemas (mcp-server 0.1.11)

Branch `mcp-output-schemas`, local only. Files touched: `mcp-server/` only.

## What changed

- `ToolDefinition` gained `outputSchema?: ZodRawShape` (`src/tools/types.ts`).
- `server.ts` registers `z.object(outputSchema).passthrough()` when a tool declares one, so the
  advertised JSON schema is `type: object` with `additionalProperties: true` and fields the API
  adds later never fail the SDK's per-call validation.
- All ten tools declare an output schema modelled on the handler's real `structuredContent`
  and `openapi-reference.yaml`. Fields the API may omit are optional. `top_up_credits` returns
  three different objects (dry run, 402 challenge, credited), so its schema is one object with
  every field optional and per-field notes on which result carries it.
- `helpers.ts` exports `toolErrorShape` / `toolErrorSchema` describing what `toToolError` builds:
  `{ error, status?, endpoint?, body? }`.
- README: one sentence under the tools table.
- Version 0.1.11 in `package.json`, `server.json` (both fields) and the lockfile root.

## Deviation from the brief, and why

The brief asked for a union of the success object with the error object. The MCP `Tool` schema
in SDK 1.29 (`types.js`, `outputSchema: z.object({ type: z.literal('object'), ... })`) requires
the advertised output schema to be a single object type, so a top-level `anyOf` would be rejected
by strict clients. The SDK also skips output validation entirely when `isError` is true
(`server/mcp.js`, `validateToolOutput`), so an error result can never fail validation. The
schemas therefore describe the success shape only; the error shape lives in `toolErrorSchema`,
is documented in the README sentence, and is proven end to end by the server test below and by
the smoke test.

## Tests

- `tests/tools/output_schemas.test.ts` (23 tests): each tool's handler runs against the existing
  fake-client / mock-fetch fixtures and its `structuredContent` must parse under its schema
  (including the alternative shapes: verify failed, unknown message_id, report no-data, top-up
  dry run / 402 / credited). Each tool's API-error result must parse under `toolErrorSchema`.
- `tests/server.test.ts`: every definition and every SDK-registered tool has an outputSchema;
  through an in-memory MCP client, `tools/list` advertises `type: object` with
  `additionalProperties: true` for all ten, a success call returns validated `structuredContent`,
  and an API error returns an `isError` result rather than an output validation error.

## Gate

```
MSYS_NO_PATHCONV=1 docker run --rm -v "C:/Users/mj/worktrees/agentkit-mcp-output-schemas:/w" -w /w/mcp-server node:22-alpine sh -c "npm ci --no-audit --no-fund && npm test && npm run build" > lane-test.log 2>&1; echo exit=$?
```

Result: `exit=0`. 9 files, 124 tests passed (was 76), `tsc` clean.

## Runtime smoke

Run in the same image after the build:

```sh
MSYS_NO_PATHCONV=1 docker run --rm -v "C:/Users/mj/worktrees/agentkit-mcp-output-schemas:/w" node:22-alpine sh /w/smoke.sh
```

`smoke.sh` (not committed):

```sh
set -e
cd /w/mcp-server
node dist/index.js --http --port 8090 --host 127.0.0.1 > /tmp/server.log 2>&1 &
for i in $(seq 1 30); do wget -q -O /dev/null http://127.0.0.1:8090/healthz && break; sleep 1; done
init='{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"smoke","version":"0"}}}'
SID=$(wget -q -S -O /tmp/init.json --header "Content-Type: application/json" --header "Accept: application/json, text/event-stream" --header "X-API-Key: bogus_key_for_smoke_test_0000000" --post-data "$init" http://127.0.0.1:8090/mcp 2>&1 | grep -i 'mcp-session-id' | awk '{print $2}' | tr -d '\r')
post() { wget -q -O - --header "Content-Type: application/json" --header "Accept: application/json, text/event-stream" --header "X-API-Key: bogus_key_for_smoke_test_0000000" --header "Mcp-Session-Id: $SID" --post-data "$1" http://127.0.0.1:8090/mcp; }
post '{"jsonrpc":"2.0","method":"notifications/initialized"}' || true
post '{"jsonrpc":"2.0","id":2,"method":"tools/list","params":{}}' | sed -n 's/^data: //p' > /tmp/list.json
node -e '
const r=JSON.parse(require("fs").readFileSync("/tmp/list.json","utf8"));
const tools=r.result.tools; let bad=[];
for(const t of tools){ if(!t.outputSchema||t.outputSchema.type!=="object") bad.push(t.name); console.log(t.name, "outputSchema.type="+(t.outputSchema&&t.outputSchema.type), "props="+Object.keys((t.outputSchema||{}).properties||{}).length, "additionalProperties="+(t.outputSchema||{}).additionalProperties); }
console.log("tools="+tools.length+" missing="+JSON.stringify(bad));
if(tools.length!==10||bad.length) process.exit(1);'
post '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"get_account_info","arguments":{}}}' | sed -n 's/^data: //p' > /tmp/call.json
node -e '
const r=JSON.parse(require("fs").readFileSync("/tmp/call.json","utf8"));
console.log(JSON.stringify(r));
if(r.error){console.log("PROTOCOL ERROR");process.exit(1);}
if(r.result.isError!==true){console.log("expected isError");process.exit(1);}
if(typeof r.result.structuredContent.error!=="string"){console.log("bad error shape");process.exit(1);}
console.log("SMOKE OK: isError result with error shape");'
```

Output (exit 0):

```
[myotp-mcp] HTTP listening on http://127.0.0.1:8090/mcp
session=3d17fd45-c08d-463b-94dd-54ff633a2cd8
data: {"result":{"protocolVersion":"2025-06-18","capabilities":{"tools":{"listChanged":true}},"serverInfo":{"name":"myotp-mcp","version":"0.1.11"}, ...
generate_otp outputSchema.type=object props=7 additionalProperties=true
verify_otp outputSchema.type=object props=3 additionalProperties=true
check_otp_status outputSchema.type=object props=6 additionalProperties=true
extend_otp outputSchema.type=object props=3 additionalProperties=true
get_account_info outputSchema.type=object props=1 additionalProperties=true
get_usage_report outputSchema.type=object props=6 additionalProperties=true
create_account outputSchema.type=object props=11 additionalProperties=true
get_account_status outputSchema.type=object props=5 additionalProperties=true
get_topup_quote outputSchema.type=object props=9 additionalProperties=true
top_up_credits outputSchema.type=object props=13 additionalProperties=true
tools=10 missing=[]
{"result":{"content":[{"type":"text","text":"Failed to fetch account info: MyOTP API error (401): Invalid API key"}],"structuredContent":{"error":"MyOTP API error (401): Invalid API key","status":401,"endpoint":"/me","body":{"error":{"http_code":401,"message":"Invalid API key"}}},"isError":true},"jsonrpc":"2.0","id":3}
SMOKE OK: isError result with error shape
```

The bogus-key call reached the live `api.myotp.app` (no `MYOTP_BASE_URL` override) and came back
as a 401 wrapped in an `isError` result, not a JSON-RPC error.

## Not done

Nothing in scope was left out. Publishing (npm tag, MCP registry, hosted endpoint) is
integrator-only.
