import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import type { User } from '@/types';

export function UserInfo({
    user,
    showEmail = false,
}: {
    user: User;
    showEmail?: boolean;
}) {
    const getInitials = useInitials();

    return (
        <>
            <Avatar
                id="user-info-avatar"
                className="h-8 w-8 overflow-hidden rounded-full"
            >
                <AvatarImage
                    id="user-info-avatar-image"
                    src={user.avatar}
                    alt={user.name}
                />
                <AvatarFallback
                    id="user-info-avatar-fallback"
                    className="rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white"
                >
                    {getInitials(user.name)}
                </AvatarFallback>
            </Avatar>
            <div
                id="user-info-details"
                className="grid flex-1 text-left text-sm leading-tight"
            >
                <span id="user-info-name" className="truncate font-medium">
                    {user.name}
                </span>
                {showEmail && (
                    <span
                        id="user-info-email"
                        className="truncate text-xs text-muted-foreground"
                    >
                        {user.email}
                    </span>
                )}
            </div>
        </>
    );
}
