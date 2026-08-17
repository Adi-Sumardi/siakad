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
const PUBLIC_PATHS = ["/login", "/aktivasi", "/lupa-password", "/reset-password"];

export function proxy(request: NextRequest) {
  const { pathname } = request.nextUrl;
  const definitelyLoggedOut = !request.cookies.has("XSRF-TOKEN");
  const isPublicPath = PUBLIC_PATHS.some((path) => pathname.startsWith(path));

  if (definitelyLoggedOut && !isPublicPath) {
    const loginUrl = new URL("/login", request.url);
    loginUrl.searchParams.set("redirect", pathname);
    return NextResponse.redirect(loginUrl);
  }

  return NextResponse.next();
}

export const config = {
  matcher: ["/((?!api|_next/static|_next/image|favicon.ico|.*\\.(?:svg|png|jpg|jpeg|webp)$).*)"],
};
