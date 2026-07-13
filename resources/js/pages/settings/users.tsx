import HeadingSmall from '@/components/heading-small';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Separator } from '@/components/ui/separator';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import { LoaderCircle, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';

interface ManagedUser {
    id: number;
    name: string;
    email: string;
    avatarUrl: string | null;
    roles: string[];
    createdAt: string;
}

interface RoleOption {
    value: string;
    label: string;
    description: string;
}

interface UsersPageProps {
    users: ManagedUser[];
    roles: RoleOption[];
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'User management',
        href: '/settings/users',
    },
];

function sameRoles(a: string[], b: string[]): boolean {
    return a.length === b.length && [...a].sort().every((role, index) => role === [...b].sort()[index]);
}

function UserCard({ user, roles, isSelf }: { user: ManagedUser; roles: RoleOption[]; isSelf: boolean }) {
    const getInitials = useInitials();
    const [selected, setSelected] = useState<string[]>(user.roles);
    const [saving, setSaving] = useState(false);
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const { errors } = usePage().props as { errors: Record<string, string> };

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

    const destroy = () => {
        router.delete(route('users.destroy', user.id), {
            preserveScroll: true,
            onFinish: () => setConfirmingDelete(false),
        });
    };

    return (
        <div className="rounded-lg border p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                    <Avatar className="size-9 overflow-hidden rounded-full">
                        <AvatarImage src={user.avatarUrl ?? undefined} alt={user.name} />
                        <AvatarFallback className="rounded-full bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                            {getInitials(user.name)}
                        </AvatarFallback>
                    </Avatar>
                    <div>
                        <p className="flex items-center gap-2 text-sm font-medium">
                            {user.name}
                            {isSelf && (
                                <Badge variant="secondary" className="text-[10px]">
                                    You
                                </Badge>
                            )}
                        </p>
                        <p className="text-muted-foreground text-xs">{user.email}</p>
                    </div>
                </div>

                {!isSelf && (
                    <Button
                        variant="ghost"
                        size="icon"
                        className="text-muted-foreground hover:text-red-600"
                        onClick={() => setConfirmingDelete(true)}
                    >
                        <Trash2 className="size-4" />
                        <span className="sr-only">Delete {user.name}</span>
                    </Button>
                )}
            </div>

            <div className="mt-3 grid gap-2 sm:grid-cols-2">
                {roles.map((role) => (
                    <label key={role.value} htmlFor={`role-${user.id}-${role.value}`} className="flex items-start gap-2.5 rounded-md border p-2.5">
                        <Checkbox
                            id={`role-${user.id}-${role.value}`}
                            checked={selected.includes(role.value)}
                            onCheckedChange={(checked) => toggle(role.value, checked === true)}
                        />
                        <span className="grid gap-0.5">
                            <span className="text-sm leading-none font-medium">{role.label}</span>
                            <span className="text-muted-foreground text-xs">{role.description}</span>
                        </span>
                    </label>
                ))}
            </div>

            {dirty && (
                <div className="mt-3 flex items-center gap-3">
                    <Button size="sm" onClick={save} disabled={saving || selected.length === 0}>
                        {saving && <LoaderCircle className="size-3.5 animate-spin" />}
                        Save roles
                    </Button>
                    <Button size="sm" variant="ghost" onClick={() => setSelected(user.roles)} disabled={saving}>
                        Reset
                    </Button>
                    {selected.length === 0 && <p className="text-xs text-amber-700 dark:text-amber-400">Select at least one role.</p>}
                </div>
            )}

            <InputError className="mt-2" message={errors.roles ?? errors.user} />

            <Dialog open={confirmingDelete} onOpenChange={setConfirmingDelete}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Delete {user.name}?</DialogTitle>
                        <DialogDescription>
                            Their session ends and their role assignments are removed. If they sign in again via SSO, they will be re-provisioned as a
                            Viewer.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button variant="outline" onClick={() => setConfirmingDelete(false)}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={destroy}>
                            Delete user
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

export default function Users({ users, roles }: UsersPageProps) {
    const { auth, flash } = usePage<SharedData & { flash: { success?: string } }>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="User management" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="User management" description="Assign roles to control what each user can do" />

                    <div className="bg-muted/40 text-muted-foreground flex items-start gap-2 rounded-lg border p-3 text-xs">
                        <ShieldCheck className="mt-px size-3.5 shrink-0" />
                        <p>
                            Users are provisioned automatically on first SSO login as <strong>Viewer</strong>. Roles combine — a user with multiple
                            roles gets the union of their permissions. At least one Super Admin must always remain.
                        </p>
                    </div>

                    {flash.success && <p className="text-sm font-medium text-emerald-600 dark:text-emerald-400">{flash.success}</p>}

                    <div className="grid gap-3">
                        {users.map((user) => (
                            <UserCard key={user.id} user={user} roles={roles} isSelf={user.id === auth.user.id} />
                        ))}
                    </div>

                    <Separator />

                    <p className="text-muted-foreground text-xs">
                        {users.length} {users.length === 1 ? 'user' : 'users'} · deleting a user does not block them from signing in again via your
                        identity provider — remove them there too.
                    </p>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
