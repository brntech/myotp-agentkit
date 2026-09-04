// Run @n8n/scan-community-package's static analysis on a local `npm pack`
// output and on the TypeScript sources. The scanner CLI only accepts a
// published npm name, so this calls its exported analyzePackage() instead.
//
// Usage (see README "Development"):
//   node scripts/scan-package.mjs <unpacked-tarball-dir> <source-dir>
// with SCANNER_DIR pointing at a directory where
// @n8n/scan-community-package is installed (default /scan).
import { pathToFileURL } from 'node:url';
import { join } from 'node:path';

const scannerDir = process.env.SCANNER_DIR ?? '/scan';
const modPath = join(scannerDir, 'node_modules/@n8n/scan-community-package/scanner/scanner.mjs');
const { analyzePackage } = await import(pathToFileURL(modPath).href);

const [packageDir, sourceDir] = process.argv.slice(2);
if (!packageDir || !sourceDir) {
	console.error('usage: node scripts/scan-package.mjs <unpacked-tarball-dir> <source-dir>');
	process.exit(1);
}

const dist = await analyzePackage(packageDir, ['**/*.js', 'package.json']);
console.log('DIST_SCAN', JSON.stringify(dist, null, 2));
const src = await analyzePackage(sourceDir, ['**/*.ts', '**/*.js', 'package.json']);
console.log('SOURCE_SCAN', JSON.stringify(src, null, 2));
process.exit(dist.passed && src.passed ? 0 : 2);
