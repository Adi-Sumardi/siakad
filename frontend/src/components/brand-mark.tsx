import { cn } from "@/lib/utils";

/**
 * "Siakad YAPI" logo + wordmark - same logo file and layout as PMB's own
 * brand-mark, since the two apps are one family and a guardian arriving from
 * PMB should recognise this immediately as the same school, not a stranger.
 *
 * `variant="dark"` is for panels on the brand-blue gradient (the login
 * page's side panel), where the navy logo would otherwise disappear against
 * navy - it gets a white disc behind it there.
 */
export function BrandMark({
  variant = "light",
  className,
  textClassName,
}: {
  variant?: "light" | "dark";
  className?: string;
  textClassName?: string;
}) {
  return (
    <div
      className={cn(
        "flex items-center gap-2.5 text-[15px] font-semibold tracking-tight",
        variant === "dark" && "text-white",
        className,
      )}
      style={{ fontFamily: "var(--font-brand)" }}
    >
      {/* eslint-disable-next-line @next/next/no-img-element */}
      <img
        src="/images/logo-yapi.png"
        alt="Logo YAPI"
        className={cn(
          "size-8 shrink-0 object-contain",
          variant === "dark" && "rounded-full bg-white p-0.5",
        )}
      />
      <span className={textClassName}>Siakad YAPI</span>
    </div>
  );
}
