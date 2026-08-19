"use client";

import { useEffect } from "react";
import { useRouter } from "next/navigation";
import { homePathFor, useAuth, type User } from "@/lib/auth/auth-context";

/**
 * Gate for a page built for exactly one role.
 *
 * Without this, a page with no guard of its own either hangs on an infinite
 * skeleton (an authenticated-but-wrong-role visitor's fetch to a role-gated
 * endpoint 403s, and nothing catches that) or, worse, never fires its fetch
 * at all if the caller only gated the fetch itself rather than redirecting -
 * both read as "broken", not as "not for you". This sends a signed-out
 * visitor to /login and a signed-in wrong-role one to their own area, the
 * same pattern /admin and /guru already enforce in their layouts.
 */
export function useRequireRole(role: User["role"]) {
  const { user, loading } = useAuth();
  const router = useRouter();

  useEffect(() => {
    if (loading) return;

    if (!user) {
      router.replace("/login");
      return;
    }

    if (user.role !== role) {
      router.replace(homePathFor(user.role));
    }
  }, [loading, user, role, router]);

  return { user, loading };
}
