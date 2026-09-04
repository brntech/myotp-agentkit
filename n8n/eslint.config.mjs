import n8nNodesBase from 'eslint-plugin-n8n-nodes-base';
import tsParser from '@typescript-eslint/parser';
import jsoncParser from 'jsonc-eslint-parser';

const tsFiles = {
	languageOptions: {
		parser: tsParser,
		parserOptions: { project: './tsconfig.json', sourceType: 'module' },
	},
	plugins: { 'n8n-nodes-base': n8nNodesBase },
};

export default [
	{ ignores: ['dist/**', 'node_modules/**', 'tests/**', 'scripts/**', '*.config.*'] },
	{
		files: ['package.json'],
		languageOptions: { parser: jsoncParser },
		plugins: { 'n8n-nodes-base': n8nNodesBase },
		rules: n8nNodesBase.configs.community.rules,
	},
	{
		...tsFiles,
		files: ['credentials/**/*.ts'],
		rules: {
			...n8nNodesBase.configs.credentials.rules,
			// Community credentials link to a public docs URL, as in n8n-nodes-starter.
			'n8n-nodes-base/cred-class-field-documentation-url-miscased': 'off',
		},
	},
	{
		...tsFiles,
		files: ['nodes/**/*.ts'],
		rules: {
			...n8nNodesBase.configs.nodes.rules,
			// Current n8n docs use NodeConnectionTypes.Main; these rules predate it.
			'n8n-nodes-base/node-class-description-inputs-wrong-regular-node': 'off',
			'n8n-nodes-base/node-class-description-outputs-wrong': 'off',
		},
	},
];
