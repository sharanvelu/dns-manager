import { LucideIcon } from 'lucide-react';

export interface Auth {
    user: User;
    can: {
        createZones: boolean;
        manageProviders: boolean;
        viewProviders: boolean;
        manageUsers: boolean;
        viewUsers: boolean;
        viewGlobalActivity: boolean;
        hasZoneAccess: boolean;
    };
}

/** Per-zone abilities for a single zone page (zones/records, zones/providers, zones/activity, zones/access). */
export interface ZoneCan {
    /** False for e.g. a user-admin who may only open the Access tab. */
    viewZone: boolean;
    manageRecords: boolean;
    manageAttachments: boolean;
    updateZone: boolean;
    deleteZone: boolean;
    viewActivity: boolean;
    viewAccess: boolean;
    manageAccess: boolean;
}

/** Per-zone abilities keyed by zone id — the entries pages' map shape. */
export type ZoneCanMap = Record<number, { manageRecords: boolean; viewActivity: boolean }>;

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    title: string;
    url: string;
    icon?: LucideIcon | null;
    isActive?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar_url?: string | null;
    roles: string[];
}
