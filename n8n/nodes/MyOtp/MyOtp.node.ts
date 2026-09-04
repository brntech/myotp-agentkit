import type {
	IDataObject,
	IExecuteFunctions,
	IHttpRequestOptions,
	INodeExecutionData,
	INodeType,
	INodeTypeDescription,
	JsonObject,
} from 'n8n-workflow';
import { NodeApiError, NodeConnectionTypes } from 'n8n-workflow';

import { buildRequest, describeApiError } from './transport';
import type { Operation, OperationParams } from './transport';

export class MyOtp implements INodeType {
	description: INodeTypeDescription = {
		displayName: 'MyOTP',
		name: 'myOtp',
		icon: 'file:myotp.svg',
		group: ['transform'],
		version: 1,
		subtitle: '={{$parameter["operation"]}}',
		description:
			'Send and verify one-time passcodes over SMS, WhatsApp and Telegram with MyOTP.App',
		defaults: {
			name: 'MyOTP',
		},
		usableAsTool: true,
		inputs: [NodeConnectionTypes.Main],
		outputs: [NodeConnectionTypes.Main],
		credentials: [
			{
				name: 'myOtpApi',
				required: true,
			},
		],
		properties: [
			{
				displayName: 'Operation',
				name: 'operation',
				type: 'options',
				noDataExpression: true,
				options: [
					{
						name: 'Account',
						value: 'account',
						description: 'Return the account the API key belongs to (GET /me)',
						action: 'Get the account',
					},
					{
						name: 'Check OTP Status',
						value: 'checkOtpStatus',
						description: 'Delivery report for a sent OTP and whether the code is still active',
						action: 'Check OTP status',
					},
					{
						name: 'Extend OTP',
						value: 'extendOtp',
						description: 'Extend the expiry of an existing OTP',
						action: 'Extend an OTP',
					},
					{
						name: 'Send OTP',
						value: 'sendOtp',
						description: 'Generate a one-time passcode and send it to a phone number',
						action: 'Send an OTP',
					},
					{
						name: 'Usage Report',
						value: 'report',
						description: 'List transactions in a date range (last 7 days by default)',
						action: 'Get a usage report',
					},
					{
						name: 'Verify OTP',
						value: 'verifyOtp',
						description: 'Check a code the user typed in',
						action: 'Verify an OTP',
					},
				],
				default: 'sendOtp',
			},

			// ----- Send OTP -----
			{
				displayName: 'Phone Number',
				name: 'phoneNumber',
				type: 'string',
				required: true,
				default: '',
				placeholder: '19876543210',
				description:
					'Destination number in E.164 order, digits only, country code first, no plus sign',
				displayOptions: { show: { operation: ['sendOtp'] } },
			},
			{
				displayName: 'Channel',
				name: 'channel',
				type: 'options',
				options: [
					{ name: 'SMS', value: 'sms' },
					{ name: 'Telegram', value: 'telegram' },
					{ name: 'WhatsApp', value: 'whatsapp' },
				],
				default: 'sms',
				description: 'Delivery channel for the OTP',
				displayOptions: { show: { operation: ['sendOtp'] } },
			},
			{
				displayName: 'Additional Fields',
				name: 'additionalFields',
				type: 'collection',
				placeholder: 'Add Field',
				default: {},
				displayOptions: { show: { operation: ['sendOtp'] } },
				options: [
					{
						displayName: 'Brand',
						name: 'brand',
						type: 'string',
						default: '',
						description:
							'Brand name shown in the message. 3 to 16 characters, letters, digits and dots. Screened against a denylist. Overrides the account and app defaults.',
					},
					{
						displayName: 'Force Send',
						name: 'force_send',
						type: 'boolean',
						default: false,
						description:
							'Whether to send even if an unexpired OTP already exists for this phone number',
					},
					{
						displayName: 'OTP Length',
						name: 'otp_length',
						type: 'number',
						typeOptions: { minValue: 4, maxValue: 8 },
						default: 6,
						description: 'Number of digits in the code, 4 to 8',
					},
					{
						displayName: 'OTP Validity (Seconds)',
						name: 'otp_validity',
						type: 'number',
						typeOptions: { minValue: 60, maxValue: 86400 },
						default: 300,
						description: 'How long the code stays valid, 60 to 86400 seconds. SMS only.',
					},
					{
						displayName: 'Return OTP',
						name: 'return_otp',
						type: 'boolean',
						default: false,
						description:
							'Whether to include the code in the response. Not compliant with regulations in many markets.',
					},
					{
						displayName: 'Template Order',
						name: 'template_order',
						type: 'number',
						typeOptions: { minValue: 1, maxValue: 5 },
						default: 1,
						description: 'Which message template to use, 1 to 5',
					},
				],
			},

			// ----- Verify OTP -----
			{
				displayName: 'OTP',
				name: 'otp',
				type: 'string',
				required: true,
				default: '',
				placeholder: '123456',
				description: 'The code the user typed in',
				displayOptions: { show: { operation: ['verifyOtp'] } },
			},
			{
				displayName: 'Additional Fields',
				name: 'additionalFields',
				type: 'collection',
				placeholder: 'Add Field',
				default: {},
				displayOptions: { show: { operation: ['verifyOtp'] } },
				options: [
					{
						displayName: 'Message ID',
						name: 'message_id',
						type: 'string',
						default: '',
						description: 'The message_id returned by Send OTP',
					},
					{
						displayName: 'Phone Number',
						name: 'phone_number',
						type: 'string',
						default: '',
						description: 'The phone number the code was sent to, digits only',
					},
				],
			},

			// ----- Extend OTP / Check OTP Status -----
			{
				displayName: 'Message ID',
				name: 'messageId',
				type: 'string',
				required: true,
				default: '',
				description: 'The message_id returned by Send OTP',
				displayOptions: { show: { operation: ['extendOtp', 'checkOtpStatus'] } },
			},
			{
				displayName: 'Duration (Seconds)',
				name: 'duration',
				type: 'number',
				required: true,
				typeOptions: { minValue: 60, maxValue: 14400 },
				default: 300,
				description: 'Seconds to extend the expiry by, 60 to 14400',
				displayOptions: { show: { operation: ['extendOtp'] } },
			},

			// ----- Usage Report -----
			{
				displayName: 'Additional Fields',
				name: 'additionalFields',
				type: 'collection',
				placeholder: 'Add Field',
				default: {},
				displayOptions: { show: { operation: ['report'] } },
				options: [
					{
						displayName: 'End Date',
						name: 'end_date',
						type: 'string',
						default: '',
						placeholder: '2026-09-04',
						description: 'End of the range, YYYY-MM-DD. Defaults to today.',
					},
					{
						displayName: 'Page',
						name: 'page',
						type: 'number',
						typeOptions: { minValue: 1 },
						default: 1,
						description: 'Page number to fetch',
					},
					{
						displayName: 'Per Page',
						name: 'per_page',
						type: 'number',
						typeOptions: { minValue: 1, maxValue: 100 },
						default: 10,
						description: 'Items per page, 1 to 100',
					},
					{
						displayName: 'Start Date',
						name: 'start_date',
						type: 'string',
						default: '',
						placeholder: '2026-08-28',
						description: 'Start of the range, YYYY-MM-DD. Defaults to 7 days ago.',
					},
				],
			},
		],
	};

	async execute(this: IExecuteFunctions): Promise<INodeExecutionData[][]> {
		const items = this.getInputData();
		const returnData: INodeExecutionData[] = [];
		const operation = this.getNodeParameter('operation', 0) as Operation;

		for (let i = 0; i < items.length; i++) {
			try {
				const params = collectParams.call(this, operation, i);
				const built = buildRequest(operation, params);

				const options: IHttpRequestOptions = {
					method: built.method,
					url: built.url,
					headers: { Accept: 'application/json' },
					json: true,
				};
				if (built.body !== undefined) {
					options.body = built.body;
				}

				const response = (await this.helpers.httpRequestWithAuthentication.call(
					this,
					'myOtpApi',
					options,
				)) as IDataObject;

				returnData.push({ json: response, pairedItem: { item: i } });
			} catch (error) {
				const info = describeApiError(error);
				if (this.continueOnFail()) {
					returnData.push({
						json: { error: info.message, http_code: info.httpCode },
						pairedItem: { item: i },
					});
					continue;
				}
				throw new NodeApiError(this.getNode(), error as JsonObject, {
					message: info.message,
					httpCode: info.httpCode,
					itemIndex: i,
				});
			}
		}

		return [returnData];
	}
}

function collectParams(this: IExecuteFunctions, operation: Operation, i: number): OperationParams {
	switch (operation) {
		case 'sendOtp':
			return {
				phone_number: this.getNodeParameter('phoneNumber', i) as string,
				channel: this.getNodeParameter('channel', i, 'sms') as string,
				additionalFields: this.getNodeParameter('additionalFields', i, {}) as IDataObject,
			};
		case 'verifyOtp':
			return {
				otp: this.getNodeParameter('otp', i) as string,
				additionalFields: this.getNodeParameter('additionalFields', i, {}) as IDataObject,
			};
		case 'extendOtp':
			return {
				message_id: this.getNodeParameter('messageId', i) as string,
				duration: this.getNodeParameter('duration', i) as number,
			};
		case 'checkOtpStatus':
			return { message_id: this.getNodeParameter('messageId', i) as string };
		case 'report':
			return {
				additionalFields: this.getNodeParameter('additionalFields', i, {}) as IDataObject,
			};
		case 'account':
		default:
			return {};
	}
}
