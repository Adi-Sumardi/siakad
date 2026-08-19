"use client";

import { createContext, useCallback, useContext, useEffect, useState } from "react";
import { api, ApiError } from "@/lib/api";

export type User = {
  ulid: string;
  name: string;
  email: string | null;
  role: "admin" | "admin_unit" | "guru" | "orangtua";
  is_active: boolean;
  school_unit?: { ulid: string; code: string; label: string } | null;
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
};

const AuthContext = createContext<AuthContextValue | null>(null);

export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    try {
      const { user } = await api.get<{ user: User }>("/api/auth/me");
      setUser(user);
    } catch (error) {
      // A 401 on load is simply "not signed in", which is why api.ts excludes
      // this path from its expired-session redirect.
      if (!(error instanceof ApiError) || error.status !== 401) {
        console.error(error);
      }
      setUser(null);
    } finally {
      setLoading(false);
    }
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

  const logout = useCallback(async () => {
    try {
      await api.post("/api/auth/logout");
    } finally {
      setUser(null);
      window.location.href = "/login";
    }
  }, []);

  return (
    <AuthContext.Provider
      value={{ user, loading, requestOtp, verifyOtp, logout, adopt: setUser }}
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
