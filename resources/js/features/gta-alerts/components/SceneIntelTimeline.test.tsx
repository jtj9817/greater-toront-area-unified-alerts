import { render, screen } from '@testing-library/react';
import React from 'react';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import type { SceneIntelItem } from '../domain/alerts/fire/scene-intel';
import * as useSceneIntelHook from '../hooks/useSceneIntel';
import { SceneIntelTimeline } from './SceneIntelTimeline';

// Mock the hook
vi.mock('../hooks/useSceneIntel', () => ({
    useSceneIntel: vi.fn(),
}));

describe('SceneIntelTimeline', () => {
    const mockItems: SceneIntelItem[] = [
        {
            id: 1,
            type: 'milestone',
            type_label: 'Milestone',
            icon: 'flag',
            content: 'Command established',
            timestamp: '2026-02-14T09:28:21+00:00',
        },
        {
            id: 2,
            type: 'alarm_change',
            type_label: 'Alarm Level Change',
            icon: 'trending_up',
            content: 'Alarm level increased to Level 2',
            timestamp: '2026-02-14T10:30:00+00:00',
        },
    ];

    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders list of items', () => {
        (useSceneIntelHook.useSceneIntel as any).mockReturnValue({
            items: mockItems,
            loading: false,
            error: null,
            refresh: vi.fn(),
        });

        render(<SceneIntelTimeline eventNum="12345" />);

        expect(screen.getByText('Scene Intel')).toBeInTheDocument();
        expect(screen.getByText('Command established')).toBeInTheDocument();
        expect(
            screen.getByText('Alarm level increased to Level 2'),
        ).toBeInTheDocument();
        expect(screen.getByText('Milestone')).toBeInTheDocument();
        expect(screen.getByText('Alarm Level Change')).toBeInTheDocument();
    });

    it('shows loading state', () => {
        (useSceneIntelHook.useSceneIntel as any).mockReturnValue({
            items: [],
            loading: true,
            error: null,
            refresh: vi.fn(),
        });

        render(<SceneIntelTimeline eventNum="12345" />);

        expect(screen.getByText('Live')).toBeInTheDocument();
    });

    it('shows error state', () => {
        (useSceneIntelHook.useSceneIntel as any).mockReturnValue({
            items: [],
            loading: false,
            error: new Error('Failed to load'),
            refresh: vi.fn(),
        });

        render(<SceneIntelTimeline eventNum="12345" />);

        expect(screen.getByText('Unable to load updates')).toBeInTheDocument();
        expect(screen.getByText('Try Again')).toBeInTheDocument();
    });

    it('shows empty state', () => {
        (useSceneIntelHook.useSceneIntel as any).mockReturnValue({
            items: [],
            loading: false,
            error: null,
            refresh: vi.fn(),
        });

        render(<SceneIntelTimeline eventNum="12345" />);

        expect(screen.getByText('No updates reported yet')).toBeInTheDocument();
    });

    it('calls refresh when try again is clicked', () => {
        const refreshMock = vi.fn();
        (useSceneIntelHook.useSceneIntel as any).mockReturnValue({
            items: [],
            loading: false,
            error: new Error('Failed to load'),
            refresh: refreshMock,
        });

        render(<SceneIntelTimeline eventNum="12345" />);

        screen.getByText('Try Again').click();

        expect(refreshMock).toHaveBeenCalled();
    });
});
