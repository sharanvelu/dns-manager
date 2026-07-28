export interface DocHeading {
    id: string;
    text: string;
    level: number;
}

export interface DocNavPage {
    slug: string;
    title: string;
    nav_order: number;
    description: string;
}

export interface Doc {
    slug: string;
    title: string;
    description: string;
    html: string;
    headings: DocHeading[];
}

export interface SearchEntry {
    slug: string;
    title: string;
    description: string;
    headings: DocHeading[];
    text: string;
}

export interface DocsPageProps {
    doc: Doc;
    pages: DocNavPage[];
    current: string;
    version: string;
    docsSiteUrl: string;
    searchIndex: SearchEntry[];
}
