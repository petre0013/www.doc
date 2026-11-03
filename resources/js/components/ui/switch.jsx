import * as React from 'react';
import { cn } from '@/lib/utils';

/**
 * Sage-themed on/off switch — an accessible toggle (`role="switch"`), no Radix
 * dependency. Controlled: `<Switch checked={x} onCheckedChange={(next) => ...} />`.
 * Track fills accent-400 when on; the knob is a bordered surface circle (separation
 * from borders, not shadows, per the design guidelines).
 */
const Switch = React.forwardRef(({ checked = false, onCheckedChange, disabled, className, ...props }, ref) => (
    <button
        ref={ref}
        type="button"
        role="switch"
        aria-checked={checked}
        disabled={disabled}
        onClick={() => onCheckedChange?.(!checked)}
        className={cn(
            'relative inline-flex h-[22px] w-[40px] shrink-0 cursor-pointer items-center rounded-full outline-none transition-colors duration-150',
            'focus-visible:ring-[3px] focus-visible:ring-accent-200',
            'disabled:cursor-not-allowed disabled:opacity-50',
            checked ? 'bg-accent-400' : 'bg-border',
            className,
        )}
        {...props}
    >
        <span
            className={cn(
                'pointer-events-none inline-block h-[18px] w-[18px] rounded-full border border-border bg-surface transition-transform duration-150',
                checked ? 'translate-x-[20px]' : 'translate-x-[2px]',
            )}
        />
    </button>
));
Switch.displayName = 'Switch';

export { Switch };
