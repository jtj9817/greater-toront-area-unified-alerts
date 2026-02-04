export default function Heading({
    title,
    description,
    variant = 'default',
    id,
}: {
    title: string;
    description?: string;
    variant?: 'default' | 'small';
    id?: string;
}) {
    return (
        <header
            id={id || 'heading'}
            className={variant === 'small' ? '' : 'mb-8 space-y-0.5'}
        >
            <h2
                id={`${id || 'heading'}-title`}
                className={
                    variant === 'small'
                        ? 'mb-0.5 text-base font-medium'
                        : 'text-xl font-semibold tracking-tight'
                }
            >
                {title}
            </h2>
            {description && (
                <p
                    id={`${id || 'heading'}-description`}
                    className="text-sm text-muted-foreground"
                >
                    {description}
                </p>
            )}
        </header>
    );
}
