"use client";

import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";

export type User = {
  ulid: string;
  name: string;
  email: string | null;
  phone?: string | null;
  role: "admin" | "admin_unit" | "guru" | "orangtua";
  is_active: boolean;
  /** Null until the welcome splash has been seen once (per account). */
  welcomed_at?: string | null;
  /** Null until the "Panduan Fitur" tour has been seen once (per account). */
  onboarded_at?: string | null;
  school_unit?: { ulid: string; code: string; label: string; jenjang_group?: string | null } | null;
};

/**
 * Where a signed-in user belongs, by role. One place for this mapping so a
 * role guard on /admin or /guru sending the wrong role elsewhere, and the
 * wali dashboard sending staff onward, can't drift out of sync with each
 * other - they did, briefly, when "/" stopped being the wali home and
 * neither layout guard was told.
 */
export function homePathFor(role: User["role"]): string {
  if (role === "admin" || role === "admin_unit") return "/admin";
  if (role === "guru") return "/guru";

  return "/dashboard";
}

/** What the server tells us after a code has been sent. */
export type OtpChallenge = {
  channel: "email" | "whatsapp";
  /** Masked, so the guardian recognises it without it being readable to anyone else. */
  identifier: string;
  expires_in_minutes: number;
  resend_after_seconds: number;
};

type AuthContextValue = {
  user: User | null;
  loading: boolean;
  requestOtp: (identifier: string) => Promise<OtpChallenge>;
  verifyOtp: (identifier: string, code: string) => Promise<User>;
  logout: () => Promise<void>;
  /** Adopts a session the server already started - used by the activation link. */
  adopt: (user: User) => void;
  /** Records that the welcome splash was seen, so it never shows again for
   *  this account (wali, guru, and admin unit are greeted today). */
  markWelcomed: () => Promise<void>;
  /** Records that the feature tour was seen (finished or skipped), once per
   *  account (wali, guru, and admin unit run it today). */
  markOnboarded: () => Promise<void>;
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  // .then() chains (not async/await) so setState only ever runs in an async
  // callback - the boot effect below calls this synchronously, and awaiting
  // first still trips react-hooks/set-state-in-effect's analysis. The loading
  // flag stays (null user means signed out, not loading) but is only ever
  // written from async callbacks.
  const refresh = useCallback(() => {
    api
      .get<{ user: User }>("/api/auth/me")
      .then(({ user }) => setUser(user))
      .catch((error) => {
        // A 401 on load is simply "not signed in", which is why api.ts excludes
        // this path from its expired-session redirect.
        if (!(error instanceof ApiError) || error.status !== 401) {
          console.error(error);
        }
        setUser(null);
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    refresh();
  }, [refresh]);

  const requestOtp = useCallback(
    (identifier: string) => api.post<OtpChallenge>("/api/auth/otp/request", { identifier }),
    [],
  );

  const verifyOtp = useCallback(async (identifier: string, code: string) => {
    const { user } = await api.post<{ user: User }>("/api/auth/otp/verify", { identifier, code });
    setUser(user);
    return user;
  }, []);

  const markWelcomed = useCallback(async () => {
    // Hidden right away either way - a failed call only means it may show
    // once more on the next login, never that the user is stuck behind it.
    setUser((current) => (current ? { ...current, welcomed_at: current.welcomed_at ?? new Date().toISOString() } : current));
    try {
      const { user } = await api.post<{ user: User }>("/api/auth/welcomed");
      setUser(user);
    } catch (error) {
      console.error(error);
    }
  }, []);

  const markOnboarded = useCallback(async () => {
    // Same contract as markWelcomed: the tour never traps the user behind
    // it, and a failed call just means it may be offered once more later.
    setUser((current) => (current ? { ...current, onboarded_at: current.onboarded_at ?? new Date().toISOString() } : current));
    try {
      const { user } = await api.post<{ user: User }>("/api/auth/onboarded");
      setUser(user);
    } catch (error) {
      console.error(error);
    }
  }, []);

  const logout = useCallback(async () => {
    try {
      await api.post("/api/auth/logout");
    } finally {
      setUser(null);
      // replace(), not href/assign: Back must not return into an
      // authenticated page after logout.
      window.location.replace("/login");
    }
  }, []);

  return (
    <AuthContext.Provider
      value={{ user, loading, requestOtp, verifyOtp, logout, adopt: setUser, markWelcomed, markOnboarded }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth() {
  const context = useContext(AuthContext);

  if (!context) {
    throw new Error("useAuth harus dipakai di dalam AuthProvider");
  }

  return context;
}
