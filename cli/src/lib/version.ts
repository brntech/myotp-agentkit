/**
 * The CLI's own version, read from package.json at runtime.
 *
 * It used to be a hand-maintained constant in index.ts, which drifted: the
 * constant said 0.1.5 while 0.1.6 was the published version, so `myotp
 * --version` reported the wrong release.
 *
 * `../../package.json` resolves to the package root from both `src/lib/` (when
 * vitest loads the TypeScript directly) and `dist/lib/` (the built output), so
 * the same specifier works in tests and in the published package.
 */

import { createRequire } from 'node:module';

export const VERSION: string = (
  createRequire(import.meta.url)('../../package.json') as { version: string }
).version;
