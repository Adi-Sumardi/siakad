"use client";

import { useEffect, useState } from "react";
import { api } from "@/lib/api";

/** Fired by the approval screen after a decision, so the nav badge updates without waiting for the poll. */
export const APPROVALS_CHANGED = "point-approvals:changed";

/**
 * How many point records are waiting for this user's decision - the nav
 * badge. `base` is "/api/guru" or "/api/admin"; null skips the request (a
 * role with no seat on the ladder). Polls every two minutes; a failed poll
 * keeps the last number.
 */
export function usePendingApprovals(base: string | null): number {
  const [count, setCount] = useState(0);

  useEffect(() => {
    if (!base) return;
    let cancelled = false;

    const load = () =>
      api
        .get<{ pending: number }>(`${base}/point-approvals/count`)
        .then((d) => {
          if (!cancelled) setCount(d.pending);
        })
        .catch(() => {});

    load();
    const interval = setInterval(load, 120_000);
    window.addEventListener(APPROVALS_CHANGED, load);

    return () => {
      cancelled = true;
      clearInterval(interval);
      window.removeEventListener(APPROVALS_CHANGED, load);
    };
  }, [base]);

  return count;
}
