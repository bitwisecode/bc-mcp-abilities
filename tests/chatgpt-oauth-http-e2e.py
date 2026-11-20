#!/usr/bin/env python3
"""
External HTTP end-to-end test that walks the same sequence ChatGPT uses:

  protected-resource discovery -> authorization-server metadata -> DCR ->
  WordPress sign-in -> consent -> token (PKCE S256) -> MCP initialize ->
  notifications/initialized -> tools/list -> tools/call bc-list-abilities

It also runs the negative cases (missing, malformed, unknown and revoked
Bearer tokens, insufficient WordPress permissions, protocol errors).

Run ONLY against a disposable/staging site with a dedicated test account:

  python3 tests/chatgpt-oauth-http-e2e.py https://staging.example.com USER PASSWORD [LOW_PRIV_USER LOW_PRIV_PASSWORD]

It never calls a write ability. Credentials are only sent to the given site.
"""

import base64
import hashlib
import html
from http.cookiejar import CookieJar
import json
import re
import secrets
import sys
import time
import urllib.error
import urllib.parse
import urllib.request

if len(sys.argv) < 4:
    print(__doc__)
    sys.exit(2)

BASE = sys.argv[1].rstrip("/")
USER, PASSWORD = sys.argv[2], sys.argv[3]
LOW_USER = sys.argv[4] if len(sys.argv) > 5 else None
LOW_PASSWORD = sys.argv[5] if len(sys.argv) > 5 else None
MCP = BASE + "/wp-json/bc-mcp/v1/server"
REDIRECT = "https://chatgpt.com/connector_platform_oauth_redirect"
FAILURES = []


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None


def opener(jar=None):
    handlers = [NoRedirect()]
    if jar is not None:
        handlers.append(urllib.request.HTTPCookieProcessor(jar))
    return urllib.request.build_opener(*handlers)


def send(method, url, body=None, headers=None, jar=None, form=False):
    data = None
    headers = dict(headers or {})
    if body is not None:
        if form:
            data = urllib.parse.urlencode(body).encode()
            headers.setdefault("Content-Type", "application/x-www-form-urlencoded")
        else:
            data = json.dumps(body).encode()
            headers.setdefault("Content-Type", "application/json")
    req = urllib.request.Request(url, data=data, method=method, headers=headers)
    try:
        resp = opener(jar).open(req, timeout=30)
        return resp.status, dict(resp.headers), resp.read().decode("utf-8", "replace")
    except urllib.error.HTTPError as err:
        return err.code, dict(err.headers), err.read().decode("utf-8", "replace")


def check(condition, label, detail=""):
    print(("PASS " if condition else "FAIL ") + label + (f"  [{detail}]" if detail and not condition else ""))
    if not condition:
        FAILURES.append(label)


def header(headers, name):
    for key, value in headers.items():
        if key.lower() == name.lower():
            return value
    return ""


def rpc(method, rid=1, params=None, token=None, extra=None):
    message = {"jsonrpc": "2.0", "method": method}
    if rid is not None:
        message["id"] = rid
    if params is not None:
        message["params"] = params
    headers = {"Accept": "application/json, text/event-stream", "MCP-Protocol-Version": "2025-06-18"}
    if token:
        headers["Authorization"] = "Bearer " + token
    headers.update(extra or {})
    return send("POST", MCP, message, headers)


def login(jar, user, password):
    send("GET", BASE + "/wp-login.php", jar=jar)
    status, headers, _ = send(
        "POST",
        BASE + "/wp-login.php",
        {"log": user, "pwd": password, "wp-submit": "Log In", "testcookie": "1"},
        jar=jar,
        form=True,
        headers={"Cookie": "wordpress_test_cookie=WP%20Cookie%20check"},
    )
    return status in (302, 303) and any(c.name.startswith("wordpress_logged_in") for c in jar)


def login_via_redirect(jar, user, password, authorize_url):
    """Start logged out, as a fresh browser does, and follow wp-login back."""
    status, headers, _ = send("GET", authorize_url, jar=jar)
    location = header(headers, "Location")
    if status != 302 or "wp-login.php" not in location:
        return None, f"authorize did not redirect to wp-login ({status} {location[:120]})"
    redirect_to = urllib.parse.parse_qs(urllib.parse.urlparse(location).query).get("redirect_to", [""])[0]
    send("GET", location, jar=jar)
    status, headers, _ = send(
        "POST",
        BASE + "/wp-login.php",
        {"log": user, "pwd": password, "wp-submit": "Log In", "testcookie": "1", "redirect_to": redirect_to},
        jar=jar,
        form=True,
        headers={"Cookie": "wordpress_test_cookie=WP%20Cookie%20check"},
    )
    back = header(headers, "Location")
    if status not in (302, 303) or not back:
        return None, f"login did not redirect back ({status})"
    return back, ""


def oauth_token(user, password, logged_out_start=False, details=None):
    """Return (client_id, token_response) after a complete DCR + consent + PKCE exchange."""
    status, _, body = send("POST", BASE + "/bc-mcp/oauth/register", {"client_name": "ChatGPT e2e", "redirect_uris": [REDIRECT], "token_endpoint_auth_method": "none"})
    if status != 201:
        return None, {"error": "registration", "status": status, "body": body[:200]}
    client_id = json.loads(body)["client_id"]
    verifier = secrets.token_urlsafe(48)
    challenge = base64.urlsafe_b64encode(hashlib.sha256(verifier.encode()).digest()).decode().rstrip("=")
    query = {
        "response_type": "code",
        "client_id": client_id,
        "redirect_uri": REDIRECT,
        "code_challenge": challenge,
        "code_challenge_method": "S256",
        "resource": MCP,
        "scope": "mcp offline_access",
        "state": "e2e state/with+chars",
    }
    authorize_url = BASE + "/bc-mcp/oauth/authorize?" + urllib.parse.urlencode(query)
    jar = CookieJar()
    if logged_out_start:
        authorize_url, error = login_via_redirect(jar, user, password, authorize_url)
        if not authorize_url:
            return client_id, {"error": "logged_out_flow", "detail": error}
    elif not login(jar, user, password):
        return client_id, {"error": "login_failed"}
    status, page_headers, page = send("GET", authorize_url, jar=jar)
    if details is not None:
        details["consent_headers"] = page_headers
        details["consent_page"] = page
    if status == 403:
        return client_id, {"error": "access_denied", "status": 403}
    nonce = re.search(r'name="_wpnonce" value="([^"]+)"', page)
    if not nonce:
        return client_id, {"error": "no_consent_form", "status": status}
    form = {k: html.unescape(v) for k, v in re.findall(r'<input type="hidden" name="([^"]+)" value="([^"]*)"', page)}
    form["_wpnonce"] = html.unescape(nonce.group(1))
    form["bc_decision"] = "approve"
    status, headers, _ = send("POST", BASE + "/bc-mcp/oauth/authorize", form, jar=jar, form=True)
    location = header(headers, "Location")
    callback = urllib.parse.parse_qs(urllib.parse.urlparse(location).query)
    code = callback.get("code", [""])[0]
    if details is not None:
        details["callback_state"] = callback.get("state", [""])[0]
    if not code:
        return client_id, {"error": "no_code", "status": status}
    status, _, body = send(
        "POST",
        BASE + "/bc-mcp/oauth/token",
        {"grant_type": "authorization_code", "code": code, "client_id": client_id, "redirect_uri": REDIRECT, "code_verifier": verifier, "resource": MCP},
        form=True,
    )
    return client_id, json.loads(body) if body.startswith("{") else {"error": body[:200]}


print(f"Target: {MCP}")

# 1. Discovery.
status, headers, body = rpc("tools/call", 1, {"name": "bc-list-abilities", "arguments": {}})
challenge = header(headers, "WWW-Authenticate")
check(status == 401, "anonymous tools/call is rejected with 401", status)
check("resource_metadata=" in challenge, "401 carries WWW-Authenticate resource_metadata", challenge)
check('error="invalid_token"' not in challenge, "no invalid_token error when no token was sent (RFC 6750 3.1)", challenge)
metadata_url = re.search(r'resource_metadata="([^"]+)"', challenge)
status, _, body = send("GET", metadata_url.group(1) if metadata_url else BASE + "/.well-known/oauth-protected-resource/wp-json/bc-mcp/v1/server")
resource = json.loads(body) if status == 200 else {}
check(resource.get("resource") == MCP, "protected-resource metadata names the MCP endpoint", body[:200])
status, _, body = send("GET", BASE + "/.well-known/oauth-authorization-server/bc-mcp")
server = json.loads(body) if status == 200 else {}
check("S256" in server.get("code_challenge_methods_supported", []), "authorization-server metadata advertises PKCE S256", body[:200])
check(bool(server.get("registration_endpoint")), "authorization-server metadata advertises DCR", body[:200])

# 2. Unauthenticated preflight that ChatGPT performs while scanning tools.
status, headers, body = rpc("initialize", 1, {"protocolVersion": "2025-06-18", "capabilities": {}, "clientInfo": {"name": "e2e", "version": "1"}})
init = json.loads(body) if status == 200 else {}
check(status == 200, "anonymous initialize succeeds", status)
check(init.get("result", {}).get("protocolVersion") == "2025-06-18", "server negotiates the client's supported protocol version", body[:200])
status, _, body = rpc("notifications/initialized", None)
check(status == 202 and body.strip() == "", "notifications/initialized returns 202 with an empty body", f"{status} {body[:80]}")
status, _, body = rpc("tools/list", 2)
tools = json.loads(body)["result"]["tools"] if status == 200 else []
check(len(tools) == 3, "tools/list returns three tools", body[:200])
for tool in tools:
    schema = tool.get("inputSchema", {})
    check(isinstance(schema.get("properties"), dict), f"{tool.get('name')} inputSchema.properties is a JSON object", json.dumps(schema)[:120])

# 3. Full OAuth flow.
details = {}
client_id, tokens = oauth_token(USER, PASSWORD, details=details)
access = tokens.get("access_token", "")
check(bool(access), "DCR + consent + PKCE token exchange issues an access token", tokens)
check(tokens.get("token_type") == "Bearer", "token_type is Bearer", tokens)
consent_headers = details.get("consent_headers", {})
check(header(consent_headers, "X-Frame-Options").upper() == "DENY", "consent page refuses framing (X-Frame-Options)", header(consent_headers, "X-Frame-Options"))
check("frame-ancestors 'none'" in header(consent_headers, "Content-Security-Policy"), "consent page refuses framing (CSP frame-ancestors)", header(consent_headers, "Content-Security-Policy"))
check("ChatGPT e2e" in details.get("consent_page", ""), "consent page names the registered client")
check(details.get("callback_state") == "e2e state/with+chars", "state is returned to the client unchanged", details.get("callback_state"))

# 3b. A browser that is not yet signed in to WordPress (the common first-time path).
_, logged_out = oauth_token(USER, PASSWORD, logged_out_start=True)
check(bool(logged_out.get("access_token")), "a logged-out user goes through wp-login and back to consent with the request intact", logged_out)

# 4. Authenticated MCP session.
status, _, body = rpc("initialize", 1, {"protocolVersion": "2025-11-25", "capabilities": {}, "clientInfo": {"name": "e2e", "version": "1"}}, token=access)
check(status == 200 and '"protocolVersion":"2025-11-25"' in body, "authenticated initialize succeeds", f"{status} {body[:160]}")
status, _, body = rpc("tools/list", 2, token=access)
check(status == 200, "authenticated tools/list succeeds", status)
status, _, body = rpc("tools/call", 3, {"name": "bc-list-abilities", "arguments": {}}, token=access)
result = json.loads(body).get("result", {}) if status == 200 else {}
listed = json.loads(result.get("content", [{}])[0].get("text", "{}")) if result else {}
check(status == 200 and result.get("isError") is False and len(listed.get("abilities", [])) > 0, "tools/call bc-list-abilities runs as the authorized user", f"{status} {body[:200]}")
status, _, body = rpc("tools/call", 4, {"name": "bc-get-ability-info", "arguments": {"ability_name": "bc-mcp-abilities/list-content"}}, token=access)
check(status == 200 and '"isError":false' in body, "tools/call bc-get-ability-info succeeds", f"{status} {body[:200]}")
status, _, body = rpc("tools/call", 5, {"name": "bc-execute-ability", "arguments": {"ability_name": "bc-mcp-abilities/list-content", "parameters": {}}}, token=access)
check(status == 200 and '"isError":false' in body, "read-only bc-execute-ability list-content succeeds", f"{status} {body[:200]}")
if "site-info" not in json.dumps(listed):
    status, _, body = rpc("tools/call", 51, {"name": "bc-execute-ability", "arguments": {"ability_name": "bc-mcp-abilities/site-info", "parameters": {}}}, token=access)
    check(status == 200 and '"isError":true' in body, "abilities outside the user's WordPress permissions stay refused", f"{status} {body[:200]}")
status, _, body = rpc("resources/list", 6, token=access)
check(status == 200 and '"code":-32601' in body, "unsupported method returns JSON-RPC -32601", f"{status} {body[:160]}")

# 5. Negative Bearer cases.
status, headers, _ = rpc("tools/call", 7, {"name": "bc-list-abilities", "arguments": {}}, token="0" * 64)
check(status == 401 and 'error="invalid_token"' in header(headers, "WWW-Authenticate"), "unknown or expired token -> 401 invalid_token", status)
status, _, _ = rpc("tools/list", 8, token="0" * 64)
check(status == 401, "a presented but invalid token is rejected even on discovery methods (lets the client refresh)", status)
status, _, _ = rpc("tools/call", 9, {"name": "bc-list-abilities", "arguments": {}}, extra={"Authorization": "Basic Zm9vOmJhcg=="})
check(status == 401, "non-Bearer authorization is rejected", status)
status, _, body = send("POST", MCP, None, {"Authorization": "Bearer " + access, "Content-Type": "application/json"})
check(status in (200, 400) and "-32600" in body, "empty body -> JSON-RPC invalid request", f"{status} {body[:120]}")
status, _, body = send("POST", MCP, [{"jsonrpc": "2.0", "id": 1, "method": "ping"}], {"Authorization": "Bearer " + access})
check("-32600" in body, "JSON-RPC batch is rejected as invalid request", f"{status} {body[:120]}")
status, _, _ = send("GET", MCP, headers={"Accept": "text/event-stream"})
check(status == 405, "GET (no SSE stream) returns 405", status)

# 6. Refresh rotation and revocation.
refresh = tokens.get("refresh_token", "")
if refresh:
    status, _, body = send("POST", BASE + "/bc-mcp/oauth/token", {"grant_type": "refresh_token", "refresh_token": refresh, "client_id": client_id, "resource": MCP}, form=True)
    rotated = json.loads(body) if status == 200 else {}
    check(bool(rotated.get("access_token")) and rotated.get("refresh_token") != refresh, "refresh token rotates", body[:160])
    status, _, _ = rpc("tools/call", 10, {"name": "bc-list-abilities", "arguments": {}}, token=rotated.get("access_token", ""))
    check(status == 200, "rotated access token works", status)
    status, _, _ = send("POST", BASE + "/bc-mcp/oauth/token", {"grant_type": "refresh_token", "refresh_token": refresh, "client_id": client_id, "resource": MCP}, form=True)
    check(status == 400, "replayed refresh token is rejected", status)
    status, _, _ = rpc("tools/call", 11, {"name": "bc-list-abilities", "arguments": {}}, token=rotated.get("access_token", ""))
    check(status == 200, "an immediate retry (client race) does not revoke the connection", status)
    print("Waiting 31 seconds to replay outside the retry window...")
    time.sleep(31)
    status, _, _ = send("POST", BASE + "/bc-mcp/oauth/token", {"grant_type": "refresh_token", "refresh_token": refresh, "client_id": client_id, "resource": MCP}, form=True)
    check(status == 400, "late replay of a rotated refresh token is rejected", status)
    status, _, _ = rpc("tools/call", 12, {"name": "bc-list-abilities", "arguments": {}}, token=rotated.get("access_token", ""))
    check(status == 401, "late refresh-token replay revokes the whole connection", status)
    status, _, _ = send("POST", BASE + "/bc-mcp/oauth/token", {"grant_type": "refresh_token", "refresh_token": rotated.get("refresh_token", ""), "client_id": client_id, "resource": MCP}, form=True)
    check(status == 400, "the newest refresh token is also revoked after replay", status)

    # Explicit revocation on a fresh connection.
    fresh_client, fresh = oauth_token(USER, PASSWORD)
    send("POST", BASE + "/bc-mcp/oauth/revoke", {"client_id": fresh_client, "token": fresh.get("access_token", "")}, form=True)
    status, _, _ = rpc("tools/call", 13, {"name": "bc-list-abilities", "arguments": {}}, token=fresh.get("access_token", ""))
    check(bool(fresh.get("access_token")) and status == 401, "revoked access token is rejected", status)

# 7. Insufficient WordPress permissions.
if LOW_USER:
    _, low = oauth_token(LOW_USER, LOW_PASSWORD)
    check(low.get("error") == "access_denied", "a user without edit_posts cannot authorize", low)

print()
if FAILURES:
    print(f"{len(FAILURES)} check(s) failed:")
    for item in FAILURES:
        print(" - " + item)
    sys.exit(1)
print("All ChatGPT OAuth/MCP HTTP checks passed.")
