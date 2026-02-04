import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

export default function InputError({
    message,
    className = '',
    id,
    ...props
}: HTMLAttributes<HTMLParagraphElement> & { message?: string; id?: string }) {
    return message ? (
        <p
            id={id || 'input-error'}
            {...props}
            className={cn('text-sm text-red-600 dark:text-red-400', className)}
        >
            {message}
        </p>
    ) : null;
}
