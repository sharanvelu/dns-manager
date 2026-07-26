export interface UserListItem {
    id: number;
    name: string;
    email: string;
    avatarUrl: string | null;
    roles: string[];
    zoneGrantsCount: number;
    createdAt: string;
}

export interface ManagedUser {
    id: number;
    name: string;
    email: string;
    avatarUrl: string | null;
    roles: string[];
    createdAt: string;
}

export interface RoleOption {
    value: string;
    label: string;
    description: string;
}

export interface ZoneGrantItem {
    zoneId: number;
    zoneName: string;
    roles: string[];
}

const globalRoleLabels: Record<string, string> = {
    'super-admin': 'Super Admin',
    'super-viewer': 'Super Viewer',
    'user-admin': 'User Admin',
};

export function globalRoleLabel(value: string): string {
    return globalRoleLabels[value] ?? value;
}

/** Tiny relative-time formatter — no date library. */
export function timeAgo(iso: string): string {
    const seconds = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000));

    if (seconds < 45) return 'just now';
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ago`;
    const hours = Math.floor(minutes / 60);
    if (hours < 24) return `${hours}h ago`;
    const days = Math.floor(hours / 24);
    if (days < 30) return `${days}d ago`;
    const months = Math.floor(days / 30);
    if (months < 12) return `${months}mo ago`;

    return `${Math.floor(months / 12)}y ago`;
}
