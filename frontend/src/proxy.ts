import { NextResponse } from "next/server";
import type { NextRequest } from "next/server";

/**
 * Stops protected UI from flashing before the client learns there is no
 * session. Next.js 16 renamed middleware.ts to proxy.ts - see AGENTS.md.
 *
 * Nothing readable here proves anyone is signed in: XSRF-TOKEN is a CSRF
 * token that Laravel hands to any visitor, and logout regenerates it. So this
 * may only redirect in the fail-safe direction - no cookie at all means
 * certainly not signed in. Real enforcement is every API call: a 401 makes the
 * client clear the user and route to /login.
 */
// /presensi and /absen are the student self-check-in pages - reached by
// scanning a QR or tapping a link in a WA group description, with zero
// cookies of any kind (students have no account in this app at all), so
// they must never be gated behind "has a session".
const PUBLIC_PATHS = ["/login", "/aktivasi", "/presensi", "/absen"];

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const definitelyLoggedOut = !request.cookies.has("XSRF-TOKEN");
  // "/" is the public landing page, not the wali home (that moved to
  // /dashboard) - it must render for a visitor with no session at all.
  const isPublicPath = pathname === "/" || PUBLIC_PATHS.some((path) => pathname.startsWith(path));

  if (definitelyLoggedOut && !isPublicPath) {
    const loginUrl = new URL("/login", request.url);
    loginUrl.searchParams.set("redirect", pathname);
    return NextResponse.redirect(loginUrl);
  }

  return NextResponse.next();
}

export const config = {
  // `sanctum` sits alongside `api`: it is the endpoint pair that HANDS OUT the
  // XSRF-TOKEN cookie in the first place, so gating it on "has XSRF-TOKEN"
  // is a chicken-and-egg loop - a first-time visitor would be redirected to
  // /login before ever receiving the cookie the gate checks for.
  matcher: ["/((?!api|sanctum|_next/static|_next/image|favicon.ico|.*\\.(?:svg|png|jpg|jpeg|webp)$).*)"],
};
