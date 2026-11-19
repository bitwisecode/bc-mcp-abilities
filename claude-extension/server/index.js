"use strict";

/**
 * BC MCP Abilities Claude Desktop extension: dependency-free MCP STDIO bridge.
 * WordPress Application Passwords arrive through Claude's secure environment.
 *
 * Copyright (c) 2026 BitwiseCode. GPL-2.0-or-later.
 */

const readline = require("node:readline");
const MAX_BYTES = 4 * 1024 * 1024;

function validateEndpoint(raw) {
  let url;
  try { url = new URL(raw); }
  catch { throw new Error("Invalid WordPress MCP endpoint in Claude extension settings."); }
  if (url.protocol !== "https:" || url.username || url.password || url.search || url.hash ||
      (url.port && url.port !== "443") || !url.hostname.includes(".") ||
      url.hostname === "localhost" ||
      !url.pathname.endsWith("/wp-json/mcp/mcp-adapter-default-server")) {
    throw new Error("Use the public HTTPS endpoint of the official WordPress MCP Adapter.");
  }
  return url.toString();
}

async function boundedText(response) {
  if (Number(response.headers.get("content-length") || 0) > MAX_BYTES) {
    throw new Error("The WordPress MCP response is too large.");
  }
  const reader = response.body?.getReader();
  if (!reader) return "";
  const parts = [];
  let size = 0;
  try {
    for (;;) {
      const { done, value } = await reader.read();
      if (done) break;
      size += value.length;
      if (size > MAX_BYTES) {
        await reader.cancel();
        throw new Error("The WordPress MCP response is too large.");
      }
      parts.push(value);
    }
  } finally { reader.releaseLock(); }
  const out = new Uint8Array(size);
  let offset = 0;
  for (const part of parts) { out.set(part, offset); offset += part.length; }
  return new TextDecoder().decode(out);
}

function decodeResponse(raw) {
  if (!raw.trim()) return null;
  try { return JSON.parse(raw); } catch { /* Try SSE format below. */ }
  for (const event of raw.split(/\r?\n\r?\n/)) {
    const data = event.split(/\r?\n/).filter((line) => line.startsWith("data:"))
      .map((line) => line.slice(5).trimStart()).join("\n");
    if (!data) continue;
    try {
      const result = JSON.parse(data);
      if (result && typeof result === "object" && ("result" in result || "error" in result)) return result;
    } catch { /* Ignore unrelated SSE messages. */ }
  }
  throw new Error("WordPress MCP Adapter returned an invalid JSON-RPC response.");
}

function createBridge(env = process.env, fetchImpl = fetch) {
  const url = validateEndpoint(env.BC_MCP_URL || "");
  const username = String(env.BC_MCP_USERNAME || "");
  const password = String(env.BC_MCP_APP_PASSWORD || "");
  if (!username || !password) {
    throw new Error("Configure the WordPress username and Application Password in Claude.");
  }
  const authorization = "Basic " + Buffer.from(username + ":" + password).toString("base64");
  let sessionId = "";

  async function send(message, method = "POST") {
    const headers = {
      Authorization: authorization,
      Accept: "application/json, text/event-stream",
      "Content-Type": "application/json",
    };
    if (sessionId) headers["Mcp-Session-Id"] = sessionId;
    const body = method === "POST" ? JSON.stringify(message) : undefined;
    if (body && Buffer.byteLength(body) > MAX_BYTES) throw new Error("MCP request is too large.");

    let response;
    try {
      response = await fetchImpl(url, {
        method,
        headers,
        body,
        redirect: "error",
        signal: AbortSignal.timeout(25000),
      });
    } catch {
      throw new Error("Could not reach WordPress MCP Adapter securely.");
    }
    if (response.status === 401 || response.status === 403) {
      throw new Error("WordPress rejected the Application Password or this user's permissions.");
    }
    if (!response.ok) throw new Error("WordPress MCP Adapter returned HTTP " + response.status + ".");
    const nextId = response.headers.get("mcp-session-id");
    if (nextId && /^[a-zA-Z0-9_-]{8,128}$/.test(nextId)) sessionId = nextId;
    return decodeResponse(await boundedText(response));
  }

  return {
    async forward(message) {
      if (!message || message.jsonrpc !== "2.0" || typeof message.method !== "string") {
        throw new Error("Invalid MCP JSON-RPC request.");
      }
      if (message.method === "initialize") sessionId = "";
      const response = await send(message);
      return Object.hasOwn(message, "id") ? response : null;
    },
    async close() {
      if (!sessionId) return;
      try { await send(undefined, "DELETE"); } catch { /* Best effort session cleanup. */ }
      sessionId = "";
    },
  };
}

function main() {
  let bridge;
  try { bridge = createBridge(); }
  catch (error) {
    process.stderr.write("BC MCP Abilities: " + error.message + "\n");
    process.exitCode = 1;
    return;
  }
  const rl = readline.createInterface({ input: process.stdin, crlfDelay: Infinity });
  let queue = Promise.resolve();
  rl.on("line", (line) => {
    if (!line.trim()) return;
    queue = queue.then(async () => {
      let input;
      try { input = JSON.parse(line); }
      catch {
        process.stderr.write("BC MCP Abilities: invalid JSON-RPC input.\n");
        return;
      }
      try {
        const output = await bridge.forward(input);
        if (output) process.stdout.write(JSON.stringify(output) + "\n");
      } catch (error) {
        if (Object.hasOwn(input, "id")) {
          process.stdout.write(JSON.stringify({
            jsonrpc: "2.0",
            id: input.id,
            error: { code: -32000, message: error.message },
          }) + "\n");
        } else {
          process.stderr.write("BC MCP Abilities: WordPress MCP notification failed.\n");
        }
      }
    });
  });
  rl.on("close", () => { queue.finally(() => bridge.close()); });
}

module.exports = { createBridge, validateEndpoint, decodeResponse };
if (require.main === module) main();
