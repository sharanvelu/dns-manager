import { NavFooter } from '@/components/nav-footer';
import { NavGroup } from '@/components/nav-group';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavItem, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BookOpen, Globe, History, LayoutGrid, Network, Plug, Users } from 'lucide-react';
import AppLogo from './app-logo';

const footerNavItems: NavItem[] = [
    {
        title: 'Documentation',
        url: '/docs',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { auth } = usePage<SharedData>().props;

    // Persona-driven nav: zone-scoped users see the platform section,
    // Super Admins/Viewers see everything, a pure User Admin sees only
    // the Settings group. Empty sections render nothing.
    const mainNavItems: NavItem[] = [
        ...(auth.can.hasZoneAccess
            ? [
                  { title: 'Dashboard', url: '/dashboard', icon: LayoutGrid },
                  { title: 'Zones', url: '/zones', icon: Network },
                  { title: 'DNS Entries', url: '/entries', icon: Globe },
              ]
            : []),
        ...(auth.can.viewProviders ? [{ title: 'Providers', url: '/providers', icon: Plug }] : []),
    ];

    const settingsGroup = {
        title: 'Settings',
        items: [
            ...(auth.can.viewUsers ? [{ title: 'Users', url: '/users', icon: Users }] : []),
            ...(auth.can.viewGlobalActivity ? [{ title: 'Activity', url: '/activity', icon: History }] : []),
        ],
    };

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/dashboard" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                {mainNavItems.length > 0 && <NavMain items={mainNavItems} />}
                <NavGroup group={settingsGroup} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
