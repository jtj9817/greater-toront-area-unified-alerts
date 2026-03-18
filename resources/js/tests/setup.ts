import '@testing-library/jest-dom';
import * as matchers from '@testing-library/jest-dom/matchers';
import { cleanup } from '@testing-library/react';
import { expect, afterEach, afterAll, beforeAll, vi } from 'vitest';

// Extends Vitest's expect method with methods from react-testing-library
expect.extend(matchers);

if (!('IntersectionObserver' in globalThis)) {
    class MockIntersectionObserver implements IntersectionObserver {
        readonly root: Element | Document | null;
        readonly rootMargin: string;
        readonly thresholds: number[];

        constructor(
            private readonly _callback: IntersectionObserverCallback,
            private readonly _options: IntersectionObserverInit = {},
        ) {
            this.root = _options.root ?? null;
            this.rootMargin = _options.rootMargin ?? '';
            const threshold = _options.threshold ?? 0;
            this.thresholds = Array.isArray(threshold)
                ? threshold
                : [threshold];
        }

        disconnect(): void {}

        observe(target: Element): void {
            const rect = target.getBoundingClientRect();
            const entry: IntersectionObserverEntry = {
                boundingClientRect: rect,
                intersectionRatio: 0,
                intersectionRect: rect,
                isIntersecting: false,
                rootBounds: null,
                target,
                time: Date.now(),
            };

            this._callback([entry], this);
        }

        takeRecords(): IntersectionObserverEntry[] {
            return [];
        }

        unobserve(target: Element): void {
            void target;
        }
    }

    Object.defineProperty(globalThis, 'IntersectionObserver', {
        writable: true,
        configurable: true,
        value: MockIntersectionObserver,
    });
}

// Reset module registry before each file so vi.mock() factories apply cleanly
// when running with isolate: false (singleFork equivalent per Vitest 4 migration guide).
beforeAll(() => {
    vi.resetModules();
});

// Runs a cleanup after each test case (e.g. clearing jsdom)
afterEach(() => {
    cleanup();
});

// Diagnostic: log open handles/timers after each file to identify what keeps the
// fork event loop alive after all tests complete. Remove once the culprit is fixed.
afterAll(() => {
    type InternalProcess = typeof process & {
        _getActiveHandles?(): { constructor?: { name?: string } }[];
        _getActiveTimers?(): unknown[];
    };
    const proc = process as InternalProcess;
    const handles = proc._getActiveHandles?.() ?? [];
    const timers = proc._getActiveTimers?.() ?? [];
    if (handles.length || timers.length) {
        console.warn(
            `[setup] open handles: ${handles.length.toString()} | active timers: ${timers.length.toString()}`,
        );
        handles.forEach((h, i) => {
            console.warn(`  handle[${i.toString()}]:`, h?.constructor?.name ?? '(unknown)');
        });
    }
});

// Force a GC pass after each file to flush accumulated jsdom/React heap
// across the reused fork process. Requires --expose-gc in NODE_OPTIONS.
afterAll(() => {
    if (typeof (globalThis as { gc?: () => void }).gc === 'function') {
        (globalThis as { gc?: () => void }).gc!();
    }
});
