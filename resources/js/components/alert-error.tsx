import { AlertCircleIcon } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

export default function AlertError({
    errors,
    title,
}: {
    errors: string[];
    title?: string;
}) {
    return (
        <Alert id="alert-error" variant="destructive">
            <AlertCircleIcon id="alert-error-icon" />
            <AlertTitle id="alert-error-title">{title || 'Something went wrong.'}</AlertTitle>
            <AlertDescription id="alert-error-description">
                <ul id="alert-error-list" className="list-inside list-disc text-sm">
                    {Array.from(new Set(errors)).map((error, index) => (
                        <li id={`alert-error-item-${index}`} key={index}>{error}</li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}
