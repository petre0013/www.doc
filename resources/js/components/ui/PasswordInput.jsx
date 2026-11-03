import * as React from 'react';
import { useState } from 'react';
import { IconEye, IconEyeOff } from '@tabler/icons-react';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';

// Password field with a show/hide eye toggle. Forwards every Input prop
// (id, value, onChange, placeholder, autoComplete, required…) so it drops in
// anywhere a plain `<Input type="password" />` was used.
const PasswordInput = React.forwardRef(({ className, ...props }, ref) => {
    const [show, setShow] = useState(false);
    return (
        <div className="relative">
            <Input
                ref={ref}
                type={show ? 'text' : 'password'}
                className={cn('pr-9', className)}
                {...props}
            />
            <button
                type="button"
                tabIndex={-1}
                aria-label={show ? 'Hide password' : 'Show password'}
                onClick={() => setShow((v) => !v)}
                className="absolute right-2.5 top-1/2 -translate-y-1/2 text-text-tertiary hover:text-text-secondary"
            >
                {show
                    ? <IconEyeOff className="h-4 w-4" stroke={1.5} />
                    : <IconEye className="h-4 w-4" stroke={1.5} />
                }
            </button>
        </div>
    );
});
PasswordInput.displayName = 'PasswordInput';

export { PasswordInput };
export default PasswordInput;
