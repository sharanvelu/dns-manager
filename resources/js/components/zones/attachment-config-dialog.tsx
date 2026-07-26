import { ConfigFieldInput, type ConfigField, type ConfigValues } from '@/components/config-fields';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useEffect, type FormEventHandler } from 'react';

interface AttachmentConfigDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    zoneId: number;
    attachment: {
        id: number;
        providerName: string;
        /** Non-secret values; secrets arrive blank (blank on submit = keep). */
        zoneConfig: Record<string, string | boolean>;
    };
    fields: ConfigField[];
}

/** Edit an attachment's per-zone connector settings (e.g. the Cloudflare zone ID). */
export function AttachmentConfigDialog({ open, onOpenChange, zoneId, attachment, fields }: AttachmentConfigDialogProps) {
    const initialConfig = (): ConfigValues =>
        Object.fromEntries(
            fields.map((field) => {
                const value = attachment.zoneConfig[field.key];

                return [field.key, field.type === 'boolean' ? value === true : typeof value === 'string' ? value : ''];
            }),
        );

    const { data, setData, put, processing, errors, clearErrors } = useForm<{ config: ConfigValues }>({ config: initialConfig() });

    useEffect(() => {
        if (!open) return;

        clearErrors();
        setData('config', initialConfig());
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, attachment.id]);

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        put(route('zone-providers.update', [zoneId, attachment.id]), {
            preserveScroll: true,
            onSuccess: () => onOpenChange(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Zone settings — {attachment.providerName}</DialogTitle>
                    <DialogDescription>Per-zone connector settings for this attachment. Credentials stay on the provider.</DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} noValidate className="space-y-5">
                    {fields.map((field) => (
                        <ConfigFieldInput
                            key={field.key}
                            field={field}
                            value={data.config[field.key]}
                            error={errors[`config.${field.key}` as keyof typeof errors]}
                            editing
                            idPrefix="attachment-config"
                            onChange={(key, value) => setData('config', { ...data.config, [key]: value })}
                        />
                    ))}

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            Save settings
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
