export interface ActivityCauser {
    id: number;
    name: string;
}

export interface ActivityChanges {
    attributes?: Record<string, unknown>;
    old?: Record<string, unknown>;
}

export interface ActivityItem {
    id: number;
    logName: string | null;
    event: string | null;
    description: string;
    causer: ActivityCauser | null;
    subjectType: 'entry' | 'provider' | 'user' | 'zone' | null;
    subjectId: number | null;
    subjectLabel: string | null;
    changes: ActivityChanges | null;
    createdAt: string;
}

export interface ActivityMeta {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
}

export interface ActivityFilters {
    subject_type: string | null;
    subject_id: number | null;
    zone_id?: number | null;
    event: string | null;
    causer_id: number | null;
    log: string | null;
    from: string | null;
    to: string | null;
    per_page: number;
    page: number;
}

export interface ActivitySubjectChip {
    type?: string;
    id?: number;
    label?: string | null;
}

export const SUBJECT_TYPES: { value: string; label: string }[] = [
    { value: 'entry', label: 'Entries' },
    { value: 'provider', label: 'Providers' },
    { value: 'user', label: 'Users' },
    { value: 'zone', label: 'Zones' },
];
