import { FlashToast } from '@/components/flash-toast';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ForcePasswordResetButton } from '@/components/users/force-password-reset-button';
import { GrantDialog } from '@/components/users/grant-dialog';
import { SsoOnlyToggle } from '@/components/users/sso-only-toggle';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { LoaderCircle, MailPlus, MoreHorizontal, Pencil, Plus, ShieldCheck, ShieldOff, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { timeAgo, type ManagedUser, type RoleOption, type ZoneGrantItem } from './types';

interface UserShowProps {
    managedUser: ManagedUser;
    grants: ZoneGrantItem[];
    roleOptions: RoleOption[];
    zoneRoleOptions: RoleOption[];
    grantableZones: Array<{ id: number; name: string }>;
    canManage: boolean;
    basicEnabled: boolean;
    ssoEnabled: boolean;
}

function sameRoles(a: string[], b: string[]): boolean {
    return a.length === b.length && [...a].sort().every((role, index) => role === [...b].sort()[index]);
}

function DetailsCard({
    user,
    canManage,
    basicEnabled,
    ssoEnabled,
    isSelf,
}: {
    user: ManagedUser;
    canManage: boolean;
    basicEnabled: boolean;
    ssoEnabled: boolean;
    isSelf: boolean;
}) {
    const { data, setData, put, processing, errors, isDirty } = useForm({ name: user.name, email: user.email, roles: user.roles });
    const [resending, setResending] = useState(false);
    const [resettingTwoFactor, setResettingTwoFactor] = useState(false);
    const [resetBusy, setResetBusy] = useState(false);
    const { errors: pageErrors } = usePage().props as unknown as { errors: Record<string, string> };

    // SSO users' name/email are refreshed from the identity provider on every login.
    const editable = canManage && !user.ssoLinked;

    const methods =
        user.ssoOnly && ssoEnabled
            ? ['Single sign-on only']
            : [user.ssoLinked ? 'Single sign-on' : null, user.hasPassword ? 'Password' : null].filter(Boolean);

    const save = () => put(route('users.update', user.id), { preserveScroll: true });

    const resetTwoFactor = () => {
        setResetBusy(true);
        router.delete(route('users.two-factor.reset', user.id), {
            preserveScroll: true,
            onSuccess: () => setResettingTwoFactor(false),
            onFinish: () => setResetBusy(false),
        });
    };

    const resend = () => {
        setResending(true);
        router.post(route('users.invite', user.id), {}, { preserveScroll: true, onFinish: () => setResending(false) });
    };

    return (
        <Card className="p-5">
            <div className="flex flex-col gap-4">
                <div>
                    <h2 className="text-sm font-medium">Account</h2>
                    <p className="text-muted-foreground text-xs">
                        {user.ssoLinked ? 'Name and email are managed by the identity provider.' : 'Name and email address.'}
                    </p>
                </div>

                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="user-name">Name</Label>
                        <Input id="user-name" value={data.name} disabled={!editable} onChange={(e) => setData('name', e.target.value)} />
                        <InputError message={errors.name} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="user-email">Email address</Label>
                        <Input
                            id="user-email"
                            type="email"
                            value={data.email}
                            disabled={!editable}
                            onChange={(e) => setData('email', e.target.value)}
                        />
                        <InputError message={errors.email} />
                    </div>
                </div>

                {editable && isDirty && (
                    <div>
                        <Button size="sm" onClick={save} disabled={processing}>
                            {processing && <LoaderCircle className="size-3.5 animate-spin" />}
                            Save changes
                        </Button>
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-2 text-xs">
                    <span className="text-muted-foreground">Sign-in:</span>
                    {user.invitePending ? (
                        <Badge variant="outline">Invite pending</Badge>
                    ) : (
                        methods.map((method) => (
                            <Badge key={method} variant="secondary">
                                {method}
                            </Badge>
                        ))
                    )}
                    {!user.invitePending && user.mustChangePassword && (
                        <Badge variant="outline" className="border-amber-500/40 text-amber-700 dark:text-amber-400">
                            Password reset required
                        </Badge>
                    )}
                    {user.twoFactorEnabled && (
                        <Badge variant="secondary" className="gap-1">
                            <ShieldCheck className="size-3" />
                            Two-factor
                        </Badge>
                    )}
                    {user.invitePending && canManage && basicEnabled && (
                        <Button size="sm" variant="outline" onClick={resend} disabled={resending}>
                            {resending ? <LoaderCircle className="size-3.5 animate-spin" /> : <MailPlus className="size-3.5" />}
                            Resend invite
                        </Button>
                    )}
                    {user.twoFactorEnabled && canManage && basicEnabled && !isSelf && (
                        <Button size="sm" variant="outline" onClick={() => setResettingTwoFactor(true)}>
                            <ShieldOff className="size-3.5" />
                            Reset two-factor
                        </Button>
                    )}
                </div>

                {ssoEnabled && canManage && <SsoOnlyToggle user={user} canManage={canManage} />}

                <InputError message={pageErrors.user} />

                <Dialog open={resettingTwoFactor} onOpenChange={setResettingTwoFactor}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Reset two-factor authentication for {user.name}?</DialogTitle>
                            <DialogDescription>
                                Their authenticator app and recovery codes stop working and all of their sessions end. They can sign in with their
                                password alone and set two-factor up again from their profile.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter className="gap-2">
                            <Button variant="outline" onClick={() => setResettingTwoFactor(false)}>
                                Cancel
                            </Button>
                            <Button variant="destructive" onClick={resetTwoFactor} disabled={resetBusy}>
                                {resetBusy && <LoaderCircle className="size-3.5 animate-spin" />}
                                Reset two-factor
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            </div>
        </Card>
    );
}

function GlobalRolesCard({ user, roleOptions, canManage }: { user: ManagedUser; roleOptions: RoleOption[]; canManage: boolean }) {
    const [selected, setSelected] = useState<string[]>(user.roles);
    const [saving, setSaving] = useState(false);
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };

    const dirty = !sameRoles(selected, user.roles);

    const toggle = (role: string, checked: boolean) => {
        setSelected((current) => (checked ? [...current, role] : current.filter((value) => value !== role)));
    };

    const save = () => {
        setSaving(true);
        router.put(
            route('users.update', user.id),
            { roles: selected },
            {
                preserveScroll: true,
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Card className="p-5">
            <div className="flex flex-col gap-4">
                <div>
                    <h2 className="text-sm font-medium">Global roles</h2>
                    <p className="text-muted-foreground text-xs">Roles combine — multiple roles grant the union of their permissions.</p>
                </div>

                <div className="grid gap-2 sm:grid-cols-2">
                    {roleOptions.map((role) => (
                        <label
                            key={role.value}
                            htmlFor={`role-${role.value}`}
                            className={
                                canManage
                                    ? 'flex items-start gap-2.5 rounded-md border p-2.5'
                                    : 'flex items-start gap-2.5 rounded-md border p-2.5 opacity-70'
                            }
                        >
                            <Checkbox
                                id={`role-${role.value}`}
                                checked={selected.includes(role.value)}
                                disabled={!canManage}
                                onCheckedChange={(checked) => toggle(role.value, checked === true)}
                            />
                            <span className="grid gap-0.5">
                                <span className="text-sm leading-none font-medium">{role.label}</span>
                                <span className="text-muted-foreground text-xs">{role.description}</span>
                            </span>
                        </label>
                    ))}
                </div>

                {selected.length === 0 && (
                    <p className="text-muted-foreground text-xs">No global roles — access comes only from zone grants below.</p>
                )}

                {canManage && dirty && (
                    <div className="flex items-center gap-3">
                        <Button size="sm" onClick={save} disabled={saving}>
                            {saving && <LoaderCircle className="size-3.5 animate-spin" />}
                            Save roles
                        </Button>
                        <Button size="sm" variant="ghost" onClick={() => setSelected(user.roles)} disabled={saving}>
                            Reset
                        </Button>
                    </div>
                )}

                <InputError message={errors.roles} />
            </div>
        </Card>
    );
}

function ZoneAccessCard({
    user,
    grants,
    zoneRoleOptions,
    grantableZones,
    canManage,
}: {
    user: ManagedUser;
    grants: ZoneGrantItem[];
    zoneRoleOptions: RoleOption[];
    grantableZones: Array<{ id: number; name: string }>;
    canManage: boolean;
}) {
    const [dialog, setDialog] = useState<{ mode: 'add' } | { mode: 'edit'; grant: ZoneGrantItem } | null>(null);
    const [removing, setRemoving] = useState<ZoneGrantItem | null>(null);
    const [removingBusy, setRemovingBusy] = useState(false);

    const zoneRoleLabel = (value: string) => zoneRoleOptions.find((option) => option.value === value)?.label ?? value;

    const remove = () => {
        if (!removing) return;

        setRemovingBusy(true);
        router.delete(`/zones/${removing.zoneId}/access/${user.id}`, {
            preserveScroll: true,
            onSuccess: () => setRemoving(null),
            onFinish: () => setRemovingBusy(false),
        });
    };

    return (
        <Card className="p-5">
            <div className="flex flex-col gap-4">
                <div className="flex items-center justify-between gap-2">
                    <div>
                        <h2 className="text-sm font-medium">Zone access</h2>
                        <p className="text-muted-foreground text-xs">Per-zone roles granted to {user.name}.</p>
                    </div>
                    {canManage && grantableZones.length > 0 && (
                        <Button size="sm" variant="outline" onClick={() => setDialog({ mode: 'add' })}>
                            <Plus className="size-3.5" />
                            Add zone access
                        </Button>
                    )}
                </div>

                {grants.length > 0 ? (
                    <div className="divide-border divide-y rounded-lg border">
                        {grants.map((grant) => (
                            <div key={grant.zoneId} className="flex items-center gap-3 px-3 py-2.5">
                                <Link href={`/zones/${grant.zoneId}`} className="min-w-0 flex-1 truncate font-mono text-sm hover:underline">
                                    {grant.zoneName}
                                </Link>
                                <span className="flex shrink-0 flex-wrap items-center justify-end gap-1.5">
                                    {grant.roles.map((role) => (
                                        <Badge key={role} variant="secondary">
                                            {zoneRoleLabel(role)}
                                        </Badge>
                                    ))}
                                </span>
                                {canManage && (
                                    <DropdownMenu>
                                        <DropdownMenuTrigger asChild>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                className="text-muted-foreground size-8 shrink-0"
                                                aria-label={`Actions for ${grant.zoneName}`}
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
                                                Remove
                                            </DropdownMenuItem>
                                        </DropdownMenuContent>
                                    </DropdownMenu>
                                )}
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-muted-foreground bg-muted/40 rounded-md px-3 py-2 text-xs">No zone access yet.</p>
                )}
            </div>

            {/* key forces a fresh mount per entry point so prefills apply. */}
            <GrantDialog
                key={dialog?.mode === 'edit' ? `edit-${dialog.grant.zoneId}` : 'add'}
                open={dialog !== null}
                onOpenChange={(open) => !open && setDialog(null)}
                zoneRoleOptions={zoneRoleOptions}
                fixedUser={{ id: user.id, name: user.name }}
                fixedZone={dialog?.mode === 'edit' ? { id: dialog.grant.zoneId, name: dialog.grant.zoneName } : undefined}
                zones={grantableZones}
                existing={dialog?.mode === 'edit' ? { roles: dialog.grant.roles } : null}
            />

            <Dialog open={removing !== null} onOpenChange={(open) => !open && setRemoving(null)}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            Remove access to <span className="font-mono">{removing?.zoneName}</span>?
                        </DialogTitle>
                        <DialogDescription>
                            {user.name} loses access to {removing?.zoneName} — they can no longer see or manage that zone unless a global role grants
                            it.
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
        </Card>
    );
}

function DangerZoneCard({ user, basicEnabled, ssoEnabled }: { user: ManagedUser; basicEnabled: boolean; ssoEnabled: boolean }) {
    const [confirming, setConfirming] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const canForceReset = basicEnabled && user.hasPassword && !(user.ssoOnly && ssoEnabled);

    const destroy = () => {
        setDeleting(true);
        router.delete(route('users.destroy', user.id), {
            onFinish: () => {
                setDeleting(false);
                setConfirming(false);
            },
        });
    };

    return (
        <Card className="border-red-500/30 p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 className="text-sm font-medium">Danger zone</h2>
                    <p className="text-muted-foreground text-xs">
                        Deleting {user.name} removes their roles and zone grants. It does not block them from signing in again via your identity
                        provider — remove them there too.
                        {canForceReset && ' Forcing a password reset signs them out everywhere and emails a reset link.'}
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {canForceReset && <ForcePasswordResetButton user={user} />}
                    <Button variant="destructive" size="sm" onClick={() => setConfirming(true)}>
                        <Trash2 className="size-3.5" />
                        Delete user
                    </Button>
                </div>
            </div>

            <InputError className="mt-2" message={errors.user} />

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete {user.name}?</DialogTitle>
                        <DialogDescription>
                            Their session ends and their roles and zone grants are removed. If they sign in again via SSO, they will be re-provisioned
                            with no access.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setConfirming(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={destroy} disabled={deleting}>
                            {deleting && <LoaderCircle className="size-3.5 animate-spin" />}
                            Delete user
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </Card>
    );
}

export default function UserShow({
    managedUser,
    grants,
    roleOptions,
    zoneRoleOptions,
    grantableZones,
    canManage,
    basicEnabled,
    ssoEnabled,
}: UserShowProps) {
    const getInitials = useInitials();
    const { auth } = usePage<SharedData>().props;
    const isSelf = managedUser.id === auth.user.id;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Users', href: '/users' },
        { title: managedUser.name, href: `/users/${managedUser.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={managedUser.name} />

            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex items-center gap-3">
                    <Avatar className="size-11 overflow-hidden rounded-full">
                        <AvatarImage src={managedUser.avatarUrl ?? undefined} alt={managedUser.name} />
                        <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                            {getInitials(managedUser.name)}
                        </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                        <h1 className="flex items-center gap-2 text-xl font-semibold tracking-tight">
                            <span className="truncate">{managedUser.name}</span>
                            {isSelf && (
                                <Badge variant="secondary" className="text-[10px]">
                                    You
                                </Badge>
                            )}
                        </h1>
                        <p className="text-muted-foreground truncate text-sm">
                            {managedUser.email} ·{' '}
                            <span title={new Date(managedUser.createdAt).toLocaleString()}>joined {timeAgo(managedUser.createdAt)}</span>
                        </p>
                    </div>
                </div>

                <DetailsCard
                    key={`${managedUser.name}|${managedUser.email}|${managedUser.ssoOnly}`}
                    user={managedUser}
                    canManage={canManage}
                    basicEnabled={basicEnabled}
                    ssoEnabled={ssoEnabled}
                    isSelf={isSelf}
                />

                {/* key resyncs the checkbox state after a successful save. */}
                <GlobalRolesCard key={managedUser.roles.join('|')} user={managedUser} roleOptions={roleOptions} canManage={canManage} />

                <ZoneAccessCard
                    user={managedUser}
                    grants={grants}
                    zoneRoleOptions={zoneRoleOptions}
                    grantableZones={grantableZones}
                    canManage={canManage}
                />

                {canManage && !isSelf && <DangerZoneCard user={managedUser} basicEnabled={basicEnabled} ssoEnabled={ssoEnabled} />}
            </div>

            <FlashToast />
        </AppLayout>
    );
}
