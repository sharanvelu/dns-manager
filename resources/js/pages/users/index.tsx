import { FlashToast } from '@/components/flash-toast';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CreateUserDialog } from '@/components/users/create-user-dialog';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Plus, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { globalRoleLabel, timeAgo, type RoleOption, type UserListItem } from './types';

interface UsersIndexProps {
    users: UserListItem[];
    canManage: boolean;
    roleOptions: RoleOption[];
    basicEnabled: boolean;
    ssoEnabled: boolean;
    canCreate: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Users',
        href: '/users',
    },
];

function UserRow({ user, isSelf, ssoEnabled }: { user: UserListItem; isSelf: boolean; ssoEnabled: boolean }) {
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
                    {user.invitePending && (
                        <Badge variant="outline" className="text-[10px]">
                            Invite pending
                        </Badge>
                    )}
                    {user.mustChangePassword && (
                        <Badge variant="outline" className="border-amber-500/40 text-[10px] text-amber-700 dark:text-amber-400">
                            Password reset required
                        </Badge>
                    )}
                    {user.ssoOnly && ssoEnabled && (
                        <Badge variant="secondary" className="text-[10px]">
                            SSO only
                        </Badge>
                    )}
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

export default function UsersIndex({ users, canManage, roleOptions, basicEnabled, ssoEnabled, canCreate }: UsersIndexProps) {
    const { auth } = usePage<SharedData>().props;
    const [creating, setCreating] = useState(false);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Users" />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-start justify-between gap-3">
                    <div>
                        <h1 className="text-xl font-semibold tracking-tight">Users</h1>
                        <p className="text-muted-foreground text-sm">Who can sign in, their global roles, and their zone access.</p>
                    </div>
                    {canManage && canCreate && (
                        <Button size="sm" onClick={() => setCreating(true)}>
                            <Plus className="size-3.5" />
                            Add user
                        </Button>
                    )}
                </div>

                {!canManage && (
                    <div className="bg-muted/40 text-muted-foreground flex items-start gap-2 rounded-lg border p-3 text-xs">
                        <ShieldCheck className="mt-px size-3.5 shrink-0" />
                        <p>Read-only — you can view users but not change them.</p>
                    </div>
                )}

                <div className="divide-border divide-y rounded-lg border">
                    {users.map((user) => (
                        <UserRow key={user.id} user={user} isSelf={user.id === auth.user.id} ssoEnabled={ssoEnabled} />
                    ))}
                </div>

                <p className="text-muted-foreground text-xs">
                    {users.length} {users.length === 1 ? 'user' : 'users'} · new users are provisioned automatically on first SSO login with no
                    access.
                </p>
            </div>

            {canCreate && (
                <CreateUserDialog
                    open={creating}
                    onOpenChange={setCreating}
                    roleOptions={roleOptions}
                    basicEnabled={basicEnabled}
                    ssoEnabled={ssoEnabled}
                />
            )}

            <FlashToast />
        </AppLayout>
    );
}
