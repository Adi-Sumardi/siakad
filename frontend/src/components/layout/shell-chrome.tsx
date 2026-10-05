"use client";

import { createContext, useContext } from "react";

/**
 * The feature tour (components/feature-tour.tsx) highlights sidebar items on a
 * phone by opening the mobile drawer itself, so each shell (wali and staff)
 * hands it just those two levers - it has no business reading drawer state
 * back. One shared context so the same tour engine can live inside any shell.
 */
export type ShellChrome = { openMobileNav: () => void; closeMobileNav: () => void };

export const ShellChromeContext = createContext<ShellChrome | null>(null);

/** For pages that mount the tour inside their own shell. */
export function useShellChrome(): ShellChrome | null {
  return useContext(ShellChromeContext);
}
