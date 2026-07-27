import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { type NavGroup as NavGroupType } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useState } from 'react';

/**
 * A collapsible sidebar group (e.g. "Settings"). Renders nothing when the
 * group has no items; active matching mirrors NavMain's prefix rule.
 */
export function NavGroup({ group }: { group: NavGroupType }) {
    const page = usePage();
    const { state, isMobile } = useSidebar();
    const [open, setOpen] = useState(true);
    const path = page.url.split('?')[0];
    const isActive = (url: string) => path === url || path.startsWith(url + '/');

    // In the icon-collapsed sidebar the group label is visually hidden
    // (opacity-0, pulled up over the item above it), so the group must stay
    // expanded — its item icons are the only visible entry points — and the
    // invisible trigger must not intercept clicks meant for that item.
    const iconCollapsed = state === 'collapsed' && !isMobile;

    if (group.items.length === 0) {
        return null;
    }

    return (
        <Collapsible open={iconCollapsed || open} onOpenChange={setOpen} className="group/collapsible">
            <SidebarGroup className="px-2 py-0">
                <SidebarGroupLabel asChild>
                    <CollapsibleTrigger className="w-full cursor-pointer group-data-[collapsible=icon]:pointer-events-none">
                        {group.title}
                        <ChevronRight className="ml-auto size-3.5 transition-transform group-data-[state=open]/collapsible:rotate-90" />
                    </CollapsibleTrigger>
                </SidebarGroupLabel>
                <CollapsibleContent>
                    <SidebarMenu>
                        {group.items.map((item) => (
                            <SidebarMenuItem key={item.title}>
                                <SidebarMenuButton asChild isActive={isActive(item.url)}>
                                    <Link href={item.url} prefetch>
                                        {item.icon && <item.icon />}
                                        <span>{item.title}</span>
                                    </Link>
                                </SidebarMenuButton>
                            </SidebarMenuItem>
                        ))}
                    </SidebarMenu>
                </CollapsibleContent>
            </SidebarGroup>
        </Collapsible>
    );
}
