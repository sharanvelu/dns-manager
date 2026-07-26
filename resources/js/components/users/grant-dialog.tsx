import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { router, usePage } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useEffect, useState, type FormEventHandler } from 'react';

export interface ZoneRoleOption {
    value: string;
    label: string;
    description: string;
}

export interface GrantDialogZone {
    id: number;
    name: string;
}

export interface GrantDialogUser {
    id: number;
    name: string;
    email: string;
}

export interface GrantDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    zoneRoleOptions: ZoneRoleOption[];
    /** User-detail entry point: the user is fixed, the caller picks/fixes the zone. */
    fixedUser?: { id: number; name: string };
    /** Zone Access tab entry point: the zone is fixed, the caller picks/fixes the user. */
    fixedZone?: { id: number; name: string };
    /** Pick-zone mode: candidate zones without a grant for the fixed user. */
    zones?: GrantDialogZone[];
    /** Pick-user mode: candidate users without a grant on the fixed zone. */
    users?: GrantDialogUser[];
    /** Edit mode: prefill roles and skip the zone/user selects. */
    existing?: { roles: string[] } | null;
    /** Zone-admin actors cannot mint or edit Zone Admin grants. */
    disallowZoneAdmin?: boolean;
}

/**
 * Grant or edit a user's zone access. Shared between the user detail page
 * (fixedUser + pick zone) and the zone Access tab (fixedZone + pick user).
 * Submits PUT /zones/{zone}/access/{user} with the selected roles.
 */
export function GrantDialog({
    open,
    onOpenChange,
    zoneRoleOptions,
    fixedUser,
    fixedZone,
    zones = [],
    users = [],
    existing = null,
    disallowZoneAdmin = false,
}: GrantDialogProps) {
    const { errors } = usePage().props as unknown as { errors: Record<string, string> };
    const [zoneId, setZoneId] = useState<number | null>(fixedZone?.id ?? null);
    const [userId, setUserId] = useState<number | null>(fixedUser?.id ?? null);
    const [roles, setRoles] = useState<string[]>(existing?.roles ?? []);
    const [saving, setSaving] = useState(false);

    const isEdit = existing !== null;
    const zoneName = fixedZone?.name ?? zones.find((zone) => zone.id === zoneId)?.name;
    const userName = fixedUser?.name ?? users.find((candidate) => candidate.id === userId)?.name;

    // Re-arm the form every time the dialog opens.
    useEffect(() => {
        if (!open) return;

        setZoneId(fixedZone?.id ?? (zones.length === 1 ? zones[0].id : null));
        setUserId(fixedUser?.id ?? (users.length === 1 ? users[0].id : null));
        setRoles(existing?.roles ?? []);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    const toggle = (role: string, checked: boolean) => {
        setRoles((current) => (checked ? [...current, role] : current.filter((value) => value !== role)));
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        if (zoneId === null || userId === null || roles.length === 0) return;

        setSaving(true);
        router.put(
            `/zones/${zoneId}/access/${userId}`,
            { roles },
            {
                preserveScroll: true,
                onSuccess: () => onOpenChange(false),
                onFinish: () => setSaving(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Edit zone access' : 'Add zone access'}</DialogTitle>
                    <DialogDescription>
                        {isEdit && userName && zoneName ? (
                            <>
                                Update {userName}&rsquo;s roles on <span className="font-mono">{zoneName}</span>.
                            </>
                        ) : fixedUser ? (
                            <>Choose the zone and the roles {fixedUser.name} should hold there.</>
                        ) : (
                            <>
                                Choose the user and the roles they should hold
                                {zoneName ? (
                                    <>
                                        {' '}
                                        on <span className="font-mono">{zoneName}</span>
                                    </>
                                ) : null}
                                .
                            </>
                        )}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} noValidate className="space-y-5">
                    {!isEdit && fixedZone === undefined && (
                        <div className="space-y-1.5">
                            <Label>Zone</Label>
                            <Select value={zoneId !== null ? String(zoneId) : undefined} onValueChange={(value) => setZoneId(Number(value))}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Choose a zone" />
                                </SelectTrigger>
                                <SelectContent>
                                    {zones.map((zone) => (
                                        <SelectItem key={zone.id} value={String(zone.id)}>
                                            <span className="font-mono">{zone.name}</span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    {!isEdit && fixedUser === undefined && (
                        <div className="space-y-1.5">
                            <Label>User</Label>
                            <Select value={userId !== null ? String(userId) : undefined} onValueChange={(value) => setUserId(Number(value))}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Choose a user" />
                                </SelectTrigger>
                                <SelectContent>
                                    {users.map((candidate) => (
                                        <SelectItem key={candidate.id} value={String(candidate.id)}>
                                            {candidate.name} <span className="text-muted-foreground">({candidate.email})</span>
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    <div className="space-y-1.5">
                        <Label>Roles</Label>
                        <TooltipProvider delayDuration={200}>
                            <div className="grid gap-2">
                                {zoneRoleOptions.map((role) => {
                                    const locked = disallowZoneAdmin && role.value === 'zone-admin';

                                    const row = (
                                        <label
                                            key={role.value}
                                            htmlFor={`grant-role-${role.value}`}
                                            className={
                                                locked
                                                    ? 'flex items-start gap-2.5 rounded-md border p-2.5 opacity-60'
                                                    : 'flex items-start gap-2.5 rounded-md border p-2.5'
                                            }
                                        >
                                            <Checkbox
                                                id={`grant-role-${role.value}`}
                                                checked={roles.includes(role.value)}
                                                disabled={locked}
                                                onCheckedChange={(checked) => toggle(role.value, checked === true)}
                                            />
                                            <span className="grid gap-0.5">
                                                <span className="text-sm leading-none font-medium">{role.label}</span>
                                                <span className="text-muted-foreground text-xs">{role.description}</span>
                                            </span>
                                        </label>
                                    );

                                    if (!locked) return row;

                                    return (
                                        <Tooltip key={role.value}>
                                            <TooltipTrigger asChild>{row}</TooltipTrigger>
                                            <TooltipContent className="max-w-64">
                                                Only a Super Admin or User Admin can grant or change Zone Admin access.
                                            </TooltipContent>
                                        </Tooltip>
                                    );
                                })}
                            </div>
                        </TooltipProvider>
                        <InputError message={errors.roles} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={saving || zoneId === null || userId === null || roles.length === 0}>
                            {saving && <LoaderCircle className="size-3.5 animate-spin" />}
                            {isEdit ? 'Save access' : 'Grant access'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
