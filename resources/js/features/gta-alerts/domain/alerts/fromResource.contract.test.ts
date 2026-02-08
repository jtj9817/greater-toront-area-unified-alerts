import { describe, expect, it, vi } from 'vitest';

import backendContractFixtureRaw from './__fixtures__/backend-unified-alerts.json?raw';
import { fromResource } from './fromResource';

interface BackendContractFixture {
    alerts: unknown[];
}

function loadBackendContractFixture(): BackendContractFixture {
    return JSON.parse(backendContractFixtureRaw) as BackendContractFixture;
}

function domainWarnMessages(warnSpy: ReturnType<typeof vi.spyOn>): string[] {
    return warnSpy.mock.calls
        .map((args: unknown[]) => args[0])
        .filter(
            (firstArg: unknown): firstArg is string =>
                typeof firstArg === 'string' &&
                firstArg.startsWith('[DomainAlert]'),
        );
}

describe('fromResource backend contract fixture', () => {
    it('parses every backend fixture alert without DomainAlert warnings', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        const fixture = loadBackendContractFixture();
        expect(Array.isArray(fixture.alerts)).toBe(true);
        expect(fixture.alerts.length).toBeGreaterThan(0);

        for (const resource of fixture.alerts) {
            const mapped = fromResource(resource);
            expect(mapped).not.toBeNull();
        }

        expect(domainWarnMessages(warn)).toEqual([]);

        warn.mockRestore();
    });
});
