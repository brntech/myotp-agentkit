/**
 * get_usage_report — POST https://api.myotp.app/report
 *
 * Date-ranged transaction list with pagination. Requires API_REPORTING
 * entitlement (Business plan or above). Date range cannot exceed 31 days.
 *
 * Truth source: MyOTP.SRVCAPI/myotpapp/views/report.py.
 */

import { z } from "zod";
import type { ReportResponse } from "../types.js";
import { compact, ok, toToolError } from "./helpers.js";
import type { ToolDefinition } from "./types.js";

const inputSchema = {
  start_date: z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/)
    .optional()
    .describe(
      "Start date in YYYY-MM-DD format (UTC). If omitted, defaults to 7 days before today. The range start_date..end_date cannot exceed 31 days."
    ),
  end_date: z
    .string()
    .regex(/^\d{4}-\d{2}-\d{2}$/)
    .optional()
    .describe(
      "End date in YYYY-MM-DD format (UTC). If omitted, defaults to today. The range start_date..end_date cannot exceed 31 days."
    ),
  page: z
    .number()
    .int()
    .min(1)
    .optional()
    .describe("Page number for paginated results, starting at 1. Default 1."),
  per_page: z
    .number()
    .int()
    .min(1)
    .max(100)
    .optional()
    .describe("Results per page, 1-100. Default 10."),
};

export const getUsageReportTool: ToolDefinition<typeof inputSchema> = {
  name: "get_usage_report",
  title: "Get usage report",
  description:
    "Fetch a paginated list of OTP transactions for a date range. " +
    "Each transaction includes message_id, timestamp, phone_number, channel, country, cost, status, and the originating client IP. " +
    "Date range cannot exceed 31 days. Defaults: last 7 days, page 1, 10 per page. " +
    "Requires the API_REPORTING entitlement (Business or Enterprise plan). " +
    "Use this to: audit recent activity, build internal dashboards, reconcile billing, or debug delivery issues across many recipients.",
  inputSchema,
  annotations: {
    readOnlyHint: true,
    idempotentHint: true,
    destructiveHint: false,
    openWorldHint: true,
  },
  handler: async (args, ctx) => {
    try {
      const body = compact({
        start_date: args.start_date,
        end_date: args.end_date,
        page: args.page,
        per_page: args.per_page,
      });

      const result = await ctx.client.post<ReportResponse>("/report", body, ctx.apiKey);

      const txCount = Array.isArray(result.transactions) ? result.transactions.length : 0;
      const summary = result.message
        ? `${result.message} (returned ${txCount} transactions)`
        : `Returned ${txCount} transactions, page ${result.current_page ?? 1} of ${result.total_pages ?? 1} (total ${result.total_count ?? txCount}).`;

      return ok(result as unknown as Record<string, unknown>, summary);
    } catch (err) {
      return toToolError(err, "Failed to fetch usage report");
    }
  },
};
