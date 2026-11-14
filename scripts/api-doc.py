import json, sys
routes = json.load(sys.stdin)
pub = [r for r in routes if "/admin/" not in r["uri"]]
adm = [r for r in routes if "/admin/" in r["uri"]]
def auth(r):
    m = " ".join(r["middleware"]) if isinstance(r["middleware"], list) else str(r["middleware"])
    if "auth:sanctum" in m or "Authenticate:sanctum" in m:
        return "token"
    if "signed" in m or "ValidateSignature" in m:
        return "signed link"
    return "public"
def method(r):
    return r["method"].replace("|HEAD", "")
out = ["# REST API v1", "",
 "Base URL: `/api/v1`. JSON everywhere. Money is **integer santim** (500000 = 5,000 ETB).", "",
 "- **Customer auth:** `Authorization: Bearer <token>` from `auth/register` or `auth/login` (Sanctum).",
 "- **Cart:** the `X-Cart-Token` header (the app) or the `km_cart` cookie (website).",
 "- **Errors:** `{\"message\": \"...\", \"errors\": {field: [...]}}` with status 401, 403, 404, 422 or 429.",
 "- A feature that's switched off answers **404**.",
 "- **Admin:** `/api/v1/admin/*` uses the same-domain session + CSRF cookie and 2FA. It is not for the app.",
 "- **Webhooks:** `webhooks/chapa` (signature + re-verify) and `webhooks/telegram` (secret header).", "",
 "## Customer / public endpoints", "", "| Method | Path | Auth |", "|---|---|---|"]
for r in sorted(pub, key=lambda r: r["uri"]):
    out.append("| %s | `/%s` | %s |" % (method(r), r["uri"], auth(r)))
out += ["", "## Admin endpoints", "", "| Method | Path |", "|---|---|"]
for r in sorted(adm, key=lambda r: r["uri"]):
    out.append("| %s | `/%s` |" % (method(r), r["uri"]))
open("docs/api.md", "w").write("\n".join(out) + "\n")
print(len(pub), len(adm))
