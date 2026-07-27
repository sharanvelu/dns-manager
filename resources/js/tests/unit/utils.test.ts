import { cn } from '@/lib/utils';
import { describe, expect, it } from 'vitest';

describe('cn', () => {
    it('merges class names', () => {
        expect(cn('px-2', 'py-1')).toBe('px-2 py-1');
    });

    it('drops falsy values', () => {
        expect(cn('px-2', undefined, null, '')).toBe('px-2');
    });

    it('lets later tailwind classes override earlier conflicting ones', () => {
        expect(cn('px-2', 'px-4')).toBe('px-4');
    });

    it('handles conditional class objects', () => {
        expect(cn({ 'font-bold': true, italic: false })).toBe('font-bold');
    });
});
