/**
 * top_up_credits — POST https://api.myotp.app/v1/topup
 *
 * Fetches a quote, then obtains an MPP Payment challenge for the caller. The
 * MCP server has no wallet, so it returns redacted retry details and commands
 * that the agent can run with its own USDC or card-capable MPP client.
 */

import { z } from "zod";
import type {
  TopUpPaymentOffer,
  TopUpPaymentRequiredResponse,
  TopUpQuoteResponse,
} from "../types.js";
import { compact, ok, toToolError } from "./helpers.js";
import { buildTopUpCommands } from "./get_topup_quote.js";
import type { ToolDefinition } from "./types.js";

const API_KEY_PLACEHOLDER = "your MyOTP API key";
const PAYMENT_CREDENTIAL_PLACEHOLDER = "Payment <credential from your MPP client>";

const inputSchema = {
  credits: z
    .number()
    .int()
    .min(25)
    .max(50_000)
    .describe("Number of credits to buy. Integer from 25 to 50,000."),
  dry_run: z
    .boolean()
    .optional()
    .describe("If true, return only the quote and explanation without requesting a payment challenge."),
};

export const topUpCreditsTool: ToolDefinition<typeof inputSchema> = {
  name: "top_up_credits",
  title: "Buy MyOTP credits",
  description:
    "Prepare or complete an autonomous MyOTP credit purchase with USDC or card through Machine Payments Protocol (MPP). " +
    "Use this when generate_otp or another send fails with HTTP 403 insufficient balance / NoBalance. " +
    "The tool quotes first, then returns a structured 402 challenge and exact retry details for the agent's own MPP client; " +
    "if fetch is already wrapped by a credential-carrying MPP runtime, it returns the credited response directly.",
  inputSchema,
  annotations: {
    readOnlyHint: false,
    idempotentHint: false,
    destructiveHint: true,
    openWorldHint: true,
  },
  handler: async (args, ctx) => {
    try {
      const credits = args.credits as number;
      const quote = await ctx.client.getTopUpQuote(credits);

      if (args.dry_run === true) {
        return ok(
          {
            quote,
            explanation:
              "Dry run only: the quote was fetched, but no top-up request or payment challenge was created. " +
              "This MCP server cannot hold your wallet; call again without dry_run and use your own MPP client " +
              "to pay the challenge and retry with its credential, which credits the account.",
          },
          `Dry-run quote: ${quote.credits} credits for $${quote.amount_usd} ${quote.currency.toUpperCase()}.`
        );
      }

      const topUp = await ctx.client.requestTopUp(credits, ctx.apiKey);
      if (topUp.status === 200) {
        return ok(
          topUp.body,
          `Top-up ${topUp.body.status}: ${topUp.body.credits} credits; balance=${topUp.body.balance}.`
        );
      }

      const challengeId = getChallengeId(topUp.body);
      const offers = parsePaymentOffers(topUp.wwwAuthenticate);
      const retryBody = compact({ credits });
      const result: Record<string, unknown> = {
        quote: quote as TopUpQuoteResponse,
        challengeId,
        offers,
        retry: {
          url: topUp.url,
          method: "POST",
          headers: {
            "X-API-Key": API_KEY_PLACEHOLDER,
            "Content-Type": "application/json",
            "Authorization": PAYMENT_CREDENTIAL_PLACEHOLDER,
          },
          body: retryBody,
        },
        how_to_pay: buildTopUpCommands(credits, topUp.url),
        explanation:
          "This MCP server cannot hold your wallet. Use one of the client commands with your own MPP client; " +
          "it pays the 402 challenge and retries this same request with the payment credential. " +
          "A successful retry credits the MyOTP account.",
      };

      return ok(
        result,
        `Payment required for ${quote.credits} credits. challengeId=${challengeId}`
      );
    } catch (err) {
      return toToolError(err, "Failed to top up credits");
    }
  },
};

function getChallengeId(body: TopUpPaymentRequiredResponse): string {
  if (typeof body.challengeId !== "string" || body.challengeId.trim() === "") {
    throw new Error("The 402 response did not include a challengeId.");
  }
  return body.challengeId;
}

/** Parse one or more comma-combined Payment challenges, preserving offer order. */
export function parsePaymentOffers(header: string): TopUpPaymentOffer[] {
  if (header.trim() === "") {
    throw new Error("The 402 response did not include a WWW-Authenticate Payment challenge.");
  }

  const challenges = splitPaymentChallenges(header);
  const offers = challenges.map((challenge) => {
    const params = parseAuthParams(challenge);
    const method = requireParam(params, "method");
    const intent = requireParam(params, "intent");
    const id = requireParam(params, "id");
    const expires = requireParam(params, "expires");
    const encodedRequest = requireParam(params, "request");
    const request = decodePaymentRequest(encodedRequest, id);

    const unit = amountUnit(method);
    const offer: TopUpPaymentOffer = {
      method,
      intent,
      id,
      expires,
      amount: request.amount,
      amount_unit: unit.label,
      amount_usd: unit.divisor ? (Number(request.amount) / unit.divisor).toFixed(2) : undefined,
      currency: request.currency,
    };
    return offer;
  });

  if (offers.length === 0) {
    throw new Error("The WWW-Authenticate header contained no Payment offers.");
  }
  return offers;
}

function splitPaymentChallenges(header: string): string[] {
  const parts: string[] = [];
  let start = 0;
  let quoted = false;
  let escaped = false;

  for (let i = 0; i < header.length; i += 1) {
    const char = header[i];
    if (escaped) {
      escaped = false;
      continue;
    }
    if (quoted && char === "\\") {
      escaped = true;
      continue;
    }
    if (char === '"') {
      quoted = !quoted;
      continue;
    }
    if (!quoted && char === "," && /^\s*Payment(?:\s|$)/i.test(header.slice(i + 1))) {
      parts.push(header.slice(start, i).trim());
      start = i + 1;
    }
  }

  if (quoted || escaped) {
    throw new Error("The WWW-Authenticate Payment challenge contains an unterminated quoted value.");
  }
  parts.push(header.slice(start).trim());
  return parts.filter((part) => /^Payment(?:\s|$)/i.test(part));
}

/** Amount units differ per rail: Tempo quotes USDC atomic units (6 decimals), Stripe quotes cents. */
function amountUnit(method: string): { label: string; divisor: number | null } {
  if (method === "tempo") return { label: "USDC atomic units (6 decimals)", divisor: 1_000_000 };
  if (method === "stripe") return { label: "USD cents", divisor: 100 };
  return { label: "method-defined units", divisor: null };
}

function parseAuthParams(challenge: string): Record<string, string> {
  const params: Record<string, string> = {};
  const source = challenge.replace(/^Payment\s*/i, "");
  const authParam = /([!#$%&'*+.^_`|~0-9A-Za-z-]+)\s*=\s*(?:"((?:\\.|[^"\\])*)"|([^,\s]+))/g;

  for (const match of source.matchAll(authParam)) {
    const key = (match[1] ?? "").toLowerCase();
    const quotedValue = match[2];
    const tokenValue = match[3];
    const value = quotedValue !== undefined
      ? quotedValue.replace(/\\(.)/g, "$1")
      : (tokenValue ?? "");
    if (key !== "") params[key] = value;
  }

  return params;
}

function requireParam(params: Record<string, string>, name: string): string {
  const value = params[name];
  if (!value) {
    throw new Error(`Payment challenge is missing the ${name} parameter.`);
  }
  return value;
}

function decodePaymentRequest(
  encoded: string,
  offerId: string
): { amount: string | number; currency: string } {
  if (!/^[A-Za-z0-9_-]+$/.test(encoded)) {
    throw new Error(`Payment offer ${offerId} has an invalid base64url request.`);
  }

  let decoded: unknown;
  try {
    decoded = JSON.parse(Buffer.from(encoded, "base64url").toString("utf8"));
  } catch {
    throw new Error(`Payment offer ${offerId} has an invalid base64url request.`);
  }

  if (!decoded || typeof decoded !== "object") {
    throw new Error(`Payment offer ${offerId} has an invalid request object.`);
  }
  const request = decoded as Record<string, unknown>;
  if (typeof request.amount !== "string" && typeof request.amount !== "number") {
    throw new Error(`Payment offer ${offerId} has no valid amount.`);
  }
  if (typeof request.currency !== "string" || request.currency === "") {
    throw new Error(`Payment offer ${offerId} has no valid currency.`);
  }

  return { amount: request.amount, currency: request.currency };
}
