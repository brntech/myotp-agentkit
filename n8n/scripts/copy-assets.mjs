// Copy the non-TypeScript assets (icon, codex json) next to the compiled node.
import { copyFileSync, mkdirSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

const pairs = [['nodes/MyOtp', 'dist/nodes/MyOtp']];
for (const [src, dst] of pairs) {
	mkdirSync(dst, { recursive: true });
	for (const file of readdirSync(src)) {
		if (file.endsWith('.svg') || file.endsWith('.json')) {
			copyFileSync(join(src, file), join(dst, file));
		}
	}
}
