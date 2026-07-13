import { RecordTypeBadge } from '@/components/icons';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { cn } from '@/lib/utils';
import type { FormDataConvertible } from '@inertiajs/core';
import { useForm } from '@inertiajs/react';
import { CircleAlert, CircleCheck, LoaderCircle, Plug } from 'lucide-react';
import { FormEventHandler, useState } from 'react';
import { defaultConfig, providerMark } from './lib';
import type { ConfigField, Connector, Provider, TestResult } from './types';

type TestState = { status: 'idle' } | { status: 'loading' } | { status: 'done'; result: TestResult };

interface FormData {
    name: string;
    type: string;
    enabled: boolean;
    managed_record_types: string[];
    config: Record<string, FormDataConvertible>;
    [key: string]: FormDataConvertible;
}

interface ProviderFormDialogProps {
    connectors: Connector[];
    /** When set, the dialog edits this provider; otherwise it creates one. */
    provider: Provider | null;
    onClose: () => void;
}

function TypePickerCard({
    connector,
    selected,
    locked,
    onSelect,
}: {
    connector: Connector;
    selected: boolean;
    locked: boolean;
    onSelect: () => void;
}) {
    const Mark = providerMark(connector.type);

    return (
        <button
            type="button"
            disabled={locked && !selected}
            onClick={onSelect}
            aria-pressed={selected}
            className={cn(
                'flex items-center gap-3 rounded-lg border p-3 text-left transition-colors',
                selected ? 'border-primary bg-primary/5 ring-primary ring-1' : 'hover:border-muted-foreground/40 hover:bg-accent/50',
                locked && !selected && 'cursor-not-allowed opacity-40',
            )}
        >
            <div className="bg-background flex size-9 shrink-0 items-center justify-center rounded-md border">
                <Mark className="size-5" />
            </div>
            <div className="min-w-0">
                <p className="text-sm font-medium">{connector.displayName}</p>
                <p className="text-muted-foreground truncate text-xs">
                    {connector.supportedRecordTypes.slice(0, 4).join(', ')}
                    {connector.supportedRecordTypes.length > 4 && ' …'}
                </p>
            </div>
        </button>
    );
}

export function ProviderFormDialog({ connectors, provider, onClose }: ProviderFormDialogProps) {
    const editing = provider !== null;

    const initialConnector = editing ? connectors.find((c) => c.type === provider.type) : connectors[0];

    const { data, setData, post, put, processing, errors, reset, clearErrors } = useForm<FormData>({
        name: provider?.name ?? '',
        type: provider?.type ?? initialConnector?.type ?? '',
        enabled: provider?.enabled ?? true,
        managed_record_types: provider ? [...provider.managedRecordTypes] : [...(initialConnector?.supportedRecordTypes ?? [])],
        config: provider ? ({ ...provider.config } as Record<string, FormDataConvertible>) : defaultConfig(initialConnector),
    });

    const [test, setTest] = useState<TestState>({ status: 'idle' });

    // Nested keys like `config.api_token` are not part of the FormData type.
    const fieldErrors = errors as Record<string, string | undefined>;

    const connector = connectors.find((c) => c.type === data.type);

    const selectType = (next: Connector) => {
        if (editing || next.type === data.type) {
            return;
        }

        setTest({ status: 'idle' });
        clearErrors();
        setData((previous) => ({
            ...previous,
            type: next.type,
            managed_record_types: [...next.supportedRecordTypes],
            config: defaultConfig(next),
        }));
    };

    const setConfigValue = (key: string, value: FormDataConvertible) => {
        setTest({ status: 'idle' });
        setData('config', { ...data.config, [key]: value });
    };

    const toggleRecordType = (type: string, checked: boolean) => {
        setData('managed_record_types', checked ? [...data.managed_record_types, type] : data.managed_record_types.filter((t) => t !== type));
    };

    const testConnection = async () => {
        setTest({ status: 'loading' });

        try {
            const response = await fetch(route('providers.test'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''),
                },
                body: JSON.stringify({
                    type: data.type,
                    config: data.config,
                    ...(editing ? { provider_id: provider.id } : {}),
                }),
            });

            const body = (await response.json().catch(() => null)) as (TestResult & { message?: string }) | null;

            if (!response.ok) {
                setTest({
                    status: 'done',
                    result: { ok: false, message: body?.message ?? `Request failed (${response.status}).`, details: {} },
                });

                return;
            }

            setTest({
                status: 'done',
                result: { ok: body?.ok ?? false, message: body?.message ?? 'No response message.', details: body?.details ?? {} },
            });
        } catch {
            setTest({
                status: 'done',
                result: { ok: false, message: 'Could not reach the server. Check your connection and try again.', details: {} },
            });
        }
    };

    const submit: FormEventHandler = (event) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                onClose();
            },
        };

        if (editing) {
            put(route('providers.update', provider.id), options);
        } else {
            post(route('providers.store'), options);
        }
    };

    const renderConfigField = (field: ConfigField) => {
        const error = fieldErrors[`config.${field.key}`];
        const value = data.config[field.key];

        if (field.type === 'boolean') {
            return (
                <div key={field.key} className="space-y-1.5">
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id={`config-${field.key}`}
                            checked={value === true}
                            onCheckedChange={(checked) => setConfigValue(field.key, checked === true)}
                        />
                        <Label htmlFor={`config-${field.key}`} className="cursor-pointer">
                            {field.label}
                            {field.required && <span className="ml-0.5 text-red-500">*</span>}
                        </Label>
                    </div>
                    {field.help && <p className="text-muted-foreground text-xs">{field.help}</p>}
                    <InputError message={error} />
                </div>
            );
        }

        const isSecret = field.secret;
        const secretPlaceholder = editing && isSecret ? '•••••••• (unchanged — leave blank to keep)' : undefined;

        return (
            <div key={field.key} className="space-y-1.5">
                <Label htmlFor={`config-${field.key}`}>
                    {field.label}
                    {field.required && <span className="ml-0.5 text-red-500">*</span>}
                </Label>
                <Input
                    id={`config-${field.key}`}
                    type={field.type === 'password' ? 'password' : 'text'}
                    inputMode={field.type === 'url' ? 'url' : undefined}
                    autoComplete="off"
                    value={typeof value === 'string' ? value : ''}
                    placeholder={secretPlaceholder ?? (field.type === 'url' ? 'https://…' : undefined)}
                    onChange={(event) => setConfigValue(field.key, event.target.value)}
                />
                {field.help && <p className="text-muted-foreground text-xs">{field.help}</p>}
                <InputError message={error} />
            </div>
        );
    };

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{editing ? `Edit ${provider.name}` : 'Add provider'}</DialogTitle>
                    <DialogDescription>
                        {editing
                            ? 'Update the connection details and which record types this provider manages.'
                            : 'Connect a DNS provider so the app can manage records for it.'}
                    </DialogDescription>
                </DialogHeader>

                <form onSubmit={submit} noValidate className="space-y-5">
                    {/* Type picker */}
                    <div className="space-y-1.5">
                        <Label>Provider type</Label>
                        <div className="grid gap-2 sm:grid-cols-2">
                            {connectors.map((c) => (
                                <TypePickerCard
                                    key={c.type}
                                    connector={c}
                                    selected={c.type === data.type}
                                    locked={editing}
                                    onSelect={() => selectType(c)}
                                />
                            ))}
                        </div>
                        {editing && <p className="text-muted-foreground text-xs">The type can’t be changed after creation.</p>}
                        <InputError message={fieldErrors.type} />
                    </div>

                    {/* Name */}
                    <div className="space-y-1.5">
                        <Label htmlFor="provider-name">
                            Name<span className="ml-0.5 text-red-500">*</span>
                        </Label>
                        <Input
                            id="provider-name"
                            value={data.name}
                            autoComplete="off"
                            placeholder={connector ? `e.g. Home ${connector.displayName}` : 'e.g. Home DNS'}
                            onChange={(event) => setData('name', event.target.value)}
                        />
                        <InputError message={fieldErrors.name} />
                    </div>

                    {/* Dynamic config */}
                    {connector && connector.configSchema.length > 0 && (
                        <>
                            <Separator />
                            <div className="space-y-4">
                                <p className="text-sm font-medium">Connection</p>
                                {connector.configSchema.map(renderConfigField)}
                            </div>
                        </>
                    )}

                    {/* Managed record types */}
                    {connector && (
                        <>
                            <Separator />
                            <div className="space-y-1.5">
                                <Label>Managed record types</Label>
                                <p className="text-muted-foreground text-xs">Only these record types will be synced to this provider.</p>
                                <div className="grid grid-cols-3 gap-2 pt-1 sm:grid-cols-4">
                                    {connector.supportedRecordTypes.map((type) => {
                                        const checked = data.managed_record_types.includes(type);

                                        return (
                                            <label
                                                key={type}
                                                className={cn(
                                                    'flex cursor-pointer items-center gap-2 rounded-md border px-2.5 py-2 transition-colors',
                                                    checked ? 'border-primary/50 bg-primary/5' : 'hover:bg-accent/50',
                                                )}
                                            >
                                                <Checkbox checked={checked} onCheckedChange={(state) => toggleRecordType(type, state === true)} />
                                                <RecordTypeBadge type={type} />
                                            </label>
                                        );
                                    })}
                                </div>
                                <InputError message={fieldErrors.managed_record_types} />
                            </div>
                        </>
                    )}

                    {/* Enabled */}
                    <div className="flex items-center gap-2">
                        <Checkbox id="provider-enabled" checked={data.enabled} onCheckedChange={(checked) => setData('enabled', checked === true)} />
                        <Label htmlFor="provider-enabled" className="cursor-pointer">
                            Enabled
                        </Label>
                        <span className="text-muted-foreground text-xs">— disabled providers are never synced.</span>
                    </div>

                    <Separator />

                    {/* Test connection */}
                    <div className="space-y-2">
                        <Button type="button" variant="outline" size="sm" disabled={test.status === 'loading' || !connector} onClick={testConnection}>
                            {test.status === 'loading' ? <LoaderCircle className="size-4 animate-spin" /> : <Plug className="size-4" />}
                            Test connection
                        </Button>

                        {test.status === 'done' && (
                            <div
                                className={cn(
                                    'rounded-md border p-3 text-sm',
                                    test.result.ok
                                        ? 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300'
                                        : 'border-red-300 bg-red-50 text-red-800 dark:border-red-500/30 dark:bg-red-500/10 dark:text-red-300',
                                )}
                            >
                                <div className="flex items-start gap-2">
                                    {test.result.ok ? (
                                        <CircleCheck className="mt-0.5 size-4 shrink-0" />
                                    ) : (
                                        <CircleAlert className="mt-0.5 size-4 shrink-0" />
                                    )}
                                    <div className="min-w-0 space-y-1">
                                        <p>{test.result.message}</p>
                                        {Object.keys(test.result.details).length > 0 && (
                                            <dl className="space-y-0.5 text-xs opacity-80">
                                                {Object.entries(test.result.details).map(([key, value]) => (
                                                    <div key={key} className="flex gap-1.5">
                                                        <dt className="font-medium">{key}:</dt>
                                                        <dd className="truncate">{String(value)}</dd>
                                                    </div>
                                                ))}
                                            </dl>
                                        )}
                                    </div>
                                </div>
                            </div>
                        )}
                    </div>

                    <DialogFooter className="gap-2 pt-1 sm:gap-0">
                        <Button type="button" variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            {editing ? 'Save changes' : 'Add provider'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
