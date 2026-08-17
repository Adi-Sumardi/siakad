import { cn } from "@/lib/utils";

export function BrandMark({ className, showName = true }: { className?: string; showName?: boolean }) {
  return (
    <div className={cn("flex items-center gap-2.5", className)}>
      <span className="grid size-8 place-items-center rounded-lg bg-primary text-sm font-bold text-primary-foreground">
        Y
      </span>
      {showName && <span className="text-[15px] font-bold tracking-tight">Siakad YAPI</span>}
    </div>
  );
}
