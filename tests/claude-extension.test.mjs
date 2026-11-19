import test from "node:test";
import assert from "node:assert/strict";
import { createRequire } from "node:module";

const require = createRequire(import.meta.url);
const { createBridge, validateEndpoint, decodeResponse } = require("../claude-extension/server/index.js");

const settings = {
  BC_MCP_URL: "https://wptoolix.com/wp-json/mcp/mcp-adapter-default-server",
  BC_MCP_USERNAME: "testing-user",
  BC_MCP_APP_PASSWORD: "not-a-real-password",
};

function createFetch(status = 200) {
  const calls = [];
  async function fakeFetch(url, options) {
    calls.push({ url, options });
    if (status !== 200) return new Response("", { status });
    if (options.method === "DELETE") return new Response(null, { status: 204 });
    const payload = JSON.parse(options.body);
    if (payload.method === "notifications/initialized") return new Response(null, { status: 202 });
    return Response.json(
      { jsonrpc: "2.0", id: payload.id, result: { ok: true, method: payload.method } },
      { headers: payload.method === "initialize" ? { "Mcp-Session-Id": "12345678-1234-1234-1234-123456789012" } : {} }
    );
  }
  return { calls, fakeFetch };
}

test("validates a public HTTPS WordPress MCP Adapter URL", () => {
  assert.equal(validateEndpoint(settings.BC_MCP_URL), settings.BC_MCP_URL);
  for (const url of [
    "http://wptoolix.com/wp-json/mcp/mcp-adapter-default-server",
    "https://localhost/wp-json/mcp/mcp-adapter-default-server",
    "https://wptoolix.com/wp-json/wp/v2/posts",
    "https://user:secret@wptoolix.com/wp-json/mcp/mcp-adapter-default-server",
  ]) assert.throws(() => validateEndpoint(url));
});

test("forwards MCP handshake, notifications and tool calls with session ID", async () => {
  const fake = createFetch();
  const bridge = createBridge(settings, fake.fakeFetch);
  const init = await bridge.forward({ jsonrpc: "2.0", id: 1, method: "initialize", params: {} });
  assert.equal(init.result.ok, true);
  assert.equal(await bridge.forward({ jsonrpc: "2.0", method: "notifications/initialized" }), null);
  const tools = await bridge.forward({ jsonrpc: "2.0", id: 2, method: "tools/list", params: {} });
  assert.equal(tools.result.method, "tools/list");
  assert.equal(fake.calls[1].options.headers["Mcp-Session-Id"], "12345678-1234-1234-1234-123456789012");
  assert.equal(fake.calls[2].options.redirect, "error");
  assert.equal(fake.calls[0].options.headers.Authorization, "Basic " + Buffer.from("testing-user:not-a-real-password").toString("base64"));
  assert.equal(JSON.stringify(tools).includes("not-a-real-password"), false);
  await bridge.close();
  assert.equal(fake.calls[3].options.method, "DELETE");
});

test("reports a 401 safely without leaking application password", async () => {
  const fake = createFetch(401);
  await assert.rejects(createBridge(settings, fake.fakeFetch).forward({ jsonrpc: "2.0", id: 1, method: "initialize" }), /rejected/);
});

test("supports SSE-wrapped MCP responses", () => {
  assert.deepEqual(decodeResponse('event: message\ndata: {"jsonrpc":"2.0","id":2,"result":{"tools":[]}}\n\n').result.tools, []);
});

test("does not connect until WordPress username and application password are provided", () => {
  assert.throws(() => createBridge({ ...settings, BC_MCP_APP_PASSWORD: "" }), /Application Password/);
});
