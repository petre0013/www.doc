import { Toaster as Sonner } from 'sonner';
import { IconCircleCheck, IconAlertTriangle } from '@tabler/icons-react';

// App-themed sonner Toaster. Top-centre, fixed below the navbar so flash
// messages stay visible no matter where the page is scrolled — unlike the old
// in-flow banner. `unstyled` strips sonner's own look so ONLY the sage/
// terracotta tokens apply: Lexend (font-sans), 1px borders, flat surface +
// subtle shadow, matching the rest of the app's cards.
export function Toaster(props) {
    return (
        <Sonner
            position="top-center"
            offset={64}
            gap={8}
            icons={{
                success: <IconCircleCheck className="h-4 w-4" stroke={1.5} />,
                error: <IconAlertTriangle className="h-4 w-4" stroke={1.5} />,
            }}
            toastOptions={{
                unstyled: true,
                classNames: {
                    toast: 'flex w-full items-center gap-2.5 rounded-lg border bg-surface px-4 py-3 font-sans text-sm shadow-sm',
                    title: 'font-medium',
                    icon: 'shrink-0',
                    success: '!border-success-border !bg-success-tint text-success-ink [&_[data-icon]]:text-success-text',
                    error: '!border-danger-border !bg-danger-surface text-danger [&_[data-icon]]:text-danger',
                },
            }}
            {...props}
        />
    );
}
