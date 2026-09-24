"use client";

import { useCallback, useEffect, useId, useMemo, useRef, useState } from "react";
import Link from "next/link";
import { usePathname } from "next/navigation";
import { ChevronDown } from "lucide-react";
import { cn } from "@/lib/utils";

export type StaffNavItem = {
  href: string;
  label: string;
  icon: React.ComponentType<{ className?: string }>;
  centralOnly?: boolean;
};

// A collapsible group of nav items. `id` must stay stable: it is the
// localStorage value for the user's open/collapsed choices and a fragment
// of the aria id pair, so renaming it silently resets preferences.
export type StaffNavGroup = {
  id: string;
  label: string;
  items: StaffNavItem[];
};

export type StaffNavSection = StaffNavItem | StaffNavGroup;

const OPEN_GROUPS_KEY = "admin-nav-open-groups";

// The single active-link formula (extracted verbatim from the previous
// inline copies) so link highlighting and group-contains-active can never
// drift apart.
export function isItemActive(pathname: string, href: string): boolean {
  return (
    pathname === href ||
    (href !== "/admin" && href !== "/guru" && pathname.startsWith(`${href}/`))
  );
}

function activeGroupIds(sections: StaffNavSection[], pathname: string): string[] {
  return sections
    .filter((section): section is StaffNavGroup => "items" in section)
    .filter((group) => group.items.some((item) => isItemActive(pathname, item.href)))
    .map((group) => group.id);
}

// Returns null (meaning "no stored preferences") when storage is unavailable,
// the value is missing, corrupt, or references unknown group ids — the caller
// then falls back to the pathname-derived default.
function readStoredOpenGroups(validIds: Set<string>): Set<string> | null {
  try {
    const raw = window.localStorage.getItem(OPEN_GROUPS_KEY);
    if (!raw) return null;
    const parsed: unknown = JSON.parse(raw);
    if (!Array.isArray(parsed)) return null;
    return new Set(parsed.filter((v): v is string => typeof v === "string" && validIds.has(v)));
  } catch {
    return null;
  }
}

// Open/collapsed group state shared by the mobile drawer and the desktop
// aside (both are mounted, so the state lives in the shell, not here).
//
// Initial state is derived from the pathname only — deterministic on server
// and first client render, so there is no hydration mismatch. Stored
// preferences merge in after mount via the effect below.
export function useNavGroups(sections: StaffNavSection[]) {
  const pathname = usePathname();
  const groupIds = useMemo(
    () =>
      new Set(
        sections
          .filter((section): section is StaffNavGroup => "items" in section)
          .map((group) => group.id),
      ),
    [sections],
  );

  const [openGroups, setOpenGroups] = useState<Set<string>>(() => new Set(activeGroupIds(sections, pathname)));

  const restoredRef = useRef(false);

  useEffect(() => {
    // Flat navs (guru) never touch storage.
    if (groupIds.size === 0) return;
    setOpenGroups((prev) => {
      let base = prev;
      if (!restoredRef.current) {
        restoredRef.current = true;
        base = readStoredOpenGroups(groupIds) ?? prev;
      }
      // Navigation may only ever OPEN the group it lands in; it never
      // collapses anything, so the user's manual layout survives routing.
      const next = new Set(base);
      let changed = false;
      for (const id of activeGroupIds(sections, pathname)) {
        if (!next.has(id)) {
          next.add(id);
          changed = true;
        }
      }
      return changed ? next : prev;
    });
  }, [pathname, sections, groupIds]);

  const toggleGroup = useCallback(
    (id: string) => {
      const next = new Set(openGroups);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      setOpenGroups(next);
      try {
        window.localStorage.setItem(OPEN_GROUPS_KEY, JSON.stringify([...next]));
      } catch {
        // Private mode / quota exhausted — the UI state still updates.
      }
    },
    [openGroups],
  );

  return { openGroups, toggleGroup };
}

function NavItemLink({
  item,
  active,
  onNavigate,
}: {
  item: StaffNavItem;
  active: boolean;
  onNavigate?: () => void;
}) {
  const Icon = item.icon;
  return (
    <Link
      href={item.href}
      onClick={onNavigate}
      className={cn(
        "flex items-center gap-3 rounded-xl px-3.5 py-2.5 text-sm font-medium transition-all",
        active
          ? "bg-primary text-primary-foreground shadow-sm font-semibold"
          : "text-muted-foreground hover:bg-accent hover:text-foreground",
      )}
    >
      <Icon className="size-4.5 shrink-0" />
      <span>{item.label}</span>
    </Link>
  );
}

// The one nav renderer shared by the mobile drawer and the desktop sidebar
// (previously two near-identical maps). `onNavigate` closes the drawer after
// a link click; the desktop simply does not pass it.
export function SidebarNav({
  sections,
  openGroups,
  onToggleGroup,
  onNavigate,
}: {
  sections: StaffNavSection[];
  openGroups: Set<string>;
  onToggleGroup: (id: string) => void;
  onNavigate?: () => void;
}) {
  const pathname = usePathname();
  // Both asides are mounted at once, so per-group aria ids must be unique
  // per instance — useId namespaces them.
  const uid = useId();

  return (
    <nav className="flex flex-col gap-1">
      {sections.map((section) => {
        if (!("items" in section)) {
          return (
            <NavItemLink
              key={section.href}
              item={section}
              active={isItemActive(pathname, section.href)}
              onNavigate={onNavigate}
            />
          );
        }

        const open = openGroups.has(section.id);
        const triggerId = `${uid}-${section.id}-trigger`;
        const panelId = `${uid}-${section.id}-panel`;

        return (
          <div key={section.id} className="flex flex-col">
            <button
              type="button"
              id={triggerId}
              onClick={() => onToggleGroup(section.id)}
              aria-expanded={open}
              aria-controls={panelId}
              className={cn(
                "flex w-full items-center justify-between gap-2 rounded-lg px-3 py-2 text-left",
                "text-[11px] font-bold uppercase tracking-wider text-muted-foreground/80",
                "hover:bg-accent/50 hover:text-foreground",
                "focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
              )}
            >
              <span>{section.label}</span>
              <ChevronDown
                aria-hidden="true"
                className={cn(
                  "size-3.5 shrink-0 motion-safe:transition-transform motion-safe:duration-200",
                  open && "rotate-180",
                )}
              />
            </button>
            {open && (
              <ul id={panelId} aria-labelledby={triggerId} className="mb-1 flex flex-col gap-1 pl-2">
                {section.items.map((item) => (
                  <li key={item.href}>
                    <NavItemLink item={item} active={isItemActive(pathname, item.href)} onNavigate={onNavigate} />
                  </li>
                ))}
              </ul>
            )}
          </div>
        );
      })}
    </nav>
  );
}
