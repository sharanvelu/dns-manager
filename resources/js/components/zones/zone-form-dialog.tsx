import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/react';
import { CircleAlert, LoaderCircle } from 'lucide-react';
import { useEffect, type FormEventHandler } from 'react';

interface ZoneFormDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Set ⇒ edit mode (the domain is immutable once created). */
    zone?: { id: number; name: string; description: string | null } | null;
    /** Enabled zoneless providers that auto-attach to a new zone. */
    zonelessProviders?: string[];
    /** false ⇒ warn that the new zone will sync nowhere. */
    hasProviders?: boolean;
}

/** Create/edit a DNS zone. On create, spells out the zoneless auto-attach behavior. */
export function ZoneFormDialog({ open, onOpenChange, zone = null, zonelessProviders = [], hasProviders = true }: ZoneFormDialogProps) {
    const editing = zone !== null;

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm({
        name: zone?.name ?? '',
        description: zone?.description ?? '',
    });

    useEffect(() => {
        if (!open) return;

        clearErrors();
        setData({ name: zone?.name ?? '', description: zone?.description ?? '' });
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, zone?.id]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        };

        if (editing) {
            put(route('zones.update', zone.id), options);
        } else {
            post(route('zones.store'), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>{editing ? `Edit ${zone.name}` : 'Add zone'}</DialogTitle>
                    <DialogDescription>
                        {editing ? 'Update the zone description.' : 'A zone groups DNS records by domain and decides which providers they sync to.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} noValidate className="space-y-5">
                    {editing ? (
                        <div className="space-y-1.5">
                            <Label>Domain</Label>
                            <p className="bg-muted/40 rounded-md border px-3 py-2 font-mono text-sm">{zone.name}</p>
                            <p className="text-muted-foreground text-xs">The domain can't be changed after creation.</p>
                        </div>
                    ) : (
                        <div className="space-y-1.5">
                            <Label htmlFor="zone-name">Domain</Label>
                            <Input
                                id="zone-name"
                                value={data.name}
                                onChange={(event) => setData('name', event.target.value)}
                                placeholder="example.com"
                                className="font-mono"
                                autoComplete="off"
                                required
                            />
                            <p className="text-muted-foreground text-xs">Record names are stored relative to this zone — use @ for the apex.</p>
                            <InputError message={errors.name} />
                        </div>
                    )}

                    <div className="space-y-1.5">
                        <Label htmlFor="zone-description">Description</Label>
                        <Input
                            id="zone-description"
                            value={data.description ?? ''}
                            onChange={(event) => setData('description', event.target.value)}
                            placeholder="Optional note about this zone"
                            autoComplete="off"
                        />
                        <InputError message={errors.description} />
                    </div>

                    {!editing && zonelessProviders.length > 0 && (
                        <div className="bg-muted/40 space-y-1 rounded-md px-3 py-2 text-xs">
                            {zonelessProviders.map((name) => (
                                <p key={name} className="text-muted-foreground">
                                    <span className="text-foreground font-medium">{name}</span> serves all zones and will be attached automatically;
                                    you can opt out later.
                                </p>
                            ))}
                        </div>
                    )}

                    {!editing && !hasProviders && (
                        <div className="flex items-start gap-2 rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-xs text-amber-700 dark:text-amber-400">
                            <CircleAlert className="mt-px size-3.5 shrink-0" />
                            <span>This zone won't sync anywhere until a provider is attached.</span>
                        </div>
                    )}

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            {editing ? 'Save changes' : 'Add zone'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
