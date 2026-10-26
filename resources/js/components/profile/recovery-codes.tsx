import { Check, Copy } from 'lucide-react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';

export function RecoveryCodes({ codes }: { codes: string[] }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(codes.join('\n'));
            setCopied(true);
            setTimeout(() => setCopied(false), 2000);
        } catch {
            setCopied(false);
        }
    };

    return (
        <div className="space-y-3 rounded-md border border-amber-500/40 bg-amber-500/5 p-4">
            <div className="flex items-start justify-between gap-3">
                <div>
                    <p className="text-sm font-medium">Recovery codes</p>
                    <p className="text-muted-foreground text-xs">
                        Store these somewhere safe. Each code works once if you lose your authenticator. They are not shown again.
                    </p>
                </div>
                <Button type="button" variant="outline" size="sm" onClick={copy}>
                    {copied ? <Check className="size-3.5 text-emerald-600" /> : <Copy className="size-3.5" />}
                    {copied ? 'Copied' : 'Copy'}
                </Button>
            </div>

            <ul className="grid grid-cols-1 gap-1.5 sm:grid-cols-2">
                {codes.map((code) => (
                    <li key={code} className="bg-muted rounded px-2 py-1 font-mono text-xs">
                        {code}
                    </li>
                ))}
            </ul>
        </div>
    );
}
