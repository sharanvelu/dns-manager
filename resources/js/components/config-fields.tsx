import InputError from '@/components/input-error';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/** Mirror of the backend ConfigField DTO serialized by ConnectorRegistry::descriptors(). */
export interface ConfigField {
    key: string;
    label: string;
    type: 'text' | 'password' | 'url' | 'boolean';
    secret: boolean;
    required: boolean;
    help?: string | null;
    default?: unknown;
}

export type ConfigValues = Record<string, string | boolean>;

export function defaultConfigFor(fields: ConfigField[]): ConfigValues {
    return Object.fromEntries(fields.map((field) => [field.key, field.type === 'boolean' ? field.default === true : '']));
}

/**
 * Schema-driven config input shared by provider credential forms and
 * zone-attachment forms. Secrets in edit mode show the
 * "unchanged — leave blank to keep" placeholder per DESIGN.md.
 */
export function ConfigFieldInput({
    field,
    value,
    error,
    editing,
    idPrefix = 'config',
    onChange,
}: {
    field: ConfigField;
    value: string | boolean | undefined;
    error?: string;
    editing: boolean;
    idPrefix?: string;
    onChange: (key: string, value: string | boolean) => void;
}) {
    if (field.type === 'boolean') {
        return (
            <div className="space-y-1.5">
                <div className="flex items-center gap-2">
                    <Checkbox
                        id={`${idPrefix}-${field.key}`}
                        checked={value === true}
                        onCheckedChange={(checked) => onChange(field.key, checked === true)}
                    />
                    <Label htmlFor={`${idPrefix}-${field.key}`} className="cursor-pointer">
                        {field.label}
                        {field.required && <span className="ml-0.5 text-red-500">*</span>}
                    </Label>
                </div>
                {field.help && <p className="text-muted-foreground text-xs">{field.help}</p>}
                <InputError message={error} />
            </div>
        );
    }

    const secretPlaceholder = editing && field.secret ? '•••••••• (unchanged — leave blank to keep)' : undefined;

    return (
        <div className="space-y-1.5">
            <Label htmlFor={`${idPrefix}-${field.key}`}>
                {field.label}
                {field.required && <span className="ml-0.5 text-red-500">*</span>}
            </Label>
            <Input
                id={`${idPrefix}-${field.key}`}
                type={field.type === 'password' ? 'password' : 'text'}
                inputMode={field.type === 'url' ? 'url' : undefined}
                autoComplete="off"
                value={typeof value === 'string' ? value : ''}
                placeholder={secretPlaceholder ?? (field.type === 'url' ? 'https://…' : undefined)}
                onChange={(event) => onChange(field.key, event.target.value)}
            />
            {field.help && <p className="text-muted-foreground text-xs">{field.help}</p>}
            <InputError message={error} />
        </div>
    );
}
