import { act, render, screen } from '@testing-library/react';
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

describe('NotificationToastLayer', () => {
    afterEach(() => {
        delete window.Echo;
        vi.restoreAllMocks();
    });

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

        expect(await screen.findByText('Assault in progress')).toBeInTheDocument();

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
