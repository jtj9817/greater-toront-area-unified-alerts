import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { NotificationToastLayer } from './NotificationToastLayer';

type ToastHandler = (payload: Record<string, unknown>) => void;

function setupEchoMock() {
    let handler: ToastHandler | null = null;

    const channel = {
        listen: vi.fn((event: string, callback: ToastHandler) => {
            handler = callback;
            return channel;
        }),
        stopListening: vi.fn(() => channel),
    };

    const echo = {
        private: vi.fn(() => channel),
        leave: vi.fn(),
    };

    window.Echo = echo as unknown as Window['Echo'];

    return {
        echo,
        channel,
        emit: (payload: Record<string, unknown>) => {
            if (handler) {
                handler(payload);
            }
        },
    };
}

function createValidPayload(overrides: Record<string, unknown> = {}) {
    return {
        alert_id: `police:${Math.random().toString(36).slice(2)}`,
        source: 'police',
        severity: 'major',
        summary: 'Test alert summary',
        sent_at: new Date().toISOString(),
        ...overrides,
    };
}

describe('NotificationToastLayer', () => {
    afterEach(() => {
        delete window.Echo;
        vi.restoreAllMocks();
    });

    describe('basic subscription behavior', () => {
        it('subscribes to the authenticated users private channel and renders toast payloads', async () => {
            const { echo, channel, emit } = setupEchoMock();

            const rendered = render(<NotificationToastLayer authUserId={42} />);

            expect(echo.private).toHaveBeenCalledWith('users.42.notifications');
            expect(channel.listen).toHaveBeenCalledWith(
                '.alert.notification.sent',
                expect.any(Function),
            );

            act(() => {
                emit({
                    alert_id: 'police:123',
                    source: 'police',
                    severity: 'major',
                    summary: 'Assault in progress',
                    sent_at: '2026-02-10T16:30:00+00:00',
                });
            });

            expect(
                await screen.findByText('Assault in progress'),
            ).toBeInTheDocument();

            rendered.unmount();

            expect(channel.stopListening).toHaveBeenCalledWith(
                '.alert.notification.sent',
            );
            expect(echo.leave).toHaveBeenCalledWith('users.42.notifications');
        });

        it('does not subscribe when there is no authenticated user', () => {
            const { echo } = setupEchoMock();

            render(<NotificationToastLayer authUserId={null} />);

            expect(echo.private).not.toHaveBeenCalled();
        });
    });

    describe('toast queue limits', () => {
        it('enforces max display count of 4 toasts', async () => {
            const { emit } = setupEchoMock();

            render(<NotificationToastLayer authUserId={42} />);

            // Emit 6 toasts
            act(() => {
                for (let i = 1; i <= 6; i++) {
                    emit(
                        createValidPayload({
                            alert_id: `fire:${i}`,
                            summary: `Toast ${i}`,
                        }),
                    );
                }
            });

            // Should only see the 4 most recent toasts (6, 5, 4, 3)
            expect(await screen.findByText('Toast 6')).toBeInTheDocument();
            expect(screen.getByText('Toast 5')).toBeInTheDocument();
            expect(screen.getByText('Toast 4')).toBeInTheDocument();
            expect(screen.getByText('Toast 3')).toBeInTheDocument();

            // Toast 1 and 2 should not be visible
            expect(screen.queryByText('Toast 1')).not.toBeInTheDocument();
            expect(screen.queryByText('Toast 2')).not.toBeInTheDocument();
        });
    });

    describe('invalid payload handling', () => {
        it('ignores invalid payloads with no render side-effects', async () => {
            const { emit } = setupEchoMock();

            render(<NotificationToastLayer authUserId={42} />);

            const invalidPayloads = [
                // Missing required fields
                { alert_id: 'test:1', source: 'police' },
                { severity: 'major', summary: 'Test' },
                // Wrong types
                {
                    alert_id: 123,
                    source: 'police',
                    severity: 'major',
                    summary: 'Test',
                    sent_at: '2026-01-01',
                },
                {
                    alert_id: 'test:2',
                    source: '',
                    severity: 'major',
                    summary: 'Test',
                    sent_at: '2026-01-01',
                },
                {
                    alert_id: 'test:3',
                    source: 'police',
                    severity: '',
                    summary: 'Test',
                    sent_at: '2026-01-01',
                },
                {
                    alert_id: 'test:4',
                    source: 'police',
                    severity: 'major',
                    summary: '',
                    sent_at: '2026-01-01',
                },
                {
                    alert_id: null,
                    source: 'police',
                    severity: 'major',
                    summary: 'Test',
                    sent_at: '2026-01-01',
                },
            ];

            act(() => {
                invalidPayloads.forEach((payload) => {
                    emit(payload);
                });
            });

            // Wait for any potential async updates
            await act(async () => {
                await new Promise((resolve) => setTimeout(resolve, 50));
            });

            // No toasts should be rendered
            expect(
                document.querySelectorAll('article[role="status"]'),
            ).toHaveLength(0);
        });

        it('renders only valid payloads when mixed with invalid ones', async () => {
            const { emit } = setupEchoMock();

            render(<NotificationToastLayer authUserId={42} />);

            act(() => {
                // Invalid - missing fields
                emit({ alert_id: 'test:1', source: 'police' });
                // Valid
                emit(
                    createValidPayload({
                        alert_id: 'fire:valid',
                        summary: 'Valid alert',
                    }),
                );
                // Invalid - empty strings
                emit({
                    alert_id: '',
                    source: 'police',
                    severity: 'major',
                    summary: 'Test',
                    sent_at: '2026-01-01',
                });
            });

            // Only the valid toast should render
            expect(await screen.findByText('Valid alert')).toBeInTheDocument();
            expect(
                document.querySelectorAll('article[role="status"]'),
            ).toHaveLength(1);
        });
    });

    describe('auth user transitions', () => {
        it('transitions channel subscription when authUserId changes', () => {
            const { echo } = setupEchoMock();

            const { rerender } = render(
                <NotificationToastLayer authUserId={42} />,
            );

            expect(echo.private).toHaveBeenCalledWith('users.42.notifications');
            expect(echo.private).toHaveBeenCalledTimes(1);

            // Change to different user
            rerender(<NotificationToastLayer authUserId={99} />);

            // Should leave old channel and join new
            expect(echo.leave).toHaveBeenCalledWith('users.42.notifications');
            expect(echo.private).toHaveBeenCalledWith('users.99.notifications');
            expect(echo.private).toHaveBeenCalledTimes(2);
        });

        it('cleans up subscription when authUserId becomes null', () => {
            const { echo } = setupEchoMock();

            const { rerender } = render(
                <NotificationToastLayer authUserId={42} />,
            );

            expect(echo.private).toHaveBeenCalledTimes(1);

            // Log out user
            rerender(<NotificationToastLayer authUserId={null} />);

            expect(echo.leave).toHaveBeenCalledWith('users.42.notifications');
            // Should not create new private subscription
            expect(echo.private).toHaveBeenCalledTimes(1);
        });

        it('starts subscription when authUserId changes from null to a value', () => {
            const { echo } = setupEchoMock();

            const { rerender } = render(
                <NotificationToastLayer authUserId={null} />,
            );

            expect(echo.private).not.toHaveBeenCalled();

            // Log in user
            rerender(<NotificationToastLayer authUserId={55} />);

            expect(echo.private).toHaveBeenCalledWith('users.55.notifications');
            expect(echo.private).toHaveBeenCalledTimes(1);
        });
    });

    describe('toast rendering details', () => {
        it('renders toast with correct severity styling for different severities', async () => {
            const { emit } = setupEchoMock();

            render(<NotificationToastLayer authUserId={42} />);

            act(() => {
                emit(
                    createValidPayload({
                        severity: 'critical',
                        summary: 'Critical alert',
                    }),
                );
            });

            const criticalToast = await screen.findByText('Critical alert');
            expect(criticalToast.closest('article')).toHaveClass(
                'border-coral/50',
            );

            act(() => {
                emit(
                    createValidPayload({
                        severity: 'minor',
                        summary: 'Minor alert',
                    }),
                );
            });

            const minorToast = await screen.findByText('Minor alert');
            expect(minorToast.closest('article')).toHaveClass(
                'border-forest/50',
            );
        });

        it('renders source label with proper formatting', async () => {
            const { emit } = setupEchoMock();

            render(<NotificationToastLayer authUserId={42} />);

            act(() => {
                emit(
                    createValidPayload({
                        source: 'go_transit',
                        summary: 'GO test',
                    }),
                );
            });

            expect(await screen.findByText('GO Transit')).toBeInTheDocument();

            act(() => {
                emit(
                    createValidPayload({
                        source: 'toronto_fire',
                        summary: 'Fire test',
                    }),
                );
            });

            expect(await screen.findByText('Toronto Fire')).toBeInTheDocument();
        });

        it('renders dismiss button with accessible label', async () => {
            const { emit } = setupEchoMock();

            render(<NotificationToastLayer authUserId={42} />);

            act(() => {
                emit(
                    createValidPayload({ summary: 'Accessible dismiss test' }),
                );
            });

            await screen.findByText('Accessible dismiss test');

            const dismissButton = screen.getByRole('button', {
                name: 'Dismiss notification Accessible dismiss test',
            });
            expect(dismissButton).toBeInTheDocument();
        });

        it('allows manual dismissal of toasts', async () => {
            const { emit } = setupEchoMock();

            render(<NotificationToastLayer authUserId={42} />);

            act(() => {
                emit(createValidPayload({ summary: 'Dismiss me' }));
            });

            await screen.findByText('Dismiss me');

            const dismissButton = screen.getByRole('button', {
                name: /dismiss/i,
            });
            fireEvent.click(dismissButton);

            await waitFor(() => {
                expect(
                    screen.queryByText('Dismiss me'),
                ).not.toBeInTheDocument();
            });
        });
    });
});
