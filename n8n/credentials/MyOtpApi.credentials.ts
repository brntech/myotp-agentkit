import type {
	IAuthenticateGeneric,
	ICredentialTestRequest,
	ICredentialType,
	INodeProperties,
} from 'n8n-workflow';

export class MyOtpApi implements ICredentialType {
	name = 'myOtpApi';

	displayName = 'MyOTP API';

	documentationUrl = 'https://github.com/brntech/myotp-agentkit/tree/main/n8n#credentials';

	properties: INodeProperties[] = [
		{
			displayName: 'API Key',
			name: 'apiKey',
			type: 'string',
			typeOptions: { password: true },
			default: '',
			description:
				'Your MyOTP.App API key. Get one at https://myotp.app/sign-up/ or with POST /v1/agent/register.',
		},
	];

	authenticate: IAuthenticateGeneric = {
		type: 'generic',
		properties: {
			headers: {
				'X-API-Key': '={{$credentials.apiKey}}',
			},
		},
	};

	test: ICredentialTestRequest = {
		request: {
			baseURL: 'https://api.myotp.app',
			url: '/me',
			method: 'GET',
		},
	};
}
