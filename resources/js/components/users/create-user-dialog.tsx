import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

interface CreateUserDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    roleOptions: Array<{ value: string; label: string; description: string }>;
    basicEnabled: boolean;
}

export function CreateUserDialog({ open, onOpenChange, roleOptions, basicEnabled }: CreateUserDialogProps) {
    const { data, setData, post, processing, errors, reset, clearErrors } = useForm<{ name: string; email: string; roles: string[] }>({
        name: '',
        email: '',
        roles: [],
    });

    const toggle = (role: string, checked: boolean) =>
        setData('roles', checked ? [...data.roles, role] : data.roles.filter((value) => value !== role));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('users.store'), {
            onSuccess: () => {
                reset();
                onOpenChange(false);
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next) clearErrors();
                onOpenChange(next);
            }}
        >
            <DialogContent className="sm:max-w-md">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <DialogHeader>
                        <DialogTitle>Add user</DialogTitle>
                        <DialogDescription>
                            {basicEnabled
                                ? 'They will receive an email with a link to create their password.'
                                : 'They can sign in with SSO using this email address.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="new-user-name">Name</Label>
                        <Input id="new-user-name" value={data.name} onChange={(e) => setData('name', e.target.value)} required autoFocus />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="new-user-email">Email address</Label>
                        <Input id="new-user-email" type="email" value={data.email} onChange={(e) => setData('email', e.target.value)} required />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <Label>Global roles (optional)</Label>
                        {roleOptions.map((role) => (
                            <label key={role.value} htmlFor={`new-role-${role.value}`} className="flex items-start gap-2.5 rounded-md border p-2.5">
                                <Checkbox
                                    id={`new-role-${role.value}`}
                                    checked={data.roles.includes(role.value)}
                                    onCheckedChange={(checked) => toggle(role.value, checked === true)}
                                />
                                <span className="grid gap-0.5">
                                    <span className="text-sm leading-none font-medium">{role.label}</span>
                                    <span className="text-muted-foreground text-xs">{role.description}</span>
                                </span>
                            </label>
                        ))}
                        <InputError message={errors.roles} />
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-3.5 animate-spin" />}
                            {basicEnabled ? 'Create and send invite' : 'Create user'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
