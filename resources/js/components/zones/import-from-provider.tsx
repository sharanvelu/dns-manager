import { ProviderMark } from '@/components/entries/helpers';
import type { ZoneAttachment, ZoneOption } from '@/components/entries/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ChevronRight, Download } from 'lucide-react';
import { useState } from 'react';
import { ImportRecordsDialog } from './import-records-dialog';

interface ImportFromProviderProps {
    /** Zones the user can manage records in — the only ones offered. */
    zones: ZoneOption[];
    zoneAttachments: Record<number, ZoneAttachment[]>;
    /** Zone records page: no zone picker. */
    lockedZone?: ZoneOption;
    defaultZoneId?: number;
}

/**
 * Toolbar entry point for the provider import: pick a zone (global entries
 * page only) and one of its attachments, then hand off to the shared
 * ImportRecordsDialog. Renders nothing when no offered zone has an
 * attachment.
 */
export function ImportFromProvider({ zones, zoneAttachments, lockedZone, defaultZoneId }: ImportFromProviderProps) {
    const candidateZones = (lockedZone ? zones.filter((zone) => zone.id === lockedZone.id) : zones).filter(
        (zone) => (zoneAttachments[zone.id] ?? []).length > 0,
    );

    const [pickerOpen, setPickerOpen] = useState(false);
    const [zoneId, setZoneId] = useState<number | null>(null);
    const [target, setTarget] = useState<{ zone: ZoneOption; attachment: ZoneAttachment } | null>(null);

    if (candidateZones.length === 0) return null;

    const pickedZone = candidateZones.find((zone) => zone.id === zoneId) ?? null;
    const attachments = pickedZone ? (zoneAttachments[pickedZone.id] ?? []) : [];

    const start = () => {
        const initial = candidateZones.find((zone) => zone.id === defaultZoneId) ?? (candidateZones.length === 1 ? candidateZones[0] : null);
        const initialAttachments = initial ? (zoneAttachments[initial.id] ?? []) : [];

        // One zone with one attachment: nothing to pick.
        if (initial && candidateZones.length === 1 && initialAttachments.length === 1) {
            setTarget({ zone: initial, attachment: initialAttachments[0] });

            return;
        }

        setZoneId(initial?.id ?? null);
        setPickerOpen(true);
    };

    const pick = (attachment: ZoneAttachment) => {
        if (!pickedZone) return;

        setPickerOpen(false);
        setTarget({ zone: pickedZone, attachment });
    };

    return (
        <>
            <Button variant="outline" onClick={start}>
                <Download className="size-4" />
                Import from provider
            </Button>

            <Dialog open={pickerOpen} onOpenChange={setPickerOpen}>
                <DialogContent className="sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Import from a provider</DialogTitle>
                        <DialogDescription>
                            Fetch the live records of one of {lockedZone ? <span className="font-mono">{lockedZone.name}</span> : 'a zone'}'s
                            providers and choose which to manage here.
                        </DialogDescription>
                    </DialogHeader>

                    {!lockedZone && (
                        <div className="grid gap-2">
                            <Label htmlFor="import-zone">Zone</Label>
                            <Select value={zoneId !== null ? String(zoneId) : ''} onValueChange={(value) => setZoneId(Number(value))}>
                                <SelectTrigger id="import-zone">
                                    <SelectValue placeholder="Select a zone" />
                                </SelectTrigger>
                                <SelectContent>
                                    {candidateZones.map((zone) => (
                                        <SelectItem key={zone.id} value={String(zone.id)} className="font-mono">
                                            {zone.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    {pickedZone && (
                        <div className="grid gap-2">
                            {!lockedZone && <Label>Provider</Label>}
                            <div className="divide-y rounded-lg border">
                                {attachments.map((attachment) => (
                                    <button
                                        key={attachment.id}
                                        type="button"
                                        onClick={() => pick(attachment)}
                                        className="hover:bg-muted/40 flex w-full cursor-pointer items-center gap-3 px-3 py-2.5 text-left text-sm first:rounded-t-lg last:rounded-b-lg"
                                    >
                                        <ProviderMark
                                            type={attachment.providerType}
                                            className={`size-4 shrink-0 ${attachment.enabled ? '' : 'opacity-50'}`}
                                        />
                                        <span className="min-w-0 flex-1 truncate">{attachment.providerName}</span>
                                        {!attachment.enabled && (
                                            <Badge variant="outline" className="text-muted-foreground shrink-0 font-normal">
                                                Paused
                                            </Badge>
                                        )}
                                        <ChevronRight className="text-muted-foreground size-4 shrink-0" />
                                    </button>
                                ))}
                            </div>
                        </div>
                    )}
                </DialogContent>
            </Dialog>

            {target && (
                <ImportRecordsDialog
                    open
                    onOpenChange={(open) => !open && setTarget(null)}
                    zone={target.zone}
                    attachment={{ id: target.attachment.id, providerName: target.attachment.providerName }}
                />
            )}
        </>
    );
}
