import { FlashToast } from '@/components/flash-toast';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { globalRoleLabel, timeAgo, type UserListItem } from './types';

interface UsersIndexProps {
    users: UserListItem[];
    canManage: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Users',
        href: '/users',
    },
];

function UserRow({ user, isSelf }: { user: UserListItem; isSelf: boolean }) {
    const getInitials = useInitials();

    return (
        <Link href={`/users/${user.id}`} className="hover:bg-muted/40 flex items-center gap-3 px-4 py-3 transition-colors" prefetch>
            <Avatar className="size-9 overflow-hidden rounded-full">
                <AvatarImage src={user.avatarUrl ?? undefined} alt={user.name} />
                <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                    {getInitials(user.name)}
                </AvatarFallback>
            </Avatar>

            <div className="min-w-0 flex-1">
                <p className="flex items-center gap-2 text-sm font-medium">
                    <span className="truncate">{user.name}</span>
                    {isSelf && (
                        <Badge variant="secondary" className="text-[10px]">
                            You
                        </Badge>
                    )}
                </p>
                <p className="text-muted-foreground truncate text-xs">{user.email}</p>
            </div>

            <div className="hidden shrink-0 items-center gap-1.5 sm:flex">
                {user.roles.map((role) => (
                    <Badge key={role} variant="secondary">
                        {globalRoleLabel(role)}
                    </Badge>
                ))}
            </div>

            <span className="text-muted-foreground w-28 shrink-0 text-right text-xs tabular-nums">
                {user.zoneGrantsCount > 0 ? `${user.zoneGrantsCount} ${user.zoneGrantsCount === 1 ? 'zone' : 'zones'}` : 'No zone access'}
            </span>

            <span
                className="text-muted-foreground hidden w-20 shrink-0 text-right text-xs whitespace-nowrap md:block"
                title={new Date(user.createdAt).toLocaleString()}
            >
                {timeAgo(user.createdAt)}
            </span>
        </Link>
    );
}

export default function UsersIndex({ users, canManage }: UsersIndexProps) {
    const { auth } = usePage<SharedData>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div>
                    <h1 className="text-xl font-semibold tracking-tight">Users</h1>
                    <p className="text-muted-foreground text-sm">Who can sign in, their global roles, and their zone access.</p>
                </div>

                {!canManage && (
                    <div className="bg-muted/40 text-muted-foreground flex items-start gap-2 rounded-lg border p-3 text-xs">
                        <ShieldCheck className="mt-px size-3.5 shrink-0" />
                        <p>Read-only — you can view users but not change them.</p>
                    </div>
                )}

                <div className="divide-border divide-y rounded-lg border">
                    {users.map((user) => (
                        <UserRow key={user.id} user={user} isSelf={user.id === auth.user.id} />
                    ))}
                </div>

                <p className="text-muted-foreground text-xs">
                    {users.length} {users.length === 1 ? 'user' : 'users'} · new users are provisioned automatically on first SSO login with no
                    access.
                </p>
            </div>

            <FlashToast />
        </AppLayout>
    );
}
