import * as React from "react"
import { Slot } from "@radix-ui/react-slot"
import { cva } from "class-variance-authority";

import { cn } from "@/lib/utils"

const buttonVariants = cva(
  "inline-flex items-center justify-center whitespace-nowrap rounded-sm text-sm font-medium transition-colors duration-150 focus-visible:outline-none focus-visible:ring-[3px] focus-visible:ring-accent-200 disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 [&_svg]:pointer-events-none [&_svg]:shrink-0",
  {
    variants: {
      variant: {
        default: "bg-accent-400 text-text-inverse hover:bg-accent-500",
        destructive: "bg-danger text-text-inverse hover:bg-danger/90",
        outline: "border border-border bg-transparent text-foreground hover:bg-surface-hover",
        secondary: "border border-border bg-surface text-foreground hover:bg-surface-hover",
        ghost: "text-foreground hover:bg-surface-hover",
        link: "text-accent-600 underline-offset-4 hover:underline",
      },
      size: {
        default: "h-[33px] gap-2 px-3.5 py-1.5 text-[13px] [&_svg]:size-4",
        sm: "h-7 gap-2 rounded-sm px-3 text-xs [&_svg]:size-4",
        xs: "h-7 gap-1 rounded-sm px-2 text-xs [&_svg]:size-3.5",
        lg: "h-11 gap-2 rounded-sm px-5 [&_svg]:size-4",
        icon: "size-[33px] [&_svg]:size-4",
        "icon-xs": "h-7 w-7 [&_svg]:size-3.5",
      },
    },
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

const Button = React.forwardRef(({ className, variant, size, asChild = false, ...props }, ref) => {
  const Comp = asChild ? Slot : "button"
  return (
    <Comp
      className={cn(buttonVariants({ variant, size, className }))}
      ref={ref}
      {...props} />
  );
})
Button.displayName = "Button"

export { Button, buttonVariants }
