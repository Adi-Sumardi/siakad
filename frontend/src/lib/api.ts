/**
 * Fetch client for the Laravel API (Sanctum SPA cookie auth).
 *
 * In production nginx serves /api and /sanctum from the same origin as this
 * app, so cookies are same-origin and NEXT_PUBLIC_API_URL stays empty. In
 * development Laravel runs on another port, so the base URL is explicit and
 * every request needs credentials: "include".
 */
const API_BASE = process.env.NEXT_PUBLIC_API_URL ?? "";

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public errors?: Record<string, string[]>,
    public body?: Record<string, unknown>,
  ) {
    super(message);
    this.name = "ApiError";
  }

  /** The first message for a field, for inline form errors. */
  fieldError(field: string): string | undefined {
    return this.errors?.[field]?.[0];
  }
}

function getCookie(name: string): string | undefined {
  return document.cookie
    .split("; ")
    .find((row) => row.startsWith(`${name}=`))
    ?.split("=")[1];
}

/** Sanctum requires this once before any state-changing request. */
export async function ensureCsrfCookie(): Promise<void> {
  await fetch(`${API_BASE}/sanctum/csrf-cookie`, { credentials: "include" });
}

const UNSAFE_METHODS = new Set(["POST", "PUT", "PATCH", "DELETE"]);

// A 401 here is the ordinary "not signed in yet" case on first page load, not
// an expired session, so it must not trigger the redirect below.
const SESSION_CHECK_PATH = "/api/auth/me";

let redirectingToLogin = false;

function handleExpiredSession() {
  if (redirectingToLogin || typeof window === "undefined" || window.location.pathname === "/login") {
    return;
  }
  redirectingToLogin = true;
  import("sonner").then(({ toast }) => toast.error("Sesi Anda berakhir. Silakan masuk kembali."));
  window.location.href = "/login";
}

export async function apiFetch<T>(path: string, options: RequestInit = {}): Promise<T> {
  const method = (options.method ?? "GET").toUpperCase();

  if (UNSAFE_METHODS.has(method) && !getCookie("XSRF-TOKEN")) {
    await ensureCsrfCookie();
  }

  const xsrfToken = getCookie("XSRF-TOKEN");
  const isFormData = options.body instanceof FormData;

  const response = await fetch(`${API_BASE}${path}`, {
    ...options,
    method,
    credentials: "include",
    headers: {
      Accept: "application/json",
      ...(isFormData ? {} : { "Content-Type": "application/json" }),
      ...(xsrfToken ? { "X-XSRF-TOKEN": decodeURIComponent(xsrfToken) } : {}),
      ...options.headers,
    },
  });

  if (response.status === 204) {
    return undefined as T;
  }

  const body = await response.json().catch(() => ({}));

  if (!response.ok) {
    if (response.status === 401 && path !== SESSION_CHECK_PATH) {
      handleExpiredSession();
    }

    throw new ApiError(
      body.message ?? "Terjadi kesalahan. Coba lagi.",
      response.status,
      body.errors,
      body,
    );
  }

  return body as T;
}

export const api = {
  get: <T>(path: string) => apiFetch<T>(path),
  post: <T>(path: string, data?: unknown) =>
    apiFetch<T>(path, { method: "POST", body: data instanceof FormData ? data : JSON.stringify(data ?? {}) }),
  patch: <T>(path: string, data?: unknown) =>
    apiFetch<T>(path, { method: "PATCH", body: JSON.stringify(data ?? {}) }),
  delete: <T>(path: string) => apiFetch<T>(path, { method: "DELETE" }),
};
