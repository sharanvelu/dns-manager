import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import { RECORD_TYPES, type RecordType } from './types';

interface BulkEditDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    selectedIds: number[];
    onDone: () => void;
}

interface FieldRowProps {
    label: string;
    enabled: boolean;
    onEnabledChange: (enabled: boolean) => void;
    error?: string;
    children: ReactNode;
}

function FieldRow({ label, enabled, onEnabledChange, error, children }: FieldRowProps) {
    return (
        <div className="grid gap-1.5">
            <label className="flex items-center gap-2.5 text-sm font-medium">
                <Checkbox checked={enabled} onCheckedChange={(checked) => onEnabledChange(checked === true)} />
                {label}
            </label>
            <div className={enabled ? 'pl-6' : 'pointer-events-none pl-6 opacity-40'}>{children}</div>
            {error && <InputError className="pl-6" message={error} />}
        </div>
    );
}

export function BulkEditDialog({ open, onOpenChange, selectedIds, onDone }: BulkEditDialogProps) {
    const [enabled, setEnabled] = useState({ type: false, content: false, ttl: false, comment: false });
    const [type, setType] = useState<RecordType>('A');
    const [content, setContent] = useState('');
    const [ttl, setTtl] = useState('');
    const [comment, setComment] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        if (open) {
            setEnabled({ type: false, content: false, ttl: false, comment: false });
            setType('A');
            setContent('');
            setTtl('');
            setComment('');
            setErrors({});
        }
    }, [open]);

    const setField = (field: keyof typeof enabled, value: boolean) => {
        setEnabled((current) => ({ ...current, [field]: value }));
    };

    const anyEnabled = Object.values(enabled).some(Boolean);
    const missingContent = enabled.content && content.trim() === '';

    const submit = () => {
        const set: { type?: RecordType; content?: string; ttl?: number | null; comment?: string | null } = {};
        if (enabled.type) set.type = type;
        if (enabled.content) set.content = content.trim();
        if (enabled.ttl) set.ttl = ttl === '' ? null : Number(ttl);
        if (enabled.comment) set.comment = comment.trim() === '' ? null : comment.trim();

        setProcessing(true);
        setErrors({});
        router.patch(
            route('entries.bulk.update'),
            { ids: selectedIds, set },
            {
                preserveScroll: true,
                onSuccess: () => {
                    onOpenChange(false);
                    onDone();
                },
                onError: (formErrors) => setErrors(formErrors),
                onFinish: () => setProcessing(false),
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Bulk edit {selectedIds.length} {selectedIds.length === 1 ? 'entry' : 'entries'}</DialogTitle>
                    <DialogDescription>
                        Tick the fields to change; unticked fields keep each entry's current value. Entries that would become invalid or duplicate
                        another entry are skipped and reported.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    <FieldRow label="Type" enabled={enabled.type} onEnabledChange={(value) => setField('type', value)} error={errors['set.type']}>
                        <Select value={type} onValueChange={(value) => setType(value as RecordType)}>
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {RECORD_TYPES.map((recordType) => (
                                    <SelectItem key={recordType} value={recordType}>
                                        {recordType}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {enabled.type && (
                            <p className="text-muted-foreground mt-1.5 text-xs">
                                Entries are re-targeted to providers managing the new type; records are deleted from providers that don't.
                            </p>
                        )}
                    </FieldRow>

                    <FieldRow
                        label="Value"
                        enabled={enabled.content}
                        onEnabledChange={(value) => setField('content', value)}
                        error={errors['set.content']}
                    >
                        <Input
                            value={content}
                            onChange={(event) => setContent(event.target.value)}
                            placeholder="e.g. 192.168.1.10"
                            className="font-mono text-sm"
                            autoComplete="off"
                        />
                    </FieldRow>

                    <FieldRow label="TTL" enabled={enabled.ttl} onEnabledChange={(value) => setField('ttl', value)} error={errors['set.ttl']}>
                        <Input
                            type="number"
                            min={60}
                            max={86400}
                            value={ttl}
                            onChange={(event) => setTtl(event.target.value)}
                            placeholder="Auto"
                            className="tabular-nums"
                        />
                        <p className="text-muted-foreground mt-1.5 text-xs">60–86400 seconds, empty = automatic</p>
                    </FieldRow>

                    <FieldRow
                        label="Comment"
                        enabled={enabled.comment}
                        onEnabledChange={(value) => setField('comment', value)}
                        error={errors['set.comment']}
                    >
                        <Input
                            value={comment}
                            onChange={(event) => setComment(event.target.value)}
                            placeholder="Empty clears the comment"
                            maxLength={255}
                        />
                    </FieldRow>

                    <InputError message={errors.set} />
                </div>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        Cancel
                    </Button>
                    <Button type="button" onClick={submit} disabled={processing || !anyEnabled || missingContent}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Apply to {selectedIds.length} {selectedIds.length === 1 ? 'entry' : 'entries'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
