import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm, usePage } from '@inertiajs/react';
import { CircleCheck, Download, FileWarning, LoaderCircle, TriangleAlert } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ImportResult, ZoneOption } from './types';

interface ImportEntriesDialogProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** Zone-locked mode pins the import to that zone. */
    lockedZone?: ZoneOption;
    zones: ZoneOption[];
    /** Default zone for the select: the active zone filter, else the only zone. */
    defaultZoneId?: number;
}

export function ImportEntriesDialog({ open, onOpenChange, lockedZone, zones, defaultZoneId }: ImportEntriesDialogProps) {
    const { flash } = usePage<{ flash: { importResult?: ImportResult } }>().props;
    const { data, setData, post, processing, errors, clearErrors } = useForm<{ file: File | null; dns_zone_id: string }>({
        file: null,
        dns_zone_id: '',
    });
    const fileInputRef = useRef<HTMLInputElement>(null);

    // The result of the LAST submit while this dialog has been open.
    const [result, setResult] = useState<ImportResult | null>(null);

    useEffect(() => {
        if (open) {
            const zoneId = lockedZone?.id ?? defaultZoneId;
            setData({ file: null, dns_zone_id: zoneId != null ? String(zoneId) : '' });
            clearErrors();
            setResult(null);
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open]);

    useEffect(() => {
        if (open && flash.importResult) {
            setResult(flash.importResult);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [flash.importResult]);

    const submit = () => {
        post(route('entries.import'), {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setData('file', null);
                if (fileInputRef.current) {
                    fileInputRef.current.value = '';
                }
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Import entries from CSV</DialogTitle>
                    <DialogDescription>
                        Imported entries land in the selected zone and sync to its compatible enabled providers. Duplicates (same name, type, and
                        content) are skipped.
                    </DialogDescription>
                </DialogHeader>

                <div className="grid gap-4">
                    <a
                        href={route('entries.import.sample')}
                        className="text-muted-foreground inline-flex items-center gap-1.5 text-sm underline-offset-4 hover:underline"
                        download
                    >
                        <Download className="size-3.5" />
                        Download the sample file
                    </a>

                    <div className="grid gap-2">
                        <Label htmlFor="import-zone">Zone</Label>
                        {lockedZone ? (
                            <p className="text-muted-foreground bg-muted/40 rounded-md border px-3 py-2 font-mono text-sm">{lockedZone.name}</p>
                        ) : (
                            <>
                                <Select value={data.dns_zone_id} onValueChange={(value) => setData('dns_zone_id', value)}>
                                    <SelectTrigger id="import-zone" className="font-mono text-sm" aria-label="Zone">
                                        <SelectValue placeholder="Select a zone" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {zones.map((zone) => (
                                            <SelectItem key={zone.id} value={String(zone.id)} className="font-mono text-sm">
                                                {zone.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.dns_zone_id} />
                            </>
                        )}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="import-file">CSV file</Label>
                        <Input
                            id="import-file"
                            ref={fileInputRef}
                            type="file"
                            accept=".csv,text/csv"
                            onChange={(event) => setData('file', event.target.files?.[0] ?? null)}
                        />
                        <p className="text-muted-foreground text-xs">
                            Columns: <code className="font-mono">name, type, content, ttl, priority, proxied, comment</code> — only the first three
                            are required. Names are zone-relative (<code className="font-mono">www</code>, <code className="font-mono">@</code> for
                            the apex). Max 1000 rows.
                        </p>
                        <InputError message={errors.file} />
                    </div>

                    {result && (
                        <div className="bg-muted/40 grid gap-2 rounded-lg border p-3 text-sm">
                            <p className="flex items-center gap-2">
                                <CircleCheck className="size-4 text-emerald-600 dark:text-emerald-400" />
                                {result.imported} imported
                                {result.skipped > 0 && <span className="text-muted-foreground">· {result.skipped} duplicate(s) skipped</span>}
                            </p>
                            {result.failed.length > 0 && (
                                <div className="grid gap-1">
                                    <p className="flex items-center gap-2 text-amber-700 dark:text-amber-400">
                                        <FileWarning className="size-4" />
                                        {result.failed.length} row(s) rejected:
                                    </p>
                                    <ul className="text-muted-foreground max-h-40 space-y-1 overflow-y-auto text-xs">
                                        {result.failed.map((failure, index) => (
                                            <li key={index} className="flex gap-2">
                                                {failure.line > 0 && <span className="shrink-0 font-mono">line {failure.line}:</span>}
                                                <span>{failure.message}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                            {result.imported > 0 && result.failed.length === 0 && (
                                <p className="text-muted-foreground text-xs">All rows imported — syncing to providers in the background.</p>
                            )}
                        </div>
                    )}

                    {result && result.imported === 0 && result.failed.length === 0 && result.skipped > 0 && (
                        <p className="flex items-start gap-2 text-xs text-amber-700 dark:text-amber-400">
                            <TriangleAlert className="mt-px size-3.5 shrink-0" />
                            Every row already exists — nothing was imported.
                        </p>
                    )}
                </div>

                <DialogFooter className="gap-2">
                    <Button type="button" variant="outline" onClick={() => onOpenChange(false)} disabled={processing}>
                        {result ? 'Close' : 'Cancel'}
                    </Button>
                    <Button type="button" onClick={submit} disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Import
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
