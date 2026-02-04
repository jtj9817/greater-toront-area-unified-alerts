import { Link } from '@inertiajs/react';
import { Fragment } from 'react';
import {
    Breadcrumb,
    BreadcrumbItem,
    BreadcrumbLink,
    BreadcrumbList,
    BreadcrumbPage,
    BreadcrumbSeparator,
} from '@/components/ui/breadcrumb';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function Breadcrumbs({
    breadcrumbs,
    id,
}: {
    breadcrumbs: BreadcrumbItemType[];
    id?: string;
}) {
    return (
        <>
            {breadcrumbs.length > 0 && (
                <Breadcrumb id={id || 'breadcrumbs'}>
                    <BreadcrumbList id={`${id || 'breadcrumbs'}-list`}>
                        {breadcrumbs.map((item, index) => {
                            const isLast = index === breadcrumbs.length - 1;
                            return (
                                <Fragment key={index}>
                                    <BreadcrumbItem
                                        id={`${id || 'breadcrumbs'}-item-${index}`}
                                    >
                                        {isLast ? (
                                            <BreadcrumbPage
                                                id={`${id || 'breadcrumbs'}-page-${index}`}
                                            >
                                                {item.title}
                                            </BreadcrumbPage>
                                        ) : (
                                            <BreadcrumbLink
                                                id={`${id || 'breadcrumbs'}-link-${index}`}
                                                asChild
                                            >
                                                <Link href={item.href}>
                                                    {item.title}
                                                </Link>
                                            </BreadcrumbLink>
                                        )}
                                    </BreadcrumbItem>
                                    {!isLast && (
                                        <BreadcrumbSeparator
                                            id={`${id || 'breadcrumbs'}-separator-${index}`}
                                        />
                                    )}
                                </Fragment>
                            );
                        })}
                    </BreadcrumbList>
                </Breadcrumb>
            )}
        </>
    );
}
