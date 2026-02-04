import type { InertiaLinkProps } from '@inertiajs/react';
import { type ClassValue, clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
    return twMerge(clsx(inputs));
}

export function formatTimeAgo(date: string | Date): string {
    const now = new Date();
    const then = new Date(date);
    const seconds = Math.floor((now.getTime() - then.getTime()) / 1000);

    if (seconds < 60) return 'Just now';

    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;

    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;

    const days = Math.floor(hours / 24);
    return `${days}d ago`;
}

export function formatTimestampEST(isoString: string): string {
    const date = new Date(isoString);
    const now = new Date();

    const dayFormatter = new Intl.DateTimeFormat('en-US', {
        timeZone: 'America/Toronto',
        year: 'numeric',
        month: 'numeric',
        day: 'numeric',
    });

    const isSameDay = dayFormatter.format(date) === dayFormatter.format(now);

    const timeOptions: Intl.DateTimeFormatOptions = {
        timeZone: 'America/Toronto',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    };

    if (!isSameDay) {
        timeOptions.month = 'short';
        timeOptions.day = 'numeric';
    }

    const formatted = new Intl.DateTimeFormat('en-US', timeOptions).format(
        date,
    );

    const tzAbbr = new Intl.DateTimeFormat('en-US', {
        timeZone: 'America/Toronto',
        timeZoneName: 'short',
    })
        .format(date)
        .split(' ')
        .pop();

    return `${formatted} ${tzAbbr}`;
}

export function toUrl(url: NonNullable<InertiaLinkProps['href']>): string {
    return typeof url === 'string' ? url : url.url;
}
