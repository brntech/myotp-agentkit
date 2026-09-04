/**
 * get_topup_quote — GET https://api.myotp.app/v1/topup/quote
 *
 * Returns current pricing and ready-to-run MPP client commands. The quote is
 * public, so this tool never sends or embeds the caller's API key.
 */

import { z } from "zod";
import type { TopUpQuoteResponse } from "../types.js";
import { ok, toToolError } from "./helpers.js";
import type { ToolDefinition } from "./types.js";

const TOP_UP_URL = "https://api.myotp.app/v1/topup";

export const TOP_UP_RULES =
  "$0.02 per credit; minimum 25 credits ($0.50); maximum 50,000 credits per call; " +
  "card top-ups are capped at $100 per account per rolling 24 hours, while USDC is uncapped; " +
  "a trial account moves to Starter pay-as-you-go on its first top-up, with no subscription.";

export function buildTopUpCommands(
  credits: number,
  url = TOP_UP_URL
): { usdc: string; card: string } {
  const body = `{\\"credits\\":${credits}}`;
  return {
    usdc:
      `npx -y mppx@0.9.2 ${url} -X POST ` +
      `-H "x-api-key: your MyOTP API key" -H "content-type: application/json" -d "${body}"`,
    card:
      `npx -y @stripe/link-cli mpp pay ${url} -X POST -d "${body}" ` +
      `-H "x-api-key: your MyOTP API key" --context "Buying MyOTP.App credits to send one-time passcodes over SMS, WhatsApp and Telegram for phone verification in my app."`,
  };
}

const inputSchema = {
  credits: z
    .number()
    .int()
    .min(25)
    .max(50_000)
    .default(100)
    .describe("Number of credits to quote. Integer from 25 to 50,000; defaults to 100."),
};

export const topUpQuoteShape = {
  credits: z.number().int().describe("The number of credits priced."),
  amount_usd: z.string().describe("Total price in US dollars, as a decimal string."),
  price_per_credit_usd: z.number().describe("Unit price. Currently 0.02."),
  min_credits: z.number().int(),
  max_credits: z.number().int(),
  currency: z.string().describe("Always 'usd'."),
  methods: z.array(z.string()).describe("Human-readable list of the accepted payment methods."),
};

export const topUpQuoteSchema = z.object(topUpQuoteShape).passthrough();

export const howToPayShape = {
  usdc: z.string().describe("mppx command that pays the challenge in USDC on Tempo."),
  card: z.string().describe("Stripe Link CLI command that pays the challenge by card."),
};

const outputSchema = {
  ...topUpQuoteShape,
  rules: z.string().describe("Top-up limits: unit price, minimum, maximum and the card cap."),
  how_to_pay: z.object(howToPayShape).passthrough().describe("Ready-to-run client commands for this amount."),
};

export const getTopUpQuoteTool: ToolDefinition<typeof inputSchema> = {
  name: "get_topup_quote",
  title: "Get a MyOTP credit top-up quote",
  description:
    "Get the live price and payment options for buying MyOTP credits, without making a purchase. " +
    "Use this when generate_otp or another send fails with HTTP 403 insufficient balance / NoBalance, " +
    "or before calling `top_up_credits` to show the cost. Returns USDC and card client commands and never exposes the configured API key.",
  inputSchema,
  outputSchema,
  annotations: {
    readOnlyHint: true,
    idempotentHint: true,
    destructiveHint: false,
    openWorldHint: true,
  },
  handler: async (args, ctx) => {
    try {
      const credits = (args.credits as number | undefined) ?? 100;
      const quote = await ctx.client.getTopUpQuote(credits);
      const result: Record<string, unknown> = {
        ...(quote as TopUpQuoteResponse),
        rules: TOP_UP_RULES,
        how_to_pay: buildTopUpCommands(credits),
      };

      return ok(
        result,
        `Top-up quote: ${quote.credits} credits for $${quote.amount_usd} ${quote.currency.toUpperCase()}.`
      );
    } catch (err) {
      return toToolError(err, "Failed to fetch top-up quote");
    }
  },
};
