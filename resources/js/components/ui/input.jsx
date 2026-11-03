import * as React from "react"

import { cn } from "@/lib/utils"

const Input = React.forwardRef(({ className, type, ...props }, ref) => {
  return (
    <input
      type={type}
      className={cn(
        "flex h-9 w-full rounded-sm border border-border bg-surface px-3 py-2 text-sm text-foreground placeholder:text-text-tertiary outline-none transition-[border-color,box-shadow] duration-150 focus-visible:border-accent-400 focus-visible:ring-[3px] focus-visible:ring-accent-200 disabled:cursor-not-allowed disabled:opacity-60 disabled:bg-canvas",
        className
      )}
      ref={ref}
      {...props} />
  );
})
Input.displayName = "Input"

export { Input }
