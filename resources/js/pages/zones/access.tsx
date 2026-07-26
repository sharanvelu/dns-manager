import { FlashToast } from '@/components/flash-toast';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { GrantDialog } from '@/components/users/grant-dialog';
import { ZoneTabs } from '@/components/zone-tabs';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { LoaderCircle, Lock, MoreHorizontal, Pencil, Plus, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { timeAgo } from '../users/types';
import type { ZoneAccessGrant, ZoneAccessProps } from './types';

export default function ZoneAccess({ zone, zoneCan, grants, zoneRoleOptions, grantableUsers, canGrantZoneAdmin }: ZoneAccessProps) {
    const getInitials = useInitials();

    const [dialog, setDialog] = useState<{ mode: 'add' } | { mode: 'edit'; grant: ZoneAccessGrant } | null>(null);
    const [removing, setRemoving] = useState<ZoneAccessGrant | null>(null);
    const [removingBusy, setRemovingBusy] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Zones', href: '/zones' },
        { title: zone.name, href: `/zones/${zone.id}` },
        { title: 'Access', href: `/zones/${zone.id}/access` },
    ];

    const zoneRoleLabel = (value: string) => zoneRoleOptions.find((option) => option.value === value)?.label ?? value;
    const canGrant = zoneCan.manageAccess && grantableUsers.length > 0;

    const remove = () => {
        if (!removing) return;

        setRemovingBusy(true);
        router.delete(`/zones/${zone.id}/access/${removing.userId}`, {
            preserveScroll: true,
            onSuccess: () => setRemoving(null),
            onFinish: () => setRemovingBusy(false),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Access — ${zone.name}`} />

            <div className="flex h-full flex-1 flex-col gap-6 p-4 md:p-6">
                <ZoneTabs zone={zone} zoneCan={zoneCan} />

                {!zoneCan.manageAccess && (
                    <div className="bg-muted/40 text-muted-foreground flex items-start gap-2 rounded-lg border p-3 text-xs">
                        <ShieldCheck className="mt-px size-3.5 shrink-0" />
                        <p>Read-only — you can see who has access to this zone but not change it.</p>
                    </div>
                )}

                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 className="text-sm font-medium">Zone access</h2>
                            <p className="text-muted-foreground text-xs">
                                Per-user roles granted on <span className="font-mono">{zone.name}</span>. Super Admins always have full access.
                            </p>
                        </div>
                        {canGrant && (
                            <Button size="sm" variant="outline" onClick={() => setDialog({ mode: 'add' })}>
                                <Plus className="size-3.5" />
                                Grant access
                            </Button>
                        )}
                    </div>

                    {grants.length > 0 ? (
                        <div className="divide-border divide-y rounded-lg border">
                            {grants.map((grant) => {
                                const locked = !canGrantZoneAdmin && grant.roles.includes('zone-admin');

                                return (
                                    <div key={grant.userId} className="flex items-center gap-3 px-4 py-3">
                                        <Avatar className="size-9 overflow-hidden rounded-full">
                                            <AvatarImage src={grant.userAvatarUrl ?? undefined} alt={grant.userName} />
                                            <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                                {getInitials(grant.userName)}
                                            </AvatarFallback>
                                        </Avatar>

                                        <div className="min-w-0 flex-1">
                                            <p className="truncate text-sm font-medium">{grant.userName}</p>
                                            <p className="text-muted-foreground truncate text-xs">{grant.userEmail}</p>
                                        </div>

                                        <span className="hidden shrink-0 flex-wrap items-center justify-end gap-1.5 sm:flex">
                                            {grant.roles.map((role) => (
                                                <Badge key={role} variant="secondary">
                                                    {zoneRoleLabel(role)}
                                                </Badge>
                                            ))}
                                        </span>

                                        {grant.createdAt && (
                                            <span
                                                className="text-muted-foreground hidden w-24 shrink-0 text-right text-xs whitespace-nowrap md:block"
                                                title={new Date(grant.createdAt).toLocaleString()}
                                            >
                                                {timeAgo(grant.createdAt)}
                                            </span>
                                        )}

                                        {zoneCan.manageAccess &&
                                            (locked ? (
                                                <TooltipProvider delayDuration={200}>
                                                    <Tooltip>
                                                        <TooltipTrigger asChild>
                                                            <span className="text-muted-foreground flex size-8 shrink-0 items-center justify-center">
                                                                <Lock className="size-3.5" />
                                                            </span>
                                                        </TooltipTrigger>
                                                        <TooltipContent>Managed by Super Admins or User Admins</TooltipContent>
                                                    </Tooltip>
                                                </TooltipProvider>
                                            ) : (
                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button
                                                            variant="ghost"
                                                            size="icon"
                                                            className="text-muted-foreground size-8 shrink-0"
                                                            aria-label={`Actions for ${grant.userName}`}
                                                        >
                                                            <MoreHorizontal className="size-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end" className="w-44">
                                                        <DropdownMenuItem onSelect={() => setDialog({ mode: 'edit', grant })}>
                                                            <Pencil />
                                                            Edit roles
                                                        </DropdownMenuItem>
                                                        <DropdownMenuSeparator />
                                                        <DropdownMenuItem
                                                            onSelect={() => setRemoving(grant)}
                                                            className="text-red-600 focus:text-red-600 dark:text-red-400 dark:focus:text-red-400"
                                                        >
                                                            <Trash2 />
                                                            Remove access
                                                        </DropdownMenuItem>
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            ))}
                                    </div>
                                );
                            })}
                        </div>
                    ) : (
                        <div className="flex flex-col items-center gap-2 rounded-lg border border-dashed p-8 text-center">
                            <p className="text-sm font-medium">No one has zone-specific access yet</p>
                            <p className="text-muted-foreground max-w-md text-xs">
                                Super Admins always have full access to every zone. Grant zone roles to give other users scoped access to{' '}
                                <span className="font-mono">{zone.name}</span>.
                            </p>
                            {canGrant && (
                                <Button size="sm" variant="outline" className="mt-2" onClick={() => setDialog({ mode: 'add' })}>
                                    <Plus className="size-3.5" />
                                    Grant access
                                </Button>
                            )}
                        </div>
                    )}
                </div>
            </div>

            {/* key forces a fresh mount per entry point so prefills apply. */}
            <GrantDialog
                key={dialog?.mode === 'edit' ? `edit-${dialog.grant.userId}` : 'add'}
                open={dialog !== null}
                onOpenChange={(open) => !open && setDialog(null)}
                zoneRoleOptions={zoneRoleOptions}
                fixedZone={{ id: zone.id, name: zone.name }}
                fixedUser={dialog?.mode === 'edit' ? { id: dialog.grant.userId, name: dialog.grant.userName } : undefined}
                users={grantableUsers}
                existing={dialog?.mode === 'edit' ? { roles: dialog.grant.roles } : null}
                disallowZoneAdmin={!canGrantZoneAdmin}
            />

            <Dialog open={removing !== null} onOpenChange={(open) => !open && setRemoving(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Remove {removing?.userName}&rsquo;s access?</DialogTitle>
                        <DialogDescription>
                            {removing?.userName} loses all access to <span className="font-mono">{zone.name}</span>. This does not affect their global
                            roles.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setRemoving(null)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={remove} disabled={removingBusy}>
                            {removingBusy && <LoaderCircle className="size-3.5 animate-spin" />}
                            Remove access
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <FlashToast />
        </AppLayout>
    );
}
