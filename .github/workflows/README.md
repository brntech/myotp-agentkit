# GitHub Actions for AgentKit packages

> These workflows assume **the AgentKit folder is the repo root** (e.g. cloning `brntech/myotp-agentkit` puts you directly into a directory containing `mcp-server/`, `cli/`, `better-auth/`, etc.).

Each TypeScript package has its own workflow that:
- Runs on push/PR when files in that package change
- Builds + tests on Node 20 + 22 (Better Auth also tests Node 18 since it's a peer-dep library)
- Auto-publishes to npm via **Trusted Publishing (OIDC)** when a tag matching `<package>@<version>` is pushed

## Trusted Publishing (no NPM_TOKEN needed)

Once the packages exist on npm, configure each one's "Trusted Publishers" page on npmjs.com:

| Package | Provider | Repo | Workflow filename | Environment |
|---------|----------|------|-------------------|-------------|
| `@myotp/mcp` | GitHub Actions | `brntech/myotp-agentkit` | `mcp-server.yml` | `production` |
| `@myotp/cli` | GitHub Actions | `brntech/myotp-agentkit` | `cli.yml` | `production` |
| `@myotp/better-auth` | GitHub Actions | `brntech/myotp-agentkit` | `better-auth.yml` | `production` |

Each `publish` job already declares `environment: production` and `permissions: id-token: write` — that's everything OIDC needs. No `NPM_TOKEN` secret required.

## First publish (bootstrap)

Trusted Publishing is configured for all three packages. A new package needs one manual `npm publish` before its trusted publisher can be saved.

## Releasing (after Trusted Publishing is configured)

```bash
# Example: ship mcp-server 0.2.0
cd mcp-server
# bump version in package.json then:
git commit -am "mcp-server: bump to 0.2.0"
git tag mcp-server@0.2.0
git push origin main mcp-server@0.2.0
```

The `production` environment in GitHub repo settings can require manual approval — adds a one-click confirmation gate before each publish actually runs.

