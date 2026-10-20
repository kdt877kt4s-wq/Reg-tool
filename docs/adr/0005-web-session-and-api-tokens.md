# ADR 0005: The web server holds API tokens; the browser holds only an HttpOnly cookie

Status: Accepted (2026-10-20). Supersedes the "Sanctum SPA cookies" line in the plan.

## Decision
- The Laravel API issues Sanctum personal access tokens (12-hour absolute lifetime, 30-minute
  idle timeout, `rr_` prefix). It runs no browser sessions and no CSRF cookie flow.
- The Next.js server is the only API client. It keeps the token in an HttpOnly, SameSite=Lax
  cookie (`rr_session`, Secure in production) and adds `Authorization: Bearer` and
  `X-Organization-Id` to API calls made from server components and server actions.
- Page scripts never see the token. Server actions get Next.js's built-in origin check
  against cross-site form posts.
- A pending two-factor sign-in is a separate short-lived HttpOnly cookie holding the API's
  one-time challenge, never a partial token.

## Why
Keeping tokens off the client removes the main way an XSS bug turns into account takeover,
and lets the API stay a plain token API that other clients (integrations, mobile) can use
later without a second auth scheme.

## Consequences
- The API base URL is server-only configuration (`API_URL`); the browser never calls the API.
- Signing out deletes the token at the API and clears the cookies.
- A password reset revokes every token for that user.
