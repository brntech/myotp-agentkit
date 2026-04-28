/**
 * Barrel re-export of all tool definitions. server.ts iterates this array.
 */

import type { ZodRawShape } from "zod";
import { generateOtpTool } from "./generate_otp.js";
import { verifyOtpTool } from "./verify_otp.js";
import { checkOtpStatusTool } from "./check_otp_status.js";
import { extendOtpTool } from "./extend_otp.js";
import { getAccountInfoTool } from "./get_account_info.js";
import { getUsageReportTool } from "./get_usage_report.js";
import { createAccountTool } from "./create_account.js";
import type { ToolDefinition } from "./types.js";

// Cast each tool to a heterogeneous-shape array so we can register them in a
// single loop without TypeScript griping that the shapes are different.
export const allTools: ReadonlyArray<ToolDefinition<ZodRawShape>> = [
  generateOtpTool,
  verifyOtpTool,
  checkOtpStatusTool,
  extendOtpTool,
  getAccountInfoTool,
  getUsageReportTool,
  createAccountTool,
] as ReadonlyArray<ToolDefinition<ZodRawShape>>;

export type { ToolDefinition, ToolContext, ToolResult } from "./types.js";
