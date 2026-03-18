import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SavedAlertActionToast } from './SavedAlertActionToast';

describe('SavedAlertActionToast', () => {
    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
    });

    it('renders nothing when there is no feedback', () => {
        render(<SavedAlertActionToast feedback={null} onDismiss={vi.fn()} />);

        expect(
            screen.queryByRole('button', {
                name: 'Dismiss saved alert message',
            }),
        ).not.toBeInTheDocument();
    });

    it('renders the feedback message and dismisses manually', () => {
        const onDismiss = vi.fn();

        render(
            <SavedAlertActionToast
                feedback={{
                    kind: 'saved',
                    message: 'Alert saved.',
                    alertId: 'fire:F1',
                }}
                onDismiss={onDismiss}
            />,
        );

        expect(screen.getByText('Saved Alert')).toBeInTheDocument();
        expect(screen.getByText('Alert saved.')).toBeInTheDocument();

        fireEvent.click(
            screen.getByRole('button', {
                name: 'Dismiss saved alert message',
            }),
        );

        expect(onDismiss).toHaveBeenCalledTimes(1);
    });

    it('auto-dismisses after the timeout window', () => {
        vi.useFakeTimers();
        const onDismiss = vi.fn();

        render(
            <SavedAlertActionToast
                feedback={{
                    kind: 'error',
                    message: 'Failed to save alert. Please try again.',
                }}
                onDismiss={onDismiss}
            />,
        );

        act(() => {
            vi.advanceTimersByTime(4500);
        });

        expect(onDismiss).toHaveBeenCalledTimes(1);
    });
});
