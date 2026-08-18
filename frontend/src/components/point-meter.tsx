import { Badge } from "@/components/ui/badge";
import type { PointThresholdInfo } from "@/lib/types/kesiswaan";

/**
 * A balance is meaningless on its own - "-30" says nothing until it is placed
 * against the band the school has defined for it. This is that placement: the
 * number, tabular so it lines up in a list, next to the label an admin wrote
 * for exactly this range ("Peringatan 1"), not a bare figure a parent has to
 * interpret themselves.
 */
export function PointMeter({
  balance,
  threshold,
  size = "default",
}: {
  balance: number;
  threshold: PointThresholdInfo | null;
  size?: "default" | "sm";
}) {
  const variant = variantFor(threshold, balance);

  return (
    <div className="flex items-center gap-2">
      <span className={`tabular font-bold ${size === "sm" ? "text-base" : "text-xl"}`}>
        {balance > 0 ? `+${balance}` : balance}
      </span>
      {threshold ? (
        <Badge variant={variant}>{threshold.label}</Badge>
      ) : (
        <Badge variant={balance >= 0 ? "good" : "default"}>Belum ada catatan khusus</Badge>
      )}
    </div>
  );
}

function variantFor(threshold: PointThresholdInfo | null, balance: number): "good" | "warn" | "bad" | "default" {
  if (!threshold) return balance >= 0 ? "good" : "default";

  const color = (threshold.color ?? "").toLowerCase();
  if (color === "bad" || color === "warn" || color === "good") return color;

  // No explicit colour on the threshold row - fall back to the balance itself.
  return balance <= -50 ? "bad" : balance < 0 ? "warn" : "good";
}
